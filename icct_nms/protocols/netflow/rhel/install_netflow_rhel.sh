#!/bin/bash
set -euo pipefail
plugin_root=${1:-/var/www/html/cacti/plugins/icct_nms}
[[ $EUID -eq 0 ]] || { echo 'Run as root.' >&2; exit 1; }
[[ -f "$plugin_root/protocols/netflow/cli/netflow_worker.php" ]] || exit 1
dnf install -y nfdump
install -d -m 700 -o apache -g apache /var/lib/icct-nms/netflow
cacti_log=$(realpath "$plugin_root/../../log")
sed -e "s|@PLUGIN_ROOT@|$plugin_root|g" -e "s|@CACTI_LOG@|$cacti_log|g" "$plugin_root/protocols/netflow/rhel/icct-nms-netflow.service.in" > /etc/systemd/system/icct-nms-netflow.service
restorecon -R "$plugin_root/protocols/netflow"
systemctl daemon-reload
systemctl enable --now icct-nms-netflow.service
printf '%s\n' 'Configure device exporter source IPs and listener ports in Inventory. Allow the selected UDP ports from your exporter networks in the host firewall. No device exporter is changed automatically.'
