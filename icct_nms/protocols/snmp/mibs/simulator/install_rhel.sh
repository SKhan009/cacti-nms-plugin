#!/usr/bin/env bash
set -euo pipefail

if (( EUID != 0 || $# > 1 )); then
    echo "Run as root: bash install_rhel.sh [cacti-php-user]" >&2
    exit 1
fi

web_user=${1:-apache}
if ! web_uid=$(id -u "$web_user") || [[ $web_uid == 0 ]]; then
    echo "Specify the existing unprivileged account that serves Cacti PHP requests." >&2
    exit 1
fi
if [[ ! -x /usr/bin/python3 || ! -d /run/systemd/system ]]; then
    echo "The simulator requires Python 3 and a running systemd on the RHEL collector." >&2
    exit 1
fi

selinux=0
if command -v getenforce >/dev/null && [[ $(getenforce) != Disabled ]]; then
    selinux=1
    if ! command -v semanage >/dev/null || ! command -v restorecon >/dev/null; then
        echo "Install policycoreutils-python-utils from matching offline RHEL media first." >&2
        exit 1
    fi
fi

source_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
state=/var/lib/icct-nms-mib-simulator
unit=icct-nms-mib-simulator.service

getent group snmpsim >/dev/null || groupadd --system snmpsim
id -u snmpsim >/dev/null 2>&1 || useradd --system --gid snmpsim --no-create-home --shell /sbin/nologin snmpsim
install -d -m 2750 -o "$web_user" -g snmpsim "$state"
install -d /usr/local/libexec
install -m 0644 "$source_dir/serve.py" /usr/local/libexec/icct-nms-mib-simulator.py
install -m 0644 "$source_dir/$unit" "/etc/systemd/system/$unit"

if (( selinux )); then
    pattern="$state(/.*)?"
    if semanage fcontext -l -C | awk -v pattern="$pattern" '$1 == pattern { found=1 } END { exit !found }'; then
        semanage fcontext -m -t httpd_sys_rw_content_t "$pattern"
    else
        semanage fcontext -a -t httpd_sys_rw_content_t "$pattern"
    fi
    restorecon -RF "$state"
    restorecon /usr/local/libexec/icct-nms-mib-simulator.py "/etc/systemd/system/$unit"
fi

systemctl daemon-reload
systemctl enable "$unit"
systemctl restart "$unit"
systemctl is-active --quiet "$unit"
echo "MIB Simulator service is ready. Open MIB Repository → Simulator."
