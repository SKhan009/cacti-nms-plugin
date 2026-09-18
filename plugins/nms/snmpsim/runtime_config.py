"""Read the explicitly selected administrator-owned configuration; no implicit path or defaults."""
import argparse
import ipaddress
import json
from pathlib import Path
import re
import stat


def load_config():
    """Validate trusted settings before a service runner can execute a process as its service identity."""
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', required=True)
    args = parser.parse_args()
    path = Path(args.config)
    if not path.is_absolute() or path.is_symlink() or not path.is_file():
        parser.error('Configuration must be an explicit absolute regular file')
    for trusted in (path, path.parent):
        info = trusted.stat()
        if info.st_uid != 0 or stat.S_IMODE(info.st_mode) & 0o022:
            parser.error('Service configuration and its directory must be administrator-owned and not group/world writable')
    config = json.loads(path.read_text(encoding='utf-8'))
    if config.get('activation') != 'systemd':
        parser.error('The service runner requires explicit systemd activation')
    if 'auto_start' in config and type(config['auto_start']) is not bool:
        parser.error('auto_start must be a boolean')
    if 'ui_control' in config and type(config['ui_control']) is not bool:
        parser.error('ui_control must be a boolean')
    if config.get('ui_control') and not config.get('status_path'):
        parser.error('UI control requires a protected status_path')
    for key in ('executable', 'data_dir', 'systemctl', 'lock_path'):
        value = config.get(key)
        if not isinstance(value, str) or not Path(value).is_absolute() or value == '/' or '..' in Path(value).parts or any(ord(c) < 32 for c in value):
            parser.error(f'Invalid {key} path')
    if 'status_path' in config:
        status_path = config['status_path']
        if not isinstance(status_path, str) or not Path(status_path).is_absolute() or status_path == '/' or '..' in Path(status_path).parts or any(ord(c) < 32 for c in status_path):
            parser.error('Invalid status_path path')
    for key in ('listen_address', 'client_address'):
        ipaddress.IPv4Address(config[key])
    if type(config.get('port')) is not int or not 1 <= config['port'] <= 65535:
        parser.error('Invalid UDP port')
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_-]{0,63}\.service', config.get('service', '')):
        parser.error('Invalid service name')
    return config
