"""Private namespace broker fixture: credentials, sessions and device edits stay in disposable storage."""

import json
import os
from pathlib import Path
import pwd
import grp
import subprocess
import time
import socket

base = Path(os.environ["ICCT_DISCOVERY_QA_DIR"])
if os.readlink("/proc/self/ns/net") == os.readlink("/proc/1/ns/net"):
    raise SystemExit("Private network namespace required.")
fixture = base / "fixtures"
root = Path("/var/www/html/cacti/plugins/icct_nms")
database = subprocess.run(
    [
        "php",
        "-r",
        "require '/var/www/html/cacti/include/cli_check.php';echo db_fetch_cell('SELECT DATABASE()');",
    ],
    capture_output=True,
    text=True,
    check=True,
).stdout
if not database.startswith("icct_discovery_qa_"):
    raise SystemExit("Disposable Cacti database required before fixture changes.")
# copytree preserves modes but not original group ownership; restore read access in the private copy only.
cacti = Path("/var/www/html/cacti")
for path in [cacti, *cacti.rglob("*")]:
    if path.is_symlink():
        continue
    os.chown(path, 0, grp.getgrnam("apache").gr_gid)
    path.chmod((path.stat().st_mode & 0o777) | (0o550 if path.is_dir() else 0o040))
account = pwd.getpwnam("nms-ssh")
group = grp.getgrnam("apache")
configuration = root / "ssh.config.local.json"
os.chown(configuration, 0, group.gr_gid)
configuration.chmod(0o640)
state = fixture / "broker-state"
(state / "secrets").mkdir(parents=True, exist_ok=True)
(state / "master.key").write_bytes(os.urandom(32))
for path in [state, state / "secrets", state / "master.key"]:
    os.chown(path, account.pw_uid, group.gr_gid)
    path.chmod(0o600 if path.is_file() else 0o700)
runtime = fixture / "broker-runtime"
runtime.mkdir(exist_ok=True)
os.chown(runtime, account.pw_uid, group.gr_gid)
runtime.chmod(0o750)
(runtime / "control.sock").unlink(missing_ok=True)
subprocess.run(["mount", "--bind", str(state), "/var/lib/nms-ssh"], check=True)
subprocess.run(["mount", "--bind", str(runtime), "/run/icct-nms-ssh"], check=True)
# Never reuse a real credential: the private store is mounted over the service path in this namespace only.
provision = """try {
require '/var/www/html/cacti/plugins/icct_nms/protocols/ssh/bootstrap.php';
require_once '/var/www/html/cacti/plugins/icct_nms/protocols/ssh/runtime.php';
if (!str_starts_with((string)db_fetch_cell('SELECT DATABASE()'),'icct_discovery_qa_')) exit(2);
$ref=(new NmsSshStore(icct_backend_ssh_config()))->put('password','IsolatedQaPassword2026');
$preset=(int)db_fetch_cell('SELECT preset_id FROM plugin_icct_nms_ssh_devices WHERE host_id=2');
if(!db_execute_prepared('UPDATE plugin_icct_nms_ssh_presets SET username=?,auth_method=?,credential_ref=?,enabled=1 WHERE id=?',['root','password',$ref,$preset]))exit(3);
echo json_encode(['ok'=>true]);
} catch(Throwable $error) {fwrite(STDERR,$error->getMessage());exit(1);} """
provision_result = subprocess.run(
    ["runuser", "-u", "nms-ssh", "-g", "apache", "--", "php", "-r", provision],
    capture_output=True,
    text=True,
)
if provision_result.returncode:
    raise RuntimeError("Private credential fixture failed: " + provision_result.stderr)

pin = " ".join((fixture / "host_key.pub").read_text().split()[:2])
setup = """try {
require '/var/www/html/cacti/plugins/icct_nms/protocols/ssh/bootstrap.php';
require_once '/var/www/html/cacti/plugins/icct_nms/protocols/ssh/runtime.php';
if (!str_starts_with((string)db_fetch_cell('SELECT DATABASE()'),'icct_discovery_qa_')) exit(2);
$device=icct_backend_ssh_device(2,false);
$observed=icct_backend_ssh_connect($device,true);
$expected=explode(' ',$argv[1]);$actual=explode(' ',$observed);
if(!hash_equals(base64_decode($expected[1],true),base64_decode($actual[1],true))) throw new RuntimeException('Private fixture host key mismatch.');
db_execute_prepared('UPDATE plugin_icct_nms_ssh_devices SET monitoring=0,host_key=?,verified_endpoint=? WHERE host_id=2',[$observed,'127.0.0.1:22']);
db_execute("UPDATE plugin_icct_nms_meta SET meta_value='0' WHERE meta_key='protocol_disabled_ssh_2'");
} catch(Throwable $error) {fwrite(STDERR,$error->getMessage());exit(1);} """

setup_result = subprocess.run(
    ["runuser", "-u", "nms-ssh", "-g", "apache", "--", "php", "-r", setup, pin],
    capture_output=True,
    text=True,
)
if setup_result.returncode:
    raise RuntimeError("Private host-key fixture failed: " + setup_result.stderr)

processes = []
logs = []
try:
    for command, name in [
        (
            [
                "runuser",
                "-u",
                "apache",
                "--",
                "/opt/icct-nms/native/guacamole-1.6.0/sbin/guacd",
                "-f",
                "-b",
                "127.0.0.1",
                "-l",
                "4823",
                "-L",
                "warning",
            ],
            "broker-guacd.log",
        ),
        (
            [
                "runuser",
                "-u",
                "nms-ssh",
                "-g",
                "apache",
                "--",
                "php",
                str(root / "protocols/ssh/gateway.php"),
            ],
            "broker.log",
        ),
    ]:
        log = (fixture / name).open("wb")
        logs.append(log)
        processes.append(
            subprocess.Popen(command, stdout=log, stderr=log, start_new_session=True)
        )
    for _ in range(80):
        if any(p.poll() is not None for p in processes):
            raise RuntimeError(
                "Private broker stopped: "
                + (fixture / "broker.log").read_text()[-1500:]
            )
        try:
            with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as probe:
                probe.settimeout(0.1)
                probe.connect(str(runtime / "control.sock"))
            break
        except OSError:
            time.sleep(0.1)
    else:
        raise RuntimeError("Private broker did not open its socket.")
    result = subprocess.run(
        ["php", str(root / "protocols/ssh/tests/broker_console_test.php")],
        capture_output=True,
        text=True,
        timeout=80,
    )
    print(result.stdout, end="")
    if result.returncode:
        print(result.stderr)
        raise SystemExit(result.returncode)
finally:
    import signal

    for process in processes:
        try:
            os.killpg(process.pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
    for process in processes:
        try:
            process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            os.killpg(process.pid, signal.SIGKILL)
            process.wait()
    for log in logs:
        log.close()
