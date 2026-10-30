import importlib.util
from pathlib import Path
import tempfile
import unittest


class HostingBackupPaths(unittest.TestCase):
    def test_existing_ids_follow_directory_rename_and_both_roots_fail(self):
        spec=importlib.util.spec_from_file_location('hosting_test_store',Path(__file__).resolve().parents[1]/'ops/test_store.py')
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        with tempfile.TemporaryDirectory() as value:
            root=Path(value).resolve();old=root/'test_frontend_backups';new=root/'backups/frontend/test'
            old.mkdir();(old/'existing-rollback-id').mkdir()
            self.assertEqual(module.hosting_backup_directory(old,new),old)
            new.parent.mkdir(parents=True);old.rename(new)
            self.assertEqual(module.hosting_backup_directory(old,new),new)
            self.assertTrue((module.hosting_backup_directory(old,new)/'existing-rollback-id').is_dir())
            old.mkdir()
            with self.assertRaises(ValueError):module.hosting_backup_directory(old,new)
            old.rmdir();old.symlink_to(new,target_is_directory=True)
            with self.assertRaises(ValueError):module.hosting_backup_directory(old,new)


if __name__=='__main__':unittest.main()
