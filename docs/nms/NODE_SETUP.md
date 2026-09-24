# Node setup

Implemented in NMS 1.10.99. Scope: node containers, explicit device membership and details. No GNSS or rack dependency. No seeded node, inferred membership or default device addresses.

## Configure

1. Create the required site in Cacti Console > Sites and assign existing devices to it.
2. Open NMS > Device management > Nodes > Create node.
3. Enter name, globally unique code, site and optional description.
4. Search and select existing devices from that site, then Save node. The node may also be saved empty.
5. Open the node to see its accessible members, availability counts, active alarms, readings and native graph links.
6. Device Add/Edit now includes Node. Select the desired same-site node or explicitly select Unassigned. Changing Site does not silently choose a replacement node: the form requires a valid selection.

A device belongs to at most one node. The node editor refuses to take devices from another node; use Device Edit for an explicit move. Removing a checked member unassigns it without deleting its Cacti device, graphs or configuration. Device membership does not create physical links. Topology selection and safe node removal are described below.

Availability is distinct from alarm severity. A node can have reachable devices and an active warning alarm. Stale readings produce Unknown. Summaries and alarms only include accessible devices. Editing an entire node is refused if it contains inaccessible devices, preventing partial membership replacement.

## Structure

- `includes/nodes/service.php`: schema, strict validation, serialized writes, membership and availability summaries.
- `includes/nodes/page.php`: authenticated Nodes tab request/view preparation.
- `templates/devices/nodes.php`: node list, editor and details.
- `js/nms-nodes.js`: shared site/membership filtering for node and device forms.
- `css/nms-nodes.css`: scoped node layout.
- Existing `devices.php`, `templates/devices/add.php`, `includes/database.php` and `INFO`: routing, device integration and lifecycle migration.

Native `host` and `sites` remain authoritative. New tables are `plugin_nms_nodes` and `plugin_nms_node_devices`. All node data is entered explicitly. Schema creation runs through the existing plugin upgrade, not ordinary page views. The legacy rack-node tables were inspected (empty on this VM) and left untouched.

## Verification performed

- Local PHP/JavaScript syntax checks.
- `php tests/nms/nodes.php`: empty, Up, Degraded, Unknown/stale and Disabled states, counts and invalid identifiers.
- On the VM, `php tests/nms/nodes-integration.php /var/www/html/cacti`: real SQL with controlled ACL fixture; multiple members, duplicate identity rejection, cross-site rejection, failed-save rollback, prevention of implicit moves, hidden-member protection, explicit unassignment/assignment, write permission checks and unchanged native connection settings. The test cleans up its own created nodes.
- Browser: create a node with two existing lab devices; inspect both members and availability counts; Device Edit displays membership and successfully saves explicit Unassigned; Add Device offers Unassigned; a temporary alarm appears under the correct member; graph link opens the correct native Cacti device graph filter.
- Removed temporary QA nodes, memberships and alarm. Confirmed zero remaining test node records; no node is preconfigured for the user. Browser console had no errors.

VM backups before deployment: `/root/nms-before-nodes.sql` and `/root/nms-before-nodes.tar.gz`. Upgrade performed with the plugin lifecycle function; current schema marker is 1.10.99. Do not restore an entire database backup after new operational data has accumulated without reviewing that data.

## Node topology and safe removal

Open Node details → View node topology, or choose a node above Network topology and press Show. The server selects that node's site and accessible enabled members. Refresh preserves the selection. Links retain their discovery evidence, freshness and manual/inferred classifications; membership never creates a connection. Connections outside the selected membership are omitted. All devices in topology scope restores the configured topology scope.

Open Node details → Remove node. Explicitly choose to leave members unassigned or move them to another same-site node, then enter the source node code. This transaction modifies only NMS node/membership records. Hidden members block removal. Cacti hosts, graphs, credentials, collectors and alarms are preserved. Individual moves remain available through Device Edit; node Edit supports explicit unassignment.

Verified on VM: two-member topology and original link evidence, browser filter/refresh/detail navigation, removal form, remove-and-move, remove-and-unassign, confirmation/ACL rejection, and unchanged native graphs and connection settings. Temporary QA nodes were removed. Broader roadmap items (map popup node grouping, node disable and persistent lifecycle audit history) remain future work.

## Phase 6

See [QA and VM rollout evidence](NODE_PHASE6_QA.md), including the persistent listener service required by this VM’s systemd poller.

## Shared member data and Cancel controls

Membership remains manual. Node details read live Cacti device records, diagnostic profiles, discovery presets and automatic-collection state on page load; there is no duplicate device configuration on a node. Configure device opens the existing member editor. Protocol checks appear only for members with a diagnostic profile; unconfigured members are identified explicitly.

Node protocol checks scopes both device/bandwidth selectors and saved history to current members. Refresh and result navigation retain the node. Manual and discovered connections uses the same membership for device/interface choices and existing links. Shared-node membership never creates a physical connection; automatic topology still needs enabled discovery and evidence.

Cancel controls share the standard secondary-button style. Create Cancel returns to the node list; Edit/Remove Cancel returns to the node details. Dialog Cancel closes without saving. Verified on the VM with temporary memberships: node profile/discovery labels, filtered protocol selectors/history, refresh retention, manual interface choices and dialog cancellation. Integration tests now isolate their fixture helper names so they can run safely when the real plugin is enabled.
