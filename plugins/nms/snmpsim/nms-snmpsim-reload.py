#!/usr/bin/env python3
# File: nms-snmpsim-reload.py
# Root-managed activation helper for queued simulator uploads.
# A process lock and claimed marker protect concurrent runs and new requests; an administrator stop is respected.
"""Root-owned timer helper: read trusted config and consume only an upload marker."""
import fcntl
import os
from pathlib import Path
import subprocess
import stat
from runtime_config import load_config


# Publish a small root-owned status cache so confined web workers never need to execute systemctl.
def publish_state(config):
    status_value = subprocess.run([config['systemctl'], '--no-pager', 'is-active', config['service']],
                                  check=False, capture_output=True, text=True, timeout=5).stdout.strip()
    if status_value not in {'active', 'inactive', 'failed', 'activating', 'deactivating', 'unknown'}:
        status_value = 'unknown'
    status_path = config.get('status_path')
    if not status_path:
        return
    target = Path(status_path)
    parent = target.parent.stat()
    if parent.st_uid != 0 or parent.st_mode & 0o022:
        raise ValueError('The status directory must be administrator-owned and not group/world writable')
    temporary = target.with_name('.' + target.name + '.tmp')
    temporary.write_text(status_value + '\n', encoding='ascii')
    os.chmod(temporary, 0o644)
    os.replace(temporary, target)


# Restart an active responder for a pending upload while preserving requests that arrive during the restart.
def control(config):
    if config.get('ui_control') is not True:
        return
    pending = Path(config['data_dir']) / '.control.pending'
    try:
        descriptor = os.open(pending, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except FileNotFoundError:
        return
    with os.fdopen(descriptor, 'r') as handle:
        if not stat.S_ISREG(os.fstat(handle.fileno()).st_mode):
            raise ValueError('Service command must be a regular file')
        command = handle.read(32).strip()
    if command not in {'start', 'stop', 'restart'}:
        raise ValueError('Invalid queued service command')
    paused = Path(config['status_path'] + '.paused')
    if command == 'stop':
        paused.touch(mode=0o644, exist_ok=True)
    else:
        paused.unlink(missing_ok=True)
    subprocess.run([config['systemctl'], command, config['service']], check=True, timeout=60)
    pending.unlink()


def activate(config):
    if config.get('status_path') and Path(config['status_path'] + '.paused').exists():
        return
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
    # Automatic mode starts stopped services too. Administrators disable the
    # reload timer to suspend that policy. Legacy configurations retain try-restart.
    command = 'restart' if config.get('auto_start') is True else 'try-restart'
    subprocess.run([config['systemctl'], command, config['service']], check=True, timeout=60)
    subprocess.run([config['systemctl'], 'is-active', '--quiet', config['service']], check=True, timeout=5)
    processing.unlink(missing_ok=True)


# Read trusted settings and serialize upload activation with a nonblocking process lock.
def main():
    config = load_config()
    publish_state(config)
    lock_path = Path(config['lock_path'])
    parent = lock_path.parent.stat()
    if parent.st_uid != 0 or parent.st_mode & 0o022:
        raise ValueError('The activation lock directory must be administrator-owned and not group/world writable')
    descriptor = os.open(lock_path, os.O_WRONLY | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        try:
            control(config)
            activate(config)
        finally:
            publish_state(config)


if __name__ == '__main__':
    main()
