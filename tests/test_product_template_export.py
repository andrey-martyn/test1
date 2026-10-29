"""Only one bounded active template can be exported, outside webroot, without DB edits."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'ops'))
import product_template as export
PHP=os.environ.get('LAVKA_TEST_PHP_CLI') or shutil.which('php')


class TemplateExport(unittest.TestCase):
    def test_private_source_file_is_outside_shop_and_storage(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);shop=root/'test';storage=root/'storage';backups=root/'backups';shop.mkdir();storage.mkdir()
            with mock.patch.object(export.store,'SHOP',shop),mock.patch.object(export.store,'STORAGE',storage),mock.patch.object(export.store,'BACKUPS',backups),mock.patch.object(export,'read_template',return_value='<h1>Only template</h1>'):
                result=export.export_template({})
            path=Path(result['exported']);self.assertEqual(path.read_text(),'<h1>Only template</h1>')
            self.assertEqual(path.stat().st_mode & 0o777,0o600);self.assertEqual(path.parent.stat().st_mode & 0o777,0o700)
            self.assertFalse(result['shop_writes']);self.assertFalse(result['database_writes']);self.assertFalse(list(shop.iterdir()));self.assertFalse(list(storage.iterdir()))

    def test_invalid_scope_never_reads_connection_config(self):
        for scope in ({'store_id':1,'theme':'unishop2','route':'product/product'},{'store_id':0,'theme':'default','route':'product/product'}):
            with mock.patch.object(export.store,'config_values') as config:
                with self.assertRaises(ValueError):export.read_template({'template_check':{'count':1,'scope':scope,'records':[{}]}})
                config.assert_not_called()

    @unittest.skipUnless(PHP,'PHP CLI required for real read-only export fixture')
    def test_php_reads_only_active_source_decodes_once_and_checks_revision(self):
        fixture=(ROOT/'tests/fixtures/probe_database.php').read_text().split('<?php',1)[1]
        source='<h1>Product &lt;b&gt;</h1>\r\n';encoded='&lt;h1&gt;Product &amp;lt;b&amp;gt;&lt;/h1&gt;\r\n'
        config={'DB_DATABASE':'lavkasp_test','DB_PREFIX':'oc_','DB_HOSTNAME':'fixture','DB_USERNAME':'fixture','DB_PASSWORD':'fixture-secret','DB_PORT':'3306','template_id':8,'expected_sha256':hashlib.sha256(source.encode()).hexdigest(),
                'fixture_theme_rows':[{'theme_id':8,'store_id':0,'theme':'unishop2','route':'product/product','code':encoded}]}
        instrumented=export.PHP_READ.replace("'ok'=>true,'source'=>$source", "'queries'=>FixtureDatabase::$queries,'ok'=>true,'source'=>$source")
        result=subprocess.run([PHP,'-n','-r',fixture+'\n'+instrumented],input=json.dumps(config),universal_newlines=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=10)
        self.assertEqual(result.returncode,0,result.stderr);payload=json.loads(result.stdout)
        self.assertEqual(payload['source'],source);self.assertNotIn('fixture-secret',result.stdout+result.stderr)
        self.assertTrue(all(query.startswith('SELECT ') for query in payload['queries']))
        self.assertIn("store_id = 0 AND theme = 'unishop2' AND route = 'product/product' AND theme_id = 8",payload['queries'][-1])
        config['expected_sha256']='a'*64
        result=subprocess.run([PHP,'-n','-r',fixture+'\n'+export.PHP_READ],input=json.dumps(config),universal_newlines=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=10)
        self.assertEqual(result.returncode,1);self.assertEqual(json.loads(result.stdout)['code'],'ACTIVE_TEMPLATE_CHANGED');self.assertNotIn('source',json.loads(result.stdout))


if __name__=='__main__':unittest.main()
