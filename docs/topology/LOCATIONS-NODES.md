# Sites, nodes and Simulator tabs — 0.4.2

The hierarchy is **Cacti Site → Node → Devices**. A Site is a location such as Delhi or Mumbai, using Cacti’s existing Sites. It can contain multiple independently named nodes. Equipment category, type and role describe each device within its node.

## Set up multiple nodes at one location

1. Create the location in native Cacti **Sites**.
2. Open **Topology → Setup → Nodes → Add**. Choose the location, enter a node name. Repeat for each node at that location.
3. Create/configure devices in native Cacti **Devices**, selecting the same location in the device's Site field.
4. In **Topology Setup → Assign a device category, type and role**, select the device and its specific node, then its category/type/role and discovery protocol.
5. Select that node in **Discovery** to save its schedule or queue discovery. **Inventory** and **Connections** use the same node selection. Labels include both location and node name.

Node names must be unique within a location; another location can use the same name. A device has one explicit node assignment. The plugin rejects a node at a different location from the device's native Site. A node with devices or imports cannot be moved to another location through the node editor. Native device settings and SNMP credentials remain in Cacti.

Discovery, jobs and evidence are independent for each node. A device at a sibling node is unresolved in the selected node's topology, even if both devices share a location. Moving a device to another node invalidates its previous discovery snapshot; it must be collected again. Users without Site-management permission see only nodes containing devices they can access, not all sibling nodes at the same location.

## Simulator tabs

| Native Cacti tab | Contents |
|---|---|
| Imports | Uploaded records, Test SNMP, native provisioning and activation retry |
| Upload SNMP Record | Device name, explicit node, category, lab community and record upload |
| LLDP Examples | Switch A / Switch B downloads |
| CDP Examples | Switch A / Switch B downloads |
| LLDP + CDP Examples | Dual-protocol Switch A / Switch B downloads |

Each tab shows the selected state using Cacti's own `tabs`, `subTab` and `selected` classes. Downloads remain direct `.snmprec` files. The old `action=upload` link opens the Upload tab for existing bookmarks. No overlay or new UI framework is used.

Uploaded records retain their original node as import provenance. Provisioning creates the native device at that node's Cacti location and explicitly assigns it to the node. Repeated provisioning returns the existing device; it never moves a device that an operator has subsequently reassigned.

## Upgrade and preservation

Back up the database and `plugins/topology`. Stop the discovery timer and service, deploy 0.4.2 retaining `simulator.local.php`, restore ownership/SELinux labels, and run the native plugin upgrade before restarting discovery. The upgrade changes plugin-owned columns and keys only; no Cacti core table definition is altered.

Each old Site-bound node becomes a named node at the same location and retains its old numeric ID. Existing devices at registered locations receive explicit membership in that preserved node. Imports, schedules, queued jobs, snapshot JSON and last-success timestamps are retained. Matching old configuration fingerprints are migrated without making changed-device evidence current. Existing native devices, credentials, data sources, graphs and RRD files are retained.

`plugin_topology_units` now has independent `id`, `site_id` and `name` (the retained internal `kind` is normalized to `node`). Device assignments and imports have `unit_id`; imports also retain their original `site_id`. Discovery policies, jobs and snapshots use `unit_id`. The policy table keeps its historical name `plugin_topology_discovery_sites`. There are still nine plugin tables. URLs now use `unit_id`; reselect the node if an old `site_id` bookmark has no selection.

## Verification

Migration tests run only in a disposable Cacti database clone whose name begins `topology_units_qa_`. They cover preservation, repeated upgrades, two nodes at one location, duplicate names, cross-location rejection, separate membership/jobs/cadence, invalidation after reassignment, sibling-node visibility and fresh installation. They never run their destructive checks against the live Cacti database.

All five Simulator tabs and the Inventory, Connections, Discovery and Setup form bodies were rendered with native Cacti helpers against the QA clone. Active tabs, upload node selection and PHP syntax were checked. A conflicting top-level `$settings` variable was renamed so native Cacti rendering retains its own settings metadata.

On this project VM, **Topology Lab Location 01** is native Cacti Site **3**. **Topology Lab Node 01** is node **3**, containing native devices **9 and 10**, with **48 native data sources and 48 graphs**. The live pre-upgrade backup is `/var/backups/topology-locations-20260907/`.

Re-run the read-only lab verification after deployment:

```sh
sudo php /var/www/html/cacti/plugins/topology/tests/discovery_live.php \
  --cacti-root=/var/www/html/cacti --unit-id=3 --host-a=9 --host-b=10 --mode=baseline
```

The local live-agent/SNMPv3 validation command in [PHASE3.md](PHASE3.md) remains unchanged.
