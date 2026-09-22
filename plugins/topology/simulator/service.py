#!/usr/bin/env python3
"""Run or reload one administrator-configured SNMPSim instance; never execute uploaded data."""
import argparse
import fcntl
import json
import os
from pathlib import Path
import stat
import subprocess
import time


def config(path):
    """Require root-owned, non-writable configuration and absolute configured paths."""
    p = Path(path)
    s = p.lstat()
    if not stat.S_ISREG(s.st_mode) or s.st_uid != 0 or s.st_mode & 0o022:
        raise ValueError('Configuration must be a root-owned regular file without group/world write access')
    c = json.loads(p.read_text())
    for key in ('data_dir', 'status_file', 'executable', 'cache_dir', 'lock_file'):
        if not Path(c[key]).is_absolute():
            raise ValueError('All service paths must be absolute')
    if c['address'] != '127.0.0.1' or not 1024 <= int(c['port']) <= 65535:
        raise ValueError('A local unprivileged UDP endpoint is required')
    return c


def reload_records(c):
    """Claim pending activation before restart; preserve markers written during activation."""
    with open(c['lock_file'], 'a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        root = Path(c['data_dir'])
        pending, processing = root / '.reload.pending', root / '.reload.processing'
        if pending.is_symlink() or processing.is_symlink():
            raise ValueError('Activation marker must not be a symbolic link')
        if not processing.exists() and pending.exists():
            if not pending.is_file():
                raise ValueError('Activation marker must be a regular file')
            pending.replace(processing)
        try:
            if processing.exists():
                subprocess.run(['/usr/bin/systemctl', 'restart', 'topology-snmpsim.service'], check=True, timeout=20)
                subprocess.run(['/usr/bin/systemctl', 'is-active', '--quiet', 'topology-snmpsim.service'], check=True, timeout=5)
                processing.unlink()
        finally:
            result = subprocess.run(['/usr/bin/systemctl', 'is-active', 'topology-snmpsim.service'], capture_output=True, text=True, timeout=5)
            target = Path(c['status_file'])
            temp = target.with_suffix('.tmp')
            temp.write_text(result.stdout.strip() + '\n')
            temp.chmod(0o644)
            temp.replace(target)


def main():
    """Dispatch only the fixed run/reload operations selected by systemd."""
    parser = argparse.ArgumentParser()
    parser.add_argument('mode', choices=['run', 'reload'])
    parser.add_argument('--config', required=True)
    args = parser.parse_args()
    c = config(args.config)
    if args.mode == 'run':
        os.execv(c['executable'], [c['executable'], '--data-dir=' + c['data_dir'],
                 '--cache-dir=' + c['cache_dir'], '--agent-udpv4-endpoint=' + c['address'] + ':' + str(c['port'])])
    else:
        reload_records(c)


if __name__ == '__main__':
    main()
