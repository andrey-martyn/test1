"""Invalidate report caches across independent web workers and CLI imports."""
def bump_revision(connection):
    connection.execute('CREATE TABLE IF NOT EXISTS app_revision (id INTEGER PRIMARY KEY CHECK(id=1), value INTEGER NOT NULL)')
    connection.execute('INSERT OR IGNORE INTO app_revision VALUES (1,0)')
    connection.execute('UPDATE app_revision SET value=value+1 WHERE id=1')


def local_revision(connection):
    if not connection.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='app_revision'").fetchone():
        return 0
    row = connection.execute('SELECT value FROM app_revision WHERE id=1').fetchone()
    return row[0] if row else 0
