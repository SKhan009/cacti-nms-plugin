#!/usr/bin/env python3
# File: configure.py
# Generate an explicit per-server simulator configuration and matching systemd bundle.
# This generator validates inputs and writes a new bundle; it neither installs files into system locations nor starts services.
"""Generate a matched, portable SNMPSim configuration bundle; never install or restart it."""
import argparse
import ipaddress
import json
import os
from pathlib import Path
import re
import shutil
import sys


def local_path(value):
    """Validate an explicit Linux installation path, including safe paths with spaces."""
    path = Path(value)
    if not path.is_absolute() or str(path) == '/' or '..' in path.parts or any(ord(c) < 32 for c in value):
        raise ValueError('Use an absolute non-root path without traversal or control characters')
    return value


def systemd_arg(value):
    """Quote a literal systemd argument and escape its percent-specifier syntax."""
    return '"' + value.replace('\\', '\\\\').replace('"', '\\"').replace('%', '%%') + '"'


# Validate explicit simulator paths, endpoint, and unprivileged account names; return the JSON configuration fields.
def build_config(args):
    for key in ('executable', 'data_dir', 'python', 'systemctl', 'helper_dir', 'config_path', 'lock_path'):
        local_path(getattr(args, key))
    if not Path(args.executable).is_file() or not os.access(args.executable, os.X_OK):
        raise ValueError('Responder executable is missing or not executable')
    if not Path(args.data_dir).is_dir():
        raise ValueError('Data directory must already exist; existing records are never moved')
    for key in ('python', 'systemctl'):
        if not Path(getattr(args, key)).is_file() or not os.access(getattr(args, key), os.X_OK):
            raise ValueError(f'{key} must identify an installed executable')
    for key in ('listen_address', 'client_address'):
        ipaddress.IPv4Address(getattr(args, key))
    if args.client_address == '0.0.0.0' or not 1 <= args.port <= 65535:
        raise ValueError('Use a reachable client address and port 1..65535')
    if type(args.poller_id) is not int or not 1 <= args.poller_id <= 4294967295:
        raise ValueError('Select the positive integer ID of the native Cacti collector for this endpoint')
    for key in ('user', 'group'):
        if not re.fullmatch(r'[A-Za-z_][A-Za-z0-9_-]{0,63}', getattr(args, key)) or getattr(args, key) == 'root':
            raise ValueError('Use an existing unprivileged service user and group')
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_-]{0,63}', args.service_name):
        raise ValueError('Invalid service name')
    return dict(executable=args.executable, data_dir=args.data_dir,
                listen_address=args.listen_address, client_address=args.client_address,
                port=args.port, poller_id=args.poller_id, service=args.service_name + '.service', activation='systemd',
                systemctl=args.systemctl, lock_path=args.lock_path)


# Write a new matched configuration/service bundle without overwriting an existing output directory or installing it.
def generate(args):
    config = build_config(args)
    output = Path(args.output_dir)
    # Refuse overwrites so an earlier installation bundle remains recoverable.
    output.mkdir(parents=True, exist_ok=False)
    (output / 'snmpsim.json').write_text(json.dumps(config, indent=2) + '\n')
    name = args.service_name
    (output / (name + '.service')).write_text(f'''[Unit]
Description=NMS configured SNMP simulator
After=network.target

[Service]
Type=simple
User={args.user}
Group={args.group}
ExecStart={systemd_arg(args.python)} {systemd_arg(str(Path(args.helper_dir) / 'nms-snmpsim-run.py'))} --config {systemd_arg(args.config_path)}
Restart=always
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=read-only

[Install]
WantedBy=multi-user.target
''')
    (output / (name + '-reload.service')).write_text(f'''[Unit]
Description=Activate pending NMS simulator records

[Service]
Type=oneshot
ExecStart={systemd_arg(args.python)} {systemd_arg(str(Path(args.helper_dir) / 'nms-snmpsim-reload.py'))} --config {systemd_arg(args.config_path)}
TimeoutStartSec=90
''')
    (output / (name + '-reload.timer')).write_text(f'''[Unit]
Description=Check for NMS simulator uploads

[Timer]
OnBootSec=10s
OnUnitActiveSec=10s
AccuracySec=1s
Unit={name}-reload.service

[Install]
WantedBy=timers.target
''')
    for filename in ('nms-snmpsim-run.py', 'nms-snmpsim-reload.py', 'runtime_config.py'):
        shutil.copyfile(Path(__file__).with_name(filename), output / filename)
    return output


# Parse required server settings, verify account IDs, and generate a configuration bundle without starting services.
def main():
    parser = argparse.ArgumentParser(description=__doc__)
    if not sys.platform.startswith('linux'):
        parser.error('This generator is the optional Linux/systemd adapter. Use manual SNMPSim activation on other operating systems.')
    import pwd
    import grp
    for option in ('executable', 'data-dir', 'listen-address', 'client-address', 'user', 'group', 'service-name', 'output-dir',
                   'python', 'systemctl', 'helper-dir', 'config-path', 'lock-path'):
        parser.add_argument('--' + option, required=True)
    parser.add_argument('--port', type=int, required=True)
    parser.add_argument('--poller-id', type=int, required=True)
    args = parser.parse_args()
    try:
        if pwd.getpwnam(args.user).pw_uid == 0 or grp.getgrnam(args.group).gr_gid == 0:
            raise ValueError('The service account and group must not have root IDs')
        output = generate(args)
    except (ValueError, OSError, KeyError) as error:
        parser.error(str(error))
    print(f'Generated {output}. No files were installed and no service was started. See README.md for installation.')


if __name__ == '__main__':
    main()
