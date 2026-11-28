# Bundled MIB sources

Original module copyright and license notices are retained in each file.
These standard and Cisco modules supply the import dependencies of the downloadable samples.

- IF-MIB: https://raw.githubusercontent.com/net-snmp/net-snmp/master/mibs/IF-MIB.txt
- IP-MIB: https://raw.githubusercontent.com/net-snmp/net-snmp/master/mibs/IP-MIB.txt
- BRIDGE-MIB: https://raw.githubusercontent.com/net-snmp/net-snmp/master/mibs/BRIDGE-MIB.txt
- CISCO-ENTITY-SENSOR-MIB: https://raw.githubusercontent.com/cisco/cisco-mibs/main/v2/CISCO-ENTITY-SENSOR-MIB.my
- ENTITY-MIB: https://raw.githubusercontent.com/cisco/cisco-mibs/main/v2/ENTITY-MIB.my
- CISCO-TC: https://raw.githubusercontent.com/cisco/cisco-mibs/main/v2/CISCO-TC.my
- CISCO-SMI: https://raw.githubusercontent.com/cisco/cisco-mibs/main/v2/CISCO-SMI.my
- SNMPv2-SMI: https://raw.githubusercontent.com/net-snmp/net-snmp/master/mibs/SNMPv2-SMI.txt
- SNMPv2-TC: https://raw.githubusercontent.com/net-snmp/net-snmp/master/mibs/SNMPv2-TC.txt
- SNMPv2-CONF: https://raw.githubusercontent.com/net-snmp/net-snmp/master/mibs/SNMPv2-CONF.txt
- SNMPv2-MIB: https://raw.githubusercontent.com/net-snmp/net-snmp/master/mibs/SNMPv2-MIB.txt
- IANAifType-MIB: https://raw.githubusercontent.com/net-snmp/net-snmp/master/mibs/IANAifType-MIB.txt
- INET-ADDRESS-MIB: https://raw.githubusercontent.com/net-snmp/net-snmp/master/mibs/INET-ADDRESS-MIB.txt
- SNMP-FRAMEWORK-MIB: https://raw.githubusercontent.com/net-snmp/net-snmp/master/mibs/SNMP-FRAMEWORK-MIB.txt

- UPS-MIB (RFC 1628): https://raw.githubusercontent.com/netdisco/netdisco-mibs/e817a0abd518ad314651ea7bf4490926b1e4cf69/rfc/UPS-MIB.txt

`standard-mibs-sources.json` records pinned revisions, upstream hashes and bundled hashes for the UPS download. The UPS copy adds the missing `TEXTUAL-CONVENTION` import from `SNMPv2-TC`; object definitions and OIDs are unchanged. `UPS-MIB-dependencies.zip` contains UPS-MIB, SNMPv2-SMI, SNMPv2-TC and SNMPv2-CONF with this provenance. It is MIB data, not an executable or an operating-system installer.
