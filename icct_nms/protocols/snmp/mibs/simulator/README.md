# MIB Simulator

Run these commands as root on the RHEL test collector from this directory.
The service requires Python 3 and an unprivileged `snmpsim` system account.
Substitute the actual Cacti PHP account for `apache`.

```sh
install -d -m 2750 -o apache -g snmpsim /var/lib/icct-nms-mib-simulator
install -d -m 0755 /usr/local/libexec
install -m 0644 serve.py /usr/local/libexec/icct-nms-mib-simulator.py
install -m 0644 icct-nms-mib-simulator.service /etc/systemd/system/
semanage fcontext -a -t httpd_sys_rw_content_t '/var/lib/icct-nms-mib-simulator(/.*)?'
restorecon -RF /var/lib/icct-nms-mib-simulator
systemctl daemon-reload
systemctl enable icct-nms-mib-simulator.service
systemctl restart icct-nms-mib-simulator.service
```

Under **MIB Repository → Simulator**, load a `.mib` file, select the OIDs and
click **Start**. The tab holds the parsed selection in the current browser
session. Imported dependencies resolve from the MIB Repository and the plugin's
bundled MIBs. For a table column, enter the instance suffix used by the Cacti
data source.

The read-only agent listens on `127.0.0.1:1161` with SNMP v2c community
`icct-mib-sim`. **Stop** makes it silent, including for the synthetic
`sysUpTime.0` used by Cacti's SNMP availability check. The agent supports
GET, GETNEXT and GETBULK, and rejects writes.

Use the selected scalar instance (ending in `.0`) or table instance in the
Cacti SNMP data source. Use GAUGE for sensor values and COUNTER for raw
Counter32/Counter64 values. Sensors produce bounded trends at the configured
Cacti poller interval, with the same value on repeated reads in a poll interval.
Counters increase and wrap at the width declared in the MIB. Text, identifiers,
and enumerations have stable synthetic values. Name test graphs with a
**Simulated** prefix to identify their data in Cacti.
