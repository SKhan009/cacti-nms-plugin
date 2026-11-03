#!/usr/bin/env bash
# Offline installation: dependencies must already be provisioned from approved RHEL media.
set -euo pipefail
plugin_root=${1:-/var/www/html/cacti/plugins/icct_nms}
[[ $EUID -eq 0 ]] || {
    echo 'Run as root.' >&2
    exit 1
}
[[ -f "$plugin_root/protocols/netflow/cli/netflow_worker.php" ]] || {
    echo 'Plugin not found.' >&2
    exit 1
}
for binary in /usr/bin/php /usr/bin/nfdump /usr/bin/nfcapd; do
    [[ -x "$binary" ]] || {
        echo "Missing $binary. Install its RPM and dependencies from approved offline media before running this installer." >&2
        exit 1
    }
done
for utility in install realpath sed restorecon systemctl; do
    command -v "$utility" > /dev/null || {
        echo "Missing required utility: $utility" >&2
        exit 1
    }
done
# Fail before writing a unit if the web account or Cacti log directory is absent.
id apache > /dev/null
cacti_log=$(realpath -e "$plugin_root/../../log")
[[ -d "$cacti_log" ]] || {
    echo 'Cacti log directory not found.' >&2
    exit 1
}
[[ "$plugin_root$cacti_log" != *[$'\n\r&|']* ]] || {
    echo 'Unsupported service path characters.' >&2
    exit 1
}
install -d -m 700 -o apache -g apache /var/lib/icct-nms/netflow
unit=$(mktemp)
trap 'rm -f "$unit"' EXIT
sed -e "s|@PLUGIN_ROOT@|$plugin_root|g" -e "s|@CACTI_LOG@|$cacti_log|g" "$plugin_root/protocols/netflow/rhel/icct-nms-netflow.service.in" > "$unit"
install -m 0644 "$unit" /etc/systemd/system/icct-nms-netflow.service
restorecon -R "$plugin_root/protocols/netflow"
systemctl daemon-reload
systemctl enable --now icct-nms-netflow.service
printf '%s\n' 'Offline installation complete. Configure exporter sources/listener ports in Inventory and allow those ports from approved exporter networks.'
