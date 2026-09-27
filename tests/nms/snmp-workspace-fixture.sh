#!/bin/bash
# Root-only ephemeral collector-local SNMP simulator. No firewall or production agent changes.
set -eu
base=/var/tmp/nms-workspace-snmp-fixture
addresses='192.0.2.249 192.0.2.250 192.0.2.251'
case "${1:-}" in
 start)
  test ! -e "$base"
  for address in $addresses; do
   if ip -4 -o address show | grep -Fq " $address/"; then echo "Fixture address already exists: $address" >&2; exit 1; fi
  done
  for unit in nms-qa-snmp-management nms-qa-snmp-neighbour; do
   if systemctl is-active --quiet "$unit"; then echo "Fixture unit already active" >&2; exit 1; fi
  done
  mkdir -m 0755 "$base"
  touch "$base/owned"
  trap 'bash "$0" stop' ERR
  mkdir "$base/management" "$base/neighbour"
  for kind in management neighbour; do
   {
    echo "1.3.6.1.2.1.1.1.0|4|SYNTHETIC NMS acceptance $kind"
    echo '1.3.6.1.2.1.1.2.0|6|1.3.6.1.4.1.8072.3.2.10'
    echo '1.3.6.1.2.1.1.3.0|67|8640000'
    echo "1.3.6.1.2.1.1.5.0|4|qa-$kind"
    if test "$kind" = management; then
     echo '1.3.6.1.2.1.4.20.1.2.192.0.2.249|2|1'
     echo '1.3.6.1.2.1.4.20.1.2.192.0.2.250|2|2'
    else
     echo '1.3.6.1.2.1.4.20.1.2.192.0.2.251|2|1'
    fi
    echo '1.3.6.1.2.1.47.1.1.1.1.4.1|2|0'
    echo '1.3.6.1.2.1.47.1.1.1.1.5.1|2|3'
    echo '1.3.6.1.2.1.47.1.1.1.1.6.1|2|0'
    echo "1.3.6.1.2.1.47.1.1.1.1.7.1|4|Synthetic $kind chassis"
    echo "1.3.6.1.2.1.47.1.1.1.1.11.1|4|QA-WORKSPACE-$kind-0001"
    echo '1.3.6.1.2.1.47.1.1.1.1.13.1|4|SYNTHETIC-WORKSPACE-TEST'
   } > "$base/$kind/nms-qa.snmprec"
  done
  for address in $addresses; do ip address add "$address/32" dev lo; echo "$address" >> "$base/addresses"; done
  systemd-run --quiet --unit=nms-qa-snmp-management --uid=snmpsim --property=RuntimeMaxSec=1800 /opt/snmpsim/venv/bin/snmpsim-command-responder --data-dir="$base/management" --agent-udpv4-endpoint=192.0.2.249:1163 --agent-udpv4-endpoint=192.0.2.250:1163
  systemd-run --quiet --unit=nms-qa-snmp-neighbour --uid=snmpsim --property=RuntimeMaxSec=1800 /opt/snmpsim/venv/bin/snmpsim-command-responder --data-dir="$base/neighbour" --agent-udpv4-endpoint=192.0.2.251:1163
  echo 'Synthetic collector-local agents started; lifetime bounded to 30 minutes. Run stop after acceptance.'
  ;;
 stop)
  test -f "$base/owned" || { echo 'No owned fixture to stop'; exit 0; }
  systemctl stop nms-qa-snmp-management nms-qa-snmp-neighbour || true
  if test -f "$base/addresses"; then
   while read -r address; do
    case "$address" in 192.0.2.249|192.0.2.250|192.0.2.251) ip address del "$address/32" dev lo;; *) echo 'Unexpected fixture address' >&2; exit 1;; esac
   done < "$base/addresses"
  fi
  rm -f "$base/management/nms-qa.snmprec" "$base/neighbour/nms-qa.snmprec" "$base/addresses" "$base/owned"
  rmdir "$base/management" "$base/neighbour" "$base"
  echo 'Synthetic agent units and owned addresses removed.'
  ;;
 *) echo 'Use start or stop' >&2; exit 1;;
esac
