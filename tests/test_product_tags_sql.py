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
    def execute(self,queries):
        with tempfile.TemporaryDirectory(prefix='lavka-tags-sql-') as directory:
            root=Path(directory)
            command=[ENGINE,'--no-defaults','--bootstrap','--skip-networking','--skip-log-bin','--datadir='+directory,'--log-error='+str(root/'error.log'),'--open-files-limit=1024','--innodb-buffer-pool-size=32M']
            if os.environ.get('LAVKA_TEST_SQL_MESSAGES'): command.append('--lc-messages-dir='+os.environ['LAVKA_TEST_SQL_MESSAGES'])
            reply=subprocess.run(command,input=';\n'.join(queries)+';\n',universal_newlines=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=30)
            log=(root/'error.log').read_text() if (root/'error.log').exists() else ''
            self.assertEqual(reply.returncode,0,reply.stderr+log)

    def test_previous_definition_schema_gains_threshold_without_resetting_labels(self):
        code="require 'store/system/library/lavka_product_tags.php'; echo json_encode([LavkaProductTags::schema('oc_')[0],LavkaProductTags::upgradeSql('oc_')]);"
        schema,upgrade=json.loads(subprocess.check_output([PHP,'-n','-r',code],cwd=ROOT,universal_newlines=True))
        previous=schema.replace(', `min_sales` INT UNSIGNED NOT NULL DEFAULT 11','')
        queries=['CREATE DATABASE tag_upgrade','USE tag_upgrade',previous,
            "INSERT INTO oc_lavka_tag_definition VALUES (0,'hit',1,'Custom name','Custom UA','#123456','#ffffff',10,0,7)",
            upgrade,
            'CREATE TABLE assert_upgrade (ok INT CHECK(ok=1)) ENGINE=InnoDB',
            "INSERT INTO assert_upgrade SELECT IF(label_ru='Custom name' AND background='#123456' AND revision=7 AND min_sales=11,1,0) FROM oc_lavka_tag_definition",
            'INSERT INTO assert_upgrade VALUES (IF((SELECT COUNT(*) FROM oc_lavka_tag_definition)=1,1,0))']
        self.execute(queries)

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
            'CREATE TABLE `oc_order` (order_id INT, store_id INT, order_status_id INT) ENGINE=InnoDB',
            'CREATE TABLE oc_order_product (order_id INT, product_id INT, quantity INT) ENGINE=InnoDB',
            'INSERT INTO `oc_order` VALUES (1,0,5),(2,0,1),(3,0,7),(4,1,5),(5,0,5),(6,0,5),(7,0,5)',
            'INSERT INTO oc_order_product VALUES (1,816,11),(2,816,99),(3,816,100),(4,816,100),(5,817,10),(6,818,6),(7,818,5)',
        ]
        checks=[
            'CREATE TABLE assert_tag_result (ok INT CHECK(ok=1)) ENGINE=InnoDB',
            "INSERT INTO assert_tag_result SELECT IF(enabled=1 AND revision=2,1,0) FROM oc_lavka_product_tag WHERE product_id=816 AND code='brand'",
            "UPDATE oc_lavka_product_tag SET enabled=0,revision=revision+1 WHERE store_id=0 AND product_id=816 AND code='brand' AND revision=1",
            'INSERT INTO assert_tag_result VALUES (IF(ROW_COUNT()=0,1,0))',
            "INSERT INTO assert_tag_result SELECT IF(enabled=1 AND revision=1,1,0) FROM oc_lavka_product_tag WHERE code='fresh'",
            'START TRANSACTION',
            "UPDATE oc_lavka_tag_definition SET label_ru='Rejected' WHERE code='new'",'ROLLBACK',
            "INSERT INTO assert_tag_result SELECT IF(label_ru<>'Rejected',1,0) FROM oc_lavka_tag_definition WHERE code='new'",
            "INSERT INTO assert_tag_result SELECT IF(background='#123456',1,0) FROM oc_lavka_tag_definition WHERE code='brand'",
            'INSERT INTO assert_tag_result VALUES (IF((SELECT COUNT(*) FROM oc_lavka_tag_definition)=5,1,0))',
            "INSERT INTO assert_tag_result SELECT IF(price=45 AND quantity=767 AND mpn='Популярно',1,0) FROM oc_product",
        ]
        sales=next(query for query in queries if query.startswith('SELECT op.product_id'))
        checks.extend([
            'INSERT INTO assert_tag_result SELECT IF(total=11,1,0) FROM ('+sales+') sales WHERE product_id=816',
            'INSERT INTO assert_tag_result SELECT IF(total=10,1,0) FROM ('+sales+') sales WHERE product_id=817',
            'INSERT INTO assert_tag_result SELECT IF(total=11,1,0) FROM ('+sales+') sales WHERE product_id=818',
        ])
        self.execute(setup+queries+checks)


if __name__=='__main__': unittest.main()
