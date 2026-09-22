# Node topology canvas — 0.5.0

Use **Topology Configuration → Node Topology**, select a node, then **View**. A node belongs to one native Cacti Site; one Site can have multiple nodes.

## Configure physical ports

1. Open **Physical Ports → Category Profiles**. Create a profile for an existing equipment category. Set the name, port-label prefix, starting number, count (1–128) and connector. A category can contain several profiles, such as 8-port and 24-port switches. Profiles currently describe one sequential bank with one connector type.
2. In **Device Ports**, select the node and choose **Configure Ports** beside a device. Only profiles from that device’s category are offered. Select a profile and save to create its physical ports.
3. Optionally **Map Interface** for each physical port. Choose an interface from Cacti’s current IF-MIB data-query cache. The mapping is explicit; physical port numbers are never assumed to equal ifIndex.
4. Profile edits do not silently change existing devices. Reapply the profile explicitly. Matching labels preserve their port IDs and interface mappings. Removing a connected port or changing its connector is rejected until its cable is disconnected.

There is no default port capacity, implicit profile selection or SNMP fallback. Devices without a profile show **Physical ports are not configured**. To change a device’s category or node, disconnect its cables and clear its physical ports first.

## Arrange and connect

- Drag a device header to move it, then **Save Layout**. Positions are saved per node. The header also supports arrow keys (Shift for larger steps).
- Drag a physical port onto a port on another device to create a cable. The cable is saved immediately. Clicking one port then another is also supported.
- **Configured Connections** provides native Cacti forms for adding and disconnecting cables.
- **Arrange Devices** places devices in a grid; save to retain the arrangement. The canvas scrolls horizontally and vertically.
- A physical port accepts one cable. Same-device, occupied-port and cross-node connections are rejected. Stale layout revisions are rejected to prevent overwriting another operator’s work.

Solid cables are labeled **Configured**. They are operator records, not proof of a live cable or successful SNMP collection. **LLDP / CDP Evidence** retains the existing observed connection view. Device status uses Cacti’s latest status; simulator devices are labeled **Simulated**. Configuring cables does not configure switch hardware.

## Cacti integration and storage

The Console header, sidebar, tabs, selectors, forms, tables, authentication and CSRF protection remain native Cacti. Only the drawing surface has scoped styles and JavaScript; there are no external libraries or replacement UI shell. Native device management permission is required for writes; reads honor native device visibility and hide cables with inaccessible endpoints.

Five additive plugin-owned tables store port profiles, physical ports, cables, positions and layout revisions. A database-specific advisory lock serializes physical edits and classification updates. Transactions preserve cable constraints and action audit records. Native Sites, devices, SNMP settings, data sources, graphs and NMS tables are not altered by the upgrade.

## Validation on the local VM

- PHP syntax and JavaScript syntax checks.
- Disposable Cacti clone: repeated upgrade, port materialization, wrong-category and capacity rejection, duplicate/uncached interface mapping, one-cable-per-port in either direction, self/cross-node rejection, profile expansion preserving IDs, rollback on unsafe profile reduction, reassignment protection, layout bounds and stale revisions, restricted-user visibility and write rejection, native-data preservation.
- Browser: native category-profile creation, device-port application, device drag, layout save and reload, physical port drag creating a configured cable.
- Lab profile: **Lab Switch — 2 Ethernet Ports**, Network category, Ethernet1/2, configured RJ45 connectors; applied only to the two existing simulated lab switches. Explicit interface mappings use their verified cached ifIndex 1/2.

Run backend tests only in a disposable clone named `topology_canvas_qa_*`:

```sh
sudo php tests/physical_test.php --cacti-root=/var/www/html/cacti --database=topology_canvas_qa_test
```

## Color coding

**Setup → Equipment Categories → Edit → Topology Color** controls each category’s header stripe and tint (blue, teal, purple, orange, pink or slate). The diagram displays a category legend.

Device badges use green for Up, red for Down/Error, amber for Recovering and gray for Unknown/Disabled, based on native Cacti status constants. Simulated devices have a purple label. Connected ports and configured cables are blue; available ports have an open-circle symbol. A selected port is amber. Text labels accompany colors. Cable color does not indicate operational link health.

The color preference is an additive column on the plugin-owned category table. Verification additionally covered hex-color validation, preservation of existing colors and the native category color editor. The final browser view preserved its cable and positions across reloads.
