# Discovery configuration — 0.6.0

Open **Topology Configuration → Discovery**, or follow Setup Guide step 7. The four native tabs are **SNMP Access → Device Protocols → Collection Policy → Run & Results**. The Discovery Profiles library is available even before a node is selected.

## Configuration ownership

| Configuration | Location |
|---|---|
| Site/location and device endpoint | Native Cacti Sites and Devices |
| SNMP version, port, timeout, security and credentials | Native Cacti device settings |
| SNMP option sets used by automation | Native Cacti Presets → SNMP; linked from SNMP Access |
| Equipment categories, colors and numbered physical ports | Topology Categories and Port Profiles |
| LLDP, CDP, both, or no collection per device | Discovery → Device Protocols; also available during device assignment |
| Named protocol, schedule, interval and stale threshold | Discovery Profiles |
| Applied collection schedule and timing | Collection Policy for the selected node |
| Switch LLDP/CDP global/interface enablement and SNMP MIB read access | Device configuration; this collector does not provision switches |

Cacti's SNMP option sets support native automation. Topology uses only each device's saved SNMP configuration; it does not cycle through preset credentials or downgrade security. SNMP Access checks configuration completeness, not network connectivity. Run discovery to validate access.

Create a named discovery profile with LLDP, CDP or both; manual or scheduled collection; collection interval; and stale threshold. Apply it explicitly to a selected node. Choose **keep** to copy timing while preserving per-device protocols, or **all** to set every device in the node to the selected protocol. A device can subsequently have its own protocol override. Editing a library profile does not silently reconfigure nodes. Archive profiles to prevent further application.

The runtime reads node timing and device protocols from the database. Profile names, descriptions, timing and scheduling are editable. Supported protocol parsers and standard OIDs remain code-defined because adding a different MIB requires a compatible parser. Both means two independent collections, with failures reported independently; it is not a fallback chain.

Collection interval is 60–86400 seconds. Stale age must be at least twice that interval and no more than seven days. These bounds protect the collector. Collection timing does not change the switch advertisement timer or Cacti's normal graph polling interval. Changing a device protocol invalidates its prior snapshot until discovery succeeds again.

## Equipment prerequisites

Enable the selected neighbor protocol on each relevant device and interface. Make IF-MIB and LLDP-MIB or CISCO-CDP-MIB readable through that device's configured SNMP security. LLDP/CDP advertisements travel between neighboring interfaces; the NMS reads the resulting tables over SNMP.

Cisco documents global LLDP enablement and interface transmit/receive controls in its [LLDP configuration guide](https://www.cisco.com/c/en/us/td/docs/switches/lan/c9000/lyr2-fwd/cdp-lldp-mac-udld/cdp-lldp-mac-udld-configuration-guide/configure-lldp.html). Use documentation for the actual device platform before changing equipment settings. A UI protocol selection alone cannot enable the protocol on the equipment.

## Verification on the local VM

- All PHP files passed syntax validation.
- 17 profile checks passed in an isolated Cacti database clone: repeatable upgrade, CRUD validation, explicit timing copy, all-device apply, independent override, evidence invalidation, archived rejection, permissions, and unchanged native device/credential rows.
- All 10 guided setup checks passed.
- Browser: created and applied **Lab LLDP — 5 Minute Collection**, using the lab's existing scheduled 300-second interval and 900-second stale age while keeping its per-device protocols. Confirmed device selection dynamically loads its saved protocol.
- Browser Discover Now produced job 30: two successful collections, zero failures. The local simulated switches retained one reciprocal LLDP connection. Five discovery baseline checks passed.
- Topology 0.6.0 and NMS 1.10.35 remain enabled; 48 topology data sources and 48 graphs remain present. Core file checksum verification passed.
- This release validates the existing local simulator. It does not claim new physical-switch or SNMPv3 connectivity validation.

Pre-upgrade VM backup: `/var/backups/topology-profiles-20260907/`. No new OS packages were required.
