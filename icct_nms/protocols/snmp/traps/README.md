# SNMP notifications on an offline RHEL collector

The receiver uses the local Net-SNMP `snmptrapd` package, PHP CLI and systemd. Install those packages from your offline RHEL repositories or media. Supply a dedicated root-readable Net-SNMP authorization file with explicit senders and permission to log their notifications. Keep credentials in that file and the sending device configuration.

For a receiver restricted to the local VM, run:

```sh
sudo bash protocols/snmp/traps/rhel/install_traps_rhel.sh /var/www/html/cacti /path/to/receiver-authorizations.conf udp:127.0.0.1:162
```

For network devices, select the collector's interface IP, authorize the intended senders, and allow that UDP port from those devices in the local firewall. Use the same port in the sender configuration. A binding is scoped to the assigned Cacti collector. The receiver's observed UDP source must match the IP in **Inventory → Edit Device → Protocol Config → SNMP Traps**. Ambiguous sender IPs are rejected.

In **FCAPS → Fault**, save a **Syslog** or **SNMP Traps** rule for messages that should raise an alarm. A rule matches all fields that are filled in. For a cross-source incident, use the same name, severity and incident group on both rules. Include the port in both match conditions and in the group when the incident is port-specific. The first enabled matching rule wins.

Review the original messages and trap fields in the device history tabs. The linked alarm leads back to its source events. Existing Syslog history and alarm links are preserved. New Syslog messages that pass collection filters stay in history and require a matching Fault rule to raise an alarm.

### RHEL troubleshooting

**FCAPS → Alarm Settings → Received event rules** lists saved rules. Open **SNMP Trap History** from the rule to check the configured sender IP, the worker status, and received notifications. An event in the receiver log still needs a unique matching device binding on the assigned collector. A stored event needs an enabled rule matching its notification OID and any configured field filter to appear in **Triggered Alarms**.

If the receiver log has new traps but device history stays empty, check both services on the device’s RHEL collector:

```sh
systemctl status icct-nms-trap-receiver.service icct-nms-trap-worker.service
sudo journalctl -u icct-nms-trap-worker.service -n 30 --no-pager
```

After deploying updated PHP worker files, restart the worker so it loads the changes:

```sh
sudo systemctl restart icct-nms-trap-worker.service
```

If either service is missing, provision it with the offline receiver installer above. For the built-in `authenticationFailure` rule, the notification OID is `1.3.6.1.6.3.1.1.5.5`. A different trap can still appear in history without triggering that rule.

To collect new local RHEL journal messages, first configure the device's loopback Syslog source and TCP receiver, then run `sudo bash protocols/syslog/rhel/enable_local_syslog_rhel.sh`. Choose the device's collection filters in **Protocol Config → Syslog**.

For a device with a saved Modbus equipment profile, open **Protocol Config → Serial → Serial Syslog events** and set register limits and a severity. The serial collector records a Syslog event when a reading crosses a limit and a Notice when the reading returns within range. In **FCAPS → Fault → Syslog**, match the program `icct-serial` and the desired message severity to raise alarms for those readings.
