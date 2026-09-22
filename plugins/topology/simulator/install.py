#!/usr/bin/env python3
"""Configure the isolated topology SNMPSim on an explicitly selected local RHEL/Cacti server."""
import argparse
import json
import os
from pathlib import Path
import pwd
import grp
import shutil
import subprocess


def run(*args):
    """Run a fixed administrative command with literal arguments and checked status."""
    subprocess.run(args, check=True)


def write(path, content, mode=0o644):
    """Write administrator configuration without following pre-existing symbolic links."""
    p = Path(path)
    if p.is_symlink():
        raise ValueError('Refusing symbolic link: ' + str(p))
    p.write_text(content)
    p.chmod(mode)


def main():
    """Install only the dedicated topology service, queue worker and selected native template IDs."""
    p = argparse.ArgumentParser()
    p.add_argument('--cacti-path', required=True)
    p.add_argument('--executable', required=True)
    p.add_argument('--port', type=int, required=True)
    p.add_argument('--poller-id', type=int, required=True)
    p.add_argument('--data-template-id', type=int, required=True)
    p.add_argument('--graph-template-id', type=int, required=True)
    p.add_argument('--interface-query-id', type=int, required=True)
    p.add_argument('--web-user', required=True)
    a = p.parse_args()
    if os.geteuid() != 0:
        raise ValueError('Run the explicit service installer as root')
    cacti = Path(a.cacti_path).resolve(strict=True)
    executable = Path(a.executable)
    if not executable.is_absolute() or not os.access(executable, os.X_OK) or not 1024 <= a.port <= 65535:
        raise ValueError('Provide an installed SNMPSim executable and unprivileged UDP port')
    for value in (a.poller_id, a.data_template_id, a.graph_template_id, a.interface_query_id):
        if value < 1:
            raise ValueError('Explicit native IDs must be positive')
    root = Path('/var/lib/cacti-topology')
    cfg = dict(data_dir=str(root/'data'), cache_dir=str(root/'cache'), status_file=str(root/'status'/'simulator.state'),
               lock_file=str(root/'status'/'reload.lock'), executable=str(executable), address='127.0.0.1', port=a.port,
               poller_id=a.poller_id, data_template_id=a.data_template_id, graph_template_id=a.graph_template_id,
               interface_query_id=a.interface_query_id)
    config_dir = Path('/etc/cacti-topology')
    config_dir.mkdir(mode=0o755, exist_ok=True)
    config_path = config_dir/'simulator.json'
    if config_path.exists() and json.loads(config_path.read_text()) != cfg:
        raise ValueError('Existing simulator configuration differs; review it before applying changes')
    try:
        pwd.getpwnam('topology-sim')
    except KeyError:
        run('/usr/sbin/useradd', '--system', '--home-dir', str(root), '--shell', '/sbin/nologin', 'topology-sim')
    uid = pwd.getpwnam('topology-sim').pw_uid
    gid = grp.getgrnam('topology-sim').gr_gid
    web_uid = pwd.getpwnam(a.web_user).pw_uid
    for path, owner, group, mode in ((root, 0, 0, 0o755), (root/'data', web_uid, gid, 0o2750),
                                    (root/'cache', uid, gid, 0o750), (root/'status', 0, 0, 0o755)):
        path.mkdir(mode=mode, exist_ok=True)
        if path.is_symlink():
            raise ValueError('Unexpected symlink: ' + str(path))
        os.chown(path, owner, group)
        path.chmod(mode)
    write(config_path, json.dumps(cfg, indent=2)+'\n')
    # JSON contains only explicit paths/IDs, no credentials. PHP is generated with literal strings.
    php = '<?php\n/** Administrator-selected simulator settings. */\nreturn ' + 'array(\n'
    for key, val in cfg.items():
        literal = str(val) if isinstance(val, int) else "'" + val.replace('\\', '\\\\').replace("'", "\\'") + "'"
        php += "    '"+key+"' => "+literal+",\n"
    php += ');\n'
    write(cacti/'plugins/topology/simulator.local.php', php)
    helper = Path('/usr/local/libexec/topology-snmpsim.py')
    helper.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
    shutil.copyfile(cacti/'plugins/topology/simulator/service.py', helper)
    helper.chmod(0o755)
    unit = '''[Unit]
Description=Topology isolated SNMP simulator
After=network.target

[Service]
User=topology-sim
Group=topology-sim
ExecStart=/usr/bin/python3 /usr/local/libexec/topology-snmpsim.py run --config /etc/cacti-topology/simulator.json
Restart=on-failure
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/lib/cacti-topology/cache

[Install]
WantedBy=multi-user.target
'''
    write('/etc/systemd/system/topology-snmpsim.service', unit)
    write('/etc/systemd/system/topology-snmpsim-reload.service', '''[Unit]
Description=Activate queued topology simulator records

[Service]
Type=oneshot
ExecStart=/usr/bin/python3 /usr/local/libexec/topology-snmpsim.py reload --config /etc/cacti-topology/simulator.json
''')
    write('/etc/systemd/system/topology-snmpsim-reload.timer', '''[Unit]
Description=Check topology simulator activation queue

[Timer]
OnBootSec=10s
OnUnitActiveSec=10s
AccuracySec=1s

[Install]
WantedBy=timers.target
''')
    # RHEL commonly disables .htaccess overrides. Enforce protection in Apache itself.
    plugin_path = str(cacti/'plugins/topology')
    if any(char in plugin_path for char in ('"', '\n', '\r', '\\')):
        raise ValueError('Cacti path cannot be represented safely in Apache configuration')
    apache_rules = '<Directory "'+plugin_path+'">\n    Options -Indexes\n'
    apache_rules += '    <Files "simulator.local.php">\n        Require all denied\n    </Files>\n</Directory>\n'
    for private_dir in ('includes', 'tests', 'simulator', 'templates'):
        apache_rules += '<Directory "'+plugin_path+'/'+private_dir+'">\n    Require all denied\n</Directory>\n'
    write('/etc/httpd/conf.d/cacti-topology.conf', apache_rules)
    run('/usr/sbin/httpd', '-t')
    run('/usr/bin/systemctl', 'reload', 'httpd.service')
    # Apply a narrow writable-data label, keeping SELinux enabled and existing policies intact.
    if shutil.which('selinuxenabled') and subprocess.run(['selinuxenabled']).returncode == 0:
        for expression, label in ((str(root/'data')+'(/.*)?', 'httpd_sys_rw_content_t'),
                                  (str(root/'status')+'(/.*)?', 'httpd_sys_content_t')):
            existing = subprocess.run(['semanage', 'fcontext', '-l', '-C'], capture_output=True, text=True, check=True).stdout
            if expression in existing:
                if label not in next(line for line in existing.splitlines() if expression in line):
                    raise ValueError('Existing SELinux label conflicts: '+expression)
            else:
                run('semanage', 'fcontext', '-a', '-t', label, expression)
        run('restorecon', '-RF', str(root/'data'), str(root/'status'))
    run('/usr/bin/systemctl', 'daemon-reload')
    run('/usr/bin/systemctl', 'enable', '--now', 'topology-snmpsim.service', 'topology-snmpsim-reload.timer')
    run('/usr/bin/systemctl', 'is-active', 'topology-snmpsim.service')
    run('/usr/bin/python3', str(cacti/'plugins/topology/simulator/install_provision.py'), '--cacti-path', str(cacti), '--web-user', a.web_user)
    print('Isolated simulator configured. Existing NMS service and records were not changed.')


if __name__ == '__main__':
    main()
