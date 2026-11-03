# Discovery and dashboard delivery audit

Goal: implement BLR.pdf 5.3.2.1–5.3.2.2 and 5.3.5.3, retaining native Cacti ownership and existing data.

## Acceptance requirements

| ID | Requirement | Evidence required before completion |
|---|---|---|
| D1 | Unified wizard with range, site, poller, ICMP/SNMP/port/CDP/LLDP selection | Browser submission, stored validated job and collector result |
| D2 | Cacti range helpers, SNMP credentials and template rules reused | Native integration test with core helpers and actual loopback SNMP |
| D3 | TCP port ranges, bounded background work, progress, cancel/retry | Real open/closed ports, invalid input tests, worker recovery |
| D4 | Results review, suggested device type and selectable graph templates | Browser and native onboarding scenario |
| D5 | Duplicate prevention including concurrent approval | Native database locking/repeated import tests |
| D6 | Same Cacti host ID across inventory, device view, graphs, topology | Native host creation plus all read models |
| D7 | Manual discovery/addition and non-SNMP equipment | Existing wizard regression plus no-SNMP onboarding |
| D8 | Status/identity changes produce inspectable inventory events | Two different observations and event history assertions |
| D9 | Collector-scoped jobs and native remote poller registration | Isolated two-poller dispatch tests and actual native registration QA |
| G1 | Configurable GNSS adapter for unknown hardware | Validated SNMP and NMEA TCP/UDP/serial/file/gpsd sources with fixtures |
| G2 | GNSS fixes update poller coordinates with freshness/invalid-fix handling | Real transport fixtures and map/radar data assertions |
| G3 | Remote locations available to main/remote LNMS | Collector-scoped readings persisted/shared through Cacti architecture |
| T1 | Physical topology inventory nodes, interfaces, CDP/LLDP and editable links/icons | Existing and new topology tests plus rendered browser |
| T2 | Rack view live status and saved placement | Regression checks |
| T3 | LNMS/poller link status, map/radar and geographic positions | Saved links/heartbeat/location fixtures, browser rendering |
| T4 | User-configurable refresh rate | Saved preference, timer check, native validation |
| X1 | Native ACL, CSRF, no secrets in results/logs, offline runtime | Unauthorized/invalid request tests, local transports, asset checks |
| X2 | Preservation, deployment and GitHub | Before/after data comparison, VM smoke test, exact file hashes and pushed commit |

## Current verified baseline

- Version 1.3.5 published as 84d10f0; VM alias cacti-rhel9.
- Existing Networks/Discovered Devices UI delegates to core automation; Inventory discovery only queues existing neighbour assignments.
- Map/Birds Eye read configured native pollers only. Coordinates come from assigned site; radar uses logical placement when unavailable.
- Current widget refresh interval is fixed at 30 seconds.
- GNSS ingestion and unified onboarding are not implemented at baseline.
- PDF pages 6, 7 and 12 visually reviewed; OCR source under /tmp/icct-blr-review (not distributed).

## Delivery evidence

The user required the existing UI only. No separate discovery or collector screen is delivered. The previous development URLs redirect into existing Topology Configuration / Presets pages.

| ID | Implemented path and verification |
|---|---|
| D1 | Existing Network editor selects the five methods; VM browser saved network 27, submitted loopback jobs, and showed completed results in existing tables. |
| D2 | Native range helpers, automation SNMP sets and OS/template matching; actual VM loopback SNMP returned cacti-rhel9.local and the Net-SNMP template. |
| D3 | Python real TCP open/closed test; native workflow test exercises input bounds, cancellation and retry with completed evidence preserved. Networks refreshes while running. |
| D4 | Review opens the existing seven-step Add Device wizard, with native suggested template, address, site and collector prefill. Existing Graphs / Data Query steps retain operator choices. |
| D5 | Named database lock surrounds native imports; a second database connection was denied the held import lock; repeated approval and pre-existing-host protection pass in isolated native-table integration tests. Ambiguous matches route to Inventory. |
| D6 | Creation uses the existing native device save path and its host ID. Native integration confirms Inventory identity; topology/graph/query screens use those same IDs. |
| D7 | Existing manual Add Device remains; isolated Basic Information save and retry pass for a port-only device with SNMP disabled and credentials cleared. |
| D8 | Native status/identity snapshots produce one event per change; repeated unchanged observations do not duplicate it. Existing dashboard toasts report new allowed-device events. |
| D9 | Existing Presets Site links use native Cacti collector settings. The native remote installer owns registration (verified in installed Cacti source); no duplicate registration UI is added. Integration rejects a wrong collector; remote worker primary-database selection and offline-primary rejection are tested. |
| G1 | SNMP OID/divisor/fix adapter test plus actual loopback NMEA TCP/UDP, serial PTY, local-file and gpsd transports. Bounds/checksum/no-fix/stale timestamps tested. |
| G2 | GNSS map/radar fixtures test current remote coordinates, stale/no-fix/future rejection and preservation of site assignments. |
| G3 | Both workers use Cacti's native primary database session and retain the local poller ID. Readings are keyed by poller and shared through that primary database. |
| T1 | Existing topology tests pass for saved layout, fresh neighbours, manual links, identity ambiguity, port labels, type icons and ACL. |
| T2 | Existing rack placement and map status tests pass, including overlap, capacity, sites and permissions. |
| T3 | Map/radar remain poller-only. Existing physical connections across collectors supply links; unit/browser-source tests cover Up/Down/unknown status and hidden-device rejection. |
| T4 | Per-user refresh validated at 5–3600 seconds, saved through existing dashboard preferences; both reading timers use it. |
| X1 | Existing CSRF wrappers retained; Automation/Management/device ACL enforced. Pure local transport tests and native loopback scans require no Internet. No credentials included in results or client prefill. |
| X2 | Existing preset and NetFlow assignments compared across native upgrade; plugin 1.4.0 enabled, both workers running on VM. Release manifest / hash and Git verification recorded with deployment. |

Physical GNSS hardware and a second deployed remote collector are not present in this environment. Their transport/database behavior is covered by fixtures and collector-context tests; site-specific receiver OIDs, serial access, routing and primary database connectivity must be verified when that equipment is connected.
