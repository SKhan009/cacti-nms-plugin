#!/usr/bin/env bash
set -euo pipefail

# Route new journal records through the existing receiver and its device binding.
if [[ $EUID -ne 0 ]]; then
    echo 'Run as root.' >&2
    exit 2
fi
if ! systemctl is-active --quiet rsyslog ||
    ! ss -H -ltn 'sport = :514' | grep . >/dev/null; then
    echo 'Start the ICCT Syslog receiver on TCP 514 first.' >&2
    exit 2
fi

target=/etc/rsyslog.d/01-icct-nms-local.conf
backup=$(mktemp)
had_config=0
if [[ -f "$target" ]]; then
    cp -p "$target" "$backup"
    had_config=1
fi
trap 'rm -f "$backup"' EXIT
cat > "$target" <<'RSYSLOG'
# Only local inputs are forwarded. Messages arriving at the receiver cannot loop back.
if (($inputname == 'imjournal') or ($inputname == 'imuxsock') or ($inputname == 'imklog')) then {
    action(type="omfwd" target="127.0.0.1" port="514" protocol="tcp"
        template="RSYSLOG_SyslogProtocol23Format"
        queue.type="LinkedList" queue.size="5000" action.resumeRetryCount="-1")
}
RSYSLOG
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
echo 'Local Syslog forwarding enabled on loopback TCP 514.'
