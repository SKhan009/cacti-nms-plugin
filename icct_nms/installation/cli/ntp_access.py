#!/usr/bin/env python3
"""Install only the exact chrony peer-statistics read permission; never configure clocks."""
import argparse
import datetime
import os
from pathlib import Path
import pwd
import re
import shutil
import subprocess
import tempfile

MARKER = "# ICCT NMS managed read-only NTP peer statistics\n"
COMMANDS = [
    "/usr/bin/chronyc -n -c -h /run/chrony/chronyd.sock tracking",
    "/usr/bin/chronyc -n -c -h /run/chrony/chronyd.sock sourcestats",
    "/usr/bin/chronyc -n -h /run/chrony/chronyd.sock ntpdata",
]


def policy(users):
    names = sorted(set(users))
    if not names or any(not re.fullmatch(r"[a-z_][a-z0-9_-]*", name) for name in names):
        raise ValueError("Supply existing monitoring account names, without sudoers syntax.")
    return MARKER + "".join(
        f"{name} ALL=(chrony) NOPASSWD: {command}\n" for name in names for command in COMMANDS
    )


def configure(users, destination=Path("/etc/sudoers.d/icct-nms-ntp")):
    content = policy(users)
    if os.geteuid() != 0:
        raise ValueError("NTP read-only access installation requires root.")
    for name in ["chrony"] + sorted(set(users)):
        pwd.getpwnam(name)
    for binary in ["/usr/bin/chronyc", "/usr/bin/sudo", "/usr/sbin/visudo", "/usr/sbin/restorecon"]:
        if not os.access(binary, os.X_OK):
            raise ValueError("Required offline dependency is missing: " + binary)
    destination = Path(destination)
    if destination.is_symlink() or (destination.exists() and not destination.is_file()):
        raise ValueError("NTP access destination must be a regular managed file.")
    previous = destination.read_text() if destination.exists() else None
    if previous is not None and not previous.startswith(MARKER):
        raise ValueError("Refusing to replace an unmanaged NTP access policy.")
    if previous == content:
        if destination.stat().st_uid != 0 or destination.stat().st_mode & 0o777 != 0o440:
            raise ValueError("Existing NTP access policy must be root-owned with mode 0440.")
        subprocess.run(["/usr/sbin/restorecon", str(destination)], check=True, capture_output=True)
        return
    descriptor, name = tempfile.mkstemp(prefix=".icct-ntp-", dir=destination.parent)
    temporary = Path(name)
    try:
        with os.fdopen(descriptor, "w") as stream:
            stream.write(content)
        os.chmod(temporary, 0o440)
        os.chown(temporary, 0, 0)
        subprocess.run(["/usr/sbin/visudo", "-cf", str(temporary)], check=True, capture_output=True)
        if previous is not None:
            backups = Path("/var/backups/icct-nms/ntp-access")
            backups.mkdir(parents=True, exist_ok=True, mode=0o700)
            stamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%S%fZ")
            shutil.copy2(destination, backups / (stamp + ".sudoers"))
        os.replace(temporary, destination)
        subprocess.run(["/usr/sbin/restorecon", str(destination)], check=True, capture_output=True)
    finally:
        temporary.unlink(missing_ok=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=["plan", "install"])
    parser.add_argument(
        "--user",
        action="append",
        required=True,
        help="Existing local or SSH monitoring account; repeat for several accounts",
    )
    args = parser.parse_args()
    content = policy(args.user)
    if args.action == "plan":
        print(content, end="")
    else:
        configure(args.user)
        print(
            "Installed exact read-only chrony peer-statistics access. Clock services and configuration were unchanged."
        )


if __name__ == "__main__":
    try:
        main()
    except (ValueError, KeyError, OSError, subprocess.CalledProcessError) as error:
        raise SystemExit("NTP access setup stopped: " + str(error))
