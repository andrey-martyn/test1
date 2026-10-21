"""Actual verified HTTPS CLI probe against a disposable PHP web runtime."""
from datetime import datetime, timedelta, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import ipaddress
import json
import os
from pathlib import Path
import secrets
import socket
import ssl
import subprocess
import tempfile
import threading
import time
import httpx
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID

ROOT=Path(__file__).resolve().parents[2]
IMAGE=os.environ.get('ANALYTICS_PHP_IMAGE','lavka-php73-dev')
name='lavka-web-probe-'+secrets.token_hex(5)
with tempfile.TemporaryDirectory() as t:
    site=Path(t);public=site/'public';public.mkdir()
    (public/'index.php').write_text('<?php echo "existing test store";')
    original=(public/'index.php').read_bytes()
    key=rsa.generate_private_key(public_exponent=65537,key_size=2048)
    subject=x509.Name([x509.NameAttribute(NameOID.COMMON_NAME,'127.0.0.1')])
    now=datetime.now(timezone.utc)
    cert=(x509.CertificateBuilder().subject_name(subject).issuer_name(subject).public_key(key.public_key())
        .serial_number(x509.random_serial_number()).not_valid_before(now-timedelta(minutes=5)).not_valid_after(now+timedelta(days=1))
        .add_extension(x509.BasicConstraints(ca=True,path_length=None),critical=True)
        .add_extension(x509.SubjectAlternativeName([x509.IPAddress(ipaddress.ip_address('127.0.0.1'))]),critical=False)
        .sign(key,hashes.SHA256()))
    (site/'ca.pem').write_bytes(cert.public_bytes(serialization.Encoding.PEM))
    (site/'key.pem').write_bytes(key.private_bytes(serialization.Encoding.PEM,serialization.PrivateFormat.PKCS8,serialization.NoEncryption()))
    (site/'key.pem').chmod(0o600)
    (site/'router.php').write_text("<?php $_SERVER['HTTPS']='on'; $file='/test-site/public'.parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH); if (is_file($file)) require $file; else {http_response_code(404);}")
    with socket.socket() as listener:listener.bind(('127.0.0.1',0));port=listener.getsockname()[1]
    docker=['docker','run','--rm','--network','host','--user',f'{os.getuid()}:{os.getgid()}',
            '-v',str(ROOT)+':/app:ro','-v',t+':/test-site']
    server=subprocess.Popen(docker+['--name',name,IMAGE,'php','-S',f'127.0.0.1:{port}','-t','/test-site/public','/test-site/router.php'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    class Proxy(BaseHTTPRequestHandler):
        def do_GET(self):
            with httpx.Client(trust_env=False) as client:r=client.get(f'http://127.0.0.1:{port}'+self.path)
            self.send_response(r.status_code);self.send_header('Content-Type',r.headers.get('Content-Type','text/plain'));self.end_headers();self.wfile.write(r.content)
        def log_message(self,*args):pass
    https=ThreadingHTTPServer(('127.0.0.1',0),Proxy)
    context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(site/'ca.pem',site/'key.pem')
    https.socket=context.wrap_socket(https.socket,server_side=True)
    thread=threading.Thread(target=https.serve_forever,daemon=True);thread.start()
    try:
        for _ in range(100):
            try:
                if httpx.get(f'http://127.0.0.1:{port}/index.php',trust_env=False).status_code==200:break
            except httpx.TransportError:pass
            time.sleep(.1)
        else:raise RuntimeError('Probe PHP server did not start')
        command=docker+['lavka-php-dev','php','php/bin/check_web.php','--webroot','/test-site/public',
            '--url',f'https://127.0.0.1:{https.server_port}','--ca','/test-site/ca.pem']
        result=subprocess.run(command,capture_output=True,text=True,check=True)
        report=json.loads(result.stdout)
        assert report['compatible'] and report['php'].startswith('7.3.') and report['origin_https']
        assert report['temporary_file_removed'] and report['application_readable']
        assert not list(public.glob('lavka-check-*.php'))
        # A TLS failure must also remove the probe, without changing site files.
        bad=command.copy();bad[bad.index('--ca')+1]='/app/php/README.md'
        assert subprocess.run(bad,capture_output=True).returncode==1
        assert not list(public.glob('lavka-check-*.php'))
        assert (public/'index.php').read_bytes()==original
        print(json.dumps({'web_php73_probe_via_cli82':'passed','verified_https':'passed','cleanup_on_success_and_tls_failure':'passed','existing_test_store':'preserved'}))
    finally:
        https.shutdown();https.server_close();thread.join(timeout=5)
        subprocess.run(['docker','rm','-f',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        server.wait(timeout=15)
