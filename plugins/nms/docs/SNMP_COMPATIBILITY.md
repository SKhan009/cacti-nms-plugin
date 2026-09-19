# SNMP compatibility

NMS uses the SNMP profile already configured for each Cacti device. Discovery,
inventory, and supplemental network probes support SNMPv1, SNMPv2c, and SNMPv3.
The plugin opens read-only SNMP sessions only; it does not write device settings,
modify device OIDs, or change `/etc/snmp/snmpd.conf` on the Cacti server.

Configure each monitored device to permit the assigned Cacti collector address
with a read-only SNMP view. A read-only view of the standard tree (`.1`) gives
the most complete inventory and topology results. The plugin reads standard and
vendor tables dynamically. Missing optional tables, including IFX-MIB on older
SNMPv1 agents, do not prevent collection of the tables the device exposes.

For SNMPv1 and SNMPv2c, save the matching read-only community in the Cacti
device profile. For SNMPv3, save the device's security name, authentication and
privacy protocols, passwords, context, and authoritative engine ID where used.
NMS passes those values to Cacti's native SNMP session without replacing them.

Some information requires the device to expose its corresponding MIB: interface
inventory requires IF-MIB, LLDP requires LLDP-MIB, CDP requires Cisco CDP-MIB,
and bridge forwarding data requires BRIDGE-MIB/Q-BRIDGE-MIB. If a requested
protocol is disabled on a device or excluded from its read-only view, NMS records
that protocol as unavailable instead of trying to reconfigure the device.
