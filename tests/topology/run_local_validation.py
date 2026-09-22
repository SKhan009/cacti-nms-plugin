#!/usr/bin/env python3
"""Run isolated, temporary local agents and read-only Cacti validation; remove secrets on exit."""
import argparse
import json
import os
from pathlib import Path
import pwd
import secrets
import shutil
import signal
import socket
import subprocess
import tempfile
import time


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--cacti-root', required=True, type=Path)
    parser.add_argument('--host-id', required=True, type=int)
    parser.add_argument('--snmpd-port', type=int, default=1163)
    parser.add_argument('--simulator-port', type=int, default=1164)
    parser.add_argument('--simulator-python', default='/opt/cacti-topology-validation/venv/bin/python')
    parser.add_argument('--client-user', default='apache')
    args = parser.parse_args()
    if os.geteuid() != 0:
        parser.error('Run with sudo to read Cacti settings and protect temporary profiles.')
    if args.snmpd_port == args.simulator_port or any(p < 1024 or p > 65535 for p in (args.snmpd_port, args.simulator_port)):
        parser.error('Choose two distinct unprivileged UDP ports.')
    for port in (args.snmpd_port, args.simulator_port):
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
            sock.bind(('127.0.0.1', port))
    account = pwd.getpwnam('topology-sim')
    client = pwd.getpwnam(args.client_user)
    plugin = args.cacti_root / 'plugins/topology'
    agents = []
    previous_umask = os.umask(0o077)
    def interrupted(signum, frame):
        raise KeyboardInterrupt()
    signal.signal(signal.SIGTERM, interrupted)
    try:
        with tempfile.TemporaryDirectory(prefix='topology-v3-', dir='/var/tmp') as tmp:
            root = Path(tmp)
            os.chown(root, account.pw_uid, client.pw_gid)
            root.chmod(0o710)
            for dirname in ('persistent', 'data', 'cache'):
                path = root / dirname
                path.mkdir()
                os.chown(path, account.pw_uid, account.pw_gid)
            auth, priv = secrets.token_hex(20), secrets.token_hex(20)
            common = dict(snmp_version='3', snmp_community='', snmp_password=auth,
                          snmp_priv_passphrase=priv, snmp_priv_protocol='AES',
                          snmp_context='', snmp_engine_id='', snmp_timeout='300')
            profiles = {'net_snmp': {}, 'simulator': []}
            conf = ['agentAddress udp:127.0.0.1:%d' % args.snmpd_port,
                    'view validation included .1', 'sysName topology-local-v3-validation',
                    'persistentDir %s' % (root / 'persistent')]
            for username, server_alg, client_alg in (('tp_sha', 'SHA', 'SHA'), ('tp_sha256', 'SHA-256', 'SHA256')):
                conf += [f'createUser {username} {server_alg} {auth} AES {priv}', f'rouser {username} priv -V validation']
                profiles['net_snmp'][client_alg + '/AES authPriv'] = dict(common, snmp_username=username, snmp_auth_protocol=client_alg, snmp_port=args.snmpd_port)
            config = root / 'snmpd.conf'
            config.write_text('\n'.join(conf) + '\n')
            os.chown(config, account.pw_uid, account.pw_gid)
            for suffix in ('a', 'b'):
                record = root / 'data' / ('v3-switch-' + suffix + '.snmprec')
                shutil.copyfile(plugin / 'tests' / 'fixtures' / 'protocols' / ('switch-' + suffix + '-dual.snmprec'), record)
                os.chown(record, account.pw_uid, account.pw_gid)
                profiles['simulator'].append(dict(common, snmp_username='tp_sim', snmp_auth_protocol='SHA', snmp_port=args.simulator_port, snmp_context='v3-switch-' + suffix))
            profile_file = root / 'profiles.json'
            profile_file.write_text(json.dumps(profiles))  # 0600; no core credentials copied here
            os.chown(profile_file, client.pw_uid, client.pw_gid)
            def demote():
                os.setgroups([])
                os.setgid(account.pw_gid)
                os.setuid(account.pw_uid)
            env = dict(os.environ, SNMP_PERSISTENT_DIR=str(root / 'persistent'), MIBS='')
            sim_log = (root / 'simulator-errors.log').open('w+')
            try:
                agents.append(subprocess.Popen(['/usr/sbin/snmpd', '-f', '-C', '-c', str(config), '-p', str(root / 'snmpd.pid')], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, preexec_fn=demote))
                simulator_options = ['--agent-udpv4-endpoint=127.0.0.1:%d' % args.simulator_port,
                    '--data-dir=' + str(root / 'data'), '--cache-dir=' + str(root / 'cache'), '--logging-method=stderr', '--log-level=error', '--v3-only',
                    '--v3-user=tp_sim', '--v3-auth-proto=SHA', '--v3-auth-key=' + auth,
                    '--v3-priv-proto=AES', '--v3-priv-key=' + priv]
                # Keep random simulator keys out of the OS process command line as well as logs.
                bootstrap = "import json,sys; from snmpsim.commands.responder import main; sys.argv=['snmpsim-command-responder',*json.load(sys.stdin)]; sys.exit(main())"
                agents.append(subprocess.Popen([args.simulator_python, '-c', bootstrap], stdin=subprocess.PIPE, stdout=subprocess.DEVNULL, stderr=sim_log, preexec_fn=demote))
                agents[-1].stdin.write(json.dumps(simulator_options).encode())
                agents[-1].stdin.close()
                time.sleep(3)
                if any(p.poll() is not None for p in agents):
                    sim_log.seek(0)
                    print(sim_log.read(8000).replace(auth, '[redacted]').replace(priv, '[redacted]'), flush=True)
                    raise RuntimeError('A temporary agent failed to start; no existing service was changed.')
                print('Running live checks as the Cacti worker account: ' + args.client_user, flush=True)
                result = subprocess.run(['/usr/sbin/runuser', '-u', args.client_user, '--', 'php', str(Path(__file__).resolve().parent / 'local_validation.php'), '--cacti-root=' + str(args.cacti_root), '--host-id=' + str(args.host_id), '--profiles=' + str(profile_file)], timeout=180)
                if result.returncode:
                    sim_log.seek(0)
                    print(sim_log.read(8000).replace(auth, '[redacted]').replace(priv, '[redacted]'), flush=True)
                    raise RuntimeError('Validation failed.')
            finally:
                for process in agents:
                    if process.poll() is None:
                        process.terminate()
                for process in agents:
                    try:
                        process.wait(timeout=5)
                    except subprocess.TimeoutExpired:
                        process.kill()
                        process.wait()
                sim_log.close()
    finally:
        os.umask(previous_umask)
        print('Temporary validation agents and profiles removed.', flush=True)


if __name__ == '__main__':
    try:
        main()
    except (Exception, KeyboardInterrupt):
        # Never print subprocess arguments: simulator arguments contain ephemeral keys.
        print('ERROR: local validation failed; review the preceding check output.', flush=True)
        raise SystemExit(1)
