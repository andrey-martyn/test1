#!/usr/bin/env python3
"""Relocate existing TEST modules, never the working ocStore or its MySQL.

Python 3.6+, standard library only. --plan is read only. Run after stopping
module writers/cron, then --apply --writers-stopped. No setup, sync or dispatch.
"""
import argparse
import base64
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import signal
import stat
import subprocess
import sys


HOSTING_ROOT = Path('/home/lavkasp/lavka-speciy.com')
MOVES = (
    ('analytics-app', 'apps/lavka-modules'),
    ('analytics-private', 'private/analytics/previous'),
    ('analytics-private-test', 'private/analytics/test'),
    ('review-factory-private-test', 'private/review-factory/test'),
    ('analytics-backups', 'backups/analytics'),
    ('test_frontend_backups', 'backups/frontend/test'),
)
OPTIONAL_MOVES = (('review-harvester-private', 'private/exports/review-harvester'),)
CONFIGS = (
    'analytics-private/deployment-php.json',
    'analytics-private-test/deployment-php.json',
    'analytics-private-test/sandbox.json',
    'review-factory-private-test/instance.json',
    'test/analytics/index.php',
    'test/review-workshop/index.php',
    'test/system/library/review_factory_demo_config.php',
    'analytics-private-test/before-sandbox-launcher.php',
)
PUBLIC = CONFIGS[4:7]
MAINTENANCE = b"<?php\nhttp_response_code(503); header('Cache-Control: no-store'); exit('TEST modules: directory relocation in progress.');\n"


class Stop(Exception):
    pass


def require(condition, code):
    if not condition:
        raise Stop(code)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def file_sha(path):
    h = hashlib.sha256()
    with path.open('rb') as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b''):
            h.update(chunk)
    return h.hexdigest()


def metadata(path, content=True):
    s = path.lstat()
    require(not stat.S_ISLNK(s.st_mode), 'symlink_refused')
    result = {'mode': stat.S_IMODE(s.st_mode), 'uid': s.st_uid, 'gid': s.st_gid}
    if path.is_file():
        result.update({'size': s.st_size, 'sha256': file_sha(path) if content else None})
    else:
        require(path.is_dir(), 'special_file_refused')
        result.update({'inode': s.st_ino, 'device': s.st_dev})
    return result


def tree(path):
    result = {}
    require(path.is_dir() and path.resolve() == path, 'private_tree_invalid')
    for parent, dirs, files in os.walk(str(path), followlinks=False):
        for name in sorted(dirs + files):
            item = Path(parent) / name
            record = metadata(item)
            if item.is_dir():
                record.pop('inode', None)
                record.pop('device', None)
            result[str(item.relative_to(path))] = record
    return result


def atomic(path, data, mode=0o600, ownership=None):
    require(path.resolve() == path and not path.is_symlink(), 'write_path_invalid')
    temporary = path.with_name(path.name + '.relocation-tmp')
    require(not temporary.exists() and not temporary.is_symlink(), 'temporary_collision')
    try:
        fd = os.open(str(temporary), os.O_WRONLY | os.O_CREAT | os.O_EXCL, mode)
        with os.fdopen(fd, 'wb') as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(str(temporary), mode)
        if ownership:
            os.chown(str(temporary), ownership['uid'], ownership['gid'])
        os.replace(str(temporary), str(path))
    finally:
        if temporary.is_file() and not temporary.is_symlink():
            temporary.unlink()


def json_bytes(value):
    return (json.dumps(value, ensure_ascii=False, indent=2) + '\n').encode('utf-8')


def frontend_repository(root):
    legacy = root.parent / 'lavka-frontend-work'
    organized = root / 'apps/lavka-frontend'
    for path in (legacy, organized):
        require(path.resolve() == path and (not path.exists() or path.is_dir()), 'frontend_path_redirected')
    require(not (legacy.exists() and organized.exists()), 'frontend_repository_conflict')
    return organized if organized.exists() else legacy


class Relocation:
    def __init__(self, root, checkpointer=None):
        self.root = Path(root)
        require(self.root.is_absolute() and self.root.resolve() == self.root and self.root.is_dir(), 'root_invalid')
        self.repository = self.root / 'analytics-app'
        self.frontend = frontend_repository(self.root)
        self.ops = self.root / 'ops'
        self.handles = []
        # Test-only dependency injection; the CLI always uses installed PHP 8.2
        # SQLite, rather than assuming the hosting's Python SQLite is recent.
        self.checkpointer = checkpointer

    def mapped(self, relative):
        for old, new in MOVES + OPTIONAL_MOVES:
            if relative == old or relative.startswith(old + '/'):
                return new + relative[len(old):]
        return relative

    def safe(self, relative):
        path = self.root / relative
        require(path.resolve() == path and (path == self.root or self.root in path.parents), 'path_redirected')
        return path

    def protected(self):
        result = {}
        for directory in ('www', 'lavka_storage', 'test', 'test_storage', 'analytics'):
            path = self.safe(directory)
            require(path.is_dir(), 'protected_directory_missing')
            result[directory] = metadata(path, False)
        for prefix in ('www', 'test'):
            for suffix in ('config.php', 'admin/config.php', 'index.php', '.htaccess'):
                relative = prefix + '/' + suffix
                path = self.safe(relative)
                require(path.is_file(), 'protected_shop_file_missing')
                result[relative] = metadata(path)
        return result

    def private_directory(self, path):
        require(path.resolve() == path, 'parent_redirected')
        if not path.exists():
            self.private_directory(path.parent)
            path.mkdir(mode=0o700)
        require(path.is_dir(), 'parent_not_directory')

    def replacements(self):
        return sorted([(str(self.root / a), str(self.root / b)) for a, b in MOVES], key=lambda pair: -len(pair[0]))

    def actual_data(self, name):
        old, new = self.safe(name), self.safe(self.mapped(name))
        require(old.exists() != new.exists(), 'private_data_path_conflict')
        return old if old.exists() else new

    def json_config(self, relative, content):
        value = json.loads(content.decode('utf-8'))
        require(isinstance(value, dict), 'config_not_object')
        oldrepo = str(self.repository / 'spice-analytics/php')
        shop = str(self.root / 'test')
        url = 'https://test.lavka-speciy.com/analytics'
        if relative.endswith('/deployment-php.json'):
            expected = {'application_root': oldrepo, 'data_dir': str((self.root / relative).parent),
                        'document_root': shop + '/analytics', 'shop_root': shop, 'public_url': url}
            changed = ('application_root', 'data_dir')
        elif relative.endswith('/sandbox.json'):
            expected = {'data_dir': str(self.root / 'analytics-private-test'), 'previous_data_dir': str(self.root / 'analytics-private'),
                        'shop_root': shop, 'environment': 'sandbox', 'database': 'lavkasp_test'}
            changed = ('data_dir', 'previous_data_dir')
        else:
            expected = {'environment': 'test', 'shop_root': shop, 'webroot': shop + '/review-workshop',
                        'analytics_data': str(self.root / 'analytics-private-test'),
                        'public_url': 'https://test.lavka-speciy.com/review-workshop'}
            changed = ('analytics_data',)
            require(set(value.get('public_roots', [])) == {shop, str(self.root / 'www'), str(self.root / 'analytics')}, 'review_public_roots_unexpected')
        require(all(value.get(k) == v for k, v in expected.items()), 'existing_configuration_mismatch')
        for key in changed:
            old = value[key]
            for before, after in self.replacements():
                if old == before or old.startswith(before + '/'):
                    value[key] = after + old[len(before):]
                    break
        # Unknown path-bearing fields must be reviewed, never globally rewritten.
        def inspect(item):
            if isinstance(item, str):
                require(not any(item == a or item.startswith(a + '/') for a, _ in self.replacements()), 'unmapped_active_config_path')
            elif isinstance(item, dict):
                for child in item.values():
                    inspect(child)
            elif isinstance(item, list):
                for child in item:
                    inspect(child)
        inspect(value)
        return json_bytes(value)

    def php_config(self, relative, content):
        text = content.decode('utf-8')
        repo = str(self.repository)
        data = str(self.root / 'analytics-private-test')
        review = str(self.root / 'review-factory-private-test')
        if relative == PUBLIC[0] or relative == 'analytics-private-test/before-sandbox-launcher.php':
            require('ANALYTICS_DATA_DIR' in text and 'ANALYTICS_PUBLIC_URL' in text and 'require ' in text, 'analytics_launcher_unknown')
            source = str(self.root / 'analytics-private') if relative.endswith('/before-sandbox-launcher.php') else data
            expected = (source, repo + '/spice-analytics/php/public/index.php')
        elif relative == PUBLIC[1]:
            require('\\ReviewFactory\\run(' in text and 'require_once ' in text, 'review_launcher_unknown')
            expected = (review, repo + '/review-factory/php/src/App.php')
        else:
            require('ReviewFactory TEST demo' in text and 'return ' in text, 'demo_config_unknown')
            expected = (review, repo + '/review-factory/php/src/Demo.php')
        for old in expected:
            require(text.count("'" + old + "'") == 1, 'php_path_not_unique_literal')
            new = old
            for before, after in self.replacements():
                if old == before or old.startswith(before + '/'):
                    new = after + old[len(before):]
                    break
            text = text.replace("'" + old + "'", "'" + new + "'")
        require(not any(before in text for before, _ in self.replacements()), 'unmapped_php_path')
        return text.encode('utf-8')

    def plan(self):
        require((self.repository / '.git').is_dir(), 'existing_git_clone_missing')
        require('organized' in (self.repository / 'spice-analytics/php/src/HostingLayout.php').read_text(), 'analytics_layout_support_missing')
        if self.checkpointer is None:
            require(Path('/usr/local/php82/bin/php').is_file(), 'php82_cli_missing')
            require((self.repository / 'ops/relocation_sqlite.php').is_file(), 'sqlite_helper_missing')
        require(self.frontend.resolve() == self.frontend and self.frontend.is_dir(), 'frontend_clone_missing')
        frontend_code = self.frontend / 'ops/test_store.py'
        require(frontend_code.resolve() == frontend_code and frontend_code.is_file(), 'frontend_tools_missing')
        require('Hosting layout v1: two closed backup roots' in frontend_code.read_text(), 'update_frontend_branch_before_relocation')
        moves = []
        for old, new in MOVES:
            source, target = self.safe(old), self.safe(new)
            require(source.is_dir() and not target.exists(), 'source_missing_or_destination_occupied')
            nearest = target.parent
            while not nearest.exists():
                nearest = nearest.parent
            require(source.stat().st_dev == nearest.stat().st_dev, 'cross_device_move_refused')
            moves.append({'source': old, 'target': new, 'metadata': metadata(source, False)})
        for old, new in OPTIONAL_MOVES:
            source, target = self.safe(old), self.safe(new)
            if source.exists():
                require(source.is_dir() and not target.exists(), 'retired_export_path_conflict')
                require(sorted(p.name for p in source.iterdir()) == ['confirmed-product-pages.json'], 'retired_export_has_unknown_files')
                require(self.safe(old + '/confirmed-product-pages.json').is_file(), 'retired_export_not_file')
                moves.append({'source': old, 'target': new, 'metadata': metadata(source, False)})
        configs = {}
        for relative in CONFIGS:
            path = self.safe(relative)
            if relative in (PUBLIC[2], 'analytics-private-test/before-sandbox-launcher.php') and not path.exists():
                continue
            require(path.is_file() and path.stat().st_nlink == 1 and path.stat().st_uid == os.getuid(), 'existing_config_invalid')
            require(path.stat().st_size < 128 * 1024, 'configuration_too_large')
            before = path.read_bytes()
            after = self.json_config(relative, before) if relative.endswith('.json') else self.php_config(relative, before)
            configs[relative] = {'metadata': metadata(path), 'before': base64.b64encode(before).decode(),
                                 'after': base64.b64encode(after).decode(), 'after_sha256': sha(after)}
        private_bytes = 0
        for directory in ('analytics-private', 'analytics-private-test', 'review-factory-private-test'):
            path = self.safe(directory)
            for record in tree(path).values():
                private_bytes += record.get('size', 0)
        require(shutil.disk_usage(str(self.root)).free > private_bytes * 1.2 + 32 * 1024 * 1024, 'insufficient_space_for_private_safety_copy')
        for directory, filename in (('analytics-private', 'analytics.sqlite3'), ('analytics-private-test', 'analytics.sqlite3'),
                                    ('analytics-private-test', 'competitors.sqlite3'), ('review-factory-private-test', 'factory.sqlite3')):
            require((self.root / directory / filename).is_file(), 'existing_database_missing')
        if PUBLIC[2] in configs:
            require((self.root / 'review-factory-private-test/synthetic-reviews.sqlite3').is_file(), 'installed_demo_data_missing')
        protected = self.protected()
        return {'schema': 1, 'state': 'planned', 'root': str(self.root), 'moves': moves, 'configs': configs,
                'protected': protected, 'private_bytes': private_bytes,
                'frontend_tool_sha256': file_sha(frontend_code)}

    def summary(self, value):
        return {'state': value['state'], 'moves': [{'from': m['source'], 'to': m['target']} for m in value['moves']],
                'changed_config_files': list(value['configs']), 'private_safety_copy_bytes': value['private_bytes'],
                'working_shop': 'protected: www and lavka_storage stay in place',
                'mysql_connections': 0, 'password_resets': 0,
                'cron': 'preserve schedules; replace code/data paths in ADM.TOOLS before resuming module jobs'}

    def write_journal(self, directory, value):
        atomic(directory / 'journal.json', json_bytes(value))

    def lock_writers(self):
        for directory in ('analytics-private', 'analytics-private-test', 'review-factory-private-test'):
            for path in sorted(self.actual_data(directory).iterdir()):
                if path.name.endswith('.lock') and path.is_file():
                    require(not path.is_symlink(), 'writer_lock_redirected')
                    handle = path.open('r+b')
                    try:
                        fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    except OSError:
                        handle.close()
                        raise Stop('module_worker_still_running')
                    self.handles.append(handle)

    def release(self):
        for handle in reversed(self.handles):
            handle.close()
        self.handles = []

    def gate(self, value):
        for relative in PUBLIC:
            if relative not in value['configs']:
                continue
            record = value['configs'][relative]
            content = b'<?php\n// ReviewFactory TEST demo: relocation\nreturn [];\n' if relative == PUBLIC[2] else MAINTENANCE
            atomic(self.safe(relative), content, record['metadata']['mode'], record['metadata'])

    def checkpoint(self):
        paths = []
        for directory in ('analytics-private', 'analytics-private-test', 'review-factory-private-test'):
            for path in sorted(self.actual_data(directory).glob('*.sqlite3')):
                require(path.resolve() == path and path.is_file(), 'database_path_invalid')
                paths.append(path)
        if self.checkpointer is not None:
            return self.checkpointer(paths)
        repo = self.root / 'analytics-app'
        if not repo.exists():
            repo = self.root / 'apps/lavka-modules'
        result = subprocess.run(['/usr/local/php82/bin/php', '-d', 'display_errors=0', '-d', 'log_errors=0',
                                 str(repo / 'ops/relocation_sqlite.php')], input=json.dumps({'root': str(self.root), 'files': [str(p) for p in paths]}),
                                universal_newlines=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=55)
        reply = json.loads(result.stdout)
        require(result.returncode == 0 and reply.get('state') == 'verified', 'sqlite_checkpoint_or_integrity_failed')
        return reply['fingerprints']

    def unchanged(self, value):
        require(self.protected() == value['protected'], 'protected_shop_files_or_permissions_changed')

    def restore(self, directory, value, completed=False):
        # Gate first. Reverse only our exact moves; keep the newest data, never
        # restore the pre-move SQLite backup over decisions made after cutover.
        self.gate(value)
        for relative, record in value['configs'].items():
            if relative in PUBLIC:
                continue
            old, new = self.safe(relative), self.safe(self.mapped(relative))
            path = new if new.exists() else old
            require(path.is_file(), 'recovery_config_missing')
            atomic(path, base64.b64decode(record['before']), record['metadata']['mode'], record['metadata'])
        for move in reversed(value['moves']):
            source, target = self.safe(move['source']), self.safe(move['target'])
            require(source.exists() != target.exists(), 'recovery_path_conflict')
            if target.exists():
                os.rename(str(target), str(source))
        for relative in PUBLIC:
            if relative in value['configs']:
                record = value['configs'][relative]
                atomic(self.safe(relative), base64.b64decode(record['before']), record['metadata']['mode'], record['metadata'])
        self.unchanged(value)
        value['state'] = 'rolled_back' if completed else 'failed_rolled_back'
        self.write_journal(directory, value)

    def apply(self, stopped, fault=None):
        require(stopped, 'writers_stopped_flag_required')
        value = self.plan()
        self.private_directory(self.ops)
        require(stat.S_IMODE(self.ops.stat().st_mode) & 0o077 == 0, 'ops_directory_must_be_private')
        handle = self.safe('ops/.relocation.lock').open('a+b')
        try:
            fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            handle.close()
            raise Stop('another_relocation_running')
        self.handles.append(handle)
        directory = self.ops / 'relocations' / datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S.%fZ')
        self.private_directory(directory)
        value['state'] = 'prepared'
        self.write_journal(directory, value)
        gated = False
        try:
            require(self.plan() == {**value, 'state': 'planned'}, 'installation_changed_after_preflight')
            self.lock_writers()
            gated = True
            self.gate(value)
            value['state'] = 'gated'
            self.write_journal(directory, value)
            # Copy whole directories, including all auxiliary files and WAL,
            # before checkpoint. Writers have been stopped, gated and locked.
            safety = directory / 'private-before'
            self.private_directory(safety)
            for name in ('analytics-private', 'analytics-private-test', 'review-factory-private-test'):
                before = tree(self.root / name)
                shutil.copytree(str(self.root / name), str(safety / name), copy_function=shutil.copy2)
                require(tree(safety / name) == before and tree(self.root / name) == before, 'private_safety_copy_not_stable')
            value['sql_fingerprints'] = self.checkpoint()
            baseline = {name: tree(self.root / name) for name in ('analytics-private', 'analytics-private-test', 'review-factory-private-test')}
            value['state'] = 'moving'
            self.write_journal(directory, value)
            for index, move in enumerate(value['moves']):
                source, target = self.safe(move['source']), self.safe(move['target'])
                self.private_directory(target.parent)
                require(not target.exists() and metadata(source, False) == move['metadata'], 'move_source_changed')
                os.rename(str(source), str(target))
                self.write_journal(directory, value)
                if fault:
                    fault('moved', index)
            for name, before in baseline.items():
                require(tree(self.root / self.mapped(name)) == before, 'private_data_changed_during_move')
            for relative, record in value['configs'].items():
                if relative not in PUBLIC:
                    path = self.safe(self.mapped(relative))
                    require(file_sha(path) == record['metadata']['sha256'], 'configuration_changed_during_move')
                    atomic(path, base64.b64decode(record['after']), record['metadata']['mode'], record['metadata'])
            # All non-config data files must still be byte-identical.
            for name, before in baseline.items():
                after = tree(self.root / self.mapped(name))
                for relative in value['configs']:
                    if relative.startswith(name + '/'):
                        tail = relative[len(name) + 1:]
                        before.pop(tail, None);after.pop(tail, None)
                require(before == after, 'permanent_data_changed')
            require(file_sha(self.frontend / 'ops/test_store.py') == value['frontend_tool_sha256'], 'frontend_tools_changed')
            self.unchanged(value)
            for relative in PUBLIC:
                if relative in value['configs']:
                    record = value['configs'][relative]
                    atomic(self.safe(relative), base64.b64decode(record['after']), record['metadata']['mode'], record['metadata'])
            self.unchanged(value)
            value['state'] = 'completed'
            self.write_journal(directory, value)
            result = self.summary(value)
            result['journal_id'] = directory.name
            result['protected_shop_files'] = 'unchanged'
            result['permanent_private_data'] = 'logical SQLite fingerprints verified; post-checkpoint files preserved; only enumerated path configs edited'
            result['module_urls'] = ['https://test.lavka-speciy.com/analytics', 'https://test.lavka-speciy.com/review-workshop']
            return result
        except BaseException:
            if gated:
                try:
                    self.restore(directory, value)
                except BaseException:
                    value['state'] = 'recovery_required'
                    self.write_journal(directory, value)
                    raise Stop('recovery_required:' + directory.name)
            raise
        finally:
            self.release()

    def rollback(self, identifier, stopped):
        require(stopped and re.fullmatch(r'[0-9TZ.]+', identifier), 'rollback_arguments_invalid')
        path = self.safe('ops/relocations/' + identifier + '/journal.json')
        require(path.is_file() and path.stat().st_uid == os.getuid() and stat.S_IMODE(path.stat().st_mode) == 0o600, 'private_journal_invalid')
        value = json.loads(path.read_text())
        require(value.get('schema') == 1 and value.get('root') == str(self.root), 'journal_root_mismatch')
        pairs = [(m['source'], m['target']) for m in value['moves']]
        require(pairs == list(MOVES) or pairs == list(MOVES + OPTIONAL_MOVES), 'journal_moves_invalid')
        require(set(value['configs']).issubset(CONFIGS) and set(CONFIGS[:6]).issubset(value['configs']), 'journal_configs_invalid')
        for record in value['configs'].values():
            require(sha(base64.b64decode(record['before'])) == record['metadata']['sha256'] and sha(base64.b64decode(record['after'])) == record['after_sha256'], 'journal_content_invalid')
        completed = value['state'] == 'completed'
        require(completed or value['state'] in ('prepared', 'gated', 'moving', 'reverting', 'recovery_required'), 'journal_not_recoverable')
        self.unchanged(value)
        for move in value['moves']:
            require(self.safe(move['source']).exists() != self.safe(move['target']).exists(), 'recovery_path_conflict')
        for relative, record in value['configs'].items():
            old, new = self.safe(relative), self.safe(self.mapped(relative))
            current = new if new.exists() else old
            digest = file_sha(current)
            allowed = {record['after_sha256']} if completed else {record['after_sha256'], record['metadata']['sha256']}
            if not completed and relative in PUBLIC:
                allowed.add(sha(b'<?php\n// ReviewFactory TEST demo: relocation\nreturn [];\n' if relative == PUBLIC[2] else MAINTENANCE))
            require(digest in allowed, 'configuration_modified_since_relocation_review_required')
        handle = self.safe('ops/.relocation.lock').open('a+b')
        try:
            fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            handle.close()
            raise Stop('another_relocation_running')
        self.handles.append(handle)
        try:
            self.lock_writers()
            value['rollback_of_completed'] = completed or value.get('rollback_of_completed', False)
            value['state'] = 'reverting'
            self.write_journal(path.parent, value)
            self.gate(value)
            self.checkpoint()
            self.restore(path.parent, value, value['rollback_of_completed'])
            return {'state': value['state'], 'journal_id': identifier, 'newest_private_data': 'moved back, not restored from backup',
                    'mysql_connections': 0, 'cron': 'restore legacy code/data paths in ADM.TOOLS before resuming jobs'}
        finally:
            self.release()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    action = parser.add_mutually_exclusive_group(required=True)
    action.add_argument('--plan', action='store_true')
    action.add_argument('--apply', action='store_true')
    action.add_argument('--rollback', metavar='JOURNAL_ID')
    parser.add_argument('--writers-stopped', action='store_true')
    args = parser.parse_args()
    # No CLI redirect to another shop, data directory, or production environment.
    relocation = None
    signal.signal(signal.SIGTERM, lambda *unused: (_ for _ in ()).throw(Stop('interrupted')))
    try:
        relocation = Relocation(HOSTING_ROOT)
        if args.plan:
            result = relocation.summary(relocation.plan())
        elif args.apply:
            result = relocation.apply(args.writers_stopped)
        else:
            result = relocation.rollback(args.rollback, args.writers_stopped)
        print(json.dumps(result, ensure_ascii=False, indent=2))
        return 0
    except (Exception, KeyboardInterrupt) as error:
        # Never print raw config/SQL/connection exception messages.
        code = str(error) if isinstance(error, Stop) else 'operation_failed_' + type(error).__name__
        print(json.dumps({'state': 'stopped', 'code': code, 'mysql_connections': 0}, ensure_ascii=False))
        return 1
    finally:
        if relocation:
            relocation.release()


if __name__ == '__main__':
    sys.exit(main())
