#!/usr/bin/env bash
set -euo pipefail

CACTI_ROOT="${1:-/usr/share/cacti}"
OPEN_FIREWALL="${OPEN_FIREWALL:-0}"
PLUGIN="$CACTI_ROOT/plugins/icct_nms"
# Load the ICCT copy rule before ordinary /etc/rsyslog.d rules so later `stop`
# statements do not prevent the NMS spool from receiving network messages.
CONF=/etc/rsyslog.d/00-icct-nms.conf
LEGACY_CONF=/etc/rsyslog.d/30-icct-nms.conf
SPOOL_DIR=/var/log/icct-nms
SPOOL_FILE=$SPOOL_DIR/remote.ndjson

if [[ $EUID -ne 0 ]]; then
  echo "Run as root." >&2
  exit 1
fi
if [[ ! -f "$PLUGIN/setup.php" || ! -f "$CACTI_ROOT/include/global.php" ]]; then
  echo "Cacti/ICCT plugin not found under $CACTI_ROOT" >&2
  exit 1
fi
for cmd in rsyslogd systemctl php ss awk grep sed stat install logger; do
  command -v "$cmd" >/dev/null 2>&1 || { echo "Missing required command: $cmd" >&2; exit 1; }
done
if ! rpm -q rsyslog >/dev/null 2>&1; then
  echo "rsyslog package is not installed. Install it from your approved offline RHEL 9.4 BaseOS/AppStream media first." >&2
  exit 1
fi

WEB_USER="$(ps -eo user=,comm= | awk '$2 ~ /^httpd$/ && $1 != "root" {print $1; exit}')"
WEB_USER="${WEB_USER:-apache}"
WEB_GROUP="$(id -gn "$WEB_USER" 2>/dev/null || echo apache)"

echo "Cacti root : $CACTI_ROOT"
echo "Web account: $WEB_USER:$WEB_GROUP"

install -d -o root -g "$WEB_GROUP" -m 0750 "$SPOOL_DIR"
touch "$SPOOL_FILE"
chown root:"$WEB_GROUP" "$SPOOL_FILE"
chmod 0640 "$SPOOL_FILE"
restorecon -RF "$SPOOL_DIR" >/dev/null 2>&1 || true

STAMP="$(date +%Y%m%d%H%M%S)"
CONF_BACKUP=""
LEGACY_BACKUP=""
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

# Detect whether another administrator-managed rsyslog file already owns port
# 514. If it does, ICCT does not bind the same socket; instead the early global
# rule copies network-input messages into the ICCT spool. If it does not, ICCT
# creates dedicated listeners bound directly to an ICCT ruleset.
otherconf="$(cat /etc/rsyslog.conf 2>/dev/null || true)"
for f in /etc/rsyslog.d/*.conf; do
  [[ -e "$f" ]] || continue
  [[ "$f" == "$CONF" || "$f" == "$LEGACY_CONF" ]] && continue
  otherconf+=$'\n'"$(cat "$f" 2>/dev/null || true)"
done
otherconf="$(sed '/^[[:space:]]*#/d' <<<"$otherconf")"

has_udp_input=0
has_tcp_input=0
grep -Eqi 'input\([^)]*type="imudp"[^)]*port="?514"?|\$UDPServerRun[[:space:]]+514' <<<"$otherconf" && has_udp_input=1 || true
grep -Eqi 'input\([^)]*type="imtcp"[^)]*port="?514"?|\$InputTCPServerRun[[:space:]]+514' <<<"$otherconf" && has_tcp_input=1 || true

load_udp=''
load_tcp=''
input_udp=''
input_tcp=''
if [[ $has_udp_input -eq 0 ]]; then
  if ! grep -Eqi 'module\(load="imudp"\)|\$ModLoad[[:space:]]+imudp' <<<"$otherconf"; then
    load_udp='module(load="imudp")'
  fi
  input_udp='input(type="imudp" port="514" name="icct-udp" ruleset="icct-nms-network")'
fi
if [[ $has_tcp_input -eq 0 ]]; then
  if ! grep -Eqi 'module\(load="imtcp"\)|\$ModLoad[[:space:]]+imtcp' <<<"$otherconf"; then
    load_tcp='module(load="imtcp")'
  fi
  input_tcp='input(type="imtcp" port="514" name="icct-tcp" ruleset="icct-nms-network")'
fi

cat > "$CONF" <<RSYSLOG
# Managed by ICCT NMS.
# Existing system logging is preserved. This file adds an early structured copy
# of network Syslog into the ICCT NDJSON spool and never uses `stop`.
$load_udp
$load_tcp

template(name="ICCTNMSJson" type="string"
  string="{\"timestamp\":\"%timereported:::date-rfc3339,json%\",\"source_ip\":\"%fromhost-ip:::json%\",\"hostname\":\"%hostname:::json%\",\"input_name\":\"%inputname:::json%\",\"program\":\"%programname:::json%\",\"facility\":\"%syslogfacility-text:::json%\",\"facility_code\":\"%syslogfacility%\",\"severity\":\"%syslogseverity-text:::json%\",\"severity_code\":\"%syslogseverity%\",\"message\":\"%msg:::json%\"}\n")

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

# If another configuration already owns UDP/TCP 514 and leaves messages in the
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
  if [[ -n "${CONF_BACKUP:-}" && -f "$CONF_BACKUP" ]]; then
    cp -a "$CONF_BACKUP" "$CONF"
  else
    rm -f "$CONF"
  fi
  if [[ -n "${LEGACY_BACKUP:-}" && -f "$LEGACY_BACKUP" ]]; then
    mv "$LEGACY_BACKUP" "$LEGACY_CONF"
  fi
  systemctl restart rsyslog >/dev/null 2>&1 || true
}

if ! rsyslogd -N1; then
  echo "rsyslog validation failed; restoring the previous ICCT configuration." >&2
  rollback_rsyslog
  exit 1
fi
systemctl enable --now rsyslog
if ! systemctl restart rsyslog; then
  echo "rsyslog failed to restart; restoring the previous ICCT configuration." >&2
  rollback_rsyslog
  exit 1
fi

# Install the importer service before the capture self-test so any record
# written during testing follows the same production path used after reboot.
sed -e "s|@CACTI_ROOT@|$CACTI_ROOT|g" \
    -e "s|@WEB_USER@|$WEB_USER|g" \
    -e "s|@WEB_GROUP@|$WEB_GROUP|g" \
    "$PLUGIN/protocols/syslog/rhel/icct-nms-syslog-worker.service.in" \
    > /etc/systemd/system/icct-nms-syslog-worker.service

sed -e "s|@CACTI_ROOT@|$CACTI_ROOT|g" \
    -e "s|@WEB_USER@|$WEB_USER|g" \
    -e "s|@WEB_GROUP@|$WEB_GROUP|g" \
    "$PLUGIN/protocols/syslog/rhel/icct-nms-syslog-retention.service.in" \
    > /etc/systemd/system/icct-nms-syslog-retention.service
cp "$PLUGIN/protocols/syslog/rhel/icct-nms-syslog-retention.timer" /etc/systemd/system/
sed -e "s|@WEB_GROUP@|$WEB_GROUP|g" \
    "$PLUGIN/protocols/syslog/rhel/icct-nms-syslog.logrotate.in" \
    > /etc/logrotate.d/icct-nms-syslog

systemctl daemon-reload
systemctl enable --now icct-nms-syslog-worker.service
systemctl enable --now icct-nms-syslog-retention.timer

if [[ "$OPEN_FIREWALL" == "1" ]] && systemctl is-active --quiet firewalld; then
  firewall-cmd --permanent --add-port=514/udp
  firewall-cmd --permanent --add-port=514/tcp
  firewall-cmd --reload
fi

# Production capture self-test. A listener merely being open is not enough;
# prove that a network message actually reaches the NDJSON spool.
SELFTEST="ICCTNMS_RSYSLOG_SELFTEST_${STAMP}_$$"
logger --udp -n 127.0.0.1 -P 514 -p local7.notice -t ICCT_INSTALL_TEST "$SELFTEST" || true
captured=0
for _ in $(seq 1 20); do
  if grep -Fq "$SELFTEST" "$SPOOL_FILE" 2>/dev/null; then captured=1; break; fi
  sleep 0.1
done
if [[ $captured -ne 1 ]]; then
  echo >&2
  echo "ERROR: UDP/TCP 514 are configured, but the ICCT spool did not receive the local network self-test." >&2
  echo "This usually means an existing port-514 input is bound to a custom rsyslog ruleset that bypasses the default ruleset." >&2
  echo "Review: grep -RniE 'imudp|imtcp|UDPServerRun|InputTCPServerRun|ruleset' /etc/rsyslog.conf /etc/rsyslog.d" >&2
  echo "The installer will not claim production readiness until /var/log/icct-nms/remote.ndjson receives network messages." >&2
  rollback_rsyslog
  exit 2
fi

# Re-apply expected file ownership/context after the self-test.
chown root:"$WEB_GROUP" "$SPOOL_FILE"
chmod 0640 "$SPOOL_FILE"
restorecon -F "$SPOOL_FILE" >/dev/null 2>&1 || true

echo
printf 'UDP 514: '; ss -lun | awk '{print $4}' | grep -Eq '(^|:)514$' && echo LISTENING || echo NOT-LISTENING
printf 'TCP 514: '; ss -ltn | awk '{print $4}' | grep -Eq '(^|:)514$' && echo LISTENING || echo NOT-LISTENING
printf 'ICCT spool capture self-test: '; [[ $captured -eq 1 ]] && echo PASSED || echo FAILED
systemctl --no-pager --full status icct-nms-syslog-worker.service | sed -n '1,12p' || true

echo
echo "ICCT Syslog production integration installed and capture-tested."
echo "Spool : $SPOOL_FILE"
echo "Events: Inventory > device > Syslog"
echo "If firewalld is managed centrally, open UDP/TCP 514 there instead of setting OPEN_FIREWALL=1."
