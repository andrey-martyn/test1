#!/usr/bin/env bash
set -eu
umask 077
ANALYTICS_SOURCE_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
cd "$ANALYTICS_SOURCE_DIR"
ANALYTICS_PYTHON="${ANALYTICS_PYTHON:-/opt/alt/python312/bin/python3.12}"
"$ANALYTICS_PYTHON" -c 'import sys,sqlite3,ssl; assert sys.version_info >= (3,11), "Python 3.11+ required"; print("Python, SQLite and TLS ready")'
if [ ! -d .venv ]; then
    "$ANALYTICS_PYTHON" -m venv .venv
fi
.venv/bin/python -m pip install --disable-pip-version-check --no-cache-dir -r requirements.txt
.venv/bin/python -c 'import fastapi, uvicorn, pymysql, maxminddb, cryptography; print("Dependencies ready")'
printf '%s\n' 'Зависимости установлены. Далее настройте отдельный каталог поддомена, пароль и пользователя БД по DEPLOYMENT.md.'
