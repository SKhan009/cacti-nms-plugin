# SNMP notifications on an offline RHEL collector

The receiver uses the local Net-SNMP `snmptrapd` package, PHP CLI and systemd. Install those packages from your offline RHEL repositories or media. Supply a dedicated root-readable Net-SNMP authorization file with explicit senders and permission to log their notifications. Keep credentials in that file and the sending device configuration.

For a receiver restricted to the local VM, run:

```sh
sudo bash protocols/snmp/traps/rhel/install_traps_rhel.sh /var/www/html/cacti /path/to/receiver-authorizations.conf udp:127.0.0.1:162
```

For network devices, select the collector's interface IP, authorize the intended senders, and allow that UDP port from those devices in the local firewall. Use the same port in the sender configuration. A binding is scoped to the assigned Cacti collector. The receiver's observed UDP source must match the IP in **Inventory → Edit Device → Protocol Config → SNMP Traps**. Ambiguous sender IPs are rejected.

In **FCAPS → Fault**, save a **Syslog** or **SNMP Traps** rule for messages that should raise an alarm. A rule matches all fields that are filled in. For a cross-source incident, use the same name, severity and incident group on both rules. Include the port in both match conditions and in the group when the incident is port-specific. The first enabled matching rule wins.

Review the original messages and trap fields in the device history tabs. The linked alarm leads back to its source events. Existing Syslog history and alarm links are preserved. New Syslog messages that pass collection filters stay in history and require a matching Fault rule to raise an alarm.

To collect new local RHEL journal messages, first configure the device's loopback Syslog source and TCP receiver, then run `sudo bash protocols/syslog/rhel/enable_local_syslog_rhel.sh`. Choose the device's collection filters in **Protocol Config → Syslog**.

For a device with a saved Modbus equipment profile, open **Protocol Config → Serial → Serial Syslog events** and set register limits and a severity. The serial collector records a Syslog event when a reading crosses a limit and a Notice when the reading returns within range. In **FCAPS → Fault → Syslog**, match the program `icct-serial` and the desired message severity to raise alarms for those readings.
