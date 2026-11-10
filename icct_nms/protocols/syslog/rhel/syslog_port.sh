#!/usr/bin/env bash
# Shared port for the managed listener, local forwarder and diagnostics.
ICCT_SYSLOG_RECEIVER=/etc/rsyslog.d/00-icct-nms.conf
ICCT_SYSLOG_LOCAL=/etc/rsyslog.d/01-icct-nms-local.conf
ICCT_SYSLOG_DEFAULT_PORT=514

icct_syslog_port() {
    if [[ ! "$1" =~ ^[1-9][0-9]{0,4}$ ]] || (( $1 > 65535 )); then
        echo 'SYSLOG_PORT must be an integer from 1 to 65535.' >&2
        return 1
    fi
    printf '%s\n' "$1"
}

icct_syslog_saved_port() {
    local saved=''
    if [[ -f "$ICCT_SYSLOG_RECEIVER" ]]; then
        saved=$(sed -n 's/^# ICCT_SYSLOG_PORT=//p' "$ICCT_SYSLOG_RECEIVER")
    fi
    printf '%s\n' "${saved:-$ICCT_SYSLOG_DEFAULT_PORT}"
}

icct_syslog_listening() {
    local sockets
    case "$1" in
        tcp) sockets=$(ss -H -ltn "sport = :$2") ;;
        udp) sockets=$(ss -H -lun "sport = :$2") ;;
        *) return 1 ;;
    esac
    [[ -n "$sockets" ]]
}

icct_syslog_write_forwarder() {
    local port=$1
    cat > "$ICCT_SYSLOG_LOCAL" <<RSYSLOG
# Only local inputs are forwarded. Messages arriving at the receiver cannot loop back.
if ((\$inputname == 'imjournal') or (\$inputname == 'imuxsock') or (\$inputname == 'imklog')) then {
    action(type="omfwd" target="127.0.0.1" port="$port" protocol="tcp"
        template="RSYSLOG_SyslogProtocol23Format"
        queue.type="LinkedList" queue.size="5000" action.resumeRetryCount="-1")
}
RSYSLOG
}
