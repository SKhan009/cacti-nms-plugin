#!/usr/bin/env python3
# File: nms-snmpsim-reload.py
# Root-managed activation helper for queued simulator uploads.
# A process lock and claimed marker protect concurrent runs and new requests; an administrator stop is respected.
"""Root-owned timer helper: read trusted config and consume only an upload marker."""
import fcntl
import json
import os
from pathlib import Path
import subprocess


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
    subprocess.run(['/usr/bin/systemctl', 'try-restart', config['service']], check=True, timeout=60)
    subprocess.run(['/usr/bin/systemctl', 'is-active', '--quiet', config['service']], check=True, timeout=5)
    processing.unlink(missing_ok=True)


# Read trusted settings and serialize upload activation with a nonblocking process lock.
def main():
    with open('/etc/cacti-nms/snmpsim.json', encoding='utf-8') as stream:
        config = json.load(stream)
    with open('/run/lock/cacti-nms-snmpsim.lock', 'w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        activate(config)


if __name__ == '__main__':
    main()
