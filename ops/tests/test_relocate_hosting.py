import base64
import fcntl
import importlib.util
import json
import multiprocessing
import os
from pathlib import Path
import sqlite3
import subprocess
import tempfile
import unittest


spec = importlib.util.spec_from_file_location('relocate_hosting', Path(__file__).resolve().parents[1] / 'relocate_hosting.py')
tool = importlib.util.module_from_spec(spec)
spec.loader.exec_module(tool)


def write(path, data, mode=0o640):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(data.encode('utf-8') if isinstance(data, str) else data)
    path.chmod(mode)


def database(path, name):
    connection = sqlite3.connect(str(path))
    connection.executescript('CREATE TABLE settings(key TEXT PRIMARY KEY,value TEXT); CREATE TABLE manual_links(a TEXT,b TEXT);')
    connection.execute('INSERT INTO settings VALUES(?,?)', ('account_password_hash', 'existing-private-password-hash'))
    connection.execute('INSERT INTO manual_links VALUES(?,?)', (name + '-a', name + '-b'))
    connection.commit();connection.close()


def sql_digest(connection):
    result={}
    for name,schema in connection.execute("SELECT name,sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name").fetchall():
        rows=[tool.sha(repr(tuple(row)).encode()) for row in connection.execute('SELECT * FROM "'+name.replace('"','""')+'"')]
        result[name]={'count':len(rows),'digest':tool.sha('\n'.join(sorted(rows)).encode()),'schema':tool.sha((schema or '').encode())}
    return result


def checkpoint(paths):
    result={}
    for path in paths:
        connection=sqlite3.connect(path.as_uri()+'?mode=rw',uri=True,timeout=2)
        try:
            tool.require(connection.execute('PRAGMA quick_check').fetchone()==('ok',),'sqlite_integrity_failed')
            connection.execute('BEGIN EXCLUSIVE');before=sql_digest(connection);connection.rollback()
            checked=connection.execute('PRAGMA wal_checkpoint(TRUNCATE)').fetchone()
            tool.require(checked[0]==0,'sqlite_writer_or_reader_still_active')
            tool.require(sql_digest(connection)==before,'checkpoint_changed_logical_data')
            result[str(path)]=before
        finally:connection.close()
    return result


def fixture(base, demo=True):
    root = base / 'lavka-speciy.com';root.mkdir()
    for name in ('www', 'test', 'lavka_storage', 'test_storage', 'analytics',
                 'analytics-private', 'analytics-private-test', 'review-factory-private-test',
                 'analytics-backups', 'test_frontend_backups', 'analytics-app/.git'):
        (root / name).mkdir(parents=True, mode=0o750)
    for prefix in ('www', 'test'):
        for name in ('config.php', 'admin/config.php', 'index.php', '.htaccess'):
            write(root / prefix / name, 'existing ' + prefix + '/' + name + ' PRIVATE_LOGIN_DO_NOT_PRINT')
    write(root / 'lavka_storage/production-access-marker', 'production access and files unchanged')
    write(root / 'analytics/index.html', 'existing public placeholder')
    repo = root / 'analytics-app'
    write(repo / 'spice-analytics/php/src/HostingLayout.php', "<?php // organized layout is supported")
    write(repo / 'spice-analytics/php/public/index.php', '<?php // existing analytics code')
    write(repo / 'review-factory/php/src/App.php', '<?php // existing review code')
    write(repo / 'review-factory/php/src/Demo.php', '<?php // existing TEST demo code')
    write(base / 'lavka-frontend-work/ops/test_store.py', '# Hosting layout v1: two closed backup roots')
    for name in ('analytics-private', 'analytics-private-test'):
        directory = root / name
        value = {'application_root': str(repo / 'spice-analytics/php'), 'data_dir': str(directory),
                 'document_root': str(root / 'test/analytics'), 'shop_root': str(root / 'test'),
                 'public_url': 'https://test.lavka-speciy.com/analytics'}
        write(directory / 'deployment-php.json', json.dumps(value))
        write(directory / 'source.json', '{"password":"existing-secret","database":"unchanged"}')
        write(directory / 'access-php.json', '{"hash":"existing-login-hash"}')
        write(directory / 'source.before-local.json', '{"password":"older-preserved-secret"}')
        database(directory / 'analytics.sqlite3', name)
        write(directory / '.sync-php.lock', '')
    test = root / 'analytics-private-test'
    write(test / 'sandbox.json', json.dumps({'environment': 'sandbox', 'database': 'lavkasp_test', 'data_dir': str(test),
          'previous_data_dir': str(root / 'analytics-private'), 'shop_root': str(root / 'test'),
          'admin_config': str(root / 'test/admin/config.php')}))
    write(test / 'identity-reviews.json', '{"different_clients":[["kept-a","kept-b"]]}')
    write(test / 'cache/existing-file', 'cached Unicode зира')
    database(test / 'competitors.sqlite3', 'confirmed-pages-and-packaging')
    review = root / 'review-factory-private-test'
    database(review / 'factory.sqlite3', 'drafts-users-and-history')
    write(review / '.worker.lock', '')
    write(review / 'instance.json', json.dumps({'environment': 'test', 'shop_root': str(root / 'test'),
          'shop_url': 'https://test.lavka-speciy.com', 'webroot': str(root / 'test/review-workshop'),
          'public_url': 'https://test.lavka-speciy.com/review-workshop', 'analytics_data': str(test),
          'public_roots': [str(root / p) for p in ('test','www','analytics')], 'language': 3, 'statuses': [2,5]}))
    write(root / 'test/analytics/index.php', "<?php\nputenv('ANALYTICS_DATA_DIR='.'" + str(test) + "');\nputenv('ANALYTICS_PUBLIC_URL=https://test.lavka-speciy.com/analytics');\nrequire '" + str(repo / 'spice-analytics/php/public/index.php') + "';\n")
    write(test / 'before-sandbox-launcher.php', (root / 'test/analytics/index.php').read_text().replace(str(test),str(root / 'analytics-private')))
    write(root / 'test/review-workshop/index.php', "<?php\nrequire_once '" + str(repo / 'review-factory/php/src/App.php') + "';\n\\ReviewFactory\\run('" + str(review) + "','https://test.lavka-speciy.com/review-workshop');\n")
    if demo:
        database(review / 'synthetic-reviews.sqlite3', 'synthetic-and-demo-moderation')
        write(root / 'test/system/library/review_factory_demo_config.php', "<?php\n// ReviewFactory TEST demo\nreturn ['data'=>'" + str(review) + "','runtime'=>'" + str(repo / 'review-factory/php/src/Demo.php') + "'];\n")
    write(root / 'analytics-backups/old-backup-id/source.json', 'old backup secret: preserve, do not rewrite')
    write(root / 'test_frontend_backups/old-rollback-id/manifest.json', 'existing rollback manifest')
    write(root / 'review-harvester-private/confirmed-product-pages.json', '[{"url":"https://example.com/confirmed"}]')
    return root


class RelocateTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.base = Path(self.temp.name).resolve()
        self.root = fixture(self.base)
        self.r = tool.Relocation(self.root, checkpoint)

    def tearDown(self):
        self.r.release();self.temp.cleanup()

    def test_plan_is_read_only_and_no_credentials_are_printed(self):
        before = tool.tree(self.base)
        value = self.r.plan();output = json.dumps(self.r.summary(value))
        self.assertEqual(tool.tree(self.base), before)
        self.assertFalse((self.root / 'ops').exists())
        self.assertNotIn('existing-secret', output)
        self.assertNotIn('existing-private-password-hash', output)
        self.assertEqual(len(value['moves']), 7)

    def test_apply_preserves_store_access_private_data_and_old_backups(self):
        protected = self.r.protected()
        sources = {name: (self.root / name / 'source.json').read_bytes() for name in ('analytics-private','analytics-private-test')}
        before = sqlite3.connect(str(self.root / 'review-factory-private-test/factory.sqlite3'))
        initial = sql_digest(before);before.close()
        result = self.r.apply(True)
        self.assertEqual(result['state'], 'completed')
        self.assertEqual(self.r.protected(), protected)
        for name, contents in sources.items():
            self.assertEqual((self.root / self.r.mapped(name) / 'source.json').read_bytes(), contents)
        self.assertTrue((self.root / 'apps/lavka-modules/.git').is_dir())
        self.assertTrue((self.root / 'backups/frontend/test/old-rollback-id/manifest.json').is_file())
        self.assertEqual((self.root / 'backups/analytics/old-backup-id/source.json').read_text(), 'old backup secret: preserve, do not rewrite')
        self.assertTrue((self.root / 'private/exports/review-harvester/confirmed-product-pages.json').is_file())
        review = self.root / 'private/review-factory/test'
        connection = sqlite3.connect(str(review / 'factory.sqlite3'))
        self.assertEqual(sql_digest(connection), initial);connection.close()
        instance = json.loads((review / 'instance.json').read_text())
        self.assertEqual(instance['language'], 3);self.assertEqual(instance['statuses'], [2,5])
        self.assertEqual(instance['analytics_data'], str(self.root / 'private/analytics/test'))
        self.assertTrue((review / 'synthetic-reviews.sqlite3').is_file())
        for path in tool.PUBLIC:
            text = (self.root / path).read_text()
            self.assertNotIn('analytics-app', text);self.assertNotIn('review-factory-private-test', text)
        historical=(self.root / 'private/analytics/test/before-sandbox-launcher.php').read_text()
        self.assertIn(str(self.root / 'private/analytics/previous'),historical)
        self.assertIn(str(self.root / 'apps/lavka-modules/spice-analytics/php/public/index.php'),historical)
        journal = self.root / 'ops/relocations' / result['journal_id']
        self.assertEqual((journal / 'journal.json').stat().st_mode & 0o777, 0o600)
        self.assertTrue((journal / 'private-before/analytics-private-test/identity-reviews.json').is_file())

    def test_failure_after_each_directory_move_restores_layout_and_access(self):
        # Each position is a different partial layout and must roll back cleanly.
        for position in range(7):
            with self.subTest(position=position), tempfile.TemporaryDirectory() as temporary:
                root = fixture(Path(temporary).resolve());r = tool.Relocation(root, checkpoint)
                protected = r.protected()
                initial = {p:(root / p).read_bytes() for p in tool.CONFIGS}
                def fault(stage, index):
                    if index == position:raise tool.Stop('injected_failure')
                with self.assertRaisesRegex(tool.Stop, 'injected_failure'):r.apply(True, fault)
                self.assertEqual(r.protected(), protected)
                for old,new in tool.MOVES + tool.OPTIONAL_MOVES:
                    self.assertTrue((root / old).is_dir());self.assertFalse((root / new).exists())
                for name,data in initial.items():self.assertEqual((root / name).read_bytes(), data)

    def test_rollback_keeps_manual_work_done_after_cutover(self):
        result = self.r.apply(True)
        path = self.root / 'private/analytics/test/analytics.sqlite3'
        connection = sqlite3.connect(str(path));connection.execute("INSERT INTO manual_links VALUES('new-a','new-b')")
        connection.commit();connection.close()
        reply = self.r.rollback(result['journal_id'], True)
        self.assertEqual(reply['state'], 'rolled_back')
        connection = sqlite3.connect(str(self.root / 'analytics-private-test/analytics.sqlite3'))
        self.assertEqual(connection.execute('SELECT count(*) FROM manual_links').fetchone()[0], 2);connection.close()

    def test_failure_after_configuration_write_restores_original_paths(self):
        original=tool.atomic
        configs={p:(self.root / p).read_bytes() for p in tool.CONFIGS}
        failed=[False]
        def once(path,data,*args,**kwargs):
            original(path,data,*args,**kwargs)
            if not failed[0] and path==self.root/'private/review-factory/test/instance.json':
                failed[0]=True
                raise tool.Stop('injected_config_failure')
        tool.atomic=once
        try:
            with self.assertRaisesRegex(tool.Stop,'injected_config_failure'):self.r.apply(True)
        finally:tool.atomic=original
        for path,data in configs.items():self.assertEqual((self.root / path).read_bytes(),data)
        self.assertTrue((self.root/'analytics-app').is_dir())

    def test_interrupted_rollback_can_resume_and_retains_new_work(self):
        result=self.r.apply(True)
        db=sqlite3.connect(str(self.root/'private/analytics/test/analytics.sqlite3'))
        db.execute("INSERT INTO manual_links VALUES('newer','preserve-through-interrupt')");db.commit();db.close()
        def crash():
            rename=tool.os.rename
            def stopped(source,target):
                rename(source,target)
                if str(target)==str(self.root/'analytics-private-test'):os._exit(78)
            tool.os.rename=stopped
            tool.Relocation(self.root,checkpoint).rollback(result['journal_id'],True)
        process=multiprocessing.Process(target=crash);process.start();process.join(20)
        self.assertFalse(process.is_alive());self.assertEqual(process.exitcode,78)
        self.assertEqual(self.r.rollback(result['journal_id'],True)['state'],'rolled_back')
        db=sqlite3.connect(str(self.root/'analytics-private-test/analytics.sqlite3'))
        self.assertEqual(db.execute('SELECT count(*) FROM manual_links').fetchone()[0],2);db.close()

    def test_killed_process_is_recovered_from_partial_layout(self):
        def crash():
            def fault(stage,index):
                if index == 3:os._exit(77)
            tool.Relocation(self.root, checkpoint).apply(True, fault)
        process = multiprocessing.Process(target=crash);process.start();process.join(20)
        self.assertFalse(process.is_alive());self.assertEqual(process.exitcode,77)
        journals = list((self.root / 'ops/relocations').iterdir())
        reply = self.r.rollback(journals[0].name, True)
        self.assertEqual(reply['state'], 'failed_rolled_back')
        for old,new in tool.MOVES:self.assertTrue((self.root / old).exists());self.assertFalse((self.root / new).exists())

    def test_active_worker_stops_before_replacing_public_launchers(self):
        path = self.root / 'review-factory-private-test/.worker.lock'
        with path.open('r+b') as handle:
            fcntl.flock(handle,fcntl.LOCK_EX | fcntl.LOCK_NB)
            before = (self.root / tool.PUBLIC[0]).read_bytes()
            with self.assertRaisesRegex(tool.Stop, 'module_worker_still_running'):self.r.apply(True)
            self.assertEqual((self.root / tool.PUBLIC[0]).read_bytes(),before)

    def test_active_wal_reader_refuses_move_and_restores_entries(self):
        path = self.root / 'analytics-private-test/analytics.sqlite3'
        writer = sqlite3.connect(str(path));writer.execute('PRAGMA journal_mode=WAL')
        writer.execute("INSERT INTO manual_links VALUES('wal-a','wal-b')");writer.commit()
        reader = sqlite3.connect(str(path));reader.execute('BEGIN');reader.execute('SELECT * FROM manual_links').fetchall()
        writer.execute("INSERT INTO manual_links VALUES('wal-c','wal-d')");writer.commit()
        try:
            with self.assertRaises(tool.Stop):self.r.apply(True)
            self.assertTrue((self.root / 'analytics-app').is_dir())
            self.assertNotIn(b'relocation in progress',(self.root / tool.PUBLIC[0]).read_bytes())
        finally:reader.close();writer.close()
        connection=sqlite3.connect(str(path));self.assertEqual(connection.execute('SELECT count(*) FROM manual_links').fetchone()[0],3);connection.close()

    def test_conflicting_destination_and_symlink_refused_read_only(self):
        path = self.root / 'private';path.symlink_to(self.root / 'www',target_is_directory=True)
        with self.assertRaisesRegex(tool.Stop,'path_redirected'):self.r.plan()
        path.unlink();(self.root / 'backups/frontend/test').mkdir(parents=True)
        with self.assertRaisesRegex(tool.Stop,'source_missing_or_destination_occupied'):self.r.plan()
        self.assertFalse((self.root / 'ops').exists())

    def test_changed_config_and_missing_frontend_adapter_refused(self):
        path = self.root / 'analytics-private-test/sandbox.json';value=json.loads(path.read_text())
        value['unknown_new_path']=str(self.root / 'analytics-private-test/new-file')
        path.write_text(json.dumps(value))
        with self.assertRaisesRegex(tool.Stop,'unmapped_active_config_path'):self.r.plan()
        frontend=self.base / 'lavka-frontend-work/ops/test_store.py';frontend.write_text('# old release')
        with self.assertRaisesRegex(tool.Stop,'update_frontend_branch_before_relocation'):self.r.plan()

    def test_no_implicit_writer_acknowledgement_and_no_demo_install(self):
        with self.assertRaisesRegex(tool.Stop,'writers_stopped_flag_required'):self.r.apply(False)
        self.assertFalse((self.root / 'ops').exists())
        (self.root / tool.PUBLIC[2]).unlink()
        result=self.r.apply(True)
        self.assertEqual(result['state'],'completed')
        self.assertFalse((self.root / tool.PUBLIC[2]).exists())

    def test_rollback_refuses_to_overwrite_a_new_config_edit(self):
        result=self.r.apply(True)
        path=self.root / 'private/review-factory/test/instance.json';value=json.loads(path.read_text())
        value['language']=9;path.write_text(json.dumps(value))
        with self.assertRaisesRegex(tool.Stop,'configuration_modified_since_relocation'):self.r.rollback(result['journal_id'],True)
        self.assertEqual(json.loads(path.read_text())['language'],9)

    @unittest.skipUnless(os.environ.get('LAVKA_RELOCATION_PHP_DOCKER') == '1', 'Optional PHP 8.2 integration')
    def test_full_move_and_rollback_using_real_php82_checkpoint(self):
        def native(paths):
            helper=Path(__file__).resolve().parents[1]/'relocation_sqlite.php'
            command=['docker','--host=unix:///var/run/docker.sock','run','--rm','-i','--network','none',
                     '-v',str(self.root)+':'+str(self.root),'-v',str(helper)+':/helper.php:ro',
                     'lavka-php-dev','php','/helper.php']
            environment=os.environ.copy()
            for key in ('DOCKER_HOST','DOCKER_CONTEXT','DOCKER_TLS','DOCKER_TLS_VERIFY','DOCKER_CERT_PATH'):environment.pop(key,None)
            process=subprocess.run(command,input=json.dumps({'root':str(self.root),'files':[str(p) for p in paths]}),
                                   universal_newlines=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=55,env=environment)
            reply=json.loads(process.stdout)
            self.assertEqual(process.returncode,0,process.stderr);self.assertEqual(reply['state'],'verified')
            return reply['fingerprints']
        r=tool.Relocation(self.root,native);protected=r.protected()
        result=r.apply(True)
        self.assertEqual(result['state'],'completed');self.assertEqual(r.protected(),protected)
        latest=self.root/'private/analytics/test/analytics.sqlite3'
        db=sqlite3.connect(str(latest));db.execute("INSERT INTO manual_links VALUES('after-native-move','preserve-me')");db.commit();db.close()
        self.assertEqual(r.rollback(result['journal_id'],True)['state'],'rolled_back')
        db=sqlite3.connect(str(self.root/'analytics-private-test/analytics.sqlite3'))
        self.assertEqual(db.execute('SELECT count(*) FROM manual_links').fetchone()[0],2);db.close()


if __name__ == '__main__':
    unittest.main()
