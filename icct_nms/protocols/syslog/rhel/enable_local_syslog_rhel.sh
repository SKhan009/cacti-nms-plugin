#!/usr/bin/env bash
set -euo pipefail

# Route new journal records through the existing receiver and its device binding.
if [[ $EUID -ne 0 ]]; then
    echo 'Run as root.' >&2
    exit 2
fi
source "$(dirname "${BASH_SOURCE[0]}")/syslog_port.sh"
port=$(icct_syslog_port "$(icct_syslog_saved_port)")
if ! systemctl is-active --quiet rsyslog || ! icct_syslog_listening tcp "$port"; then
    echo "Start the ICCT Syslog receiver on TCP $port first." >&2
    exit 2
fi

target=$ICCT_SYSLOG_LOCAL
backup=$(mktemp)
had_config=0
if [[ -f "$target" ]]; then
    cp -p "$target" "$backup"
    had_config=1
fi
trap 'rm -f "$backup"' EXIT
icct_syslog_write_forwarder "$port"
chmod 0644 "$target"
restorecon "$target"
if ! rsyslogd -N1 || ! systemctl restart rsyslog; then
    if [[ $had_config -eq 1 ]]; then
        cp -p "$backup" "$target"
        restorecon "$target"
    else
        rm -f "$target"
    fi
    systemctl restart rsyslog
    echo 'Local journal forwarding could not start. Restored the previous configuration.' >&2
    exit 1
fi
echo "Local Syslog forwarding enabled on loopback TCP $port."
