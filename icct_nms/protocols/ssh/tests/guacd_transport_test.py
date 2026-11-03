"""Real strict Guacamole SSH sessions inside the disposable RHEL QA network only."""

import os
from pathlib import Path
import pwd
import socket
import signal
import subprocess
import time

fixture = Path(os.environ["ICCT_DISCOVERY_QA_DIR"]) / "fixtures"
if os.readlink("/proc/self/ns/net") == os.readlink("/proc/1/ns/net"):
    raise SystemExit("Refusing to test Guacamole outside a private network namespace.")


def instruction(parts):
    return (",".join(str(len(part)) + "." + part for part in parts) + ";").encode()


def read_instruction(connection):
    parts = []
    while True:
        digits = b""
        while True:
            char = connection.recv(1)
            if not char:
                raise EOFError("Guacamole connection closed")
            if char == b".":
                break
            digits += char
            if len(digits) > 6 or not digits.isdigit():
                raise ValueError("Invalid Guacamole frame")
        size = int(digits)
        if size > 1048576:
            raise ValueError("Oversized Guacamole frame")
        data = b""
        while len(data) < size:
            chunk = connection.recv(size - len(data))
            if not chunk:
                raise EOFError("Truncated Guacamole frame")
            data += chunk
        parts.append(data.decode())
        separator = connection.recv(1)
        if separator == b";":
            return parts
        if separator != b",":
            raise ValueError("Invalid Guacamole delimiter")


def attempt(name, credential, expected, pin, port=22, require_error=True, server_none=False):
    before = (fixture / "sshd.log").stat().st_size
    error = False
    with socket.create_connection(("127.0.0.1", 4823), timeout=3) as connection:
        connection.settimeout(1)
        connection.sendall(instruction(["select", "ssh"]))
        arguments = read_instruction(connection)
        assert arguments[0] == "args" and "host-key" in arguments
        values = {
            "hostname": "127.0.0.1",
            "port": str(port),
            "username": "root",
            "host-key": pin,
            "font-name": "monospace",
            "font-size": "12",
            "timeout": "3",
            "enable-sftp": "false",
            **credential,
        }
        for frame in [
            ["size", "800", "600", "96"],
            ["audio"],
            ["video"],
            ["image", "image/png"],
            ["connect"] + [values.get(key, "") for key in arguments[1:]],
        ]:
            connection.sendall(instruction(frame))
        until = time.monotonic() + 7
        while time.monotonic() < until:
            try:
                frame = read_instruction(connection)
                if frame[0] == "sync":
                    connection.sendall(instruction(frame))
                if frame[0] == "error":
                    error = True
                    break
                accepted = "Accepted " in (fixture / "sshd.log").read_text()[before:]
                if accepted and expected:
                    break
            except (EOFError, socket.timeout):
                break
    new_logs = (fixture / "sshd.log").read_text()[before:]
    accepted = "Accepted " in new_logs
    if server_none:
        assert (
            "Accepted none" in new_logs
        ), "The private server must actually allow SSH none authentication"
    else:
        assert accepted == expected, (
            name + " did not produce the expected real SSH authentication result"
        )
    if not expected and require_error:
        assert error, name + " must fail explicitly in Guacamole"
    print("PASS:", name)


account = pwd.getpwnam("apache")


def unprivileged():
    os.setgroups([])
    os.setgid(account.pw_gid)
    os.setuid(account.pw_uid)


with (fixture / "guacd.log").open("wb") as log:
    daemon = subprocess.Popen(
        [
            "/opt/icct-nms/native/guacamole-1.6.0/sbin/guacd",
            "-f",
            "-b",
            "127.0.0.1",
            "-l",
            "4823",
            "-L",
            "warning",
        ],
        stdout=log,
        stderr=log,
        preexec_fn=unprivileged,
        start_new_session=True,
    )
    try:
        for _ in range(50):
            try:
                with socket.create_connection(("127.0.0.1", 4823), timeout=0.1):
                    break
            except OSError:
                if daemon.poll() is not None:
                    raise RuntimeError("Private Guacamole daemon did not start")
                time.sleep(0.1)
        pin = "[127.0.0.1]:22 " + " ".join((fixture / "host_key.pub").read_text().split()[:2])
        key = (fixture / "client_key").read_text()
        attempt(
            "Guacamole explicit password",
            {"password": "IsolatedQaPassword2026"},
            True,
            pin,
        )
        attempt(
            "Guacamole wrong password without keyboard-interactive fallback",
            {"password": "wrong-password"},
            False,
            pin,
        )
        attempt("Guacamole explicit private key", {"private-key": key}, True, pin)
        attempt(
            "Guacamole does not authenticate with missing credentials",
            {},
            False,
            pin,
            require_error=False,
        )
        wrong_pin = "[127.0.0.1]:22 " + " ".join(
            (fixture / "client_key.pub").read_text().split()[:2]
        )
        attempt(
            "Guacamole rejects changed host key before authentication",
            {"password": "IsolatedQaPassword2026"},
            False,
            wrong_pin,
        )
        # Permit none only in this private namespace, after password/key tests have completed.
        shadow = fixture / "shadow"
        lines = shadow.read_text().splitlines()
        for index, line in enumerate(lines):
            if line.startswith("root:"):
                fields = line.split(":")
                fields[1] = ""
                lines[index] = ":".join(fields)
        shadow.write_text("\n".join(lines) + "\n")
        none_config = fixture / "sshd-none.conf"
        none_config.write_text(
            (fixture / "sshd.conf").read_text().replace("Port 22\n", "Port 2223\n")
            + "PermitEmptyPasswords yes\n"
        )
        with (fixture / "sshd-none.log").open("wb") as server_log:
            server = subprocess.Popen(
                ["/usr/sbin/sshd", "-D", "-e", "-f", str(none_config)],
                stdout=server_log,
                stderr=server_log,
            )
            try:
                time.sleep(0.2)
                assert server.poll() is None, "Private none-auth server failed to start"
                # Capture both fixture logs through the common reader without changing the primary server.
                primary_log = fixture / "sshd.log"
                primary_log.rename(fixture / "sshd-primary.log")
                primary_log.symlink_to(fixture / "sshd-none.log")
                try:
                    attempt(
                        "Strict Guacamole rejects server-accepted SSH none authentication",
                        {"password": "must-not-use-none"},
                        False,
                        pin.replace("]:22 ", "]:2223 "),
                        port=2223,
                        server_none=True,
                    )
                finally:
                    primary_log.unlink()
                    (fixture / "sshd-primary.log").rename(primary_log)
            finally:
                server.terminate()
                server.wait(timeout=5)
    finally:
        os.killpg(daemon.pid, signal.SIGTERM)
        try:
            daemon.wait(timeout=5)
        except subprocess.TimeoutExpired:
            daemon.kill()
            daemon.wait()
