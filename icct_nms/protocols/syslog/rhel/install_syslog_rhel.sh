#!/usr/bin/env bash
set -euo pipefail

CACTI_ROOT="${1:-/usr/share/cacti}"
OPEN_FIREWALL="${OPEN_FIREWALL:-0}"
PLUGIN="$CACTI_ROOT/plugins/icct_nms"
# Load the ICCT copy rule before ordinary /etc/rsyslog.d rules so later `stop`
# statements do not prevent the NMS spool from receiving network messages.
SCRIPT_DIR=$(dirname "${BASH_SOURCE[0]}")
source "$SCRIPT_DIR/syslog_port.sh"
port=$(icct_syslog_port "${SYSLOG_PORT-$(icct_syslog_saved_port)}")
CONF=$ICCT_SYSLOG_RECEIVER
LEGACY_CONF=/etc/rsyslog.d/30-icct-nms.conf
SPOOL_DIR=/var/log/icct-nms
SPOOL_FILE=$SPOOL_DIR/remote.ndjson
TEMPLATE_FILE="$SCRIPT_DIR/rsyslog-template.conf"

if [[ $EUID -ne 0 ]]; then
    echo "Run as root." >&2
    exit 1
fi
if [[ ! -f "$PLUGIN/setup.php" || ! -f "$CACTI_ROOT/include/global.php" ]]; then
    echo "Cacti/ICCT plugin not found under $CACTI_ROOT" >&2
    exit 1
fi
if [[ ! -s "$TEMPLATE_FILE" ]]; then
    echo "Missing Syslog template: $TEMPLATE_FILE" >&2
    exit 1
fi
# The rsyslog service runs in its own SELinux domain and cannot read the Cacti tree.
rsyslog_template="$(cat "$TEMPLATE_FILE")"
if ! command -v rsyslogd > /dev/null 2>&1; then
    echo "rsyslog is missing. Install it from approved offline media for this host." >&2
    exit 1
fi
for cmd in systemctl php ss awk grep sed stat install; do
    command -v "$cmd" > /dev/null 2>&1 || {
        echo "Missing required command: $cmd" >&2
        exit 1
    }
done
WEB_USER="${WEB_USER:-$(ps -eo user=,comm= | awk '$2 ~ /^httpd$/ && $1 != "root" {print $1; exit}')}"
WEB_USER="${WEB_USER:-apache}"
WEB_GROUP="${WEB_GROUP:-$(id -gn "$WEB_USER" 2> /dev/null || echo apache)}"

# A listener bound to a named ruleset bypasses our early default-ruleset copy.
# Report the owning file before changing either the installed receiver or its spool.
otherconf=''
has_udp_input=0
has_tcp_input=0
for f in /etc/rsyslog.conf /etc/rsyslog.d/*.conf; do
    [[ -f "$f" && "$f" != "$CONF" && "$f" != "$LEGACY_CONF" ]] || continue
    config="$(sed '/^[[:space:]]*#/d' "$f")"
    inputs="$(tr '\n' ' ' <<< "$config" | grep -Eio 'input[[:space:]]*\([^)]*\)' || true)"
    listeners="$(grep -Ei "[ (]port[[:space:]]*=[[:space:]]*\"?$port\"?([[:space:])]|$)" <<< "$inputs" || true)"
    if grep -Ei '[ (]type[[:space:]]*=[[:space:]]*"im(udp|tcp)"' <<< "$listeners" |
        grep -Ei 'ruleset[[:space:]]*=' |
        grep -Eiv 'ruleset[[:space:]]*=[[:space:]]*"RSYSLOG_DefaultRuleset"' > /dev/null; then
        echo "Syslog UDP/TCP $port in $f routes to a separate ruleset that bypasses ICCT NMS." >&2
        echo "Move that listener to the default ruleset or retire it so the ICCT installer can create its own listener, then rerun setup." >&2
        exit 1
    fi
    grep -Eqi '[ (]type[[:space:]]*=[[:space:]]*"imudp"' <<< "$listeners" && has_udp_input=1
    grep -Eqi '[ (]type[[:space:]]*=[[:space:]]*"imtcp"' <<< "$listeners" && has_tcp_input=1
    otherconf+=$'\n'"$config"
done

echo "Cacti root : $CACTI_ROOT"
echo "Web account: $WEB_USER:$WEB_GROUP"

install -d -o root -g "$WEB_GROUP" -m 0750 "$SPOOL_DIR"
touch "$SPOOL_FILE"
chown root:"$WEB_GROUP" "$SPOOL_FILE"
chmod 0640 "$SPOOL_FILE"
if command -v restorecon > /dev/null 2>&1; then restorecon -RF "$SPOOL_DIR"; fi

STAMP="$(date +%Y%m%d%H%M%S)"
CONF_BACKUP=""
LEGACY_BACKUP=""
LOCAL_BACKUP=""
if [[ -f "$ICCT_SYSLOG_LOCAL" ]]; then
    LOCAL_BACKUP="$ICCT_SYSLOG_LOCAL.bak.$STAMP"
    cp -a "$ICCT_SYSLOG_LOCAL" "$LOCAL_BACKUP"
fi
if [[ -f "$CONF" ]]; then
    CONF_BACKUP="$CONF.bak.$STAMP"
    cp -a "$CONF" "$CONF_BACKUP"
fi
# v1.2.13 and earlier used 30-icct-nms.conf. Disable it while validating the
# new early copy rule so templates/actions are not duplicated.
if [[ -f "$LEGACY_CONF" ]]; then
    LEGACY_BACKUP="$LEGACY_CONF.disabled.$STAMP"
    mv "$LEGACY_CONF" "$LEGACY_BACKUP"
fi

# Detect whether another administrator-managed rsyslog file owns the configured
# port. If it does, ICCT does not bind the same socket; instead the early global
# rule copies network-input messages into the ICCT spool. If it does not, ICCT
# creates dedicated listeners bound directly to an ICCT ruleset.
grep -Eqi '\$UDPServerRun[[:space:]]+'"$port"'([[:space:]]|$)' <<< "$otherconf" && has_udp_input=1 || true
grep -Eqi '\$InputTCPServerRun[[:space:]]+'"$port"'([[:space:]]|$)' <<< "$otherconf" && has_tcp_input=1 || true

load_udp=''
load_tcp=''
input_udp=''
input_tcp=''
if [[ $has_udp_input -eq 0 ]]; then
    if ! grep -Eqi 'module\(load="imudp"\)|\$ModLoad[[:space:]]+imudp' <<< "$otherconf"; then
        load_udp='module(load="imudp")'
    fi
    input_udp="input(type=\"imudp\" port=\"$port\" name=\"icct-udp\" ruleset=\"icct-nms-network\")"
fi
if [[ $has_tcp_input -eq 0 ]]; then
    if ! grep -Eqi 'module\(load="imtcp"\)|\$ModLoad[[:space:]]+imtcp' <<< "$otherconf"; then
        load_tcp='module(load="imtcp")'
    fi
    input_tcp="input(type=\"imtcp\" port=\"$port\" name=\"icct-tcp\" ruleset=\"icct-nms-network\")"
fi

cat > "$CONF" << RSYSLOG
# Managed by ICCT NMS.
# ICCT_SYSLOG_PORT=$port
# Existing system logging is preserved. This file adds an early structured copy
# of network Syslog into the ICCT NDJSON spool and never discards other system logging.
$load_udp
$load_tcp

$rsyslog_template

ruleset(name="icct-nms-network") {
  action(type="omfile"
    file="$SPOOL_FILE"
    template="ICCTNMSJson"
    fileOwner="root"
    fileGroup="$WEB_GROUP"
    fileCreateMode="0640"
    dirCreateMode="0750"
    createDirs="on")
}

$input_udp
$input_tcp

# If another configuration already owns the configured port and leaves messages in the
# default ruleset, copy every non-local-input message before later rules can
# discard it. Local journal/socket/kernel messages are excluded.
if ((\$inputname != 'imjournal') and (\$inputname != 'imuxsock') and (\$inputname != 'imklog') and (\$inputname != 'impstats') and (\$inputname != 'icct-udp') and (\$inputname != 'icct-tcp')) then {
  action(type="omfile"
    file="$SPOOL_FILE"
    template="ICCTNMSJson"
    fileOwner="root"
    fileGroup="$WEB_GROUP"
    fileCreateMode="0640"
    dirCreateMode="0750"
    createDirs="on")
}
RSYSLOG

rollback_rsyslog() {
    if [[ -n "$LOCAL_BACKUP" ]]; then
        cp -a "$LOCAL_BACKUP" "$ICCT_SYSLOG_LOCAL"
    fi
    if [[ -n "${CONF_BACKUP:-}" && -f "$CONF_BACKUP" ]]; then
        cp -a "$CONF_BACKUP" "$CONF"
    else
        rm -f "$CONF"
    fi
    if [[ -n "${LEGACY_BACKUP:-}" && -f "$LEGACY_BACKUP" ]]; then
        mv "$LEGACY_BACKUP" "$LEGACY_CONF"
    fi
    systemctl restart rsyslog > /dev/null 2>&1 || true
}

if [[ -n "$LOCAL_BACKUP" ]] && ! icct_syslog_write_forwarder "$port"; then
    rollback_rsyslog
    exit 1
fi
if ! rsyslogd -N1; then
    echo "rsyslog validation failed; restoring the previous ICCT configuration." >&2
    rollback_rsyslog
    exit 1
fi
if [[ "${SYSLOG_RECEIVER_ONLY:-0}" != "1" ]]; then
    systemctl enable rsyslog || { rollback_rsyslog; exit 1; }
fi
if ! systemctl restart rsyslog ||
    ! icct_syslog_listening udp "$port" || ! icct_syslog_listening tcp "$port"; then
    echo "rsyslog could not listen on TCP and UDP port $port; restoring the previous ICCT configuration." >&2
    echo "Check socket ownership and SELinux syslogd_port_t for the configured port." >&2
    rollback_rsyslog
    exit 1
fi

if [[ "${SYSLOG_RECEIVER_ONLY:-0}" == "1" ]]; then
    echo "Syslog receiver listening on TCP and UDP port $port."
    exit 0
fi

# Start the importer only after the receiver configuration has been validated.
sed -e "s|@CACTI_ROOT@|$CACTI_ROOT|g" \
    -e "s|@WEB_USER@|$WEB_USER|g" \
    -e "s|@WEB_GROUP@|$WEB_GROUP|g" \
    -e "s|/usr/bin/php|${PHP_BINARY:-/usr/bin/php}|g" \
    "$PLUGIN/protocols/syslog/rhel/icct-nms-syslog-worker.service.in" \
    > /etc/systemd/system/icct-nms-syslog-worker.service

sed -e "s|@CACTI_ROOT@|$CACTI_ROOT|g" \
    -e "s|@WEB_USER@|$WEB_USER|g" \
    -e "s|@WEB_GROUP@|$WEB_GROUP|g" \
    -e "s|/usr/bin/php|${PHP_BINARY:-/usr/bin/php}|g" \
    "$PLUGIN/protocols/syslog/rhel/icct-nms-syslog-retention.service.in" \
    > /etc/systemd/system/icct-nms-syslog-retention.service
cp "$PLUGIN/protocols/syslog/rhel/icct-nms-syslog-retention.timer" /etc/systemd/system/
sed -e "s|@WEB_GROUP@|$WEB_GROUP|g" \
    -e "s|/usr/bin/php|${PHP_BINARY:-/usr/bin/php}|g" \
    "$PLUGIN/protocols/syslog/rhel/icct-nms-syslog.logrotate.in" \
    > /etc/logrotate.d/icct-nms-syslog

systemctl daemon-reload
systemctl enable icct-nms-syslog-worker.service
systemctl restart icct-nms-syslog-worker.service
systemctl is-active --quiet icct-nms-syslog-worker.service
systemctl enable --now icct-nms-syslog-retention.timer

if [[ "$OPEN_FIREWALL" == "1" ]] && systemctl is-active --quiet firewalld; then
    firewall-cmd --permanent --add-port="$port"/udp
    firewall-cmd --permanent --add-port="$port"/tcp
    firewall-cmd --reload
fi

# Validate configuration without injecting test messages into production history.
# Wire capture/ingestion acceptance tests run in an isolated network and database.
echo "Receiver configured. Readiness is confirmed by actual device traffic and worker status."

# Preserve restricted spool ownership and its SELinux context.
chown root:"$WEB_GROUP" "$SPOOL_FILE"
chmod 0640 "$SPOOL_FILE"
restorecon -F "$SPOOL_FILE" > /dev/null 2>&1 || true

echo
printf 'UDP and TCP %s: LISTENING\n' "$port"
systemctl --no-pager --full status icct-nms-syslog-worker.service | sed -n '1,12p' || true

echo
echo "ICCT Syslog production integration installed."
echo "Spool : $SPOOL_FILE"
echo "Events: Inventory > device > Syslog"
echo "If firewalld is managed centrally, open UDP/TCP $port there instead of setting OPEN_FIREWALL=1."
