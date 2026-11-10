#!/usr/bin/env bash
set -euo pipefail

# The authorization file is supplied by the collector administrator from local configuration.
if [[ $# != 3 || $EUID != 0 ]]; then
    printf 'Usage: sudo %s CACTI_ROOT AUTHORIZATION_FILE NET_SNMP_LISTENER\n' "$0" >&2
    exit 2
fi

cacti_root=$(realpath "$1")
authorization=$(realpath "$2")
listener=$3
if [[ ! $cacti_root =~ ^/[a-zA-Z0-9_/.-]+$ ||
    ( ! $listener =~ ^udp:[0-9.]+:[0-9]+$ && ! $listener =~ ^udp6:\[[0-9a-fA-F:]+\]:[0-9]+$ ) ||
    ! -r "$authorization" ||
    ! -x /usr/sbin/snmptrapd ||
    ! -x /usr/bin/php ||
    ! -r "$cacti_root/plugins/icct_nms/protocols/syslog/cli/syslog_worker.php" ]]; then
    echo 'Provide a local Cacti installation, an authorization file, Net-SNMP, PHP and an explicit UDP listener.' >&2
    exit 2
fi
if ! grep -Ei '^[[:space:]]*auth(Community|User)[[:space:]]' "$authorization" >/dev/null ||
    grep -Ei '^[[:space:]]*(disableAuthorization|include|traphandle|forward|agentAddress|logOption|format|outputOption)' "$authorization" >/dev/null; then
    echo 'Use explicit authCommunity or authUser entries in a dedicated receiver authorization file.' >&2
    exit 2
fi
web_user=${ICCT_WEB_USER:-apache}
web_group=${ICCT_WEB_GROUP:-$(id -gn "$web_user")}
install -d -m 0750 -o root -g "$web_group" /var/log/icct-nms
install -d -m 0700 /etc/icct-nms/traps
saved_auth=/etc/icct-nms/traps/authorized-senders
if [[ "$authorization" != "$saved_auth" ]]; then
    install -m 0600 "$authorization" "$saved_auth"
fi
{
    cat "$saved_auth"
    printf '\n'
    cat <<'FORMAT'
# Keep v1 enterprise and trap type separate from the receiver's observed network peer.
format1 ICCT-TRAP-V1 %t %b\t%N\t%w\t%q\t%a\t%V\t%v\n
format2 ICCT-TRAP %t %b\t%V\t%v\n
FORMAT
} > /etc/icct-nms/traps/snmptrapd.conf
chmod 0600 /etc/icct-nms/traps/snmptrapd.conf
if [[ ! -e /var/log/icct-nms/traps.log ]]; then
    install -m 0640 -o root -g "$web_group" /dev/null /var/log/icct-nms/traps.log
fi
cat > /etc/systemd/system/icct-nms-trap-receiver.service <<EOF
[Unit]
Description=ICCT NMS SNMP notification receiver
After=network.target

[Service]
Environment=MIBS=
Environment=SNMPCONFPATH=/etc/icct-nms/traps
StateDirectory=icct-nms-traps
Environment=SNMP_PERSISTENT_DIR=/var/lib/icct-nms-traps
# ICCT NMS reads the spool directly, so the receiver does not need AgentX registration.
ExecStart=/usr/sbin/snmptrapd -X -f -n -On -Ox -Oe -Ih --hexOutputLength=0 -A -Lf /var/log/icct-nms/traps.log $listener
Restart=on-failure
UMask=0027
NoNewPrivileges=true
PrivateTmp=true
ProtectHome=true

[Install]
WantedBy=multi-user.target
EOF
cat > /etc/systemd/system/icct-nms-trap-worker.service <<EOF
[Unit]
Description=ICCT NMS SNMP notification ingestion
After=mariadb.service icct-nms-trap-receiver.service
Requires=icct-nms-trap-receiver.service

[Service]
User=$web_user
Group=$web_group
ExecStart=/usr/bin/php $cacti_root/plugins/icct_nms/protocols/syslog/cli/syslog_worker.php --file=/var/log/icct-nms/traps.log --traps --follow
Restart=on-failure
RestartSec=2
NoNewPrivileges=true
PrivateTmp=true
ProtectHome=true

[Install]
WantedBy=multi-user.target
EOF
cat > /etc/logrotate.d/icct-nms-traps <<EOF
/var/log/icct-nms/traps.log {
    daily
    maxsize 100M
    rotate 14
    missingok
    notifempty
    compress
    delaycompress
    create 0640 root $web_group
    postrotate
        /usr/bin/systemctl kill -s HUP icct-nms-trap-receiver.service
    endscript
}
EOF
restorecon -RF /etc/logrotate.d/icct-nms-traps /etc/icct-nms/traps /var/log/icct-nms /etc/systemd/system/icct-nms-trap-{receiver,worker}.service
systemd-analyze verify /etc/systemd/system/icct-nms-trap-{receiver,worker}.service
systemctl daemon-reload
systemctl enable icct-nms-trap-{receiver,worker}.service
systemctl restart icct-nms-trap-receiver.service icct-nms-trap-worker.service
systemctl is-active icct-nms-trap-receiver.service icct-nms-trap-worker.service
