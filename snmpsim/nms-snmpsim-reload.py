#!/usr/bin/env python3
# File: nms-snmpsim-reload.py
# Root-managed activation helper for queued simulator uploads.
# A process lock and claimed marker protect concurrent runs and new requests; an administrator stop is respected.
"""Root-owned timer helper: read trusted config and consume only an upload marker."""
import fcntl
import os
from pathlib import Path
import subprocess
from runtime_config import load_config


# Restart an active responder for a pending upload while preserving requests that arrive during the restart.
def activate(config):
    directory = Path(config['data_dir'])
    pending = directory / '.reload.pending'
    processing = directory / '.reload.processing'
    # Claim the old request before restarting; an upload during restart creates a
    # fresh pending marker which must not be deleted with the completed request.
    if not processing.exists():
        try:
            os.replace(pending, processing)
        except FileNotFoundError:
            return
    # Respect an administrator's stop. Keep the request until the service is active again.
    subprocess.run([config['systemctl'], 'try-restart', config['service']], check=True, timeout=60)
    subprocess.run([config['systemctl'], 'is-active', '--quiet', config['service']], check=True, timeout=5)
    processing.unlink(missing_ok=True)


# Read trusted settings and serialize upload activation with a nonblocking process lock.
def main():
    config = load_config()
    lock_path = Path(config['lock_path'])
    parent = lock_path.parent.stat()
    if parent.st_uid != 0 or parent.st_mode & 0o022:
        raise ValueError('The activation lock directory must be administrator-owned and not group/world writable')
    descriptor = os.open(lock_path, os.O_WRONLY | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        activate(config)


if __name__ == '__main__':
    main()
