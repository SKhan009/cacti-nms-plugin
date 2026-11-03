#!/usr/bin/env python3
"""Install the explicit Linux/systemd adapter. No database configuration is duplicated."""
import argparse
import json
import os
import pathlib
import pwd
import grp
import shutil
import subprocess
import sys
import re
import hashlib
import importlib.util
import tempfile
import time
from urllib.parse import urlsplit


def validate_origin(origin):
    parsed = urlsplit(origin)
    if (
        not re.fullmatch(r"https://[A-Za-z0-9.-]+(?::[0-9]+)?", origin)
        or not parsed.hostname
        or parsed.port is not None
        and not 1 <= parsed.port <= 65535
    ):
        raise ValueError(
            "Use an explicit HTTPS origin without a path, credentials or query."
        )
    return origin


def validate_settings(origin, service_user, state_directory, guacd_port):
    validate_origin(origin)
    if not re.fullmatch(r"[a-z_][a-z0-9_-]*", service_user):
        raise ValueError("Invalid SSH service account.")
    if not re.fullmatch(r"/var/lib/[a-z][a-z0-9_-]*", state_directory):
        raise ValueError(
            "Use an explicit private state directory directly under /var/lib."
        )
    if not 1024 <= guacd_port <= 65535:
        raise ValueError("Use an unprivileged Guacamole port.")


def verified_guacd(plugin, bundle):
    """Use only the pinned strict-auth build for the target OS/CPU, never a stock guacd."""
    spec = importlib.util.spec_from_file_location(
        "icct_ssh_native", plugin / "installation/cli/native.py"
    )
    native = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(native)
    data = native.verify_bundle(bundle, native.setup.os_info())
    if data["component"] != "guacamole-strict":
        raise ValueError("SSH requires the guacamole-strict native bundle.")
    prefix = pathlib.Path(native.PROFILES["guacamole-strict"]["prefix"])
    for name, details in data["files"].items():
        target = prefix / name
        for checked in [target, *target.parents]:
            if (
                checked.is_symlink()
                or checked.exists()
                and (checked.stat().st_uid != 0 or checked.stat().st_mode & 0o022)
            ):
                raise ValueError("Native SSH files must be root-owned and protected.")
        if (
            not target.is_file()
            or hashlib.sha256(target.read_bytes()).hexdigest() != details["sha256"]
        ):
            raise ValueError(
                "Install the verified native bundle before configuring SSH: "
                + str(target)
            )
    return prefix, data


def write_managed(path, content):
    """Retain a changed unit before atomic replacement; repeated setup is idempotent."""
    path = pathlib.Path(path)
    if path.is_symlink():
        raise ValueError("Refusing service configuration symlink: " + str(path))
    if path.exists() and path.read_text() == content:
        return
    if path.exists():
        saved = path.with_name(path.name + ".before-icct-ssh")
        if not saved.exists():
            shutil.copy2(path, saved)
    temporary = path.with_name(path.name + ".icct-new")
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o644)
    with os.fdopen(fd, "w") as stream:
        stream.write(content)
    os.replace(temporary, path)


def protect_http_configuration(plugin, family):
    """Deny static access even when .htaccess overrides are disabled by the administrator."""
    content = (
        '<Directory "'
        + str(plugin)
        + '">\n    <Files "ssh.config.local.json">\n        Require all denied\n    </Files>\n</Directory>\n'
    )
    if family == "rhel":
        write_managed("/etc/httpd/conf.d/icct-nms-ssh-private.conf", content)
        subprocess.run(["httpd", "-t"], check=True)
        subprocess.run(["systemctl", "reload", "httpd"], check=True)
    elif family == "debian":
        write_managed("/etc/apache2/conf-available/icct-nms-ssh-private.conf", content)
        subprocess.run(["a2enconf", "icct-nms-ssh-private"], check=True)
        subprocess.run(["apache2ctl", "configtest"], check=True)
        subprocess.run(["systemctl", "reload", "apache2"], check=True)
    else:
        raise ValueError("No supported Apache adapter for the SSH configuration guard.")


def configure_selinux(plugin):
    """Permit only the broker socket; retain enforcement and existing HTTP network policy."""
    getenforce = shutil.which("getenforce")
    if not getenforce or subprocess.check_output(
        [getenforce], text=True
    ).strip() not in ("Enforcing", "Permissive"):
        return
    makefile = pathlib.Path("/usr/share/selinux/devel/Makefile")
    for utility in ["make", "semodule", "semanage", "restorecon"]:
        if not shutil.which(utility):
            raise ValueError(
                "Provision SELinux build tools from the offline bundle: " + utility
            )
    if not makefile.is_file():
        raise ValueError("Provision selinux-policy-devel from the offline bundle.")
    with tempfile.TemporaryDirectory(prefix="icct-ssh-policy-") as directory:
        policy = pathlib.Path(directory) / "icct_backend_ssh.te"
        shutil.copyfile(plugin / "protocols/ssh/icct_nms_ssh.te", policy)
        subprocess.run(
            ["make", "-f", str(makefile), "icct_backend_ssh.pp"],
            cwd=directory,
            check=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )
        subprocess.run(["semodule", "-i", str(policy.with_suffix(".pp"))], check=True)
    # RHEL maps /run to /var/run in the SELinux file-context equivalence table.
    pattern = "/var/run/icct-nms-ssh(/.*)?"
    rules = subprocess.check_output(["semanage", "fcontext", "-l", "-C"], text=True)
    exists = any(
        line.split() and line.split()[0] == pattern for line in rules.splitlines()
    )
    subprocess.run(
        [
            "semanage",
            "fcontext",
            "-m" if exists else "-a",
            "-t",
            "icct_backend_ssh_runtime_t",
            pattern,
        ],
        check=True,
    )


def wait_for_broker(command):
    """Type=simple starts before PHP opens its socket; wait only for that same endpoint."""
    for attempt in range(40):
        result = subprocess.run(command, capture_output=True, timeout=5)
        if result.returncode == 0:
            return
        if result.returncode != 2:
            result.check_returncode()
        if attempt < 39:
            time.sleep(0.25)
    raise RuntimeError(
        "SSH broker did not open its configured socket within the startup deadline."
    )


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--cacti-path", required=True)
    p.add_argument("--origin", required=True)
    p.add_argument("--web-group", required=True)
    p.add_argument("--web-user", required=True)
    p.add_argument("--poller-id", required=True, type=int)
    p.add_argument("--service-user", default="icct-nms-ssh")
    p.add_argument("--guacd-port", type=int, default=4823)
    p.add_argument("--native-bundle", required=True)
    p.add_argument("--state-directory", default="/var/lib/icct-nms-ssh")
    a = p.parse_args()
    if sys.platform != "linux" or os.geteuid() != 0:
        p.error("Run as root on a Linux system with systemd.")
    base = pathlib.Path(a.cacti_path).resolve()
    plugin = base / "plugins/icct_nms"
    php = shutil.which("php")
    if (
        not (base / "include/cli_check.php").is_file()
        or not (plugin / "protocols/ssh/gateway.php").is_file()
        or not php
    ):
        p.error("Installed Cacti, ICCT NMS SSH files and PHP CLI are required.")
    validate_settings(a.origin, a.service_user, a.state_directory, a.guacd_port)
    if a.poller_id < 1:
        p.error("Use a valid native collector ID.")
    state = pathlib.Path(a.state_directory)
    prefix, native_manifest = verified_guacd(plugin, a.native_bundle)
    if not re.fullmatch(r"[a-z_][a-z0-9_-]*", a.web_group):
        p.error("Invalid service account or web group.")
    for utility in ["systemctl", "systemd-analyze", "setfacl", "useradd", "runuser"]:
        if not shutil.which(utility):
            p.error("Required service utility missing: " + utility)
    subprocess.run(
        [
            php,
            "-r",
            "foreach (['openssl','posix','pcntl','sockets'] as $e) {if (!extension_loaded($e)) {fwrite(STDERR, 'Missing PHP extension: '.$e);exit(2);}}",
        ],
        check=True,
    )
    group = grp.getgrnam(a.web_group)
    if pwd.getpwnam(a.web_user).pw_uid == 0:
        p.error("The PHP web account must not be root.")
    code = (
        'require $argv[1]."/include/cli_check.php"; if (!db_fetch_cell_prepared("SELECT id '
        'FROM poller WHERE id=?",array((int)$argv[2]))) exit(3); echo '
        'json_encode(array("url_path"=>$config["url_path"],"log_path"=>read_config_option("path_cactilog")));'
    )
    result = json.loads(
        subprocess.check_output(
            [php, "-r", code, str(base), str(a.poller_id)], text=True
        )
    )
    key = state / "master.key"
    config = {
        "control_socket": "/run/icct-nms-ssh/control.sock",
        "store_dir": str(state / "secrets"),
        "master_key": str(key),
        "origin": a.origin,
        "guacd_listen": "127.0.0.1:" + str(a.guacd_port),
        "poller_id": a.poller_id,
    }
    config_file = plugin / "ssh.config.local.json"
    for checked in [
        str(state),
        str(state / "secrets"),
        str(state / "master.key"),
        str(plugin / "ssh.config.local.json"),
    ]:
        if pathlib.Path(checked).is_symlink():
            p.error("Refusing symlink in service configuration: " + checked)
    if config_file.exists() and json.loads(config_file.read_text()) != config:
        p.error(
            "Existing SSH configuration differs. Review and retain it; installer will not overwrite it."
        )
    try:
        user = pwd.getpwnam(a.service_user)
    except KeyError:
        subprocess.run(
            [
                "useradd",
                "--system",
                "--home-dir",
                str(state),
                "--shell",
                "/sbin/nologin",
                a.service_user,
            ],
            check=True,
        )
        user = pwd.getpwnam(a.service_user)
    if user.pw_uid == 0 or user.pw_shell not in [
        "/sbin/nologin",
        "/usr/sbin/nologin",
        "/bin/false",
    ]:
        p.error(
            "Use a dedicated non-root service account without an interactive shell."
        )
    for suffix in ["", "/secrets"]:
        path = pathlib.Path(str(state) + suffix)
        if path.exists() and (
            path.stat().st_uid != user.pw_uid or path.stat().st_mode & 0o077
        ):
            p.error(
                "Existing SSH state must belong to the selected private service account."
            )
        path.mkdir(exist_ok=True)
        os.chown(path, user.pw_uid, group.gr_gid)
        path.chmod(0o700)
    if not key.exists():
        fd = os.open(key, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, "wb") as stream:
            stream.write(os.urandom(32))
    if (
        key.stat().st_uid not in [0, user.pw_uid]
        or len(key.read_bytes()) != 32
        or key.stat().st_mode & 0o077
    ):
        p.error("Existing SSH key has invalid ownership, length or permissions.")
    os.chown(key, user.pw_uid, group.gr_gid)
    key.chmod(0o600)
    write_managed(config_file, json.dumps(config, indent=2) + "\n")
    os.chown(config_file, 0, group.gr_gid)
    config_file.chmod(0o640)
    unit = (plugin / "protocols/ssh/icct-nms-ssh.service.in").read_text()
    for tag, value in {
        "SERVICE_USER": a.service_user,
        "WEB_GROUP": a.web_group,
        "PHP_BINARY": php,
        "PLUGIN_PATH": str(plugin),
        "CACTI_PATH": str(base),
        "CACTI_LOG": result["log_path"],
        "STATE_NAME": state.name,
    }.items():
        if any(c in value for c in '\n\r"\\%'):
            p.error(
                "Service paths and identities cannot contain control characters or systemd escape characters."
            )
        unit = unit.replace("@" + tag + "@", value)
    guacd_unit = (
        plugin / "protocols/ssh/guacamole/icct-nms-guacd.service.in"
    ).read_text()
    guacd_user = "icct-nms-guacd"
    try:
        daemon = pwd.getpwnam(guacd_user)
    except KeyError:
        subprocess.run(
            [
                "useradd",
                "--system",
                "--user-group",
                "--home-dir",
                "/nonexistent",
                "--shell",
                "/sbin/nologin",
                guacd_user,
            ],
            check=True,
        )
        daemon = pwd.getpwnam(guacd_user)
    if daemon.pw_uid == 0 or daemon.pw_shell not in [
        "/sbin/nologin",
        "/usr/sbin/nologin",
        "/bin/false",
    ]:
        p.error("Guacamole requires its dedicated non-interactive account.")
    guacd_unit = guacd_unit.replace(
        "@GUACD_BINARY@", str(prefix / "sbin/guacd")
    ).replace("@GUACD_PORT@", str(a.guacd_port))
    protect_http_configuration(plugin, native_manifest["target"]["family"])
    configure_selinux(plugin)
    write_managed("/etc/systemd/system/icct-nms-guacd.service", guacd_unit)
    write_managed("/etc/systemd/system/icct-nms-ssh.service", unit)
    # This proof contains hashes only. Never copy credentials into an installer receipt.
    write_managed(
        prefix / "icct-verified.json", json.dumps(native_manifest, indent=2) + "\n"
    )
    subprocess.run(
        ["setfacl", "-m", "u:" + a.service_user + ":rw", result["log_path"]], check=True
    )
    subprocess.run(
        [
            "systemd-analyze",
            "verify",
            "/etc/systemd/system/icct-nms-guacd.service",
            "/etc/systemd/system/icct-nms-ssh.service",
        ],
        check=True,
    )
    subprocess.run(["systemctl", "daemon-reload"], check=True)
    for name in ["icct-nms-guacd.service", "icct-nms-ssh.service"]:
        subprocess.run(["systemctl", "enable", "--now", name], check=True)
        subprocess.run(["systemctl", "restart", name], check=True)
        subprocess.run(["systemctl", "is-active", "--quiet", name], check=True)
    if shutil.which("restorecon"):
        subprocess.run(
            ["restorecon", "-RF", "/run/icct-nms-ssh", str(config_file)], check=True
        )
    # A running process alone is insufficient: require the actual local control socket.
    probe = "require $argv[1].'/include/cli_check.php';require_once $argv[1].'/plugins/icct_nms/shared/services/backend.php';$c=icct_backend_ssh_config();$s=@stream_socket_client('unix://'.$c['control_socket'],$n,$e,3);if(!$s)exit(2);fclose($s);"
    wait_for_broker(["runuser", "-u", a.web_user, "--", php, "-r", probe, str(base)])
    print(
        json.dumps(
            {
                "configuration": str(config_file),
                "service": "icct-nms-ssh",
                "guacd_service": "icct-nms-guacd",
                "guacd_listen": config["guacd_listen"],
                "origin": a.origin,
                "status": "ready",
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
