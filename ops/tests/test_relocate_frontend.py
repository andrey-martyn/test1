import multiprocessing
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import relocate_frontend as frontend
from relocate_hosting import Relocation, Stop, tree
from test_relocate_hosting import fixture


class FrontendRelocation(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.base=Path(self.temp.name).resolve()
        self.root=fixture(self.base);self.move=frontend.FrontendMove(self.root)
        subprocess.run(['git','-C',str(self.move.source),'init','-q'],check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        (self.move.source/'uncommitted-work.txt').write_text('existing local work: keep me')
        (self.move.source/'uncommitted-work.txt').chmod(0o640)

    def tearDown(self):self.temp.cleanup()

    def test_plan_read_only_and_move_keeps_entire_clone(self):
        before=tree(self.base);plan=self.move.plan()
        self.assertEqual(tree(self.base),before);self.assertEqual(plan['state'],'planned')
        files=tree(self.move.source);protected=self.move.guard.protected()
        result=self.move.apply()
        self.assertEqual(result['state'],'completed');self.assertFalse(self.move.source.exists())
        self.assertEqual(tree(self.move.target),files);self.assertEqual(self.move.guard.protected(),protected)
        self.assertEqual(Relocation(self.root).frontend,self.move.target)
        self.assertEqual(self.move.apply()['state'],'already_relocated')

    def test_failure_returns_same_files_and_permissions(self):
        before=tree(self.move.source)
        def fail():raise Stop('injected_failure')
        with self.assertRaisesRegex(Stop,'injected_failure'):self.move.apply(fail)
        self.assertEqual(tree(self.move.source),before);self.assertFalse(self.move.target.exists())

    def test_rollback_keeps_work_added_after_move(self):
        result=self.move.apply()
        (self.move.target/'newer-local-work.txt').write_text('preserve newest work')
        self.assertEqual(self.move.rollback(result['journal_id'])['state'],'rolled_back')
        self.assertEqual((self.move.source/'newer-local-work.txt').read_text(),'preserve newest work')

    def test_process_interruption_recovered_from_journal(self):
        def child():
            self.move.apply(lambda:os._exit(77))
        process=multiprocessing.Process(target=child);process.start();process.join(15)
        self.assertFalse(process.is_alive());self.assertEqual(process.exitcode,77)
        journal=next((self.root/'ops/relocations').iterdir()).name
        recovered=frontend.FrontendMove(self.root)
        self.assertEqual(recovered.rollback(journal)['state'],'rolled_back')
        self.assertTrue(self.move.source.is_dir());self.assertFalse(self.move.target.exists())

    def test_old_git_path_refused_before_modification(self):
        config=self.move.source/'.git/config'
        config.write_text(config.read_text()+'\n[core]\n sshCommand = ssh -i '+str(self.move.source/'key')+'\n')
        before=tree(self.base)
        with self.assertRaisesRegex(Stop,'git_config_references_old_frontend_path'):self.move.plan()
        self.assertEqual(tree(self.base),before)

    def test_two_copies_or_redirected_parent_not_selected(self):
        self.move.target.mkdir(parents=True)
        with self.assertRaises(Stop):self.move.plan()
        with self.assertRaisesRegex(Stop,'frontend_repository_conflict'):Relocation(self.root)
        self.move.target.rmdir();self.move.target.parent.rmdir()
        (self.root/'apps').symlink_to(self.root/'www',target_is_directory=True)
        with self.assertRaises(Stop):self.move.plan()

    def test_active_git_operation_blocks_move(self):
        (self.move.source/'.git/index.lock').write_text('active')
        with self.assertRaisesRegex(Stop,'git_operation_still_running'):self.move.plan()
        self.assertFalse((self.root/'ops').exists())


if __name__=='__main__':unittest.main()
