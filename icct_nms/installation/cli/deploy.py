#!/usr/bin/env python3
"""Release bootstrap: verify the archive, retain local data, then run bundled setup."""
import argparse
import datetime
import hashlib
import importlib.util
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile


def verify_release(directory):
    directory = Path(directory).resolve()
    manifest = json.loads((directory / 'release.json').read_text())
    if manifest.get('format') != 1 or not isinstance(manifest.get('files'), dict) or not manifest['files']:
        raise ValueError('Invalid release manifest.')
    for name, digest in manifest['files'].items():
        parts = PurePosixPath(name).parts
        if not parts or name.startswith('/') or '..' in parts or '\\' in name or parts[0] not in ('icct_nms', 'packages', 'install.py'):
            raise ValueError('Invalid release path: ' + name)
        path = directory / name
        if any(parent.is_symlink() for parent in [path, *path.parents] if parent != directory.parent):
            raise ValueError('Refusing release symlink: ' + name)
        if not path.is_file() or hashlib.sha256(path.read_bytes()).hexdigest() != digest:
            raise ValueError('Missing or changed release file: ' + name)
    for required in ['install.py','icct_nms/setup.php','icct_nms/installation/cli/setup.py']:
        if required not in manifest['files']:
            raise ValueError('Release is incomplete: ' + required)
    return manifest


def validate_destination(root, names):
    root = Path(root).resolve()
    if not (root / 'include/cli_check.php').is_file():
        raise ValueError('Existing Cacti installation required.')
    destination = root / 'plugins/icct_nms'
    for relative in names:
        if not relative.startswith('icct_nms/'):
            continue
        parts = PurePosixPath(relative).parts
        if '..' in parts or '\\' in relative:
            raise ValueError('Invalid plugin destination.')
        local = '/'.join(parts[1:])
        if parts[-1].startswith('.env') or parts[-1] in ('config.local.json','ssh.config.local.json') or (any(local.startswith(prefix + '/') for prefix in ('protocols/snmp/mibs/storage','shared/assets/images/device-types/uploads')) and parts[-1] not in ('.htaccess','.gitignore')):
            raise ValueError('Release contains local configuration or runtime data: ' + relative)
        path = root / 'plugins' / relative
        if destination not in path.parents:
            raise ValueError('Invalid plugin destination.')
        for candidate in [path, *path.parents]:
            if candidate.is_symlink():
                raise ValueError('Refusing destination symlink: ' + str(candidate))
            if candidate == root:
                break
    return destination


def copy_source(directory, root, manifest, backup_root=Path('/var/backups/icct-nms-install')):
    destination = validate_destination(root, manifest['files'])
    backup = None
    if destination.exists():
        backup_dir = Path(backup_root)
        backup_dir.mkdir(parents=True, exist_ok=True)
        backup = backup_dir / ('plugin-' + datetime.datetime.now().strftime('%Y%m%dT%H%M%S%f') + '.tar.gz')
        with tarfile.open(backup, 'x:gz') as archive:
            archive.add(destination, arcname='icct_nms')
        backup.chmod(0o600)
    for name in manifest['files']:
        if not name.startswith('icct_nms/'):
            continue
        path = Path(root) / 'plugins' / name
        path.parent.mkdir(parents=True, exist_ok=True)
        descriptor, temporary = tempfile.mkstemp(prefix='.icct-install-', dir=path.parent)
        try:
            with os.fdopen(descriptor, 'wb') as stream, open(Path(directory) / name, 'rb') as source:
                shutil.copyfileobj(source, stream)
            os.chmod(temporary, 0o644)
            os.replace(temporary, path)
        finally:
            Path(temporary).unlink(missing_ok=True)
    return destination, backup


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--cacti-root', required=True)
    parser.add_argument('--plan', action='store_true')
    parser.add_argument('--online', action='store_true')
    parser.add_argument('--no-packages', action='store_true')
    parser.add_argument('--application-only', action='store_true')
    parser.add_argument('--web-user')
    parser.add_argument('--web-group')
    args = parser.parse_args()
    if args.application_only and (args.online or args.no_packages):
        parser.error('--application-only cannot be combined with package options.')
    directory = Path(__file__).resolve().parent
    manifest = verify_release(directory)
    root = Path(args.cacti_root).resolve()
    if not re.fullmatch(r'/[A-Za-z0-9_./-]+', str(root)):
        raise ValueError('Unsupported Cacti path characters.')
    destination = validate_destination(root, manifest['files'])
    spec = importlib.util.spec_from_file_location('icct_release_setup', directory / 'icct_nms/installation/cli/setup.py')
    setup = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(setup)
    target = setup.os_info()
    if args.application_only:
        pass
    elif manifest['target']:
        if args.online or args.no_packages:
            raise ValueError('A bundled release always uses its verified offline packages.')
        setup.verify_bundle(directory / 'packages', target)
    elif args.online == args.no_packages:
        raise ValueError('Source-only release requires either --online or --no-packages.')
    plan = {'destination': str(destination), 'target': target, 'offline': bool(manifest['target']), 'source_files': sum(name.startswith('icct_nms/') for name in manifest['files'])}
    if args.plan:
        print(json.dumps(plan, indent=2))
        return
    if os.geteuid() != 0:
        raise ValueError('Run deployment as administrator; --plan is read-only.')
    # Reject incompatible Cacti/PHP before replacing files or invoking a package manager.
    setup.preflight(root)
    destination, backup = copy_source(directory, root, manifest)
    if shutil.which('restorecon'):
        subprocess.run(['restorecon','-R',str(destination)], check=True)
    command = [sys.executable, str(destination / 'installation/cli/setup.py'), 'install', '--cacti-root', str(root)]
    if args.application_only:
        command += ['--application-only']
    else:
        command += ['--bundle', str(directory / 'packages')] if manifest['target'] else (['--online'] if args.online else ['--no-packages'])
    for name in ['web_user','web_group']:
        if getattr(args,name):
            command.extend(['--'+name.replace('_','-'),getattr(args,name)])
    print(json.dumps({'source_installed': str(destination), 'backup': str(backup) if backup else None}), flush=True)
    subprocess.run(command, check=True)


if __name__ == '__main__':
    try:
        main()
    except (ValueError, KeyError, OSError, subprocess.CalledProcessError) as error:
        print('Installation stopped: ' + str(error), file=sys.stderr)
        sys.exit(1)
