"""Mobile-product delivery must stay test-only, read-only in DB, reversible and complete."""
import contextlib
import hashlib
import io
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'ops'))
import product_page as release


class ProductPageRelease(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        root=Path(self.temp.name)
        for key,value in [('REPO',root/'repo'),('SHOP',root/'test'),('STORAGE',root/'storage'),('BACKUPS',root/'backups')]:
            value.mkdir();patch=mock.patch.object(release.store,key,value);patch.start();self.addCleanup(patch.stop)
        self.repo=release.store.REPO;self.shop=release.store.SHOP
        self.template=self.shop/'catalog/view/theme/unishop2/template/product/product.twig';self.template.parent.mkdir(parents=True);self.template.write_bytes(b'reviewed template')
        self.controller='catalog/controller/product/product.php';self.asset='catalog/view/theme/unishop2/js/lavka-mobile-product.js'
        files=[]
        for path,before,after in [(self.controller,b'original\r\n',b'enhanced\r\n'),(self.asset,None,b'new JS')]:
            payload=self.repo/'store'/path;payload.parent.mkdir(parents=True,exist_ok=True);payload.write_bytes(after)
            if before is not None:
                target=self.shop/path;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(before)
            sha=lambda raw:hashlib.sha256(raw).hexdigest()
            files.append({'path':path,'before_sha256':sha(before) if before else None,'allowed_before_sha256':[sha(before)] if before else [],'after_sha256':sha(after)})
        manifest={'feature':'mobile-product','target':'test.lavka-speciy.com','database':'lavkasp_test','store_id':0,'files':files,'required_sources':[{'path':str(self.template.relative_to(self.shop)),'allowed_sha256':[release.store.sha(self.template)]}],'database_changes':[]}
        (self.repo/'docs').mkdir();(self.repo/'docs/product-page-release.json').write_text(json.dumps(manifest))
        patch=mock.patch.object(release.store,'preflight',return_value={'ok':True,'db':{'runtime_store_id':0,'product_template_overrides':0}});self.preflight=patch.start();self.addCleanup(patch.stop)
        patch=mock.patch.object(release.checked,'database',side_effect=AssertionError('No schema writes for front-end release'));self.db=patch.start();self.addCleanup(patch.stop)

    def run_action(self,*args):
        output=io.StringIO()
        with mock.patch.object(sys,'argv',['product_page.py']+list(args)),contextlib.redirect_stdout(output),contextlib.redirect_stderr(output):status=release.main()
        self.db.assert_not_called()
        return status,output.getvalue()

    def test_check_readonly_apply_idempotent_and_byte_exact_rollback(self):
        status,out=self.run_action('--check');self.assertEqual(status,0,out);self.assertFalse(list(release.store.BACKUPS.iterdir()))
        self.assertFalse((self.shop/self.asset).exists())
        status,out=self.run_action('--apply');self.assertEqual(status,0,out);backup=json.loads(out)['rollback_id']
        self.assertEqual((self.shop/self.controller).read_bytes(),b'enhanced\r\n')
        status,out=self.run_action('--apply');self.assertEqual(status,0,out);self.assertEqual(json.loads(out)['installed'],'already_current')
        status,out=self.run_action('--rollback',backup);self.assertEqual(status,0,out)
        self.assertEqual((self.shop/self.controller).read_bytes(),b'original\r\n');self.assertFalse((self.shop/self.asset).exists())

    def test_different_template_or_controller_stops_before_any_shop_writes(self):
        for target in (self.template,self.shop/self.controller):
            before=target.read_bytes();target.write_bytes(b'other version')
            status,out=self.run_action('--apply');self.assertEqual(status,2,out)
            self.assertFalse(list(release.store.BACKUPS.iterdir()));self.assertFalse((self.shop/self.asset).exists());target.write_bytes(before)

    def test_unverified_db_store_or_template_editor_cannot_deploy(self):
        for report in ({'ok':False},{'ok':True,'db':{'runtime_store_id':3,'product_template_overrides':0}}, {'ok':True,'db':{'runtime_store_id':0,'product_template_overrides':1}}, {'ok':True,'db':{'runtime_store_id':0}}):
            self.preflight.return_value=report
            status,out=self.run_action('--apply');self.assertEqual(status,2,out);self.assertFalse(list(release.store.BACKUPS.iterdir()))

    def test_reviewed_effective_override_is_accepted_without_db_writes(self):
        self.preflight.return_value['db'].update({'product_template_overrides':1,
            'product_template_override_scope':{'store_id':0,'theme':'unishop2','route':'product/product'},
            'product_template_override_details':[{'theme_id':4,'source_bounded':True,'decoded_sha256':release.store.sha(self.template)}]})
        status,out=self.run_action('--apply');self.assertEqual(status,0,out)
        self.assertEqual(json.loads(out)['template_check']['status'],'active_override_matches_reviewed_source')

    def test_different_override_diagnosis_is_readonly_and_apply_stays_blocked(self):
        self.preflight.return_value['db'].update({'product_template_overrides':1,
            'product_template_override_scope':{'store_id':0,'theme':'unishop2','route':'product/product'},
            'product_template_override_details':[{'theme_id':9,'source_bounded':True,'decoded_sha256':'a'*64}]})
        status,out=self.run_action('--diagnose');self.assertEqual(status,0,out)
        diagnostic=json.loads(out);self.assertFalse(diagnostic['shop_writes']);self.assertFalse(diagnostic['database_writes'])
        self.assertEqual(diagnostic['template_check']['status'],'active_override_differs')
        status,out=self.run_action('--apply');self.assertEqual(status,2,out)
        self.assertEqual(json.loads(out)['template_check']['records'][0]['theme_id'],9)
        self.assertFalse(list(release.store.BACKUPS.iterdir()))

    def test_modified_installed_file_blocks_rollback(self):
        status,out=self.run_action('--apply');self.assertEqual(status,0,out)
        (self.shop/self.asset).write_bytes(b'changed later')
        status,out=self.run_action('--rollback',json.loads(out)['rollback_id']);self.assertEqual(status,2,out)
        self.assertEqual((self.shop/self.controller).read_bytes(),b'enhanced\r\n')

    def test_real_previous_mobile_release_upgrades_and_rolls_back_exactly(self):
        for revision in ('4c9719c','f30a4fc','75c5e5f','485e1fc','0d04893','74a7233'):
            with self.subTest(revision=revision):
                manifest=json.loads((ROOT/'docs/product-page-release.json').read_text())
                manifest['required_sources']=[{'path':str(self.template.relative_to(self.shop)),'allowed_sha256':[release.store.sha(self.template)]}]
                originals={}
                for entry in manifest['files']:
                    path=entry['path']
                    raw=subprocess.check_output(['git','show',revision+':store/'+path],cwd=ROOT)
                    originals[path]=raw
                    target=self.shop/path;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(raw)
                    payload=self.repo/'store'/path;payload.parent.mkdir(parents=True,exist_ok=True);payload.write_bytes((ROOT/'store'/path).read_bytes())
                (self.repo/'docs/product-page-release.json').write_text(json.dumps(manifest))
                status,out=self.run_action('--check');self.assertEqual(status,0,out)
                self.assertEqual(len(json.loads(out)['files_to_update']),3)
                status,out=self.run_action('--apply');self.assertEqual(status,0,out)
                backup=json.loads(out)['rollback_id']
                for entry in manifest['files']:self.assertEqual(release.store.sha(self.shop/entry['path']),entry['after_sha256'])
                status,out=self.run_action('--rollback',backup);self.assertEqual(status,0,out)
                for path,raw in originals.items():self.assertEqual((self.shop/path).read_bytes(),raw)



class ProductPayload(unittest.TestCase):
    def test_exported_override_is_reviewed_for_db_only_and_keeps_native_regions(self):
        manifest=json.loads((ROOT/'docs/product-page-release.json').read_text())
        active=manifest['reviewed_overrides'][0]
        fixture=(ROOT/active['source']).read_bytes()
        digest=hashlib.sha256(fixture).hexdigest()
        self.assertEqual(digest,'46ac7c1c16c46d869916540d5e9723235924f0ec1701424403974c0f511dcf82')
        self.assertIn(digest,active['allowed_sha256'])
        self.assertNotIn(digest,manifest['required_sources'][0]['allowed_sha256'],'DB override must not broaden on-disk source hashes')
        code=fixture.decode().replace('\r\n','\n')
        regions=active['review']['unchanged_regions']
        markup=code[:code.index('{% if socialbutton %}')]
        cart=code[code.index("$('html body').on('click', '#button-cart:not(.disabled)'"):code.index("$('button[id^=\\'button-upload\\']')")]
        for name,source in [('markup_and_gallery',markup),('native_cart',cart)]:
            self.assertTrue(regions[name]['identical_to_ftp']);self.assertEqual(hashlib.sha256(source.encode()).hexdigest(),regions[name]['lf_sha256'])
        report={'db':{'product_template_overrides':1,'product_template_override_scope':{'store_id':0,'theme':'unishop2','route':'product/product'},'product_template_override_details':[{'theme_id':3,'source_bounded':True,'decoded_sha256':digest,'decoded_lf_sha256':digest}]}}
        self.assertTrue(release.template_check(report,manifest)['ok'])
        report['db']['product_template_override_details'][0]['decoded_sha256']='a'*64;report['db']['product_template_override_details'][0]['decoded_lf_sha256']='b'*64
        self.assertFalse(release.template_check(report,manifest)['ok'],'Unreviewed edits must remain blocked')

    def test_manifest_patch_and_complete_files_agree(self):
        manifest=json.loads((ROOT/'docs/product-page-release.json').read_text());self.assertEqual(len(manifest['files']),3);self.assertEqual(manifest['database_changes'],[])
        with tempfile.TemporaryDirectory() as directory:
            target=Path(directory)
            for entry in manifest['files']:
                self.assertEqual(release.store.sha(ROOT/'store'/entry['path']),entry['after_sha256'])
                if entry['before_sha256'] is None:continue
                raw=subprocess.check_output(['git','show',manifest['source_commit']+':store/'+entry['path']],cwd=ROOT)
                self.assertEqual(hashlib.sha256(raw).hexdigest(),entry['before_sha256'])
                path=target/entry['path'];path.parent.mkdir(parents=True);path.write_bytes(raw)
            reply=subprocess.run(['git','apply','--whitespace=nowarn',str(ROOT/'docs/product-page.patch')],cwd=target,stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
            self.assertEqual(reply.returncode,0,reply.stderr)
            for entry in manifest['files']:self.assertEqual(release.store.sha(target/entry['path']),entry['after_sha256'])


if __name__=='__main__':unittest.main()
