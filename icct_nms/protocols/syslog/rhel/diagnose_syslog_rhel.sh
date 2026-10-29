#!/usr/bin/env bash
set -u
CACTI_ROOT="${1:-/usr/share/cacti}"
PLUGIN="$CACTI_ROOT/plugins/icct_nms"
SPOOL=/var/log/icct-nms/remote.ndjson
pass(){ printf '[PASS] %s\n' "$*"; }
warn(){ printf '[WARN] %s\n' "$*"; }
fail(){ printf '[FAIL] %s\n' "$*"; }

[[ $EUID -eq 0 ]] || { echo 'Run with sudo/root.' >&2; exit 1; }
[[ -f "$PLUGIN/INFO" ]] && pass "Plugin found: $PLUGIN" || fail "Plugin not found under $PLUGIN"
rsyslogd -N1 >/tmp/icct-rsyslog-check.$$ 2>&1 && pass 'rsyslog configuration validates' || { fail 'rsyslog configuration invalid'; cat /tmp/icct-rsyslog-check.$$; }
rm -f /tmp/icct-rsyslog-check.$$ || true
systemctl is-active --quiet rsyslog && pass 'rsyslog service active' || fail 'rsyslog service inactive'
systemctl is-active --quiet icct-nms-syslog-worker && pass 'ICCT Syslog worker active' || fail 'ICCT Syslog worker inactive'
ss -lun | awk '{print $4}' | grep -Eq '(^|:)514$' && pass 'UDP 514 listening' || fail 'UDP 514 not listening'
ss -ltn | awk '{print $4}' | grep -Eq '(^|:)514$' && pass 'TCP 514 listening' || warn 'TCP 514 not listening'
[[ -f "$SPOOL" ]] && pass "Spool exists: $SPOOL" || fail "Spool missing: $SPOOL"
if [[ -f "$SPOOL" ]]; then
  stat -c 'Spool owner/mode: %U:%G %a, size=%s bytes, mtime=%y' "$SPOOL"
fi

echo
printf '%s\n' '--- Port-514 definitions / rulesets ---'
grep -RniE 'imudp|imtcp|UDPServerRun|InputTCPServerRun|ruleset|icct-nms' /etc/rsyslog.conf /etc/rsyslog.d 2>/dev/null | head -n 120 || true

echo
printf '%s\n' '--- Capture self-test ---'
marker="ICCTNMS_DIAG_$(date +%s)_$$"
logger --udp -n 127.0.0.1 -P 514 -p local7.notice -t ICCT_DIAG "$marker" || warn 'logger UDP send failed'
found=0
for _ in $(seq 1 20); do
  if grep -Fq "$marker" "$SPOOL" 2>/dev/null; then found=1; break; fi
  sleep .1
done
if [[ $found -eq 1 ]]; then
  pass 'Network Syslog reached the ICCT NDJSON spool'
  grep -F "$marker" "$SPOOL" | tail -n 1
else
  fail 'Network Syslog did NOT reach the ICCT NDJSON spool'
  echo 'Likely causes: an existing listener is bound to a custom ruleset, an earlier rsyslog rule stops processing, or SELinux/file policy blocks the write.'
fi

echo
printf '%s\n' '--- Worker journal (last 30 lines) ---'
journalctl -u icct-nms-syslog-worker -n 30 --no-pager || true

echo
printf '%s\n' '--- SELinux denials mentioning rsyslog/icct (if audit tools are present) ---'
if command -v ausearch >/dev/null 2>&1; then
  ausearch -m AVC,USER_AVC -ts recent 2>/dev/null | grep -Ei 'rsyslog|icct-nms|remote.ndjson' | tail -n 30 || true
else
  echo 'ausearch not installed; skipped.'
fi

[[ $found -eq 1 ]] || exit 2
