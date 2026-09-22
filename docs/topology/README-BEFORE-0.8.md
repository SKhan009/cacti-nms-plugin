## Start here: Setup Guide (0.5.1)

Open **Topology Configuration → Setup Guide**. Configure reusable categories and port profiles first, then Site, Node, Devices and Topology. See [the setup guide](docs/SETUP-GUIDE.md).

## Node topology canvas (0.5.0)

The **Topology Configuration** sidebar now includes **Node Topology** and **Physical Ports**. See [the canvas guide](docs/NODE-CANVAS.md) for category profiles, device port assignment, saved layouts and configured cables.

# Topology Setup — Phase 3

Independent Cacti plugin `topology`, version **0.4.2**, tested on the project's RHEL 9 VM with Cacti 1.2.31, PHP 8.0 and MariaDB 10.5. It provides node inventory, SNMPSim provisioning, background LLDP/CDP discovery and a connection diagram inside the native Cacti Console. Phase 3 adds strict SNMPv3 security validation and isolated native collection, verified against the live local VM and encrypted simulator contexts.

Version 0.4.2 supports multiple named nodes beneath each native Cacti Site. Sites represent locations such as Delhi or Mumbai. Assignments, discovery schedules and evidence are scoped to the selected node. Simulator uses native Cacti tabs: Imports, Upload SNMP Record, LLDP Examples, CDP Examples and LLDP + CDP Examples. See [location/node setup and upgrade](docs/LOCATIONS-NODES.md).

## Open the working lab

**Console → Topology → Connections → Topology Lab Location 01 / Topology Lab Node 01 → View**

Switch A **Ethernet1** connects to Switch B **Ethernet1**. Both ends report the link through LLDP. Discovery is scheduled every **300 seconds**, with a **900-second** stale threshold. The separate native Cacti poller maintains the existing 48 data sources/graphs.

| Page | What you do |
|---|---|
| Inventory | View native devices grouped by node and equipment category |
| Connections | Inspect the diagram, endpoint ports, protocol evidence and collection health |
| Discovery | Save the node's schedule/stale policy; queue Discover Now; inspect jobs |
| Setup | Create named nodes under Cacti locations; classify devices; choose None, LLDP only, CDP only or both |
| Simulator | Upload static records and create native devices, data sources and graphs |

Create locations using native Cacti Sites and assign each native device to its location. In Topology Setup, create named nodes beneath that location and explicitly assign devices to a node. Configure address/SNMP credentials/port/timeouts through native Cacti. Enable LLDP/CDP on real equipment separately. The plugin performs read-only SNMP collection; it does not configure the equipment.

## How connection evidence works

- Selected protocols run independently in a background queue. LLDP-only never switches to CDP after failure; both explicitly runs both collectors.
- LLDP matches exact advertised chassis identifiers and their subtypes. Ports map by their declared interface-name, alias or MAC subtype; the LLDP local port number is not assumed to be `ifIndex`.
- CDP matches the exact advertised global Device-ID and the remote advertised port name to the peer's interface identifiers.
- Reverse-direction and dual-protocol observations merge by the same native endpoint pair. Parallel interface pairs remain separate.
- Unknown or duplicate identities and unmapped ports remain in **Unresolved Neighbor Evidence**. Matching does not use DNS, management-IP guesses, system-name guesses or uploaded file contents.
- **Reciprocal** means current observations from both ends. **One-sided** means current evidence from one end. Interface status, native Cacti device status and discovery success are displayed separately.
- Failure preserves the prior observation as **Historical / stale** and preserves its last-success time. Missing rows are marked missing; they are not proof of a cable failure. Evidence older than seven days is excluded from the diagram.

## What Cacti owns

| Requirement | Implementation |
|---|---|
| Locations | Native `sites` and `host.site_id` |
| Nodes within a location | `plugin_topology_units.id`, location `site_id`, and explicit `plugin_topology_devices.unit_id` |
| Devices, SNMP settings, availability | Native device editor, `host` and poller |
| Interface inventory and graphs | Native interface query, `host_snmp_cache`, graph/data-source APIs |
| Time-series collection | Native poller and RRDtool |
| UI, authentication and authorization | Native headers, sidebar, forms, tables, CSRF bootstrap and realms |
| Topology-specific data | Nine additive `plugin_topology_*` tables for classification, imports, audits, schedules, jobs and evidence snapshots |

Discovery uses Cacti's native SNMP session factory and reads live IF-MIB identifiers/status for a discovery snapshot. It does not overwrite core interface caches or store a second set of device credentials. No Cacti core files/table definitions are changed. No custom UI framework or separate graphing backend is installed. The existing `nms` plugin is not required.

## Simulator

The dedicated test endpoint is **127.0.0.1:1162 inside the VM**, explicitly using SNMPv2c. The original NMS simulator remains separate at port 1161.

Download a matching pair from a Simulator example tab. Use Upload SNMP Record with a unique lab community and an explicit node/category. After activation, Test SNMP and Create Device and Data Sources. Classify the new devices with the matching protocol preference, then save their node's discovery policy. Use one pair per test node to avoid duplicated advertised identities.

Imports accept bounded static `.snmprec` records: 2 MB, 5000 lines, up to 64 numeric metrics, and required sysName/sysUpTime. Numeric metrics create native data sources/graphs; text and LLDP/CDP identifiers are excluded. Duplicate uploads, executable variation modules, malformed values and unsafe names are rejected. Static counters produce zero rates. These agents serve MIB records over SNMP; they do not exchange real LLDP/CDP Ethernet frames.

## Scope and limits

The plugin supports one local collector, up to 32 visible devices per node, native PHP SNMP with explicitly configured SNMPv2c or v3, a 30-second/5000-object limit per protocol and a 180-second job budget. The session cannot honor an explicitly configured SNMP engine ID, so it rejects that configuration. Native timeout must be 1–5000 ms and native retry count 0–3. Missing required MIB objects, non-increasing OIDs and incomplete responses fail explicitly.

The persistent lab uses SNMPv2c. Phase 3 separately verifies live VM SNMPv3 SHA/AES and SHA-256/AES, negative credential tests, and simulated LLDP/CDP collection through named SNMPv3 contexts. Each v3 collection requires a fresh PHP CLI process to prevent Net-SNMP key reuse; no shared-session fallback is allowed. Physical device firmware variations, stacks and vendor-specific port-ID subtypes still need testing on the intended equipment. There is no automatic network scan, manual link editor, drag-and-drop layout, VLAN/routing inference, WAN/GNSS view, remote collector support or CNMS synchronization in this release.

See [Phase 3 validation and rerun instructions](docs/PHASE3.md), [installation](docs/INSTALL.md), [Phase 2 operation and verification](docs/PHASE2.md), and the retained [Phase 1 verification](docs/VERIFICATION.md).
