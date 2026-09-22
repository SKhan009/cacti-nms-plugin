# Phase 1 verification

Verified against the running project VM on 7 September 2026 (Mac date). The VM clock reports 6 September; its clock was not changed. Cacti/graph timestamps use the VM time.

## Result

- New `topology` 0.1.0 plugin installed and enabled through Cacti's lifecycle; the existing `nms` 1.10.35 remains enabled.
- Native browser workflow created Site 3, **Topology Lab Node 01**, category **Network**, and devices 9/10, **Topology Lab Switch A/B**.
- Both devices show **Up**, with roles **Core/Access**, type **Ethernet Switch** and stored **LLDP only** preference.
- Each uploaded fixture contains 51 records and 24 numeric metrics. Cacti created 48 native data sources, 48 graphs, 48 poller items and 48 RRD files in total.
- Both devices have two indexed native interfaces. Each fixture includes reciprocal LLDP neighbor data for later discovery tests.
- The normal `cacti-poller.service`, running as Apache, collected numeric samples in all 48 RRD files. The native uptime data sources contain 8,640,000 ticks, matching a real request to the configured simulator.
- Native Graph Management links the generated graph to its data source and device. Its RRDtool debug check reported **SVG/XML Output OK**.
- Inventory was visually inspected in the native Cacti Console: existing green theme, header, sidebar, forms and tables; no overlay or custom frontend styling.

## Failure and integrity checks

- Parser tests passed: duplicate OIDs, unsafe community names, variation modules, invalid OID arcs, oversized input, unsigned overflow and missing identity are rejected; neighbor identifiers are separated from numeric metrics.
- Integration checks passed: repeated provisioning returns the same device; native object counts remain unchanged; duplicate imports do not publish extra files; missing configured templates, wrong communities, malformed device IDs and devices outside registered nodes fail explicitly.
- The restricted account check passed using an isolated Cacti permission session. Cacti's native realm cache must not be reused when switching test identities within a process.
- Both live probes failed while the **dedicated topology simulator** was stopped. The simulator and timer were restored and normal polling subsequently succeeded. No uploaded sample was returned as a successful live reading.
- Installer rerun with the same explicit configuration succeeded. Apache configuration validation passed; private configuration, helper directories, tests and simulator source return HTTP 403.
- All plugin PHP files passed PHP lint on the VM. Both simulator Python files passed syntax checks.
- Pre-install checksums for Cacti's root PHP files matched. The existing NMS plugin matched its pre-install archive. Original device Site assignments were retained.
- Both simulator services are active. SELinux remains **Enforcing**.

## Practical limits

This verifies the current local VM and static two-switch lab. It does not establish portability across other Cacti releases or operating systems. Phase 1 does not collect neighbor tables, draw links, infer VLANs or configure LLDP on real equipment. The fixture records prepare those later tests. Static counters produce zero rates; early time windows may have insufficient samples. The base Generic OID graph style is inherited from native Cacti and can be edited through its normal template workflow.

## 0.5.0 single-node canvas (2026-09-07)

- Deployed to the existing local Cacti VM; sidebar is **Topology Configuration**.
- 27 disposable-clone assertions passed, covering physical port profiles, color validation, cable constraints, transactional rollback, category/node membership guards, layout revisions, permissions and preservation of native device/graph data.
- PHP syntax and JavaScript syntax checks passed.
- Native browser forms created the Network lab profile and applied it to Switch A; Switch B and explicit interface mappings were configured using the same backend APIs with lab identity checks.
- Browser device drag, Save Layout, reload persistence and port-to-port drag succeeded. Ethernet1 on Switch A is configured to Ethernet1 on Switch B. Both devices remain labeled Simulated; blue cable is Configured.
- Native category color form saved Blue for Network; category stripe, status badges, available/connected port symbols and legend verified visually.
- Live LLDP baseline passed: reciprocal link, endpoint-change invalidation and restricted-user isolation. Background discovery remained operational. 48 topology data sources and 48 graphs remain.
- Backup: `/var/backups/topology-canvas-20260907/` on the VM. Existing NMS and Cacti core files were preserved.

## 0.5.1 guided setup (2026-09-07)

See SETUP-GUIDE.md for the final categories-first sequence and validation. Ten read-only rendering/scope checks passed. Native category Save returned to its guide step with Site/node preserved. Backup: `/var/backups/topology-flow-20260907/topology-before.tar.gz` on the VM.
