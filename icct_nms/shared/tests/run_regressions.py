#!/usr/bin/env python3
"""Portable standalone regressions. Does not connect to Cacti, SSH, or devices."""
import argparse
from pathlib import Path
import shutil
import subprocess
import sys


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default=shutil.which('php'))
    parser.add_argument('--node', default=shutil.which('node'))
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[2]
    if not args.php or not args.node:
        parser.error('PHP and Node.js are required; provide --php/--node or put them on PATH.')
    checked = subprocess.run([args.php, '-r',
        '$required=["ctype","filter","mbstring","dom","pcntl"];'
        'foreach($required as $e)if(!extension_loaded($e)){fwrite(STDERR,"Missing PHP extension: $e\\n");exit(1);}'])
    if checked.returncode:
        return checked.returncode
    failures = []
    php_files = sorted(p for p in root.rglob('*.php') if not set(p.parts) & {'vendor', 'bundles'})
    for path in php_files:
        result = subprocess.run([args.php, '-l', str(path)], capture_output=True, text=True)
        if result.returncode:
            failures.append(str(path.relative_to(root)))
            print(result.stdout + result.stderr)
    print(f'PHP syntax: {len(php_files)} files checked', flush=True)
    suites = [(args.php, p) for p in php_files if p.name.endswith('test.php') and p.name != 'ntp_integration_test.php']
    suites += [(args.node, p) for p in sorted((root / 'shared/tests').glob('*test.js'))]
    for executable, path in suites:
        result = subprocess.run([executable, str(path)], cwd=root)
        print(('PASS' if result.returncode == 0 else 'FAIL'), path.relative_to(root), flush=True)
        if result.returncode:
            failures.append(str(path.relative_to(root)))
    result = subprocess.run([sys.executable, '-m', 'unittest', 'discover', '-s',
        str(root / 'installation/cli'), '-p', 'test_*.py'], cwd=root)
    if result.returncode:
        failures.append('Python installer tests')
    print('NOT RUN: protocols/ntp/tests/ntp_integration_test.php requires an explicitly disposable live Cacti database.')
    print(f'{len(suites)} standalone PHP/JS suites; {len(failures)} failing checks.')
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
