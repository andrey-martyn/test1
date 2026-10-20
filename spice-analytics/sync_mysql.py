"""Pull six ocStore tables with SELECT only; atomically replace the private copy.

Full reconciliation also catches child edits that do not bump order.date_modified.
MyISAM is checked with two matching reads; it does not offer transactional snapshots.
"""
from __future__ import annotations
import argparse
from contextlib import contextmanager
from datetime import date, datetime, timezone
from decimal import Decimal
import getpass
import hashlib
import json
import os
from pathlib import Path
import re
import sqlite3
import ssl
import time

from auth import private_directory
from import_dump import TABLES

FIELDS = {
    'oc_order': ('order_id customer_id firstname lastname email telephone total currency_code currency_value '
                 'order_status_id date_added date_modified shipping_city shipping_zone shipping_country '
                 'payment_city payment_zone payment_country shipping_method payment_method ip').split(),
    'oc_order_product': 'order_product_id order_id product_id name model quantity price total'.split(),
    'oc_order_option': 'order_option_id order_id order_product_id name value'.split(),
    'oc_order_total': 'order_total_id order_id code title value sort_order'.split(),
    'oc_order_history': 'order_history_id order_id order_status_id date_added'.split(),
    'oc_order_status': 'order_status_id language_id name'.split(),
}
ROOT = Path(__file__).parent
DEFAULT_DATA = Path(os.environ.get('ANALYTICS_DATA_DIR', str(ROOT / 'data')))


class SyncError(Exception):
    """Fixed, non-sensitive operator messages only."""


def now():
    return datetime.now(timezone.utc).isoformat()


def load_config(path):
    config = json.loads(Path(path).read_text())
    if not isinstance(config, dict):
        raise SyncError('Некорректный файл подключения')
    if not re.fullmatch(r'[A-Za-z0-9_]{0,32}', config.get('prefix', 'oc_')):
        raise SyncError('Недопустимый префикс таблиц')
    if not config.get('database') or not config.get('user') or not config.get('password'):
        raise SyncError('Не заполнены данные отдельного пользователя БД')
    if not config.get('unix_socket') and not config.get('host'):
        raise SyncError('Не указан адрес БД или локальный сокет')
    return config


def connect_source(config):
    import pymysql
    options = {key: config[key] for key in ('host', 'user', 'password', 'database', 'unix_socket') if config.get(key)}
    options.update(port=int(config.get('port', 3306)), charset='utf8mb4', autocommit=True,
                   connect_timeout=10, read_timeout=45, write_timeout=15,
                   cursorclass=pymysql.cursors.DictCursor)
    if config.get('unix_socket'):
        pass
    elif config.get('host') in ('localhost', '127.0.0.1', '::1') and not config.get('ssl_ca'):
        # Local hosting connection or an authenticated SSH tunnel.
        pass
    else:
        # Remote DB transport always verifies both the CA and the server hostname.
        context = ssl.create_default_context(cafile=config.get('ssl_ca') or None)
        options['ssl'] = context
    connection = required_tls_connection(**options) if options.get('ssl') else pymysql.connect(**options)
    if options.get('ssl'):
        with connection.cursor() as cursor:
            cursor.execute("SHOW SESSION STATUS LIKE 'Ssl_cipher'")
            result = cursor.fetchone()
            if not result or not result.get('Value'):
                connection.close()
                raise SyncError('Сервер БД не установил TLS. Нужен локальный сокет или SSH-туннель.')
    return connection


def required_tls_connection(**options):
    import pymysql
    from pymysql.constants import CLIENT
    # PyMySQL otherwise permits falling back when the server omits CLIENT_SSL.
    # Fail before authentication instead; dependency version is pinned and tested.
    class RequiredTLSConnection(pymysql.connections.Connection):
        def _request_authentication(self):
            if not self.server_capabilities & CLIENT.SSL:
                raise SyncError('Сервер БД не поддерживает TLS. Нужен локальный сокет или SSH-туннель.')
            return super()._request_authentication()
    return RequiredTLSConnection(**options)


def scalar(value):
    if value is None:
        return ''
    if isinstance(value, datetime):
        return value.strftime('%Y-%m-%d %H:%M:%S')
    if isinstance(value, date):
        return value.isoformat()
    if isinstance(value, bytes):
        return value.decode('utf-8')
    return str(value)


def read_snapshot(connection, prefix):
    rows = {table: {} for table in TABLES}
    count = 0
    with connection.cursor() as cursor:
        for table, fields in FIELDS.items():
            physical = prefix + table.removeprefix('oc_')
            pk = TABLES[table]
            ordering = f'`{pk}`' if pk else '`order_status_id`,`language_id`'
            # Identifiers come only from FIELDS and a validated prefix, never a web request.
            cursor.execute('SELECT ' + ','.join('`' + field + '`' for field in fields) +
                           f' FROM `{physical}` ORDER BY {ordering}')
            for source in cursor.fetchall():
                record = {field: scalar(source[field]) for field in fields}
                key = record[pk] if pk else record['order_status_id'] + ':' + record['language_id']
                if not key or key in rows[table]:
                    raise SyncError('Некорректные ключи исходных записей')
                rows[table][key] = record
                count += 1
                if count > 2_000_000:
                    raise SyncError('Объём превысил лимит синхронизации; требуется отдельная настройка')
    return rows


def digest_rows(rows):
    return hashlib.sha256(json.dumps(rows, ensure_ascii=False, sort_keys=True,
                                     separators=(',', ':')).encode()).hexdigest()


def validate(rows):
    if set(rows) != set(TABLES) or not rows['oc_order'] or not rows['oc_order_product'] or not rows['oc_order_status']:
        raise SyncError('Неполный снимок: отсутствуют заказы, товары или статусы')
    orders, products = rows['oc_order'], rows['oc_order_product']
    for table in ('oc_order_product', 'oc_order_option', 'oc_order_total', 'oc_order_history'):
        if any(record['order_id'] not in orders for record in rows[table].values()):
            raise SyncError('Снимок изменился во время чтения: найдены записи без заказа')
    if any(record['order_product_id'] not in products or
           products[record['order_product_id']]['order_id'] != record['order_id']
           for record in rows['oc_order_option'].values()):
        raise SyncError('Снимок изменился во время чтения: найдены опции без товара')
    products_by_order = {record['order_id'] for record in products.values()}
    for order in orders.values():
        if not re.fullmatch(r'\d+', order['order_id']):
            raise SyncError('Некорректный номер заказа')
        try:
            date.fromisoformat(order['date_added'][:10])
            values = [Decimal(order[key]) for key in ('total', 'currency_value')]
            if not all(value.is_finite() for value in values) or values[1] <= 0:
                raise ValueError()
        except Exception:
            raise SyncError('Некорректная дата, сумма или курс заказа') from None
        if order['order_status_id'] != '0' and order['order_id'] not in products_by_order:
            raise SyncError('Оформленный заказ без товаров: снимок отложен для повторной проверки')


def stable_snapshot(connection, config, delay=1):
    prefix = config.get('prefix', 'oc_')
    physical = [prefix + table.removeprefix('oc_') for table in TABLES]
    with connection.cursor() as cursor:
        cursor.execute('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s '
                       'AND TABLE_NAME IN (' + ','.join(['%s'] * len(physical)) + ')',
                       [config['database'], *physical])
        engines = {record['TABLE_NAME']: record['ENGINE'] for record in cursor.fetchall()}
        if set(engines) != set(physical):
            raise SyncError('Не найдены шесть таблиц магазина или нет прав SELECT')
        transactional = all(engine.casefold() == 'innodb' for engine in engines.values())
        if transactional:
            cursor.execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')
            cursor.execute('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY')
    if transactional:
        try:
            rows = read_snapshot(connection, prefix)
            validate(rows)
            return rows, 'innodb_snapshot'
        finally:
            connection.rollback()
    # MyISAM has no snapshot isolation. No LOCK TABLES, DML or store-side writes.
    for _ in range(3):
        first = read_snapshot(connection, prefix)
        time.sleep(delay)
        second = read_snapshot(connection, prefix)
        if digest_rows(first) == digest_rows(second):
            validate(second)
            return second, 'verified_two_reads'
    raise SyncError('Заказы менялись во время всех проверок. Сохранена предыдущая копия.')


def status_connection(database):
    private_directory(database.parent)
    con = sqlite3.connect(database, timeout=30)
    database.chmod(0o600)
    con.execute('CREATE TABLE IF NOT EXISTS sync_status (id INTEGER PRIMARY KEY CHECK(id=1), payload TEXT)')
    con.commit()
    return con


def sync_status(database):
    if not Path(database).exists():
        return {'configured': False}
    with sqlite3.connect(database, timeout=15) as con:
        try:
            row = con.execute('SELECT payload FROM sync_status WHERE id=1').fetchone()
            if not row:
                return {'configured': False}
            state = json.loads(row[0])
            last = state.get('last_success')
            state['stale'] = not last or (datetime.now(timezone.utc) - datetime.fromisoformat(last)).total_seconds() > 900
            return {'configured': True, **state}
        except sqlite3.OperationalError:
            return {'configured': False}


def record_status(database, **updates):
    con = status_connection(database)
    try:
        with con:
            row = con.execute('SELECT payload FROM sync_status WHERE id=1').fetchone()
            state = json.loads(row[0]) if row else {}
            state.update(updates)
            con.execute('INSERT OR REPLACE INTO sync_status VALUES (1,?)', (json.dumps(state),))
    finally:
        con.close()


def apply_snapshot(database, rows, consistency):
    validate(rows)
    digest = digest_rows(rows)
    con = status_connection(database)
    try:
        con.execute('PRAGMA journal_mode=WAL')
        con.execute('BEGIN IMMEDIATE')
        con.execute('CREATE TABLE IF NOT EXISTS raw_records (table_name TEXT, record_id TEXT, payload TEXT, PRIMARY KEY(table_name,record_id))')
        con.execute('CREATE TABLE IF NOT EXISTS imports (id INTEGER PRIMARY KEY, imported_at TEXT, digest TEXT, summary TEXT)')
        con.execute('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)')
        con.execute('CREATE TABLE IF NOT EXISTS manual_links (a TEXT, b TEXT, created_at TEXT, PRIMARY KEY(a,b))')
        previous = con.execute('SELECT digest FROM imports ORDER BY id DESC LIMIT 1').fetchone()
        changed = not previous or previous[0] != digest
        summary = {'source':'mysql', 'consistency':consistency,
                   'counts':{table:len(records) for table,records in rows.items()}, 'duplicate_rows':0}
        if changed:
            con.execute('DELETE FROM raw_records')
            for table, records in rows.items():
                con.executemany('INSERT INTO raw_records VALUES (?,?,?)',
                                ((table, key, json.dumps(record, ensure_ascii=False)) for key,record in records.items()))
            con.execute('INSERT INTO imports(imported_at,digest,summary) VALUES (?,?,?)',
                        (now(), digest, json.dumps(summary)))
        # The import and success marker commit together. CRM/settings/GA4 are untouched.
        state = {'state':'ok', 'last_success':now(), 'checked_at':now(),
                 'changed':changed, 'consistency':consistency, 'orders':len(rows['oc_order'])}
        con.execute('INSERT OR REPLACE INTO sync_status VALUES (1,?)', (json.dumps(state),))
        con.commit()
        return state
    except Exception:
        con.rollback()
        raise
    finally:
        con.close()


@contextmanager
def sync_lock(database):
    import fcntl
    private_directory(database.parent)
    with (database.parent / '.sync.lock').open('a') as handle:
        try:
            fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise SyncError('Синхронизация уже выполняется') from None
        yield


def run(config_path, database):
    with sync_lock(database):
        record_status(database, state='running', checked_at=now())
        try:
            config = load_config(config_path)
            connection = connect_source(config)
            try:
                rows, consistency = stable_snapshot(connection, config)
            finally:
                connection.close()
            return apply_snapshot(database, rows, consistency)
        except Exception as exc:
            message = str(exc) if isinstance(exc, SyncError) else 'Ошибка подключения или чтения БД. Проверьте права, TLS и параметры подключения.'
            record_status(database, state='error', checked_at=now(), error=message)
            raise SyncError(message) from None


def configure(path):
    if path.exists():
        raise SyncError('Файл подключения уже существует; отредактируйте его через защищённый доступ')
    print('Отдельный пользователь магазина: только SELECT на шесть таблиц. Пароль вводится скрыто.')
    config = {'host':input('Адрес MySQL (для локального сокета оставьте пустым): ').strip(),
              'port':int(input('Порт [3306]: ').strip() or '3306'),
              'unix_socket':input('Локальный сокет (если используется): ').strip(),
              'database':input('Имя базы магазина: ').strip(), 'prefix':input('Префикс [oc_]: ').strip() or 'oc_',
              'user':input('Отдельный пользователь SELECT: ').strip(), 'password':getpass.getpass('Пароль этого пользователя: '),
              'ssl_ca':input('Путь CA для удалённой БД (пусто = системные CA): ').strip()}
    private_directory(path.parent)
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'w') as f:
        json.dump(config, f, ensure_ascii=False, indent=2)
    load_config(path)
    print('Параметры сохранены в закрытом файле. Значения не записываются в журнал.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Синхронизация ocStore только на чтение')
    parser.add_argument('--config', type=Path, default=DEFAULT_DATA / 'source.json')
    parser.add_argument('--database', type=Path, default=DEFAULT_DATA / 'analytics.sqlite3')
    parser.add_argument('--configure', action='store_true')
    args = parser.parse_args()
    try:
        if args.configure:
            configure(args.config)
        else:
            print(json.dumps(run(args.config, args.database), ensure_ascii=False))
    except SyncError as exc:
        parser.exit(1, str(exc) + '\n')
