"""Read-only frontend export: real PHP extraction, private ZIP and deployment gates."""
import contextlib
import hashlib
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
import zipfile

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'ops'))
import export_legacy as legacy

PHP = os.environ.get('LAVKA_TEST_PHP_CLI') or shutil.which('php')


@unittest.skipUnless(PHP, 'Set LAVKA_TEST_PHP_CLI to execute the PHP exporter fixtures.')
class PhpLegacyExport(unittest.TestCase):
    def extract(self, **scenario):
        config = {'DB_HOSTNAME':'fixture','DB_USERNAME':'fixture','DB_PASSWORD':'fixture-secret',
                  'DB_DATABASE':'lavkasp_test','DB_PORT':'3306','DB_PREFIX':'oc_','source_store_id':0,
                  'fixture_settings':{'version':'1.9.0.0','license':'fixture-secret',
                                      'user_js':'if (a &amp;&amp; b) { title = &quot;Фасовка&quot;; } // &amp;amp;',
                                      'user_css':'.keep { color: #abc; }'}}
        config.update(scenario)
        fixture = (ROOT/'tests/fixtures/probe_database.php').read_text().split('<?php',1)[1]
        result = subprocess.run([PHP,'-n','-r',fixture+'\n'+legacy.PHP_EXPORT], input=json.dumps(config),
                                universal_newlines=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=10)
        self.assertEqual(result.stderr, '')
        self.assertNotIn('fixture-secret', result.stdout)
        return result.returncode, json.loads(result.stdout)

    def test_only_frontend_fields_are_extracted_and_decoded_once(self):
        status, payload = self.extract()
        self.assertEqual(status,0,payload)
        self.assertEqual(set(payload['fields']), {'user_js','user_css'})
        self.assertEqual(payload['fields']['user_js']['code'], 'if (a && b) { title = "Фасовка"; } // &amp;')
        self.assertNotIn('license', json.dumps(payload))
        code = payload['fields']['user_js']['code'].encode('utf-8')
        self.assertEqual(payload['fields']['user_js']['decoded_sha256'], hashlib.sha256(code).hexdigest())

    def test_other_database_cannot_export_settings(self):
        status, payload = self.extract(fixture_database='wrong_database')
        self.assertEqual(status,1)
        self.assertEqual(payload, {'ok':False,'code':'DATABASE_IDENTITY_MISMATCH'})

    def test_duplicate_row_or_non_string_field_is_rejected(self):
        for scenario in ({'fixture_duplicate_settings':True},
                         {'fixture_settings':{'version':'1.9.0.0','user_js':{'secret':'fixture-secret'}}}):
            with self.subTest(scenario=list(scenario)):
                status, payload = self.extract(**scenario)
                self.assertEqual(status,1)
                self.assertNotIn('fields',payload)

    def test_absent_fields_are_recorded_without_inventing_data(self):
        status, payload = self.extract(fixture_settings={'version':'1.9.0.0'})
        self.assertEqual(status,0)
        self.assertEqual(payload['fields']['user_js']['code'],'')
        self.assertFalse(payload['fields']['user_js']['present'])

    def test_confirmed_non_default_store_is_used(self):
        status, payload = self.extract(source_store_id=3,fixture_stores=[3])
        self.assertEqual(status,0,payload)


class LegacyExport(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        root = Path(self.temp.name)
        for name, path in [('SHOP',root/'test'),('STORAGE',root/'test_storage'),('BACKUPS',root/'backups')]:
            path.mkdir()
            replacement = patch.object(legacy.store,name,path)
            replacement.start(); self.addCleanup(replacement.stop)
        self.report = {'target':legacy.store.HOSTNAME,'isolation_ok':True,'ok':False,
                       'issues':[legacy.store.LEGACY_ISSUE],
                       'db':{'ok':True,'runtime_store_id':0,'unishop_settings_found':True,
                             'unishop_settings_source':{'table':'oc_uni_setting','column':'data','store_id':0,'format':'json'}}}
        self.payload = {'ok':True,'database':'lavkasp_test','settings_sha256':'a'*64,'fields':{}}
        for key, code in [('user_js','window.legacy = true;'),('user_css','.legacy { color: red; }')]:
            digest = hashlib.sha256(code.encode('utf-8')).hexdigest()
            self.payload['fields'][key] = {'present':True,'code':code,'stored_sha256':digest,
                                          'stored_bytes':len(code),'decoded_sha256':digest}

    def test_private_zip_contains_only_frontend_and_safe_metadata(self):
        with patch.object(legacy.store,'checked_manifest',return_value={}):
            result = legacy.export_bundle(self.report, self.payload)
        path = Path(result['exported'])
        self.assertEqual(path.stat().st_mode & 0o777,0o600)
        self.assertEqual(path.parent.stat().st_mode & 0o777,0o700)
        self.assertTrue(legacy.store.within(path,legacy.store.BACKUPS))
        with zipfile.ZipFile(str(path)) as archive:
            self.assertEqual(set(archive.namelist()),{'custom.js','custom.css','review.json'})
            metadata = json.loads(archive.read('review.json'))
            self.assertNotIn('code',metadata['fields']['user_js'])
            self.assertEqual(archive.read('custom.js').decode(),self.payload['fields']['user_js']['code'])
        self.assertEqual(list(legacy.store.SHOP.iterdir()),[])
        self.assertEqual(list(legacy.store.STORAGE.iterdir()),[])

    def test_only_legacy_issue_is_allowed_for_reading_and_code_is_not_printed(self):
        with patch.object(legacy.store,'preflight',return_value=self.report), \
             patch.object(legacy,'read_frontend',return_value=self.payload), \
             patch.object(legacy.store,'checked_manifest',return_value={}):
            with contextlib.redirect_stdout(io.StringIO()) as output:
                status = legacy.main()
        self.assertEqual(status,0)
        self.assertNotIn('window.legacy',output.getvalue())
        self.assertFalse(json.loads(output.getvalue())['database_writes'])

    def test_another_preflight_issue_still_blocks_export(self):
        self.report['issues'].append('Active UniShop2 theme was not confirmed.')
        with patch.object(legacy.store,'preflight',return_value=self.report), \
             patch.object(legacy,'read_frontend') as read:
            with contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(legacy.main(),2)
            read.assert_not_called()
        self.assertEqual(list(legacy.store.BACKUPS.iterdir()),[])

    def test_redirected_backup_directory_is_rejected(self):
        elsewhere = Path(self.temp.name)/'elsewhere'; elsewhere.mkdir()
        legacy.store.BACKUPS.rmdir(); legacy.store.BACKUPS.symlink_to(elsewhere,target_is_directory=True)
        with self.assertRaises(ValueError): legacy.export_bundle(self.report,self.payload)
        self.assertEqual(list(elsewhere.iterdir()),[])

    def test_source_hash_difference_is_included_without_altering_files(self):
        diagnostic = {'error':'SOURCE_HASH_MISMATCH','path':'catalog/view/theme/unishop2/js/common.js','actual_sha256':'b'*64}
        with patch.object(legacy.store,'checked_manifest',side_effect=legacy.store.ReleaseCheckError(json.dumps(diagnostic))):
            result = legacy.export_bundle(self.report,self.payload)
        self.assertEqual(result['release_source_check']['diagnostic'],diagnostic)

    def test_loader_report_exposes_cache_identifiers_only(self):
        path = legacy.store.SHOP/'catalog/model/extension/module/uni_settings.php'
        path.parent.mkdir(parents=True)
        path.write_text("<?php $secret = 'fixture-secret'; $this->cache->get('unishop2.' . $store_id); $this->cache->set($dynamic, $data);")
        metadata = legacy.loader_metadata()
        self.assertEqual(metadata[0]['cache_key_prefixes'],['unishop2.'])
        self.assertEqual(metadata[0]['unresolved_cache_calls'],1)
        self.assertNotIn('fixture-secret',json.dumps(metadata))

    def test_read_frontend_preserves_python36_api_and_validates_source(self):
        config = {'DB_PREFIX':'oc_'}
        class Result:
            returncode = 0
            stdout = json.dumps(self.payload)
        with patch.object(legacy.store,'config_values',return_value=config), \
             patch.object(legacy.subprocess,'run',return_value=Result()) as run:
            self.assertEqual(legacy.read_frontend(self.report),self.payload)
            options = run.call_args[1]
            self.assertTrue(options['universal_newlines'])
            self.assertNotIn('text',options)
            self.assertNotIn('capture_output',options)
            self.report['db']['unishop_settings_source']['table'] = 'oc_setting'
            run.reset_mock()
            with self.assertRaises(ValueError): legacy.read_frontend(self.report)
            run.assert_not_called()


if __name__ == '__main__':
    unittest.main()
