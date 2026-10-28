"""Run actual service SQL against a temporary InnoDB bootstrap, without networking."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
PHP=os.environ.get('LAVKA_TEST_PHP_CLI') or shutil.which('php')
ENGINE=os.environ.get('LAVKA_TEST_SQL_ENGINE') or shutil.which('mariadbd')


@unittest.skipUnless(PHP and ENGINE,'Set PHP and SQL test engines to validate tag SQL.')
class TagSql(unittest.TestCase):
    def test_real_ddl_cas_and_transaction_queries_execute(self):
        reply=subprocess.check_output([PHP,'-n',str(ROOT/'tests/fixtures/product_tags.php'),'sql'],universal_newlines=True)
        queries=json.loads(reply)['queries']
        native=json.dumps({'sticker_new':1,'sticker_new_date':30,'mpn_as_sticker':1})
        setup=[
            'CREATE DATABASE tags_fixture','USE tags_fixture',
            'CREATE TABLE oc_uni_setting (store_id INT, data TEXT) ENGINE=InnoDB',
            "INSERT INTO oc_uni_setting VALUES (0,'"+native+"')",
            'CREATE TABLE oc_product (product_id INT, date_available DATE, mpn VARCHAR(80), price DECIMAL(15,4), quantity INT) ENGINE=InnoDB',
            "INSERT INTO oc_product VALUES (816,'2026-10-27','Популярно',45,767)",
        ]
        checks=[
            'CREATE TABLE assert_tag_result (ok INT CHECK(ok=1)) ENGINE=InnoDB',
            "INSERT INTO assert_tag_result SELECT IF(enabled=1 AND revision=2,1,0) FROM oc_lavka_product_tag WHERE product_id=816 AND code='hit'",
            "UPDATE oc_lavka_product_tag SET enabled=0,revision=revision+1 WHERE store_id=0 AND product_id=816 AND code='hit' AND revision=1",
            'INSERT INTO assert_tag_result VALUES (IF(ROW_COUNT()=0,1,0))',
            "INSERT INTO assert_tag_result SELECT IF(enabled=1 AND revision=1,1,0) FROM oc_lavka_product_tag WHERE code='fresh'",
            'START TRANSACTION',
            "UPDATE oc_lavka_tag_definition SET label_ru='Rejected' WHERE code='new'",'ROLLBACK',
            "INSERT INTO assert_tag_result SELECT IF(label_ru<>'Rejected',1,0) FROM oc_lavka_tag_definition WHERE code='new'",
            "INSERT INTO assert_tag_result SELECT IF(background='#123456',1,0) FROM oc_lavka_tag_definition WHERE code='brand'",
            'INSERT INTO assert_tag_result VALUES (IF((SELECT COUNT(*) FROM oc_lavka_tag_definition)=5,1,0))',
            "INSERT INTO assert_tag_result SELECT IF(price=45 AND quantity=767 AND mpn='Популярно',1,0) FROM oc_product",
        ]
        with tempfile.TemporaryDirectory(prefix='lavka-tags-sql-') as directory:
            root=Path(directory)
            command=[ENGINE,'--no-defaults','--bootstrap','--skip-networking','--skip-log-bin','--datadir='+directory,'--log-error='+str(root/'error.log'),'--open-files-limit=1024','--innodb-buffer-pool-size=32M']
            if os.environ.get('LAVKA_TEST_SQL_MESSAGES'): command.append('--lc-messages-dir='+os.environ['LAVKA_TEST_SQL_MESSAGES'])
            reply=subprocess.run(command,input=';\n'.join(setup+queries+checks)+';\n',universal_newlines=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=30)
            log=(root/'error.log').read_text() if (root/'error.log').exists() else ''
            self.assertEqual(reply.returncode,0,reply.stderr+log)


if __name__=='__main__': unittest.main()
