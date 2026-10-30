#!/usr/bin/env python3
"""Move the existing frontend Git clone into apps, without touching either shop."""
import argparse
import datetime
import fcntl
import json
import os
from pathlib import Path
import re
import subprocess
import sys

from relocate_hosting import HOSTING_ROOT, Relocation, Stop, atomic, json_bytes, metadata, require, tree


class FrontendMove:
    def __init__(self, root):
        self.guard = Relocation(root)
        self.root = self.guard.root
        self.source = self.root.parent / 'lavka-frontend-work'
        self.target = self.root / 'apps/lavka-frontend'

    def validate_repository(self, path):
        require(path.resolve() == path and path.is_dir(), 'frontend_repository_missing_or_redirected')
        git = path / '.git'
        require(git.is_dir() and git.resolve() == git, 'standalone_git_clone_required')
        for name in ('worktrees', 'modules'):
            folder = git / name
            require(not folder.exists() or not any(folder.iterdir()), 'git_worktrees_or_submodules_require_review')
        require(not any(git.rglob('*.lock')), 'git_operation_still_running')
        config = git / 'config'
        require(config.is_file() and config.resolve() == config, 'git_configuration_missing')
        # Do not print remotes, credentials, SSH commands or included config.
        require(str(self.source).encode() not in config.read_bytes(), 'git_config_references_old_frontend_path')
        process = subprocess.run(['git', '-C', str(path), 'rev-parse', '--show-toplevel'],
                                 stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True, timeout=10)
        require(process.returncode == 0 and process.stdout.strip() == str(path), 'git_worktree_path_unexpected')
        effective = subprocess.run(['git', '-C', str(path), 'config', '--includes', '--get-regexp', r'^(core\.|remote\.)'],
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True, timeout=10)
        require(effective.returncode in (0, 1) and str(self.source) not in effective.stdout, 'effective_git_paths_require_review')
        alternates = git / 'objects/info/alternates'
        if alternates.is_file():
            require(alternates.resolve() == alternates, 'git_object_paths_redirected')
            require(str(self.source).encode() not in alternates.read_bytes(), 'git_object_paths_require_review')
        for item in (path / 'ops').rglob('*'):
            if item.is_file() and item.suffix in ('.py', '.php', '.sh'):
                require(item.resolve() == item, 'frontend_tool_redirected')
                require(str(self.source).encode() not in item.read_bytes(), 'frontend_tool_contains_old_absolute_clone_path')

    def plan(self):
        if not self.source.exists() and self.target.exists():
            self.validate_repository(self.target)
            return {'state': 'already_relocated', 'from': str(self.source), 'to': str(self.target)}
        require(self.source.is_dir() and self.source.resolve() == self.source and not self.target.exists(), 'frontend_source_missing_or_target_occupied')
        require(self.target.resolve() == self.target, 'frontend_target_redirected')
        self.validate_repository(self.source)
        parent = self.target.parent
        while not parent.exists():
            parent = parent.parent
        require(parent.stat().st_dev == self.source.stat().st_dev, 'cross_device_move_refused')
        return {'state': 'planned', 'from': str(self.source), 'to': str(self.target),
                'root': str(self.root), 'protected': self.guard.protected(),
                'repository_metadata': metadata(self.source, False), 'files': tree(self.source)}

    def summary(self, value):
        return {'state': value['state'], 'from': str(self.source), 'to': str(self.target),
                'shop_files': 'unchanged', 'mysql_connections': 0, 'git': 'existing clone, branch and local work preserved'}

    def journal(self, path, value):
        atomic(path / 'journal.json', json_bytes(value))

    def acquire(self):
        self.guard.private_directory(self.guard.ops)
        require(self.guard.ops.stat().st_mode & 0o077 == 0, 'ops_directory_must_be_private')
        handle = self.guard.safe('ops/.relocation.lock').open('a+b')
        try:
            fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            handle.close()
            raise Stop('another_relocation_running')
        return handle

    def apply(self, fault=None):
        value = self.plan()
        if value['state'] == 'already_relocated':
            return self.summary(value)
        handle = self.acquire()
        try:
            require(self.plan() == value, 'frontend_changed_after_preflight')
            directory = self.guard.ops / 'relocations' / ('frontend-' + datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S.%fZ'))
            self.guard.private_directory(directory)
            value['state'] = 'prepared'
            self.journal(directory, value)
            moved = False
            try:
                self.guard.private_directory(self.target.parent)
                require(not self.target.exists(), 'frontend_target_occupied')
                os.rename(str(self.source), str(self.target))
                moved = True
                if fault:
                    fault()
                self.validate_repository(self.target)
                require(tree(self.target) == value['files'], 'frontend_files_changed_during_move')
                require(metadata(self.target, False) == value['repository_metadata'], 'frontend_permissions_changed')
                require(self.guard.protected() == value['protected'], 'protected_shop_changed')
                value['state'] = 'completed'
                self.journal(directory, value)
                result = self.summary(value)
                result['journal_id'] = directory.name
                result['repository_files'] = 'byte-identical, including .git and uncommitted work'
                return result
            except BaseException:
                if moved:
                    require(not self.source.exists() and self.target.is_dir(), 'frontend_recovery_path_conflict')
                    os.rename(str(self.target), str(self.source))
                value['state'] = 'failed_rolled_back'
                self.journal(directory, value)
                raise
        finally:
            handle.close()

    def rollback(self, identifier):
        require(re.fullmatch(r'frontend-[0-9TZ.]+', identifier) is not None, 'journal_id_invalid')
        path = self.guard.safe('ops/relocations/' + identifier + '/journal.json')
        require(path.is_file() and path.stat().st_uid == os.getuid() and path.stat().st_mode & 0o777 == 0o600, 'private_journal_invalid')
        value = json.loads(path.read_text(encoding='utf-8'))
        require(value.get('root') == str(self.root) and value.get('from') == str(self.source) and value.get('to') == str(self.target), 'journal_paths_invalid')
        require(value.get('state') in ('prepared', 'completed'), 'frontend_journal_not_recoverable')
        require(self.guard.protected() == value['protected'], 'protected_shop_changed')
        handle = self.acquire()
        try:
            require(self.source.exists() != self.target.exists(), 'frontend_recovery_path_conflict')
            if self.target.exists():
                self.validate_repository(self.target)
                os.rename(str(self.target), str(self.source))
            self.validate_repository(self.source)
            value['state'] = 'rolled_back'
            self.journal(path.parent, value)
            return self.summary(value)
        finally:
            handle.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    action = parser.add_mutually_exclusive_group(required=True)
    action.add_argument('--plan', action='store_true')
    action.add_argument('--apply', action='store_true')
    action.add_argument('--rollback', metavar='JOURNAL_ID')
    args = parser.parse_args()
    try:
        move = FrontendMove(HOSTING_ROOT)
        if args.plan:
            result = move.summary(move.plan())
        elif args.apply:
            result = move.apply()
        else:
            result = move.rollback(args.rollback)
        print(json.dumps(result, ensure_ascii=False, indent=2))
        return 0
    except (Exception, KeyboardInterrupt) as error:
        code = str(error) if isinstance(error, Stop) else 'operation_failed_' + type(error).__name__
        print(json.dumps({'state': 'stopped', 'code': code, 'mysql_connections': 0}))
        return 1


if __name__ == '__main__':
    sys.exit(main())
