#!/usr/bin/env python3
"""Install a GitHub main ZIP using the same verified bootstrap as release ZIPs."""
import argparse
import importlib.util
from pathlib import Path
import subprocess
import sys
import tempfile
import zipfile


def main():
    source = Path(__file__).resolve().parent
    parser = argparse.ArgumentParser(description=__doc__, add_help=False)
    parser.add_argument('--cacti-root')
    args, remaining = parser.parse_known_args()
    if not args.cacti_root and not any(flag in remaining for flag in ('-h', '--help')):
        candidates = [p for p in (Path('/usr/share/cacti'), Path('/var/www/html/cacti'))
                      if (p / 'include/cli_check.php').is_file()]
        if len(candidates) != 1:
            parser.error('Specify --cacti-root /path/to/cacti (no unique Cacti installation found).')
        args.cacti_root = str(candidates[0])
    spec = importlib.util.spec_from_file_location('icct_release_package', source / 'icct_nms/installation/cli/package.py')
    package = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(package)
    media = source / 'icct_nms/installation/bundles/media'
    source_only = any(flag in remaining for flag in ('--online', '--no-packages', '--application-only', '-h', '--help'))
    with tempfile.TemporaryDirectory(prefix='icct-complete-installer-') as temporary:
        archive = Path(temporary) / 'installer.zip'
        package.build_archive(source / 'icct_nms', archive, media=None if source_only else media)
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
