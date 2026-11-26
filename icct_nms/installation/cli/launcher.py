#!/usr/bin/env python3
"""Install a GitHub main ZIP using the same verified bootstrap as release ZIPs."""
import argparse
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import zipfile


def matching_media(directory, target):
    """Select exact, declared targets; never substitute another release or CPU."""
    directory = Path(directory)
    candidates = sorted({manifest.parent.parent for manifest in directory.rglob('packages/bundle.json')})
    matches = []
    for candidate in candidates:
        manifest = json.loads((candidate / 'packages/bundle.json').read_text())
        if manifest.get('target') == target:
            matches.append(candidate)
    if len(matches) > 1:
        raise ValueError('Multiple matching offline bundles; select one with --media.')
    return matches[0] if matches else None


def installation_source(setup, directory, arguments, offline=False):
    explicit_source = any(flag in arguments for flag in ('--online', '--no-packages', '--application-only', '-h', '--help'))
    if offline and explicit_source:
        raise ValueError('--offline cannot be combined with online, source-only or help options.')
    if explicit_source:
        return None, list(arguments)
    target = setup.os_info()
    media = matching_media(directory, target)
    print('Detected collector: ' + json.dumps(target), flush=True)
    if media:
        print('Using matching offline media: ' + str(media), flush=True)
        return media, list(arguments)
    if offline:
        raise ValueError('No matching offline media for ' + json.dumps(target) + '. Supply --media with a matching bundle.')
    print('No matching offline media. Using this system’s configured package repositories; existing packages are reused.', flush=True)
    return None, list(arguments) + ['--online']


def main():
    source = Path(__file__).resolve().parents[2]
    parser = argparse.ArgumentParser(description=__doc__, add_help=False)
    parser.add_argument('--cacti-root')
    parser.add_argument('--media', type=Path, help='Offline media or a directory containing platform-specific media trees.')
    parser.add_argument('--offline', action='store_true', help='Require matching offline media; never use repositories.')
    args, remaining = parser.parse_known_args()
    if not args.cacti_root and not any(flag in remaining for flag in ('-h', '--help')):
        candidates = [p for p in (Path('/usr/share/cacti'), Path('/var/www/html/cacti'))
                      if (p / 'include/cli_check.php').is_file()]
        if len(candidates) != 1:
            parser.error('Specify --cacti-root /path/to/cacti (no unique Cacti installation found).')
        args.cacti_root = str(candidates[0])
    spec = importlib.util.spec_from_file_location('icct_release_package', source / 'installation/cli/package.py')
    package = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(package)
    media, remaining = installation_source(package.setup,
        args.media or source / 'installation/bundles/media', remaining, args.offline)
    with tempfile.TemporaryDirectory(prefix='icct-complete-installer-') as temporary:
        archive = Path(temporary) / 'installer.zip'
        package.build_archive(source, archive, media=media, allow_partial=True)
        release = Path(temporary) / 'release'
        with zipfile.ZipFile(archive) as stream:
            stream.extractall(release)
        command = [sys.executable, str(release / 'install.py')]
        if args.cacti_root:
            command += ['--cacti-root', args.cacti_root]
        subprocess.run(command + remaining, check=True)


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        print('Installation stopped: ' + str(error), file=sys.stderr)
        sys.exit(1)
