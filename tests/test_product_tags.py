"""Real tag service/controller behavior with isolated adapters; no shop requests."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[1]
PHP=os.environ.get('LAVKA_TEST_PHP_CLI') or shutil.which('php')


@unittest.skipUnless(PHP,'Set LAVKA_TEST_PHP_CLI to execute PHP tag scenarios.')
class ProductTags(unittest.TestCase):
    def scenario(self,name,fixture='product_tags.php'):
        reply=subprocess.run([PHP,'-n',str(ROOT/'tests/fixtures'/fixture),name],stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True,timeout=10)
        self.assertEqual(reply.returncode,0,reply.stderr+reply.stdout)
        self.assertEqual(reply.stderr,'')
        return json.loads(reply.stdout)

    def test_all_tags_native_colors_language_and_automatic_top(self): self.assertTrue(self.scenario('compose')['ok'])
    def test_automatic_period_uses_availability_and_expires(self): self.assertTrue(self.scenario('dates')['ok'])
    def test_partial_updates_reject_stale_revisions_without_product_writes(self): self.assertTrue(self.scenario('conflicts')['ok'])
    def test_settings_validation_transactions_and_repeat_initialization(self): self.assertTrue(self.scenario('settings')['ok'])
    def test_automatic_sales_threshold_cache_and_no_manual_override(self): self.assertTrue(self.scenario('bestseller')['ok'])

    def test_admin_guard_rejects_token_permission_verb_and_input_before_db(self):
        for name in ('bad_token','array_token','no_permission','get','bad_input'):
            with self.subTest(name=name): self.assertIn('error',self.scenario(name,'product_tag_endpoints.php')['response'])

    def test_admin_authorized_partial_save_and_targeted_cache_request(self): self.assertTrue(self.scenario('admin_ok','product_tag_endpoints.php')['response']['success'])

    def test_admin_cannot_manually_write_automatic_top(self): self.assertEqual(self.scenario('auto_hit','product_tag_endpoints.php')['response']['error'],'INVALID_INPUT')

    def test_public_api_restricts_visibility_and_exposes_no_price_or_session(self):
        reply=self.scenario('public_ok','product_tag_endpoints.php')
        self.assertIn('Cache-Control: private, no-store, max-age=0',reply['headers'])

    def test_public_api_rejects_invalid_ids_and_get_before_db(self):
        for name in ('public_bad_ids','public_get'):
            with self.subTest(name=name): self.assertIn('error',self.scenario(name,'product_tag_endpoints.php')['response'])

    def test_schema_bridge_rejects_non_test_target_before_loading_mysql(self):
        for change in ({'DB_DATABASE':'lavkasp_live'},{'store_id':3},{'DB_PREFIX':'bad`prefix'},{'action':'drop'}):
            config={'DB_DATABASE':'lavkasp_test','DB_PREFIX':'oc_','store_id':0,'action':'initialize','DB_PASSWORD':'fixture-secret'}
            config.update(change)
            reply=subprocess.run([PHP,'-n',str(ROOT/'ops/product-tags-db.php')],input=json.dumps(config),universal_newlines=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=10)
            self.assertEqual(reply.returncode,1)
            self.assertEqual(json.loads(reply.stdout),{'ok':False,'code':'INVALID_TEST_TARGET'})
            self.assertNotIn('fixture-secret',reply.stdout+reply.stderr)


if __name__=='__main__': unittest.main()
