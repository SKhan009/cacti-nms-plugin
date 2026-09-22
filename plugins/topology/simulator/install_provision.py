#!/usr/bin/env python3
"""Install the automatic simulator queue worker on the explicitly selected RHEL/Cacti VM."""
import argparse
import os
from pathlib import Path
import pwd
import subprocess


def main():
    """Install a dedicated unprivileged systemd timer; keep the native poller untouched."""
    parser = argparse.ArgumentParser()
    parser.add_argument('--cacti-path', required=True)
    parser.add_argument('--web-user', required=True)
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise ValueError('Run the service installer as root')
    root = Path(args.cacti_path).resolve(strict=True)
    pwd.getpwnam(args.web_user)
    if not args.web_user.isalnum() or any(c in str(root) for c in (' ', '\n', '\r', '"', '%', '\\')):
        raise ValueError('Unsupported service path or user')
    if not (root/'plugins/topology/simulator_worker.php').is_file():
        raise ValueError('Install automatic simulator plugin code first')
    unit = f'''[Unit]
Description=Cacti simulator import queue
After=network.target mariadb.service

[Service]
Type=oneshot
User={args.web_user}
Group={pwd.getpwnam(args.web_user).pw_gid}
WorkingDirectory={root}
ExecStart=/usr/bin/php {root}/plugins/topology/simulator_worker.php
TimeoutStartSec=240
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ProtectHome=true
'''
    timer = '''[Unit]
Description=Check Cacti simulator import queue

[Timer]
OnBootSec=15s
OnUnitInactiveSec=15s
AccuracySec=1s
Unit=topology-simulator-import.service

[Install]
WantedBy=timers.target
'''
    for name, content in (('topology-simulator-import.service', unit), ('topology-simulator-import.timer', timer)):
        path = Path('/etc/systemd/system')/name
        if path.is_symlink():
            raise ValueError('Refusing existing service symlink')
        path.write_text(content)
        path.chmod(0o644)
    subprocess.run(['/usr/bin/systemctl', 'daemon-reload'], check=True)
    subprocess.run(['/usr/bin/systemctl', 'enable', '--now', 'topology-simulator-import.timer'], check=True)
    print('Automatic simulator import timer installed.')


if __name__ == '__main__':
    main()
