"""Preflight and deployment tests use an isolated temporary filesystem and fake DB response."""
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('test_store', ROOT/'ops/test_store.py')
ops = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ops)


class ReleaseTools(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        root = Path(self.temp.name)
        for name, path in [('SHOP',root/'test'),('STORAGE',root/'test_storage'),('BACKUPS',root/'backups'),('REPO',root/'repo')]:
            path.mkdir()
            replacement = patch.object(ops,name,path)
            replacement.start(); self.addCleanup(replacement.stop)
        (ops.SHOP/'admin').mkdir()
        (ops.SHOP/'index.php').write_text("<?php define('VERSION', '3.0.2.0');")
        (ops.STORAGE/'cache').mkdir()
        self.config = "<?php\n" + '\n'.join("define('%s', '%s');" % (key,value) for key,value in {
            'HTTP_SERVER':'https://test.lavka-speciy.com/', 'HTTPS_SERVER':'https://test.lavka-speciy.com/',
            'DIR_STORAGE':str(ops.STORAGE)+'/', 'DIR_APPLICATION':str(ops.SHOP)+'/',
            'DIR_CACHE':str(ops.STORAGE/'cache')+'/', 'DIR_MODIFICATION':str(ops.STORAGE)+'/',
            'DB_DRIVER':'mysqli','DB_HOSTNAME':'localhost','DB_USERNAME':'fixture','DB_PASSWORD':'fixture-secret',
            'DB_DATABASE':'lavkasp_test','DB_PREFIX':'oc_','DB_PORT':'3306'
        }.items())
        self.write_configs()
        self.probe = {'ok':True,'database':'lavkasp_test','settings':{'config_theme':'unishop2'},'liveprice_enabled':True,'relevant_template_overrides':0}
        replacement = patch.object(ops.shutil,'which',return_value='/fake/php')
        replacement.start(); self.addCleanup(replacement.stop)
        replacement = patch.object(ops.subprocess,'run',return_value=SimpleNamespace(stdout=json.dumps(self.probe),returncode=0))
        self.run = replacement.start(); self.addCleanup(replacement.stop)

    def write_configs(self):
        for relative in ['config.php','admin/config.php']:
            (ops.SHOP/relative).write_text(self.config)

    def release(self):
        relative = 'catalog/view/theme/unishop2/js/common.js'
        target = ops.SHOP/relative; target.parent.mkdir(parents=True)
        target.write_text('original'); target.chmod(0o640)
        payload = ops.REPO/'store'/relative; payload.parent.mkdir(parents=True)
        payload.write_text('changed')
        new = 'catalog/view/theme/unishop2/stylesheet/lavka-mobile-cards.css'
        (ops.SHOP/new).parent.mkdir(parents=True)
        (ops.REPO/'store'/new).parent.mkdir(parents=True)
        (ops.REPO/'store'/new).write_text('new')
        manifest={'target':ops.HOSTNAME,'files':[
            {'path':relative,'before_sha256':ops.sha(target),'after_sha256':ops.sha(payload)},
            {'path':new,'before_sha256':None,'after_sha256':ops.sha(ops.REPO/'store'/new)}]}
        (ops.REPO/'docs').mkdir()
        (ops.REPO/'docs/release-manifest.json').write_text(json.dumps(manifest))
        return manifest

    def test_static_parser_and_redacted_success_report(self):
        self.config += "\n// define('DB_DATABASE', 'wrong');\ndefine('DIR_LOGS', DIR_STORAGE . 'cache/');"
        self.write_configs()
        values = ops.config_values(ops.SHOP/'config.php')
        self.assertEqual(values['DB_PASSWORD'],'fixture-secret')
        self.assertEqual(values['DIR_LOGS'],str(ops.STORAGE/'cache')+'/')
        report = ops.preflight()
        self.assertTrue(report['ok'],report)
        self.assertNotIn('fixture-secret',json.dumps(report))
        self.assertNotIn('DB_USERNAME',json.dumps(report))

    def test_unexpected_database_never_connects(self):
        self.config = self.config.replace("'lavkasp_test'","'wrong_database'")
        self.write_configs()
        self.assertFalse(ops.preflight()['ok'])
        self.run.assert_not_called()

    def test_shared_cache_is_rejected(self):
        elsewhere = Path(self.temp.name)/'shared_cache'; elsewhere.mkdir()
        (ops.STORAGE/'cache').rmdir(); (ops.STORAGE/'cache').symlink_to(elsewhere,target_is_directory=True)
        self.assertFalse(ops.preflight()['ok'])
        self.run.assert_not_called()

    def test_admin_database_difference_is_rejected(self):
        path = ops.SHOP/'admin/config.php'
        path.write_text(self.config.replace("'fixture'","'different_user'"))
        self.assertFalse(ops.preflight()['ok'])
        self.run.assert_not_called()

    def test_source_hash_mismatch_leaves_files_untouched(self):
        manifest = self.release()
        target = ops.SHOP/manifest['files'][0]['path']; target.write_text('other change')
        with self.assertRaises(ValueError): ops.checked_manifest()
        self.assertEqual(target.read_text(),'other change')
        self.assertEqual(list(ops.BACKUPS.iterdir()),[])

    def test_new_file_conflict_is_rejected(self):
        manifest = self.release()
        target = ops.SHOP/manifest['files'][1]['path']; target.write_text('existing user file')
        with self.assertRaises(ValueError): ops.checked_manifest()
        self.assertEqual(target.read_text(),'existing user file')

    def test_file_symlink_is_rejected(self):
        manifest = self.release()
        target = ops.SHOP/manifest['files'][0]['path']; target.unlink()
        elsewhere = Path(self.temp.name)/'elsewhere.js'; elsewhere.write_text('original')
        target.symlink_to(elsewhere)
        with self.assertRaises(ValueError): ops.checked_manifest()
        self.assertEqual(elsewhere.read_text(),'original')

    def test_install_and_rollback_preserve_content_and_mode(self):
        manifest = self.release()
        ops.checked_manifest()
        result = ops.install(manifest)
        target = ops.SHOP/manifest['files'][0]['path']
        self.assertEqual(target.read_text(),'changed')
        self.assertEqual(target.stat().st_mode & 0o777,0o640)
        ops.rollback(result['rollback_id'])
        self.assertEqual(target.read_text(),'original')
        self.assertFalse((ops.SHOP/manifest['files'][1]['path']).exists())

    def test_partial_failure_restores_originals(self):
        manifest = self.release()
        replace = ops.os.replace
        def interrupted(source,destination):
            if str(destination).endswith('.css'): raise OSError('simulated write failure')
            return replace(source,destination)
        with patch.object(ops.os,'replace',side_effect=interrupted):
            with self.assertRaises(OSError): ops.install(manifest)
        self.assertEqual((ops.SHOP/manifest['files'][0]['path']).read_text(),'original')
        self.assertFalse((ops.SHOP/manifest['files'][1]['path']).exists())
        self.assertFalse(list(ops.SHOP.rglob('*.lavka-install-tmp')))

    def test_rollback_refuses_subsequent_changes(self):
        manifest = self.release()
        result = ops.install(manifest)
        target = ops.SHOP/manifest['files'][0]['path']; target.write_text('later edit')
        with self.assertRaises(ValueError): ops.rollback(result['rollback_id'])
        self.assertEqual(target.read_text(),'later edit')
        self.assertTrue((ops.SHOP/manifest['files'][1]['path']).exists())


if __name__ == '__main__':
    unittest.main()
