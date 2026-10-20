"""Persistent password sessions. No password, cookie or IP appears in logs."""
from __future__ import annotations
import hashlib
import hmac
import json
import os
from pathlib import Path
import secrets
import sqlite3
import tempfile
import time

LIFETIME = 12 * 3600


def private_directory(directory):
    directory = Path(directory)
    directory.mkdir(parents=True, exist_ok=True, mode=0o700)
    directory.chmod(0o700)
    return directory


def set_password(path, password):
    if not 12 <= len(password) <= 512:
        raise ValueError('Пароль должен содержать от 12 до 512 символов')
    path = Path(path)
    private_directory(path.parent)
    salt = secrets.token_bytes(16)
    payload = {'salt': salt.hex(), 'hash': hashlib.scrypt(
        password.encode(), salt=salt, n=16384, r=8, p=1).hex(),
        'secret': secrets.token_hex(32)}
    fd, temporary = tempfile.mkstemp(dir=path.parent, prefix='.access-')
    try:
        with os.fdopen(fd, 'w') as f:
            json.dump(payload, f)
            f.flush()
            os.fsync(f.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


class Auth:
    def __init__(self, directory, password_file):
        self.directory = private_directory(directory)
        self.password_file = Path(password_file)
        self.database = self.directory / 'sessions.sqlite3'

    def connect(self):
        con = sqlite3.connect(self.database, timeout=15)
        self.database.chmod(0o600)
        con.execute('CREATE TABLE IF NOT EXISTS sessions (token TEXT PRIMARY KEY, expires REAL, revision TEXT)')
        con.execute('CREATE TABLE IF NOT EXISTS attempts (client TEXT, moment REAL)')
        con.execute('CREATE INDEX IF NOT EXISTS attempts_moment ON attempts(moment)')
        return con

    def revision(self):
        return hashlib.sha256(self.password_file.read_bytes()).hexdigest()

    def check_password(self, password):
        if not self.password_file.exists():
            return False
        payload = json.loads(self.password_file.read_text())
        hashed = hashlib.scrypt(password.encode(), salt=bytes.fromhex(payload['salt']),
                                n=16384, r=8, p=1).hex()
        return hmac.compare_digest(hashed, payload['hash'])

    def login(self, password, ip):
        now = time.time()
        # Persist only a keyed pseudonym for rate limiting, never the visitor's IP.
        key = json.loads(self.password_file.read_text())['secret']
        client = hmac.new(key.encode(), ip.encode(), 'sha256').hexdigest()
        con = self.connect()
        try:
            con.execute('BEGIN IMMEDIATE')
            con.execute('DELETE FROM attempts WHERE moment < ?', (now - 300,))
            con.execute('DELETE FROM sessions WHERE expires < ?', (now,))
            recent = con.execute('SELECT count(*) FROM attempts WHERE client=?', (client,)).fetchone()[0]
            total = con.execute('SELECT count(*) FROM attempts').fetchone()[0]
            if recent >= 10 or total >= 200:
                raise PermissionError('rate_limit')
            if not self.check_password(password):
                con.execute('INSERT INTO attempts VALUES (?,?)', (client, now))
                con.commit()
                raise PermissionError('password')
            con.execute('DELETE FROM attempts WHERE client=?', (client,))
            token = secrets.token_urlsafe(32)
            con.execute('INSERT INTO sessions VALUES (?,?,?)',
                        (hashlib.sha256(token.encode()).hexdigest(), now + LIFETIME, self.revision()))
            con.commit()
            return token
        finally:
            con.close()

    def valid(self, token):
        if not token or len(token) > 128 or not self.password_file.exists():
            return False
        con = self.connect()
        try:
            row = con.execute('SELECT expires,revision FROM sessions WHERE token=?',
                              (hashlib.sha256(token.encode()).hexdigest(),)).fetchone()
            return bool(row and row[0] > time.time() and hmac.compare_digest(row[1], self.revision()))
        finally:
            con.close()

    def logout(self, token):
        con = self.connect()
        try:
            with con:
                con.execute('DELETE FROM sessions WHERE token=?', (hashlib.sha256(token.encode()).hexdigest(),))
        finally:
            con.close()
