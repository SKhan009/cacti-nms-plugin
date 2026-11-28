#!/usr/bin/env python3
"""Build and audit one complete offline release on the matching RHEL build host."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import struct
import subprocess
import sys
import tempfile
import zipfile

import native
import native_rpm
import package
import setup

TARGET = dict(family='rhel', id='rhel', version='9.4', arch='x86_64')


def require_target(target):
    observed = setup.os_info()
    if observed != target:
        raise ValueError('Build host must match ' + json.dumps(target) +
                         '; observed ' + json.dumps(observed) +
                         '. Never relabel another platform\'s RPMs or native binaries.')


def profiles(target):
    return [None] + [name for name, spec in setup.MANIFEST['optional_components'].items()
                     if spec['packages'][target['family']]]


def profile_directory(media, component):
    return media / ('components/' + component if component else 'packages')


def rpm_identity(path):
    result = subprocess.run(['rpm', '-qp', '--qf', '%{NAME}\t%{ARCH}\t%{NEVRA}', str(path)],
                            check=True, capture_output=True, text=True)
    name, arch, nevra = result.stdout.split('\t')
    return name, arch, nevra


def verify_elf(path, arch):
    with path.open('rb') as stream:
        header = stream.read(20)
    expected = {'x86_64': 62, 'aarch64': 183}[arch]
    if (len(header) != 20 or header[:4] != b'\x7fELF' or header[4:6] != b'\x02\x01'
            or struct.unpack('<H', header[18:20])[0] != expected):
        raise ValueError('Native runtime is not a compatible 64-bit ELF executable: ' + str(path))


def audit(media, target):
    """Check bytes, RPM headers and dependency closure without the build host's RPM database."""
    import dnf

    packages = {}
    requested = set()
    counts = {}
    for component in profiles(target):
        directory = profile_directory(media, component)
        files = setup.verify_bundle(directory, target, component)
        counts[component or 'base'] = len(files)
        required = set(setup.component_packages(target, component))
        present = set()
        for path in files:
            name, arch, nevra = rpm_identity(path)
            if arch not in (target['arch'], 'noarch'):
                raise ValueError('Incompatible RPM architecture: ' + str(path))
            present.add(name)
            key = (name, arch)
            digest = native.digest(path)
            if key in packages and packages[key][1:] != (nevra, digest):
                raise ValueError('Conflicting RPM versions or bytes across profiles: ' + name)
            packages[key] = (path, nevra, digest)
        if required - present:
            raise ValueError('Bundle lacks requested RPMs: ' + ', '.join(sorted(required - present)))
        requested.update(required)
    for component, executable in [('pathchar', 'sbin/pchar'), ('guacamole-strict', 'sbin/guacd')]:
        directory = media / 'native' / component
        data = native.verify_bundle(directory, target)
        if data['component'] != component or executable not in data['files']:
            raise ValueError('Native bundle is missing its required executable: ' + component)
        verify_elf(directory / 'payload' / executable, target['arch'])
        if component == 'guacamole-strict' and 'lib/libguac-client-ssh.so' not in data['files']:
            raise ValueError('Guacamole was built without the SSH client library.')
    with tempfile.TemporaryDirectory(prefix='icct-release-audit-') as temporary, dnf.Base() as base:
        base.conf.plugins = False
        base.conf.install_weak_deps = False
        base.conf.cachedir = temporary + '/cache'
        base.conf.persistdir = temporary + '/state'
        base.fill_sack(load_system_repo=False, load_available_repos=False)
        candidates = base.add_remote_rpms([str(entry[0]) for entry in packages.values()])
        for name in sorted(requested):
            matches = [pkg for pkg in candidates if pkg.name == name]
            if len(matches) != 1:
                raise ValueError('Expected one bundled package for ' + name)
            base.package_install(matches[0])
        base.resolve(allow_erasing=False)
        resolved = {pkg.name for pkg in base.transaction.install_set}
        if requested - resolved:
            raise ValueError('Dependency audit omitted required packages.')
    return dict(target=target, profiles=counts, unique_rpms=len(packages),
                dependency_closure='resolved without installed packages or repositories',
                native_components=['pathchar', 'guacamole-strict'],
                runtime_tested=False)


def prepare_media(media, target):
    # Package download needs no root; native compilation must never run as root.
    if os.geteuid() == 0:
        raise ValueError('Run the release builder as an unprivileged build account.')
    missing = [name for name in ('dnf', 'rpm', 'rpmbuild', 'gcc', 'g++', 'make', 'patch', 'pkg-config')
               if not shutil.which(name)]
    if missing:
        raise ValueError('Missing build tools: ' + ', '.join(missing))
    for component in [None] + setup.repository_components(target):
        directory = profile_directory(media, component)
        if (directory / 'bundle.json').is_file():
            setup.verify_bundle(directory, target, component)
        else:
            setup.download_bundle(directory, target, component)
    for component in native.PROFILES:
        directory = media / 'native' / component
        if (directory / 'native.json').is_file():
            native.verify_bundle(directory, target)
        else:
            native.build(component, directory)
        rpms = profile_directory(media, component)
        if (rpms / 'bundle.json').is_file():
            setup.verify_bundle(rpms, target, component)
        else:
            native_rpm.build(directory, rpms, 'rpmbuild')


def build(media, output, target, existing=False):
    require_target(target)
    if any(path.exists() for path in (output, output.with_suffix(output.suffix + '.sha256'),
                                     output.with_suffix(output.suffix + '.audit.json'))):
        raise ValueError('Use a new output filename; existing releases are preserved.')
    if not existing:
        prepare_media(media, target)
    report = audit(media, target)
    output.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='.icct-release-', dir=output.parent) as temporary:
        staged = Path(temporary) / output.name
        result = package.build_archive(setup.PLUGIN, staged, media=media)
        if not result['complete_media'] or result['target'] != target:
            raise ValueError('Refusing to publish an incomplete or mismatched release.')
        with zipfile.ZipFile(staged) as stream:
            bad = stream.testzip()
            if bad:
                raise ValueError('Archive integrity check failed: ' + bad)
        # The report describes package validation, not a fresh-VM runtime test.
        report['archive_sha256'] = hashlib.sha256(staged.read_bytes()).hexdigest()
        staged.replace(output)
    output.with_suffix(output.suffix + '.sha256').write_text(report['archive_sha256'] + '  ' + output.name + '\n')
    output.with_suffix(output.suffix + '.audit.json').write_text(json.dumps(report, indent=2) + '\n')
    return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--media', type=Path, required=True, help='Build/cache directory for the complete target media.')
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--existing-media', action='store_true', help='Audit and package already built media without downloads.')
    parser.add_argument('--target', default='rhel-9.4-x86_64', choices=['rhel-9.4-x86_64'])
    args = parser.parse_args()
    print(json.dumps(build(args.media.resolve(), args.output.resolve(), TARGET, args.existing_media), indent=2))


if __name__ == '__main__':
    try:
        main()
    except (ValueError, KeyError, OSError, subprocess.CalledProcessError) as error:
        sys.exit('Release build stopped: ' + str(error))
