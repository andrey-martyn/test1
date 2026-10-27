"""Reviewed CSS migration: preserve other JSON bytes, test-only cache scope and rollback."""
import contextlib
import hashlib
import html
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'ops'))
import migrate_legacy as migration

PHP = os.environ.get('LAVKA_TEST_PHP_CLI') or shutil.which('php')


class LegacyMigration(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        root = Path(self.temp.name)
        for name,path in [('SHOP',root/'test'),('STORAGE',root/'test_storage'),('BACKUPS',root/'backups')]:
            path.mkdir()
            replacement = patch.object(migration.store,name,path)
            replacement.start(); self.addCleanup(replacement.stop)
        self.plan = migration.read_plan()
        css = html.escape(self.plan['before_css'],quote=False).replace('"','&quot;')
        self.assertEqual(migration.digest(css),self.plan['expected_css_stored_sha256'])
        self.js = 'window.preserveMe = true;'
        self.plan['expected_js_stored_sha256'] = migration.digest(self.js)
        self.raw = '{ "license":"fixture-secret", "empty":{}, "large":123456789012345678901234567890, "float":1.00000000, "nested":{"user_css":"keep nested"}, "user_js":'+json.dumps(self.js)+', "user_css":'+json.dumps(css)+' }\n'
        self.original = self.raw
        self.calls = []
        replacement = patch.object(migration,'bridge',side_effect=self.bridge)
        replacement.start(); self.addCleanup(replacement.stop)
        loader = migration.store.SHOP/'catalog/model/extension/module/uni_settings.php'
        loader.parent.mkdir(parents=True)
        loader.write_text('<?php $this->cache->get("unishop.settings." . $store_id);')
        self.plan['settings_loader_sha256'] = migration.store.sha(loader)
        self.cache = migration.store.STORAGE/'cache'; self.cache.mkdir()
        (self.cache/'cache.unishop.settings.0.99999').write_text('fixture-cache')
        (self.cache/'cache.unishop.settings.1.99999').write_text('another-store')
        (self.cache/'cache.product.unishop.99999').write_text('product-cache')
        self.generated = migration.store.SHOP/'catalog/view/theme/unishop2/stylesheet/generated-user-style.0.css'
        self.generated.parent.mkdir(parents=True)
        self.generated.write_bytes(self.plan['before_css'].encode())

    def bridge(self, action, **values):
        self.calls.append(action)
        if action == 'read':
            return {'ok':True,'row':self.raw,'decoded_css':html.unescape(json.loads(self.raw)['user_css'])}
        self.assertEqual(action,'replace')
        if values['before'] != self.raw: raise ValueError('Concurrent fixture update')
        self.raw = values['after']
        return {'ok':True,'updated':True}

    def state(self):
        return migration.migration_state(self.plan)

    def test_only_top_level_css_literal_changes_and_other_bytes_are_exact(self):
        state = self.state()
        after = migration.replace_literal(self.raw,state['after_literal'])
        before_start,before_end,_ = migration.css_span(self.raw)
        after_start,after_end,css = migration.css_span(after)
        self.assertEqual(self.raw[:before_start]+self.raw[before_end:],after[:after_start]+after[after_end:])
        self.assertEqual(css,self.plan['after_css'])
        self.assertEqual(json.loads(after)['empty'],{})
        self.assertIn('"float":1.00000000',after)
        self.assertEqual(json.loads(after)['nested']['user_css'],'keep nested')

    def test_duplicate_css_key_is_not_silently_selected(self):
        with self.assertRaises(ValueError): migration.css_span('{"user_css":"a","user_css":"b"}')

    def test_changed_css_or_js_stops_before_writes(self):
        for key in ('user_css','user_js'):
            with self.subTest(field=key):
                values = json.loads(self.original); values[key] += ' changed'
                self.raw = json.dumps(values)
                with self.assertRaises(ValueError): self.state()
        self.assertNotIn('replace',self.calls)

    def test_apply_and_rollback_restore_the_exact_original_row(self):
        result = migration.apply(self.plan,self.state())
        self.assertEqual(json.loads(self.raw)['user_css'],self.plan['after_css'])
        self.assertEqual(json.loads(self.raw)['user_js'],self.js)
        self.assertFalse(self.generated.exists())
        self.assertFalse((self.cache/'cache.unishop.settings.0.99999').exists())
        self.assertTrue((self.cache/'cache.unishop.settings.1.99999').exists())
        self.assertTrue((self.cache/'cache.product.unishop.99999').exists())
        saved = Path(result['backup'])
        self.assertEqual(saved.stat().st_mode & 0o777,0o700)
        record = (saved/'migration.json').read_text()
        self.assertNotIn('fixture-secret',record)
        self.assertNotIn(self.js,record)
        for p in saved.rglob('*'):
            if p.is_file(): self.assertEqual(p.stat().st_mode & 0o777,0o600)
        migration.rollback(self.plan,result['rollback_id'])
        self.assertEqual(self.raw,self.original)

    def test_rollback_keeps_later_unrelated_setting_and_javascript_edits(self):
        result = migration.apply(self.plan,self.state())
        self.raw = self.raw.replace('"license":"fixture-secret"','"license":"later-license"').replace(json.dumps(self.js),json.dumps('window.later = true;'))
        migration.rollback(self.plan,result['rollback_id'])
        self.assertEqual(self.raw,self.original.replace('"license":"fixture-secret"','"license":"later-license"').replace(json.dumps(self.js),json.dumps('window.later = true;')))

    def test_css_edit_after_apply_requires_manual_merge(self):
        result = migration.apply(self.plan,self.state())
        self.raw = migration.replace_literal(self.raw,json.dumps('.new-css { color: blue; }'))
        edited = self.raw
        with self.assertRaises(ValueError): migration.rollback(self.plan,result['rollback_id'])
        self.assertEqual(self.raw,edited)

    def test_cache_failure_after_update_restores_css_automatically(self):
        original = migration.purge_cache
        attempts = []
        def interrupted(plan, saved):
            attempts.append(True)
            if len(attempts) == 1: raise OSError('simulated cache failure')
            return original(plan,saved)
        with patch.object(migration,'purge_cache',side_effect=interrupted):
            with contextlib.redirect_stderr(io.StringIO()) as output:
                with self.assertRaises(ValueError): migration.apply(self.plan,self.state())
        self.assertEqual(self.raw,self.original)
        self.assertIn('MIGRATION_FAILED_AND_RECOVERED',output.getvalue())
        self.assertNotIn('fixture-secret',output.getvalue())

    def test_read_only_check_creates_no_backup_or_cache_changes(self):
        with patch.object(migration,'guarded_report',return_value={}), \
             patch.object(migration,'read_plan',return_value=self.plan), \
             patch.object(migration.store,'checked_manifest',return_value={'files':[{}]*6}), \
             patch.object(sys,'argv',['migrate_legacy.py','--check']):
            with contextlib.redirect_stdout(io.StringIO()) as output:
                self.assertEqual(migration.main(),0)
        self.assertEqual(self.calls,['read'])
        self.assertEqual(list(migration.store.BACKUPS.iterdir()),[])
        self.assertTrue(self.generated.exists())
        self.assertNotIn('fixture-secret',output.getvalue())
        self.assertNotIn('data-lavka',output.getvalue())

    def test_unknown_generated_css_and_cache_symlinks_are_rejected(self):
        self.generated.write_text('.someone-else { color:red; }')
        with self.assertRaises(ValueError): migration.cache_targets(self.plan)
        self.generated.write_bytes(self.plan['before_css'].encode())
        outside = Path(self.temp.name)/'outside'; outside.write_text('untouched')
        cached = self.cache/'cache.unishop.settings.0.99999'
        cached.unlink(); cached.symlink_to(outside)
        with self.assertRaises(ValueError): migration.cache_targets(self.plan)
        self.assertEqual(outside.read_text(),'untouched')

    def test_source_hash_failure_blocks_migration_before_private_row_read(self):
        error = migration.store.ReleaseCheckError('{"error":"SOURCE_HASH_MISMATCH"}')
        with patch.object(migration,'guarded_report',return_value={}), \
             patch.object(migration,'read_plan',return_value=self.plan), \
             patch.object(migration.store,'checked_manifest',side_effect=error), \
             patch.object(sys,'argv',['migrate_legacy.py','--apply']):
            with contextlib.redirect_stderr(io.StringIO()): self.assertEqual(migration.main(),2)
        self.assertEqual(self.calls,[])

    def test_repeated_apply_does_not_update_database_again(self):
        migration.apply(self.plan,self.state())
        self.calls = []
        result = migration.apply(self.plan,self.state())
        self.assertEqual(result['migration'],'already_clean')
        self.assertEqual(self.calls,['read'])

    def test_another_preflight_issue_is_not_ignored(self):
        report = {'isolation_ok':True,'issues':[migration.store.LEGACY_ISSUE,'another issue'],
                  'db':{'unishop_settings_source':self.plan['source']}}
        with patch.object(migration.store,'preflight',return_value=report):
            with contextlib.redirect_stdout(io.StringIO()):
                with self.assertRaises(ValueError): migration.guarded_report()


@unittest.skipUnless(PHP,'Set LAVKA_TEST_PHP_CLI for the actual migration PHP bridge.')
class MigrationPhpBridge(unittest.TestCase):
    def run_bridge(self, action='replace', **scenario):
        before = '{ "version":"1.9.0.0", "license":"fixture-secret", "empty":{}, "user_css":"old" }'
        config = {'DB_HOSTNAME':'fixture','DB_USERNAME':'fixture','DB_PASSWORD':'fixture-secret',
                  'DB_DATABASE':'lavkasp_test','DB_PORT':'3306','DB_PREFIX':'oc_','store_id':0,
                  'action':action,'before':before,'after':before.replace('"old"','"новый CSS"'),
                  'fixture_raw_settings':before}
        config.update(scenario)
        fixture = (ROOT/'tests/fixtures/probe_database.php').read_text().split('<?php',1)[1]
        code = (ROOT/'ops/migrations/user-css.php').read_text().split('<?php',1)[1]
        result = subprocess.run([PHP,'-n','-r',fixture+'\n'+code],input=json.dumps(config),universal_newlines=True,
                                stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=10)
        self.assertEqual(result.stderr,'')
        if action != 'read': self.assertNotIn('fixture-secret',result.stdout)
        return result.returncode,json.loads(result.stdout)

    def test_prepared_css_only_update_is_accepted(self):
        status,response = self.run_bridge()
        self.assertEqual(status,0,response)
        self.assertEqual(response,{'ok':True,'updated':True})

    def test_read_response_is_for_private_pipe_only(self):
        status,response = self.run_bridge(action='read')
        self.assertEqual(status,0,response)
        self.assertEqual(json.loads(response['row'])['user_css'],'old')

    def test_other_database_and_other_store_are_rejected(self):
        for scenario in ({'fixture_database':'other_db'},{'DB_DATABASE':'production'},{'store_id':1}):
            with self.subTest(scenario=list(scenario)):
                status,response = self.run_bridge(**scenario)
                self.assertEqual(status,1)
                self.assertNotIn('row',response)

    def test_non_css_changes_and_lost_updates_are_rejected(self):
        for scenario in ({'after':'{"license":"changed","user_css":"new"}'},
                         {'before':'not the current row'}, {'fixture_race':True}, {'fixture_update_error':True}):
            with self.subTest(scenario=list(scenario)):
                status,response = self.run_bridge(**scenario)
                self.assertEqual(status,1)
                self.assertFalse(response['ok'])


if __name__ == '__main__':
    unittest.main()
