"""Preflight and deployment tests use an isolated temporary filesystem and fake DB response."""
import contextlib
import hashlib
import importlib.util
import io
import json
import sys
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
        self.probe = {'ok':True,'database':'lavkasp_test','settings':{'config_theme':'unishop2'},'unishop_settings_found':True,'liveprice_enabled':True,'relevant_template_overrides':0}
        replacement = patch.object(ops.shutil,'which',return_value='/fake/php')
        replacement.start(); self.addCleanup(replacement.stop)
        self.real_run = ops.subprocess.run
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

    def test_missing_theme_settings_do_not_mean_disabled_or_allow_install(self):
        self.probe.pop('unishop_settings_found')
        self.probe.pop('liveprice_enabled')
        self.probe['unishop_loader_refs'] = []  # PHP encodes an empty associative array as [].
        self.run.return_value.stdout = json.dumps(self.probe)
        report = ops.preflight()
        self.assertTrue(report['isolation_ok'])
        self.assertFalse(report['ok'])
        self.assertTrue(any('source is unverified' in issue for issue in report['issues']))
        self.assertFalse(any('is disabled' in issue for issue in report['issues']))

    def test_legacy_code_still_blocks_apply_before_any_file_write(self):
        self.probe['legacy_mobile_code'] = True
        self.run.return_value.stdout = json.dumps(self.probe)
        with patch.object(sys,'argv',['test_store.py','--apply']), \
             patch.object(ops,'install') as install:
            with contextlib.redirect_stdout(io.StringIO()) as output:
                self.assertEqual(ops.main(),2)
            install.assert_not_called()
        self.assertIn(ops.LEGACY_ISSUE,output.getvalue())
        self.assertEqual(list(ops.BACKUPS.iterdir()),[])

    def test_late_probe_error_preserves_identity_without_cascading_app_errors(self):
        self.run.return_value.stdout = json.dumps({'ok':False,'database':'lavkasp_test','database_verified':True,'code':'STORE_MAPPING_QUERY_FAILED','failed_stage':'store_mapping','diagnostics':{'sql_errno':1054}})
        report = ops.preflight()
        self.assertFalse(report['ok'])
        self.assertTrue(report['isolation_ok'])
        self.assertEqual(len(report['issues']), 1)
        self.assertIn('SQL errno=1054', report['issues'][0])
        self.assertEqual(report['warnings'], [])
        self.assertNotIn('runtime_source_refs', report)

    def test_runtime_source_diagnostics_emit_only_identifiers(self):
        path = ops.SHOP/'catalog/model/extension/module/uni_settings.php'
        path.parent.mkdir(parents=True)
        path.write_text('''<?php $private = 'do-not-print'; $table = DB_PREFIX . "uni_setting"; $this->config->get('uni_settings');''')
        self.probe['unishop_loader_refs'] = {'UniShop2 template': {'load_routes':['extension/module/uni_settings','../../elsewhere']}}
        refs = ops.runtime_source_refs(self.probe)
        self.assertEqual(refs[0]['table_names'], ['uni_setting'])
        self.assertEqual(refs[0]['config_get_keys'], ['uni_settings'])
        self.assertNotIn('do-not-print', json.dumps(refs))

    def test_native_settings_source_is_accepted_without_standard_setting_row(self):
        self.probe['unishop_settings_source'] = {'table':'oc_uni_setting','column':'data','store_id':0,'format':'json'}
        self.probe['unishop_setting_rows'] = []
        self.run.return_value.stdout = json.dumps(self.probe)
        report = ops.preflight()
        self.assertTrue(report['ok'], report)
        self.assertEqual(report['db']['unishop_settings_source']['table'], 'oc_uni_setting')
        self.probe['liveprice_enabled'] = False
        self.run.return_value.stdout = json.dumps(self.probe)
        report = ops.preflight()
        self.assertFalse(report['ok'])
        self.assertTrue(any('is disabled in the confirmed settings' in issue for issue in report['issues']))

    def test_probe_uses_python36_subprocess_api_and_captures_stderr(self):
        def python36_run(command, **options):
            self.assertNotIn('text', options)
            self.assertNotIn('capture_output', options)
            self.assertTrue(options.get('universal_newlines'))
            self.assertEqual(options.get('stdout'), ops.subprocess.PIPE)
            self.assertEqual(options.get('stderr'), ops.subprocess.PIPE)
            script = 'import sys,json; json.load(sys.stdin); print(' + repr(json.dumps(self.probe)) + '); sys.stderr.write("fixture-secret")'
            return self.real_run([sys.executable, '-c', script], **options)
        with patch.object(ops.subprocess, 'run', side_effect=python36_run):
            with contextlib.redirect_stdout(io.StringIO()) as output:
                report = ops.preflight()
        self.assertTrue(report['ok'], report)
        self.assertNotIn('fixture-secret', json.dumps(report) + output.getvalue())

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
        with self.assertRaises(ops.ReleaseCheckError) as error: ops.checked_manifest()
        diagnostic = json.loads(str(error.exception))
        self.assertEqual(diagnostic['error'], 'SOURCE_HASH_MISMATCH')
        self.assertEqual(diagnostic['path'], manifest['files'][0]['path'])
        self.assertEqual(diagnostic['actual_sha256'], ops.sha(target))
        self.assertEqual(target.read_text(),'other change')
        self.assertEqual(list(ops.BACKUPS.iterdir()),[])

    def test_known_lf_variant_is_verified_and_rollback_restores_hosting_bytes(self):
        manifest = self.release()
        target = ops.SHOP/manifest['files'][0]['path']
        original = b'first\nsecond\n'
        target.write_bytes(original)
        manifest['files'][0]['before_sha256'] = hashlib.sha256(original.replace(b'\n',b'\r\n')).hexdigest()
        manifest['files'][0]['before_lf_sha256'] = hashlib.sha256(original).hexdigest()
        (ops.REPO/'docs/release-manifest.json').write_text(json.dumps(manifest))
        checked = ops.checked_manifest()
        self.assertEqual(checked['files'][0]['before_sha256'],hashlib.sha256(original).hexdigest())
        self.assertEqual(checked['files'][0]['archive_before_sha256'],manifest['files'][0]['before_sha256'])
        result = ops.install(checked)
        ops.rollback(result['rollback_id'])
        self.assertEqual(target.read_bytes(),original)

    def test_lf_hash_does_not_allow_real_code_changes(self):
        manifest = self.release()
        target = ops.SHOP/manifest['files'][0]['path']
        manifest['files'][0]['before_lf_sha256'] = ops.sha(target)
        (ops.REPO/'docs/release-manifest.json').write_text(json.dumps(manifest))
        target.write_text('original with a code change')
        with self.assertRaises(ops.ReleaseCheckError): ops.checked_manifest()

    def test_install_rechecks_before_hash_after_check(self):
        manifest = self.release()
        target = ops.SHOP/manifest['files'][0]['path']
        target.write_text('changed after check')
        with self.assertRaises(ValueError): ops.install(manifest)
        self.assertEqual(target.read_text(),'changed after check')

    def test_incremental_manifest_checks_only_selected_installed_files_and_rolls_back(self):
        manifest = self.release()
        # The previously new CSS already exists on the hosting baseline.
        css = manifest['files'][1]
        target = ops.SHOP/css['path']; target.write_text('installed old CSS')
        css['before_sha256'] = ops.sha(target)
        manifest['files'] = [css]
        (ops.REPO/'docs/title-weight-update.json').write_text(json.dumps(manifest))
        with patch.object(sys,'argv',['test_store.py','--check','--manifest','docs/title-weight-update.json']):
            with contextlib.redirect_stdout(io.StringIO()) as output:
                self.assertEqual(ops.main(),0)
        self.assertIn('passed',output.getvalue())
        self.assertEqual(target.read_text(),'installed old CSS')
        result = ops.install(ops.checked_manifest('docs/title-weight-update.json'))
        self.assertEqual(target.read_text(),'new')
        ops.rollback(result['rollback_id'])
        self.assertEqual(target.read_text(),'installed old CSS')
        self.assertEqual((ops.SHOP/'catalog/view/theme/unishop2/js/common.js').read_text(),'original')

    def test_manifest_must_stay_in_repo_docs(self):
        self.release()
        outside = Path(self.temp.name)/'outside.json'; outside.write_text('{}')
        (ops.REPO/'docs/redirect.json').symlink_to(outside)
        for relative in (str(outside),'../outside.json','docs/redirect.json'):
            with self.subTest(path=relative):
                with self.assertRaises(ValueError): ops.checked_manifest(relative)

    def test_check_returns_failure_and_safe_hash_diagnostics_without_installing(self):
        manifest = self.release()
        target = ops.SHOP/manifest['files'][0]['path']
        target.write_text('existing edit')
        with patch.object(sys, 'argv', ['test_store.py','--check']):
            with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()) as output:
                result = ops.main()
        self.assertEqual(result, 2)
        self.assertEqual(json.loads(output.getvalue())['error'], 'SOURCE_HASH_MISMATCH')
        self.assertNotIn('fixture-secret', output.getvalue())
        self.assertEqual(target.read_text(), 'existing edit')
        self.assertEqual(list(ops.BACKUPS.iterdir()), [])

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
