# NMS Fault Management and Dynamic Topology Plugin

The `nms` plugin adds dynamic fault management to Cacti 1.2.31 without modifying Cacti core.

Data sources:

- current device status from `host`;
- actual device parameter definitions from Cacti data templates;
- latest raw values returned by Cacti device collection, captured through `poller_output`;

The plugin stores category mappings, fault-rule configuration, incident lifecycle, and audit
events only in its own `plugin_nms_*` tables. Cacti core tables remain unchanged.

## Device categories and fault rules

The **Fault Configuration** page contains the ten Figure 5.2 groups: Voice / Video,
Security, Network, VSAT, LOS, Computers, Power, Timing, Fire Prevention, and Sensors &
Instrumentation. Every existing Cacti host template is automatically placed in one group;
an administrator can correct a mapping at any time. The mapping points to the actual
`host_template.id`, so all existing and future devices using that template receive the same
fault rules.

The configuration page has two compact tabs: **Fault values and severity** and **Cacti
template mapping**. Parameter choices come only from data sources attached to real devices
in the selected category. A rule can compare numeric readings, for example temperature
greater than `80` or free memory less than `500`, and text readings, for example interface
state does not equal `up`. Unknown, empty, equals, not-equals, contains, greater-than and
less-than conditions are supported. Threshold, unit, severity, name and enabled state are
editable. Multiple rules can monitor the same parameter at different severities.

The plugin does not create generic poller or RRD-file faults. It records the latest raw
device value in `plugin_nms_device_parameters`, evaluates it against category rules and
stores only resulting incident state. It never inserts sample readings.

## Dynamic topology

The **Topology** module has no sample-device fallback. It reads device names, addresses,
status, availability, category, configured fault severity, SNMP version, interface counts,
graph counts and links from Cacti core tables. Before a map can be used, devices must be enabled and assigned
to a Cacti Site under **Management → Devices**.

For each site, an administrator explicitly selects the real core switch or gateway. The
plugin never guesses this device. Other Cacti devices are then dragged from Inventory onto
the canvas. The plugin's `plugin_nms_topology` table stores only the selected root,
parent-device relationship and X/Y layout. It does not copy or replace Cacti device data.
When Cacti has indexed interface data for the parent device, the detail panel also offers a
parent switch-port selector. Those choices come from `host_snmp_cache`; the plugin stores
only the selected SNMP index. Because core Cacti does not discover physical cabling by
itself, the administrator must confirm the real parent and port instead of accepting a guess.

Removing a device from the map deletes only its layout row. The device remains unchanged in
Cacti and immediately remains available in Inventory. Use **Configure in Cacti** for device
changes and **Open Cacti graphs** for the host's native graphs.

The fault dashboard is rendered as a dedicated NMS interface. It still uses Cacti's
authenticated session and live database, but does not display Cacti's administration
chrome. Administrators can return through the **Cacti Backend** button in the header.

The Fault dashboard displays every enabled device from Cacti's `host` table, including
healthy devices. Each row shows status, availability, latest real device parameters and the
latest reading time. Faulted devices also show their
active incident and acknowledgement action. Collector, RRD-file, automation, PHP and
system-log messages are not shown on this page.

The left navigation sidebar can be opened or collapsed from the menu button in the
header. Acknowledgements are recorded against Cacti's authenticated user and move an
incident from **Open** to **Acknowledged** without modifying Cacti core.

## SNMP test devices

The repository includes a reproducible SNMPSim lab under `snmpsim/`. It supplies a
router, Linux server, and environmental sensor on the VM loopback interface for testing
real Cacti SNMP collection and NMS fault rules. See `snmpsim/README.md` for the endpoint,
community names, readings, and VM layout.

## Device management and SNMP record imports

The **Devices** module provides a live inventory from Cacti's `host`, template, site,
poller, graph, data-source, and poller-item tables. Its Add Device form calls Cacti's
supported `api_device_save()` function, so the created target is a normal core Cacti
device rather than a copied NMS record.

Administrators can upload a static `.snmprec` file up to 2 MB and 5,000 lines. NMS
validates every OID, type tag, value, community, filename, and category before making
changes. It stores import metadata in `plugin_nms_snmprec_*` tables and deploys the
validated record to the configured SNMPSim directory. Text and object-identifier records
remain visible in the import inventory. Numeric Integer, Counter, Gauge, TimeTicks, and
Counter64 records receive native Cacti data-source and graph templates by duplicating the
installed **SNMP - Generic OID Template** through Cacti's template APIs and fixing the
template OID to the imported record. A single upload is limited to 64 graphable readings.

Each import creates or reuses a named Cacti host template, links all generated graph
templates to it, and maps it to one NMS device category. The administrator can then use
**Add device** with the imported community and host template. Uploading templates does not
create synthetic Cacti readings: the poller must retrieve the actual OID from SNMPSim or
the eventual real device.

Use **Upload SNMP record → Download sample file** for a ready-to-import test record. The
sample community can be `nms-device-demo`, with a host template such as **NMS Demo
Environmental Device**. It contains 23 OIDs, including 18 numeric CPU, memory,
environmental, storage, and interface readings.

On **Devices → Manage → Create a graph from a data source**, NMS lists the device's live
`data_local` and `data_template_rrd` items from Cacti core. Already-graphed readings stay
visible and are marked read-only. For an ungraphed reading, one action creates a native
Cacti graph template, links its LINE and Current/Average/Maximum graph items to the
selected data-template item, and creates the device graph against the existing local data
source. The data source is reused; it is not copied into an NMS table.
