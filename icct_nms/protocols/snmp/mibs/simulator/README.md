# MIB Simulator

On the RHEL test collector, install Net-SNMP, Python 3 and an unprivileged
`snmpsim` system account. Use the following commands as root from this directory.
Substitute the actual Cacti PHP account for `apache`.

```sh
install -d -m 2750 -o apache -g snmpsim /var/lib/icct-nms-mib-simulator
install -d -m 0755 /usr/local/libexec
install -m 0644 serve.py /usr/local/libexec/icct-nms-mib-simulator.py
install -m 0644 snmpd.conf /etc/icct-nms-mib-simulator.conf
install -m 0644 icct-nms-mib-simulator.service /etc/systemd/system/
semanage fcontext -a -t httpd_sys_rw_content_t '/var/lib/icct-nms-mib-simulator(/.*)?'
restorecon -RF /var/lib/icct-nms-mib-simulator
systemctl daemon-reload
systemctl enable --now icct-nms-mib-simulator.service
```

Import `ICCT-SPIKE-SIM-MIB.mib` through MIB Repository. Create a scalar SNMP GET
GAUGE graph for `icctSimulatedLevel` and associate it with a test device at
`127.0.0.1:1161` using community `icct-mib-sim`. Use the local collector's
poller interval for the graph's data source profile.

Under **MIB Repository → Simulator**, open the linked Cacti device to see its
graph. **Start** returns 90 on the next poll, then 15 on the following poll, and
continues alternating. **Stop** returns the baseline value of 15 while the
device remains reachable. For alarm testing, configure a rule on this scalar
with a threshold between 15 and 90.
