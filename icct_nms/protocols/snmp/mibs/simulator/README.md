# MIB Simulator

If the Simulator tab says it is not set up, run the bundled installer from
this directory on the RHEL test collector. It creates the unprivileged
`snmpsim` account if needed, sets state directory access for Cacti, and
enables and starts the local systemd service. It requires Python 3 and,
when SELinux is enabled, `policycoreutils-python-utils`. Use matching
offline RHEL media if these prerequisites are missing.

```sh
sudo bash install_rhel.sh apache
```

Replace `apache` with the account that serves Cacti PHP requests. Reload the
Simulator tab after setup. If the service fails to start or respond, check
`systemctl status icct-nms-mib-simulator.service` and
`sudo journalctl -u icct-nms-mib-simulator.service -n 30 --no-pager`.

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
