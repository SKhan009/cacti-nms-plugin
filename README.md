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
