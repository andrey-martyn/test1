"""Execute the actual PHP probe against a fixture mysqli adapter, without network/DB."""
import importlib.util
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('probe_ops', ROOT/'ops/test_store.py')
ops = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ops)
PHP = os.environ.get('LAVKA_TEST_PHP_CLI') or shutil.which('php')


@unittest.skipUnless(PHP, 'Set LAVKA_TEST_PHP_CLI or install PHP CLI to execute the PHP probe fixtures.')
class PhpProbe(unittest.TestCase):
    def probe(self, **scenario):
        config = {'DB_HOSTNAME':'fixture','DB_USERNAME':'fixture','DB_PASSWORD':'fixture-secret','DB_DATABASE':'lavkasp_test','DB_PORT':'3306','DB_PREFIX':'oc_','HTTP_SERVER':'https://test.lavka-speciy.com/','HTTPS_SERVER':'https://test.lavka-speciy.com/'}
        config.update(scenario)
        fixture = (ROOT/'tests/fixtures/probe_database.php').read_text().split('<?php',1)[1]
        result = subprocess.run([PHP,'-n','-r',fixture+'\n'+ops.PHP_PROBE],input=json.dumps(config),universal_newlines=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=10)
        self.assertNotIn('fixture-secret', result.stdout + result.stderr)
        self.assertEqual(result.stderr, '')
        output = json.loads(result.stdout)
        return result.returncode, output

    def test_store_schema_without_ssl_reads_native_settings(self):
        status, report = self.probe()
        self.assertEqual(status, 0, report)
        self.assertTrue(report['database_verified'])
        self.assertEqual(report['runtime_store_id'], 0)
        self.assertNotIn('ssl', report['store_mapping']['columns'])
        self.assertEqual(report['store_mapping']['status'], 'default_store')
        self.assertTrue(report['unishop_settings_found'])
        self.assertEqual(report['unishop_settings_source'], {'table':'oc_uni_setting','column':'data','store_id':0,'format':'json'})
        self.assertTrue(report['liveprice_enabled'])

    def test_store_schema_with_ssl_uses_matched_store(self):
        status, report = self.probe(fixture_columns=['store_id','name','url','ssl'],fixture_stores=[3])
        self.assertEqual(status, 0, report)
        self.assertEqual(report['runtime_store_id'], 3)
        self.assertEqual(report['unishop_settings_source']['store_id'], 3)

    def test_product_override_matches_native_active_theme_store_and_route(self):
        rows=[{'theme_id':1,'store_id':0,'theme':'default','route':'product/product','code':'inactive'},
              {'theme_id':2,'store_id':3,'theme':'unishop2','route':'product/product','code':'other-store'},
              {'theme_id':3,'store_id':0,'theme':'unishop2','route':'product/product_extra','code':'other-route'}]
        status,report=self.probe(fixture_theme_rows=rows)
        self.assertEqual(status,0);self.assertEqual(report['product_template_overrides'],0)
        encoded='&lt;h1&gt;fixture-secret&lt;/h1&gt;\r\n'
        rows.append({'theme_id':4,'store_id':0,'theme':'unishop2','route':'product/product','code':encoded})
        status,report=self.probe(fixture_theme_rows=rows)
        self.assertEqual(status,0);self.assertEqual(report['product_template_overrides'],1)
        self.assertEqual(report['product_template_override_scope'],{'store_id':0,'theme':'unishop2','route':'product/product'})
        metadata=report['product_template_override_details'][0]
        self.assertEqual(metadata['decoded_sha256'],hashlib.sha256(b'<h1>fixture-secret</h1>\r\n').hexdigest())
        self.assertEqual(metadata['decoded_lf_sha256'],hashlib.sha256(b'<h1>fixture-secret</h1>\n').hexdigest())
        self.assertNotIn('code',metadata)

    def test_product_override_query_failure_has_stage_and_errno(self):
        status,report=self.probe(fixture_theme_count_error=True)
        self.assertEqual(status,0);self.assertIsNone(report['product_template_overrides'])
        self.assertEqual(report['product_template_override_diagnostics'],{'stage':'active_template_count','sql_errno':1142})

    def test_ambiguous_mapping_preserves_confirmed_database(self):
        status, report = self.probe(fixture_stores=[1,2])
        self.assertEqual(status, 1)
        self.assertEqual(report['code'], 'STORE_MAPPING_AMBIGUOUS')
        self.assertTrue(report['database_verified'])
        self.assertEqual(report['database'], 'lavkasp_test')
        self.assertEqual(report['store_mapping']['matched_store_ids'], [1,2])

    def test_schema_query_failure_exposes_errno_only(self):
        status, report = self.probe(fixture_schema_error=True)
        self.assertEqual(status, 1)
        self.assertEqual(report['code'], 'STORE_SCHEMA_QUERY_FAILED')
        self.assertEqual(report['diagnostics']['sql_errno'], 1142)
        self.assertTrue(report['database_verified'])

    def test_mapping_query_failure_preserves_stage_and_identity(self):
        status, report = self.probe(fixture_mapping_error=True)
        self.assertEqual(status, 1)
        self.assertEqual(report['failed_stage'], 'store_mapping')
        self.assertEqual(report['diagnostics']['sql_errno'], 2013)
        self.assertTrue(report['database_verified'])

    def test_missing_url_column_does_not_default_to_store_zero(self):
        status, report = self.probe(fixture_columns=['store_id'])
        self.assertEqual(status, 1)
        self.assertEqual(report['code'], 'STORE_SCHEMA_UNSUPPORTED')
        self.assertNotIn('runtime_store_id', report)

    def test_other_database_stops_before_reading_settings(self):
        status, report = self.probe(fixture_database='unexpected_database')
        self.assertEqual(status, 1)
        self.assertEqual(report['code'], 'DATABASE_IDENTITY_MISMATCH')
        self.assertNotIn('unishop_settings_found', report)

    def test_invalid_settings_do_not_confirm_theme_flags(self):
        status, report = self.probe(fixture_bad_json=True)
        self.assertEqual(status, 0)
        self.assertFalse(report['unishop_settings_found'])
        self.assertIsNone(report['liveprice_enabled'])
        self.assertEqual(report['unishop_settings_error'], 'SETTINGS_JSON_FORMAT_UNSUPPORTED')

    def test_duplicate_settings_rows_are_not_silently_selected(self):
        status, report = self.probe(fixture_duplicate_settings=True)
        self.assertEqual(status, 0)
        self.assertFalse(report['unishop_settings_found'])
        self.assertEqual(report['unishop_settings_error'], 'UNIQUE_SETTINGS_ROW_NOT_FOUND')

    def test_setting_table_without_loader_does_not_confirm_source(self):
        status, report = self.probe(fixture_no_loader=True)
        self.assertEqual(status, 0)
        self.assertFalse(report['unishop_settings_found'])


if __name__ == '__main__':
    unittest.main()
