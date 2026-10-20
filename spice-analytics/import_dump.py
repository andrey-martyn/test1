"""Read phpMyAdmin INSERT literals. Never execute uploaded SQL."""
from __future__ import annotations
import argparse
import hashlib
import json
import re
import sqlite3
from datetime import datetime, timezone
from pathlib import Path

TABLES = {
    'oc_order': 'order_id', 'oc_order_product': 'order_product_id',
    'oc_order_option': 'order_option_id', 'oc_order_total': 'order_total_id',
    'oc_order_history': 'order_history_id', 'oc_order_status': None,
}
REQUIRED = {'oc_order', 'oc_order_product', 'oc_order_option', 'oc_order_total', 'oc_order_status'}

def statements(text):
    start, i, quote = 0, 0, None
    while i < len(text):
        c = text[i]
        if quote:
            if c == '\\':
                i += 2
                continue
            if c == quote:
                if i + 1 < len(text) and text[i + 1] == quote:
                    i += 2
                    continue
                quote = None
        elif c in "'\"`":
            quote = c
        elif text.startswith('--', i) or c == '#':
            end = text.find('\n', i)
            end = len(text) if end < 0 else end
            # Preserve positions while removing comments outside literals.
            text = text[:i] + ' ' * (end-i) + text[end:]
            i = end
            continue
        elif text.startswith('/*', i):
            end = text.find('*/', i+2)
            if end < 0:
                raise ValueError('Незакрытый комментарий SQL')
            end += 2
            text = text[:i] + ' ' * (end-i) + text[end:]
            i = end
            continue
        elif c == ';':
            yield text[start:i].strip()
            start = i + 1
        i += 1
    if quote:
        raise ValueError('Незакрытая строка SQL')
    if text[start:].strip():
        yield text[start:].strip()

def literal_rows(values):
    i, n = 0, len(values)
    escapes = {'0':'\0', 'n':'\n', 'r':'\r', 't':'\t', 'b':'\b', 'Z':'\x1a'}
    while i < n:
        while i < n and (values[i].isspace() or values[i] == ','):
            i += 1
        if i == n:
            break
        if values[i] != '(':
            raise ValueError('Ожидалась строка VALUES; выражения SQL не поддерживаются')
        i += 1
        row = []
        while True:
            while i < n and values[i].isspace():
                i += 1
            if i >= n:
                raise ValueError('Оборванная строка VALUES')
            if values[i] == "'":
                i += 1
                chars = []
                while i < n:
                    c = values[i]
                    if c == '\\':
                        i += 1
                        if i >= n:
                            raise ValueError('Оборванная escape-последовательность')
                        chars.append(escapes.get(values[i], values[i]))
                    elif c == "'":
                        if i + 1 < n and values[i+1] == "'":
                            chars.append("'")
                            i += 1
                        else:
                            i += 1
                            break
                    else:
                        chars.append(c)
                    i += 1
                else:
                    raise ValueError('Оборванная строка')
                value = ''.join(chars)
            else:
                j = i
                while i < n and values[i] not in ',)':
                    i += 1
                token = values[j:i].strip()
                if token.upper() == 'NULL':
                    value = None
                elif re.fullmatch(r'[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?', token):
                    value = token
                else:
                    raise ValueError('Разрешены только строковые, числовые и NULL-значения')
            row.append(value)
            while i < n and values[i].isspace():
                i += 1
            if i >= n:
                raise ValueError('Оборванная строка VALUES')
            if values[i] == ')':
                i += 1
                break
            if values[i] != ',':
                raise ValueError('Ожидалась запятая')
            i += 1
        yield row

def import_dump(path: Path, database: Path):
    raw = path.read_bytes()
    text = raw.decode('utf-8-sig')
    rows = {t: {} for t in TABLES}
    seen = set()
    duplicate_rows = 0
    for stmt in statements(text):
        created = re.match(r'CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`(\w+)`', stmt, re.I)
        if created and created[1] in TABLES:
            seen.add(created[1])
        m = re.match(r'INSERT\s+(?:IGNORE\s+)?INTO\s+`(\w+)`\s*\((.*?)\)\s*VALUES\s*(.*)\Z', stmt, re.I | re.S)
        if not m or m[1] not in TABLES:
            continue
        table = m[1]
        seen.add(table)
        columns = re.findall(r'`(\w+)`', m[2])
        if not columns or len(columns) != len(set(columns)):
            raise ValueError('Некорректные имена колонок')
        for values in literal_rows(m[3]):
            if len(values) != len(columns):
                raise ValueError(f'Несовпадение числа колонок: {table}')
            record = dict(zip(columns, values))
            pk = TABLES[table]
            if pk and pk not in record:
                raise ValueError(f'Нет ключа {pk}')
            key = str(record[pk]) if pk else f"{record['order_status_id']}:{record['language_id']}"
            if key in rows[table]:
                duplicate_rows += 1
            rows[table][key] = record
    if missing := REQUIRED - seen:
        raise ValueError('Нет обязательных таблиц: ' + ', '.join(sorted(missing)))
    if not rows['oc_order'] or not rows['oc_order_product']:
        raise ValueError('Нет записей заказов или товаров. Нужен экспорт с данными.')
    order_ids = set(rows['oc_order'])
    orphaned = sum(str(r['order_id']) not in order_ids for t in ('oc_order_product','oc_order_option','oc_order_total','oc_order_history') for r in rows[t].values())
    if orphaned:
        raise ValueError(f'Неполная выгрузка: {orphaned} записей ссылаются на отсутствующие заказы')
    product_ids = set(rows['oc_order_product'])
    if any(str(r['order_product_id']) not in product_ids for r in rows['oc_order_option'].values()):
        raise ValueError('Есть опции без соответствующих позиций заказа')
    database.parent.mkdir(parents=True, exist_ok=True)
    con = sqlite3.connect(database)
    with con:
        con.execute('CREATE TABLE IF NOT EXISTS raw_records (table_name TEXT, record_id TEXT, payload TEXT, PRIMARY KEY(table_name, record_id))')
        con.execute('CREATE TABLE IF NOT EXISTS imports (id INTEGER PRIMARY KEY, imported_at TEXT, digest TEXT, summary TEXT)')
        con.execute('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)')
        con.execute('CREATE TABLE IF NOT EXISTS manual_links (a TEXT, b TEXT, created_at TEXT, PRIMARY KEY(a,b))')
        con.execute('DELETE FROM raw_records')
        for table, records in rows.items():
            con.executemany('INSERT INTO raw_records VALUES (?,?,?)', [(table, key, json.dumps(r, ensure_ascii=False)) for key,r in records.items()])
        summary = {'counts':{t:len(v) for t,v in rows.items()}, 'duplicate_rows':duplicate_rows}
        con.execute('INSERT INTO imports(imported_at,digest,summary) VALUES (?,?,?)', (datetime.now(timezone.utc).isoformat(),hashlib.sha256(raw).hexdigest(),json.dumps(summary)))
    con.close()
    database.chmod(0o600)
    return summary

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Импорт полной SQL-выгрузки ocStore; SQL не выполняется')
    parser.add_argument('dump', type=Path)
    parser.add_argument('--database', type=Path, default=Path(__file__).parent/'data'/'analytics.sqlite3')
    args = parser.parse_args()
    print(json.dumps(import_dump(args.dump, args.database), ensure_ascii=False, indent=2))
