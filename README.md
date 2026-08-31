# NMS Fault Management and Dynamic Topology Plugin

The `nms` plugin adds dynamic fault management to Cacti 1.2.31 without modifying Cacti core.

Data sources:

- current device status from `host`;
- collector status and heartbeat age from `poller`;
- live RRD file existence and modification time;
- unknown (`U`) values received through the `poller_output` hook;

The plugin stores category mappings, fault-rule configuration, incident lifecycle, and audit
events only in its own `plugin_nms_*` tables. Cacti core tables remain unchanged.

## Device categories and fault rules

The **Fault Configuration** page contains the ten Figure 5.2 groups: Voice / Video,
Security, Network, VSAT, LOS, Computers, Power, Timing, Fire Prevention, and Sensors &
Instrumentation. Every existing Cacti host template is automatically placed in one group;
an administrator can correct a mapping at any time. The mapping points to the actual
`host_template.id`, so all existing and future devices using that template receive the same
fault rules.

Each category has independent rules for Cacti device state, availability, poller response,
RRD freshness, and missing RRD files. The threshold, severity, rule name, and enabled state
are editable. Multiple rules can be active for the same category and can create multiple
faults for one device. Saving a mapping or rule immediately evaluates the affected live
Cacti devices; no sample or fallback readings are used.

## Dynamic topology

The **Topology** module has no sample-device fallback. It reads device names, addresses,
status, availability, poller assignment, SNMP version, interface counts, graph counts and
links from Cacti core tables. Before a map can be used, devices must be enabled and assigned
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
healthy devices. Each row shows status, availability, poller response time, poll totals,
failed polls, RRD freshness, and the latest reading time. Faulted devices also show their
active incident and acknowledgement action. Collector, automation, PHP, and system-log
messages are not shown on this page; RRD data appears only as a per-device health reading.

The left navigation sidebar can be opened or collapsed from the menu button in the
header. Acknowledgements are recorded against Cacti's authenticated user and move an
incident from **Open** to **Acknowledged** without modifying Cacti core.
