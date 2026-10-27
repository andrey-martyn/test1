"""Parse/execute SQL emitted by the real PHP probe with a one-shot, network-free MariaDB bootstrap."""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('sql_probe_ops', ROOT/'ops/test_store.py')
ops = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ops)
PHP = os.environ.get('LAVKA_TEST_PHP_CLI') or shutil.which('php')
MARIADB = os.environ.get('LAVKA_TEST_SQL_ENGINE') or shutil.which('mariadbd')


@unittest.skipUnless(PHP and MARIADB, 'Set LAVKA_TEST_PHP_CLI and LAVKA_TEST_SQL_ENGINE to validate emitted SQL with MariaDB.')
class ProbeSql(unittest.TestCase):
    def emitted_queries(self, ssl):
        config = {'DB_HOSTNAME':'fixture','DB_USERNAME':'fixture','DB_PASSWORD':'fixture-secret','DB_DATABASE':'lavkasp_test','DB_PORT':'3306','DB_PREFIX':'oc_','HTTP_SERVER':'https://test.lavka-speciy.com/','HTTPS_SERVER':'https://test.lavka-speciy.com/','fixture_columns':['store_id','name','url']+(['ssl'] if ssl else [])}
        fixture = (ROOT/'tests/fixtures/probe_database.php').read_text().split('<?php',1)[1]
        marker = 'echo json_encode($out);'
        self.assertEqual(ops.PHP_PROBE.count(marker), 1)
        instrumented = ops.PHP_PROBE.replace(marker, "$out['fixture_queries'] = FixtureDatabase::$queries; " + marker)
        result = subprocess.run([PHP,'-n','-r',fixture+'\n'+instrumented],input=json.dumps(config),universal_newlines=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=10)
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertNotIn('fixture-secret',result.stdout+result.stderr)
        report = json.loads(result.stdout)
        self.assertTrue(report['ok'])
        self.assertTrue(all(query.lstrip().upper().startswith('SELECT ') for query in report['fixture_queries']))
        return report['fixture_queries']

    def execute(self, queries, ssl):
        # Bootstrap is one process reading stdin: no daemon, sockets or network clients.
        with tempfile.TemporaryDirectory(prefix='lavka-sql-test-') as directory:
            root = Path(directory)
            setup = [
                'CREATE DATABASE probe_fixture;', 'USE probe_fixture;',
                'CREATE TABLE `oc_store` (`store_id` INT, `name` VARCHAR(100), `url` VARCHAR(255)'+(', `ssl` VARCHAR(255)' if ssl else '')+') ENGINE=MEMORY;',
                'CREATE TABLE `oc_setting` (`store_id` INT, `code` VARCHAR(100), `key` VARCHAR(100), `value` VARCHAR(4096), `serialized` INT) ENGINE=MEMORY;',
                'CREATE TABLE `oc_modification` (`code` VARCHAR(100), `name` VARCHAR(100), `version` VARCHAR(32), `xml` VARCHAR(4096), `status` INT) ENGINE=MEMORY;',
                'CREATE TABLE `oc_uni_setting` (`store_id` INT, `data` VARCHAR(4096)) ENGINE=MEMORY;',
                'CREATE TABLE `oc_theme` (`route` VARCHAR(100)) ENGINE=MEMORY;'
            ]
            sql = '\n'.join(setup+[query.rstrip(';')+';' for query in queries])+'\n'
            command = [MARIADB,'--no-defaults','--bootstrap','--skip-networking','--skip-innodb','--default-storage-engine=MEMORY','--skip-log-bin','--datadir='+directory,'--log-error='+str(root/'error.log'),'--open-files-limit=1024']
            messages = os.environ.get('LAVKA_TEST_SQL_MESSAGES')
            if messages: command.append('--lc-messages-dir='+messages)
            result = subprocess.run(command,input=sql,universal_newlines=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=30)
            log = (root/'error.log').read_text() if (root/'error.log').exists() else ''
            return result.returncode, result.stderr+log

    def test_emitted_queries_execute_with_ssl_column(self):
        queries = self.emitted_queries(True)
        self.assertGreater(len(queries),8)
        status, log = self.execute(queries,True)
        self.assertEqual(status,0,log)

    def test_emitted_queries_execute_without_ssl_column(self):
        status, log = self.execute(self.emitted_queries(False),False)
        self.assertEqual(status,0,log)

    def test_engine_rejects_original_unquoted_ssl_query(self):
        query = "SELECT store_id FROM `oc_store` WHERE url IN ('https://test.lavka-speciy.com/') OR ssl IN ('https://test.lavka-speciy.com/') LIMIT 2"
        status, log = self.execute([query],True)
        self.assertNotEqual(status,0)
        self.assertIn('1064',log)
        self.assertIn('ssl IN',log)

    def test_real_migration_prepared_sql_preserves_data_and_rejects_case_sensitive_race(self):
        before = '{ "version":"1.9.0.0", "empty":{}, "large":123456789012345678901234567890, "user_js":"keep", "user_css":"old" }'
        after = before.replace('"old"','"new"')
        config = {'DB_HOSTNAME':'fixture','DB_USERNAME':'fixture','DB_PASSWORD':'fixture-secret',
                  'DB_DATABASE':'lavkasp_test','DB_PORT':'3306','DB_PREFIX':'oc_','store_id':0,
                  'action':'replace','before':before,'after':after,'fixture_raw_settings':before}
        fixture = (ROOT/'tests/fixtures/probe_database.php').read_text().split('<?php',1)[1]
        code = (ROOT/'ops/migrations/user-css.php').read_text().split('<?php',1)[1]
        marker = "echo json_encode(array('ok'=>true,'updated'=>true));"
        self.assertEqual(code.count(marker),1)
        code = code.replace(marker,"echo json_encode(array('ok'=>true,'fixture_queries'=>FixtureDatabase::$queries));")
        result = subprocess.run([PHP,'-n','-r',fixture+'\n'+code],input=json.dumps(config),universal_newlines=True,
                                stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=10)
        self.assertEqual(result.returncode,0,result.stderr)
        statement = json.loads(result.stdout)['fixture_queries'][-1]
        self.assertTrue(statement.startswith('UPDATE `oc_uni_setting` '))
        def value(text):
            return 'CONVERT(0x'+text.encode('utf-8').hex()+' USING utf8mb4)'
        for race in (False,True):
            with self.subTest(concurrent_change=race):
                actual = before.replace('"old"','"OLD"') if race else before
                expected = actual if race else after
                queries = [
                    'INSERT INTO `oc_uni_setting` (`store_id`,`data`) VALUES (0,'+value(actual)+')',
                    "PREPARE css_update FROM '"+statement+"'",
                    'SET @css_after='+value(after), 'SET @css_store_id=0', 'SET @css_before='+value(before),
                    'EXECUTE css_update USING @css_after,@css_store_id,@css_before', 'DEALLOCATE PREPARE css_update',
                    'CREATE TABLE assert_css_update (ok INT CHECK (ok=1)) ENGINE=MEMORY',
                    'INSERT INTO assert_css_update SELECT IF(BINARY `data`=BINARY '+value(expected)+',1,0) FROM `oc_uni_setting` WHERE `store_id`=0',
                    'INSERT INTO assert_css_update VALUES (IF((SELECT COUNT(*) FROM `oc_uni_setting`)=1,1,0))',
                ]
                status,log = self.execute(queries,True)
                self.assertEqual(status,0,log)


if __name__ == '__main__':
    unittest.main()
