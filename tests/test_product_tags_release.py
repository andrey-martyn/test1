"""Installer guards, idempotency, exact backups and rollback on isolated files."""
import contextlib
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import sys
import subprocess
import tempfile
import unittest
from unittest import mock

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'ops'))
spec=importlib.util.spec_from_file_location('tag_release',ROOT/'ops/product_tags.py')
release=importlib.util.module_from_spec(spec); spec.loader.exec_module(release)


class TagRelease(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name);self.repo=self.root/'repo';self.shop=self.root/'test';self.storage=self.root/'test_storage';self.backups=self.root/'backups'
        self.shop.mkdir();self.storage.mkdir();(self.repo/'docs').mkdir(parents=True)
        self.files=[]
        for path,before,after in [('admin/controller/catalog/product.php',b'old\n',b'new\n'),('system/library/lavka_product_tags.php',None,b'new class\n')]:
            source=self.repo/'store'/path;source.parent.mkdir(parents=True,exist_ok=True);source.write_bytes(after)
            if before is not None:
                target=self.shop/path;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(before)
            sha=lambda raw:hashlib.sha256(raw).hexdigest()
            self.files.append({'path':path,'before_sha256':sha(before) if before else None,'allowed_before_sha256':[sha(before)] if before else [],'after_sha256':sha(after)})
        self.manifest={'target':'test.lavka-speciy.com','database':'lavkasp_test','store_id':0,'files':self.files}
        self.save_manifest()
        for key,value in [('REPO',self.repo),('SHOP',self.shop),('STORAGE',self.storage),('BACKUPS',self.backups)]:
            patch=mock.patch.object(release.store,key,value);patch.start();self.addCleanup(patch.stop)
        self.preflight=mock.patch.object(release.store,'preflight',return_value={'ok':True,'db':{'runtime_store_id':0}}).start();self.addCleanup(mock.patch.stopall)
        self.database=mock.patch.object(release,'database',return_value={'ok':True}).start()

    def save_manifest(self): (self.repo/'docs/product-tags-release.json').write_text(json.dumps(self.manifest))

    def run_action(self,*args):
        output=io.StringIO()
        with mock.patch.object(sys,'argv',['product_tags.py']+list(args)),contextlib.redirect_stdout(output),contextlib.redirect_stderr(output): status=release.main()
        return status,output.getvalue()

    def test_check_is_readonly_and_reports_all_files(self):
        status,output=self.run_action('--check');self.assertEqual(status,0,output)
        self.database.assert_called_once_with('check');self.assertFalse(self.backups.exists())
        self.assertFalse((self.shop/'system').exists())

    def test_apply_idempotency_and_byte_exact_rollback(self):
        status,output=self.run_action('--apply');self.assertEqual(status,0,output);result=json.loads(output)
        self.assertEqual((self.shop/self.files[0]['path']).read_bytes(),b'new\n')
        self.assertEqual(self.database.call_args_list,[mock.call('check'),mock.call('initialize')])
        status,output=self.run_action('--apply');self.assertEqual(status,0,output);self.assertEqual(json.loads(output)['installed'],'already_current')
        self.database.reset_mock()
        status,output=self.run_action('--rollback',result['rollback_id']);self.assertEqual(status,0,output)
        self.assertEqual((self.shop/self.files[0]['path']).read_bytes(),b'old\n');self.assertFalse((self.shop/self.files[1]['path']).exists())
        self.database.assert_not_called();self.assertIn('Retained',output)

    def test_source_mismatch_stops_before_db_or_backups(self):
        (self.shop/self.files[0]['path']).write_bytes(b'other changes\n')
        status,output=self.run_action('--apply');self.assertEqual(status,2);self.assertIn('SOURCE_HASH_MISMATCH',output)
        self.database.assert_not_called();self.assertFalse(self.backups.exists())

    def test_known_previous_module_file_can_be_upgraded_and_rolled_back(self):
        entry=self.files[1];old=b'previous module\n';target=self.shop/entry['path'];target.parent.mkdir(parents=True);target.write_bytes(old)
        entry['allowed_before_sha256']=[hashlib.sha256(old).hexdigest()];self.save_manifest()
        status,output=self.run_action('--apply');self.assertEqual(status,0,output);result=json.loads(output)
        status,output=self.run_action('--rollback',result['rollback_id']);self.assertEqual(status,0,output)
        self.assertEqual(target.read_bytes(),old)

    def test_target_identity_must_confirm_default_test_store(self):
        self.preflight.return_value={'ok':True,'db':{'runtime_store_id':3}}
        status,_=self.run_action('--apply');self.assertEqual(status,2);self.database.assert_not_called()

    def test_parent_symlink_and_new_file_collision_are_rejected(self):
        (self.shop/'system').symlink_to(self.storage,target_is_directory=True)
        status,_=self.run_action('--apply');self.assertEqual(status,2);self.database.assert_not_called()
        (self.shop/'system').unlink();target=self.shop/self.files[1]['path'];target.parent.mkdir(parents=True);target.write_bytes(b'collision')
        status,_=self.run_action('--apply');self.assertEqual(status,2);self.database.assert_not_called()

    def test_config_and_traversal_payloads_are_rejected(self):
        for path in ('admin/config.php','../production/file.php'):
            with self.subTest(path=path):
                self.manifest['files'][0]['path']=path;self.save_manifest()
                status,_=self.run_action('--apply');self.assertEqual(status,2);self.database.assert_not_called()


class RealTagPayload(unittest.TestCase):
    def test_manifest_and_patch_recreate_every_final_file(self):
        manifest=json.loads((ROOT/'docs/product-tags-release.json').read_text())
        self.assertEqual(len(manifest['files']),21)
        with tempfile.TemporaryDirectory(prefix='lavka-tags-patch-') as directory:
            target=Path(directory)
            for entry in manifest['files']:
                payload=(ROOT/'store'/entry['path']).read_bytes()
                self.assertEqual(hashlib.sha256(payload).hexdigest(),entry['after_sha256'])
                if entry['before_sha256'] is None: continue
                before=subprocess.check_output(['git','show',manifest['source_commit']+':store/'+entry['path']],cwd=ROOT)
                self.assertEqual(hashlib.sha256(before).hexdigest(),entry['before_sha256'])
                path=target/entry['path'];path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(before)
            reply=subprocess.run(['git','apply','--whitespace=nowarn',str(ROOT/'docs/product-tags.patch')],cwd=target,stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
            self.assertEqual(reply.returncode,0,reply.stderr)
            for entry in manifest['files']: self.assertEqual(hashlib.sha256((target/entry['path']).read_bytes()).hexdigest(),entry['after_sha256'])


if __name__=='__main__':unittest.main()
