"""Install only a CGI launcher and static files in an EMPTY document root.

Never changes the shop, DNS, TLS configuration, cron, passwords or credentials.
"""
from __future__ import annotations
import argparse
import json
import os
from pathlib import Path
import shutil
import sys
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parent


def prepare(webroot, data, public_url):
    webroot, data = webroot.resolve(), data.resolve()
    parsed = urlsplit(public_url)
    if (parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password
            or parsed.path.strip('/') or parsed.query or parsed.fragment):
        raise ValueError('Нужен HTTPS-адрес поддомена без пути')
    # Application, data and the shop must never be exposed through the public root.
    if data == webroot or data.is_relative_to(webroot) or ROOT == webroot or ROOT.is_relative_to(webroot):
        raise ValueError('Код и данные должны находиться вне публичного каталога')
    if webroot == ROOT or webroot.is_relative_to(ROOT):
        raise ValueError('Используйте отдельный пустой каталог поддомена')
    if webroot.exists() and any(webroot.iterdir()):
        raise ValueError('Публичный каталог не пуст. Существующие файлы не изменены.')
    data.mkdir(parents=True, exist_ok=True, mode=0o700)
    data.chmod(0o700)
    webroot.mkdir(parents=True, exist_ok=True)
    interpreter = Path(sys.executable).absolute()
    launcher = ('#!' + str(interpreter) + '\nimport os,sys\n' +
                'os.environ["ANALYTICS_DATA_DIR"]=' + repr(str(data)) + '\n' +
                'os.environ["ANALYTICS_PUBLIC_URL"]=' + repr(public_url.rstrip('/')) + '\n' +
                'sys.path.insert(0,' + repr(str(ROOT)) + ')\nfrom cgi_entry import main\nmain()\n')
    (webroot / 'analytics.cgi').write_text(launcher)
    (webroot / 'analytics.cgi').chmod(0o700)
    (webroot / '.htaccess').write_text('''Options -Indexes +ExecCGI
AddHandler cgi-script .cgi
DirectoryIndex analytics.cgi
RewriteEngine On
RewriteCond %{HTTPS} !=on
RewriteRule ^ https://''' + parsed.netloc + '''%{REQUEST_URI} [R=302,L,NE]
RewriteRule ^static/ - [L]
RewriteRule ^analytics\\.cgi$ - [L]
RewriteRule ^ analytics.cgi [L,QSA]
''')
    shutil.copytree(ROOT / 'static', webroot / 'static')
    # These are non-secret deployment paths; credentials remain in data/source.json.
    manifest = {'document_root':str(webroot), 'data_dir':str(data), 'public_url':public_url.rstrip('/')}
    (data / 'deployment.json').write_text(json.dumps(manifest, indent=2))
    (data / 'deployment.json').chmod(0o600)
    return manifest


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Подготовка отдельного поддомена на shared hosting')
    parser.add_argument('--webroot', required=True, type=Path)
    parser.add_argument('--data', required=True, type=Path)
    parser.add_argument('--url', required=True)
    args = parser.parse_args()
    try:
        result = prepare(args.webroot, args.data, args.url)
    except ValueError as exc:
        parser.exit(1, str(exc) + '\n')
    print(json.dumps(result, ensure_ascii=False, indent=2))
    print('Файлы подготовлены. Это не подтверждает работу CGI, DNS или HTTPS на хостинге.')
