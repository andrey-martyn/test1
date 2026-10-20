"""Private, consistent SQLite backups including CRM; never write into a web root."""
from datetime import datetime, timezone
import argparse
import os
from pathlib import Path
import shutil
import sqlite3
import tempfile

from auth import private_directory


def backup(data, destination):
    data, destination = data.resolve(), destination.resolve()
    if destination == data or destination.is_relative_to(data):
        raise ValueError('Каталог копий должен находиться отдельно от рабочей базы')
    private_directory(destination)
    moment = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    temporary = Path(tempfile.mkdtemp(prefix='.backup-', dir=destination))
    try:
        source = data/'analytics.sqlite3'
        if not source.exists():
            raise ValueError('Нет базы для резервного копирования')
        with sqlite3.connect(source) as original, sqlite3.connect(temporary/'analytics.sqlite3') as copy:
            original.backup(copy)
            if copy.execute('PRAGMA quick_check').fetchone()[0] != 'ok':
                raise ValueError('Проверка резервной копии не пройдена')
        (temporary/'analytics.sqlite3').chmod(0o600)
        for filename in ('access.json', 'source.json', 'deployment.json'):
            if (data/filename).exists():
                shutil.copy2(data/filename, temporary/filename)
                (temporary/filename).chmod(0o600)
        completed = destination/moment
        os.replace(temporary, completed)
        return completed
    finally:
        if temporary.exists():
            shutil.rmtree(temporary)


if __name__ == '__main__':
    parser=argparse.ArgumentParser(description='Закрытая резервная копия аналитики, настроек и CRM')
    parser.add_argument('--data',required=True,type=Path)
    parser.add_argument('--destination',required=True,type=Path)
    args=parser.parse_args()
    try:
        backup(args.data,args.destination)
    except ValueError as exc:
        parser.exit(1,str(exc)+'\n')
    print('Резервная копия создана и проверена. Храните её вне публичных каталогов.')
