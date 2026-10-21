"""Reproduce PHP 8.2 CLI / PHP 7.3 web test images with verified artifacts."""
import os
from pathlib import Path
import socket
import ssl
import subprocess
import sys
from urllib.parse import urlsplit

ROOT=Path(__file__).resolve().parents[2]
docker_config=Path('/tmp/lavka-docker-config');docker_config.mkdir(exist_ok=True)
legacy='--php73' in sys.argv
image='lavka-php73-dev' if legacy else 'lavka-php-dev'
dockerfile='php/tests/Dockerfile73' if legacy else 'php/tests/Dockerfile'
ready=subprocess.run(['docker','image','inspect',image],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
if ready.returncode:
    command=['docker','--config',str(docker_config),'build','--network=host','-t',image,'-f',dockerfile]
    proxy_hosts=set()
    for name in ('HTTP_PROXY','HTTPS_PROXY','NO_PROXY'):
        if os.environ.get(name):command.extend(['--build-arg',name])
        if name!='NO_PROXY' and os.environ.get(name):
            hostname=urlsplit(os.environ[name]).hostname
            if hostname and hostname not in proxy_hosts:
                command.extend(['--add-host',hostname+':'+socket.gethostbyname(hostname)]);proxy_hosts.add(hostname)
    ca=ssl.get_default_verify_paths().cafile
    if ca:command.extend(['--secret','id=host_ca,src='+ca])
    command.append('php/tests')
    log=ROOT/('test-results/php73-runtime-build.log' if legacy else 'test-results/php-runtime-build.log');log.parent.mkdir(exist_ok=True)
    with log.open('w') as output:
        result=subprocess.run(command,cwd=ROOT,stdout=output,stderr=subprocess.STDOUT)
    if result.returncode:raise RuntimeError('PHP image build failed; inspect the local build log. TLS verification remains enabled.')
if '--build-only' not in sys.argv:
    subprocess.run(['docker','run','--rm','-v',str(ROOT)+':/app:ro',image,'php','php/bin/preflight.php']+(['--web-only'] if legacy else []),check=True)
