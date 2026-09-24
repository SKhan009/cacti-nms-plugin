# Node setup roadmap — one node, multiple devices

Scope: NMS plugin only. No GNSS, clock integration or ICCT Rack work. This supersedes the GNSS roadmap for the current request. Phases 1–3 implemented in 1.10.99. See NODE_SETUP.md for configuration and verification. Later phases remain planned.

## What exists

- Native Cacti sites contain multiple devices through host.site_id. Device Add/Edit already selects Site and Data collector.
- Operational groups use plugin_nms_groups and plugin_nms_group_members. These are many-to-many labels, not exclusive physical node membership.
- Older physical planning code defines plugin_nms_rack_nodes, plugin_nms_racks and plugin_nms_rack_devices. Its model links devices through racks. The current topology configuration controller rejects planning actions and the active template has no node/rack prerequisite. This is not an active general-purpose node setup UI.
- Geographic map groups devices by Cacti site; network topology shows individual devices and their links.

Evidence: includes/groups.php; includes/topology/config.php; includes/topology/config_page.php; templates/topology/config.php; templates/devices/add.php; includes/topology/map.php.

## Recommended hierarchy

Site -> Node -> Devices

Example: Bengaluru site -> Node A -> router, switch, server, UPS; Bengaluru site -> Node B -> router, switch.

A node is a management container with a stable identity, not another polled host. Each device retains its native Cacti host ID, management address, credentials, template, collector, graphs and alarms. Default rule: a device belongs to at most one node, and the device and node must belong to the same site. Devices may remain explicitly unassigned. Operational group membership remains independent.

If one node is always exactly one site, native sites already provide the basic grouping. The separate node layer is needed when several nodes can exist at the same site; do not merely rename Sites and lose that distinction.

## Configuration workflow

Proposed NMS > Device management > Nodes:
1. Create node: name, unique code, site, description and enabled state.
2. Add devices: choose existing accessible Cacti devices from that site, with search and multi-select.
3. Node details: member devices, Up/Down/Unknown/Disabled counts, alarms and links to device readings/graphs.
4. Device Add/Edit: choose Site, then optional Node filtered to that site.
5. Topology: select Node to display its member devices and their real connections; separately distinguish external neighbours. Never infer physical connectivity just from shared node membership.

## Implementation phases

| Phase | Deliverable | Acceptance |
| --- | --- | --- |
| 1. Data model | NMS-owned nodes and device membership referencing sites and host IDs | One node accepts many devices; duplicate/cross-site membership rejected |
| 2. Setup UI | Nodes tab, create/edit node, searchable bulk device assignment, device Node field | Assignment persists and is editable without requiring a rack |
| 3. Node overview | Device list, visible member counts, aggregate health and active alarms | No member's status or alarms leak through permissions; empty/unknown is never healthy |
| 4. Topology/map | Node filter, drill-down, site popup lists nodes and devices | Nodes sharing a site remain distinguishable without fabricated separate coordinates |
| 5. Lifecycle and migration | Node disable/delete rules, device moves, site moves and audit trail | Removing a container does not delete devices; populated-node deletion requires explicit unassign/move workflow |
| 6. QA and VM rollout | Migration backup, regression checks, targeted deployment | Existing polling, graphs, diagnostics, discovery and site maps still work |

Suggested schema: plugin_nms_nodes (id, site_id, code, name, description, enabled, audit fields) and plugin_nms_node_devices (host_id primary key, node_id, audit fields). Enforce relational integrity using the existing Cacti-compatible storage conventions and transactional application validation. Reuse native management authorization and host visibility helpers. Do not store duplicate SNMP credentials or separate device IP fields on nodes.

Before adding tables, audit installed legacy rack-node data. Preserve it; do not silently reuse, delete or convert populated rack definitions. Any useful migration must be explicit and must not change rack behavior.

## Status rules

Keep reachability, alarm severity and membership separate. Show visible-device counts and aggregate reachability: Empty for no devices, Disabled when all are disabled, Unknown when there are no usable readings, Degraded when some active members fail, and Up only when all active members have fresh Up readings. Display active alarm severity separately. Label user-visible summaries as scoped to accessible members when access is partial.

## Tests

- One node with several devices; several nodes at one site.
- Duplicate assignment, cross-site assignment and concurrent moves.
- Unauthorized node changes and inaccessible member devices/counts.
- Unassigned, disabled, deleted and stale devices.
- Node rename, disable and safe removal; device site changes.
- Live SNMP polling, graphs, alarms and discovery after assignment.
- Topology membership does not create fictitious physical links.

## First milestone

Implement Nodes under Device management with create/edit, same-site multi-device assignment and node details. Keep topology visualization as the next step. Hardware model and GNSS details are not needed.

## Phase 4–5 delivery update

Delivered network topology node selection and node-details drill-down, with the filter retained during refresh. Safe container removal requires explicit member unassignment or a same-site move and matching node-code confirmation. Native hosts and monitoring data are preserved and integration-tested.

Remaining extended items: geographic popup node grouping, external-neighbour expansion, node disable and persistent lifecycle audit history. These are not included in this delivery.

Phase 6 QA and VM verification completed for delivered node features. See [evidence and limitations](NODE_PHASE6_QA.md). The VM runner deployment was repaired; one intermittent iPerf3 timeout passed on retry and remains recorded.
