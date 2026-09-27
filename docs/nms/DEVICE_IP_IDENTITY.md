# Device IP addresses and identity

NMS collects the device's own IPv4/IPv6 addresses through its existing Cacti SNMP connection. It retains them in the existing `identity` discovery snapshot, with interface index, address type/status, source and collection time. No schema migration is required.

## Where to see it

- Device Management → Device readings → **Device IP addresses and identity**.
- `plugins/nms/topology.php?tab=inventory` → expand **IP addresses and identity**.
- Topology → click a device to see its reported addresses and matching evidence.

Deploy the changed plugin files, assign an enabled discovery preset and run **Test selected methods**, or wait for the scheduled collector. Existing snapshots do not contain these new address fields until collection runs again. The SNMP view must allow IP-MIB. Unsupported or unreadable tables leave an explicit empty/incomplete message; other discovery evidence remains available. The UI reads stored snapshots, not on-demand SNMP.

## Interpretation

Own-address tables associate several addresses with the SNMP agent that reported them. They are not ARP neighbour addresses. Virtual/shared IPs can legitimately appear on more than one device.

Matching is limited to visible devices in the same site, collector and SNMP context, using current successful snapshots with unchanged connection settings:

- **Matching device evidence**: identical complete chassis serial/model sets, supported by reciprocal ownership of the configured IPs or matching LLDP identity, without identity conflicts.
- **Possible same device / shared address**: overlapping address or chassis evidence without enough corroboration.
- **Conflicting identity**: overlap accompanied by differing chassis or LLDP identity.

Scoped, link-local, loopback, multicast, unspecified, anycast and unusable addresses do not identify another host. Scoped addresses remain visible with their zone. DNS names are not resolved for matching. Missing serial/model information leaves a candidate advisory; shared hostnames or interface MACs alone never establish identity.

This feature recognizes and displays address/identity evidence. Cacti remains the inventory owner. It does not intercept Cacti Automation admission, automatically merge/delete hosts, combine graph histories, or change/fail over polling addresses. Review duplicate records in Cacti before removing them.

## Implementation and checks

- `includes/discovery_identity.php`: IP-MIB parsing, normalization and identity comparison.
- `includes/discovery_snmp.php`: collector integration.
- `templates/devices/address_identity.php`: shared UI.
- `tests/nms/discovery-identity.php`: parsing, matching, visibility, freshness gates and HTML rendering.
- `tests/nms/discovery-address-transport.php`: real walk/parser integration with a simulated SNMP transport.

Address-table definitions: [RFC 4293, IP-MIB](https://www.rfc-editor.org/rfc/rfc4293.html). Legacy IPv4 ipAddrTable is retained for older agents; modern type/status takes precedence when both tables report the same address and interface.
