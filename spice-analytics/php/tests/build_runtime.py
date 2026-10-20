"""Reproduce the PHP 8.2 development image without weakening TLS verification."""
import os
from pathlib import Path
import socket
import ssl
import subprocess
from urllib.parse import urlsplit

ROOT=Path(__file__).resolve().parents[2]
docker_config=Path('/tmp/lavka-docker-config');docker_config.mkdir(exist_ok=True)
ready=subprocess.run(['docker','image','inspect','lavka-php-dev'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
if ready.returncode:
    command=['docker','--config',str(docker_config),'build','--network=host','-t','lavka-php-dev','-f','php/tests/Dockerfile']
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
    log=ROOT/'test-results/php-runtime-build.log';log.parent.mkdir(exist_ok=True)
    with log.open('w') as output:
        result=subprocess.run(command,cwd=ROOT,stdout=output,stderr=subprocess.STDOUT)
    if result.returncode:raise RuntimeError('PHP image build failed; inspect the local build log. TLS verification remains enabled.')
subprocess.run(['docker','run','--rm','-v',str(ROOT)+':/app:ro','lavka-php-dev','php','php/bin/preflight.php'],check=True)
