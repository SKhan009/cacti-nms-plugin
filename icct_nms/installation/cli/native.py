#!/usr/bin/env python3
"""Build pinned native dependencies without root; install a verified OS-specific bundle as root."""
import argparse
from contextlib import contextmanager
import grp
import hashlib
import importlib.util
import json
import os
from pathlib import Path, PurePosixPath
import shutil
import subprocess
import tarfile
import tempfile

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('icct_native_setup', Path(__file__).with_name('setup.py'))
setup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(setup)
PROFILES = {
    'guacamole-strict': {'version':'1.6.0', 'archive':'guacamole-server-1.6.0.tar.gz',
        'sha256':'8bc45675da96d7b6f39728160181e3d4ff3c08f460f6d26de5805b642bf13f2b',
        'prefix':'/opt/icct-nms/native/guacamole-1.6.0',
        'patch':'protocols/ssh/guacamole/strict-auth.patch',
        'patch_sha256':'68d40413442fd99a9d97ba8741ea806806a284147a196a94d319d1c571d91e3c'},
    'pathchar': {'version':'1.5', 'archive':'pchar-1.5.tar.gz',
        'sha256':'7019297084f1000557a019501532ecae67772851717329cc02227f5c17e36d27',
        'prefix':'/opt/icct-nms/native/pchar-1.5',
        'patch':'installation/native-source/pchar-modern-cxx.patch',
        'patch_sha256':'0dc1829dbeb4d87292a5b30d9cbaf368080a6b36affc19fdd115800c3316812d'},
}


def digest(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def extract_source(archive, destination):
    with tarfile.open(archive) as source:
        for member in source.getmembers():
            parts = PurePosixPath(member.name).parts
            if not parts or member.name.startswith('/') or '..' in parts or '\\' in member.name or not (member.isdir() or member.isfile()):
                raise ValueError('Unsafe native source archive member.')
            target = destination / member.name
            if member.isdir():
                target.mkdir(parents=True, exist_ok=True)
            else:
                target.parent.mkdir(parents=True, exist_ok=True)
                with source.extractfile(member) as stream, target.open('wb') as output:
                    shutil.copyfileobj(stream, output)
                target.chmod(0o755 if member.mode & 0o111 else 0o644)
                os.utime(target, (member.mtime, member.mtime))


def build(component, output):
    if os.geteuid() == 0:
        raise ValueError('Build as an unprivileged account, then install the finished bundle as root.')
    profile = PROFILES[component]
    output = Path(output).absolute()
    if output.exists():
        raise ValueError('Use a new native bundle directory.')
    archive = ROOT / 'installation/native-source' / profile['archive']
    if archive.is_symlink() or digest(archive) != profile['sha256']:
        raise ValueError('Pinned upstream source checksum differs.')
    patch = ROOT / profile['patch']
    expected = profile.get('patch_sha256')
    if expected and digest(patch) != expected:
        raise ValueError('Strict-auth patch checksum differs.')
    with tempfile.TemporaryDirectory(prefix='icct-native-build-') as temporary:
        work = Path(temporary)
        extract_source(archive, work)
        source = work / ('guacamole-server-1.6.0' if component == 'guacamole-strict' else 'pchar-1.5')
        subprocess.run(['patch','--batch','--forward','-p1','-i',str(patch)], cwd=source, check=True)
        flags = ['--prefix='+profile['prefix']]
        if component == 'guacamole-strict':
            flags += ['--without-freerdp','--without-vnc','--without-telnet','--without-pulse','--without-avcodec','--without-avformat','--without-avutil','--without-swscale','--disable-kubernetes','--disable-guacenc','--disable-guaclog']
        else:
            flags += ['--host='+('aarch64' if setup.os_info()['arch'] == 'aarch64' else setup.os_info()['arch'])+'-unknown-linux-gnu']
        subprocess.run(['./configure', *flags], cwd=source, check=True)
        subprocess.run(['make','-j2'], cwd=source, check=True)
        payload = work / 'payload'
        payload.mkdir()
        if component == 'guacamole-strict':
            stage = work / 'stage'
            subprocess.run(['make','DESTDIR='+str(stage),'install'], cwd=source, check=True)
            installed = stage / profile['prefix'].lstrip('/')
            for item in installed.rglob('*'):
                relative = item.relative_to(installed)
                if item.is_file() and (relative.parts[0] == 'sbin' or '.so' in item.name):
                    # Materialize only internal library links as regular files.
                    if installed.resolve() not in item.resolve().parents:
                        raise ValueError('Native build escaped staging directory.')
                    dest = payload / relative
                    dest.parent.mkdir(parents=True, exist_ok=True)
                    shutil.copy2(item, dest)
                    dest.chmod(0o755)
        else:
            (payload / 'sbin').mkdir()
            shutil.copy2(source / 'pchar', payload / 'sbin/pchar')
            (payload / 'sbin/pchar').chmod(0o755)
        docs = payload / 'share/doc'
        docs.mkdir(parents=True)
        for name in ['LICENSE','NOTICE','README','CHANGES']:
            if (source / name).is_file():
                shutil.copy2(source / name, docs / name)
                (docs / name).chmod(0o644)
        manifest = {'format':1, 'component':component, 'version':profile['version'], 'target':setup.os_info(),
            'source_sha256':profile['sha256'], 'patch_sha256':digest(patch), 'files':{}}
        for item in sorted(payload.rglob('*')):
            if item.is_file():
                manifest['files'][item.relative_to(payload).as_posix()] = {'sha256':digest(item),'mode':item.stat().st_mode & 0o777}
        # Publish only a complete successful build.
        output.parent.mkdir(parents=True, exist_ok=True)
        shutil.copytree(payload, output / 'payload')
        (output / 'native.json').write_text(json.dumps(manifest, indent=2)+'\n')
    return manifest


def verify_bundle(directory, target):
    directory = Path(directory).resolve()
    data = json.loads((directory / 'native.json').read_text())
    component = data.get('component')
    if data.get('format') != 1 or component not in PROFILES or data.get('target') != target:
        raise ValueError('Native bundle component, OS version or CPU differs.')
    profile = PROFILES[component]
    if data.get('source_sha256') != profile['sha256'] or data.get('version') != profile['version'] or data.get('patch_sha256') != digest(ROOT / profile['patch']):
        raise ValueError('Native bundle source or patch differs from this release.')
    if not data.get('files'):
        raise ValueError('Native bundle is empty.')
    for name, details in data['files'].items():
        parts = PurePosixPath(name).parts
        path = directory / 'payload' / name
        if not parts or name.startswith('/') or '..' in parts or '\\' in name or details.get('mode') not in (0o644,0o755):
            raise ValueError('Unsafe native payload path or mode.')
        if any(p.is_symlink() for p in [path,*path.parents]) or not path.is_file() or digest(path) != details['sha256']:
            raise ValueError('Native payload is missing, symlinked or changed: '+name)
    required = 'sbin/guacd' if component == 'guacamole-strict' else 'sbin/pchar'
    if required not in data['files']:
        raise ValueError('Native executable missing.')
    return data


@contextmanager
def frozen_bundle(directory):
    directory = Path(directory).resolve()
    data = verify_bundle(directory, setup.os_info())
    # Root-owned private staging prevents replacement between verification and execution.
    with tempfile.TemporaryDirectory(prefix='icct-native-install-') as temporary:
        staged = Path(temporary)
        (staged / 'native.json').write_text(json.dumps(data))
        for name in data['files']:
            destination = staged / 'payload' / name
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(directory / 'payload' / name, destination)
        verify_bundle(staged, setup.os_info())
        yield staged, data


def install(directory, web_group):
    if os.geteuid() != 0:
        raise ValueError('Native installation requires root.')
    with frozen_bundle(directory) as (staged, data):
        install_verified(staged, data, web_group)


def install_verified(directory, data, web_group):
    profile = PROFILES[data['component']]
    if data['component'] == 'pathchar' and Path('/proc/self/status').exists():
        bounded = next((line.split(':',1)[1].strip() for line in Path('/proc/self/status').read_text().splitlines() if line.startswith('CapBnd:')), '0')
        if not int(bounded,16) & (1 << 13):
            raise ValueError('Pathchar requires CAP_NET_RAW on the host; this container capability boundary prevents raw-socket diagnostics.')
    prefix = Path(profile['prefix'])
    group_id = grp.getgrnam(web_group).gr_gid
    for parent in [prefix, *prefix.parents]:
        if parent.exists() and (parent.is_symlink() or parent.stat().st_uid != 0 or parent.stat().st_mode & 0o022):
            raise ValueError('Native destination parents must be root-owned and not group/world-writable.')
    # Resolve runtime libraries before changing the destination.
    environment = dict(os.environ, LD_LIBRARY_PATH=str(directory / 'payload/lib'))
    for candidate in (directory / 'payload').rglob('*'):
        if candidate.is_file() and (candidate.parent.name == 'sbin' or '.so' in candidate.name):
            result = subprocess.run(['ldd', str(candidate)], env=environment, check=True, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            if 'not found' in result.stdout:
                raise ValueError('Native runtime libraries missing: '+result.stdout)
    # Do not overwrite an existing custom build or follow destination links.
    for name, details in data['files'].items():
        target = prefix / name
        if any(p.is_symlink() for p in [target,*target.parents]):
            raise ValueError('Refusing native destination symlink.')
        if target.exists() and (not target.is_file() or digest(target) != details['sha256']):
            raise ValueError('Different native build already exists: '+str(target))
    for name, details in data['files'].items():
        target = prefix / name
        target.parent.mkdir(parents=True, exist_ok=True, mode=0o755)
        if not target.exists():
            shutil.copyfile(directory / 'payload' / name, target)
        os.chown(target, 0, 0)
        target.chmod(details['mode'])
    if data['component'] == 'pathchar':
        executable = prefix / 'sbin/pchar'
        os.chown(executable, 0, group_id)
        executable.chmod(0o750)
        subprocess.run(['setcap','cap_net_raw=ep',str(executable)], check=True)
        # Preserve any previously installed diagnostic executable.
        link = Path('/usr/local/sbin/pchar')
        if not link.exists() and not link.is_symlink():
            link.parent.mkdir(parents=True, exist_ok=True)
            link.symlink_to(executable)
    executable = prefix / ('sbin/guacd' if data['component']=='guacamole-strict' else 'sbin/pchar')
    subprocess.run(['ldd',str(executable)], check=True, stdout=subprocess.PIPE)
    flag = '-v' if data['component']=='guacamole-strict' else '-h'
    subprocess.run([str(executable),flag], check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if shutil.which('restorecon'):
        subprocess.run(['restorecon','-RF',str(prefix)], check=True)
    print('Native dependency installed at '+str(prefix)+'. Service configuration and HTTPS remain separate.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['build','install','verify'])
    parser.add_argument('--component',choices=list(PROFILES))
    parser.add_argument('--bundle',required=True)
    parser.add_argument('--web-group',default='apache' if Path('/etc/redhat-release').exists() else 'www-data')
    args = parser.parse_args()
    if args.action == 'build':
        if not args.component: parser.error('build requires --component')
        result = build(args.component,args.bundle)
        print(json.dumps({'component':result['component'],'files':len(result['files']),'target':result['target']}))
    elif args.action == 'verify':
        print(json.dumps(verify_bundle(args.bundle,setup.os_info())['target']))
    else:
        install(args.bundle,args.web_group)


if __name__ == '__main__':
    try:
        main()
    except (ValueError, KeyError, OSError, subprocess.CalledProcessError) as error:
        raise SystemExit('Native setup stopped: '+str(error))
