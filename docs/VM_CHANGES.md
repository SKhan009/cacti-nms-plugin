# VM configuration changes

## 2026-09-01 — Device management and SNMP record imports

NMS 1.9.0 adds a Device Management page that reads the complete device inventory from
Cacti core and creates devices with Cacti's supported device API. A validated `.snmprec`
upload now creates a native Cacti host template plus one Generic OID data-source and graph
template for each numeric reading. Import metadata and Cacti object IDs are kept in the
plugin's `plugin_nms_snmprec_imports` and `plugin_nms_snmprec_oids` tables.

The SNMPSim runtime data directory moved to `/var/lib/snmpsim/data`. It is owned by
`apache:snmpsim`, mode `2770`, and labelled `httpd_sys_rw_content_t`; uploaded files are
mode `0640` and inherit the `snmpsim` group. A root-owned systemd timer checks the upload
marker every ten seconds and restarts SNMPSim. Apache receives no sudo permission.

End-to-end verification imported `sim-ui-demo`, created Cacti host template 30, three
data-source templates, three graph templates, and Device 6 (**NMS Imported Sensor Demo
Device**). Its template associations were applied and the Cacti poller reports the device
Up with 100% availability. A second end-to-end import based on the supplied Serial Device
Server reference created host template 32 and 13 data-source/graph template pairs from 19
OID records. The `serial-device-server` community became available automatically on the
next ten-second activation check. No Cacti core file was modified.

## 2026-09-01 — SNMPSim lab devices

SNMPSim 1.2.2 was installed in an isolated Python 3.11 environment at
`/opt/snmpsim/venv`. Its systemd service runs as the unprivileged `snmpsim` account,
starts at boot, and listens only on `127.0.0.1:1161`. The VM's existing Net-SNMP service
continues to own port 161.

Three SNMP v2c devices were added through Cacti's supported CLI:

- Device 3, **NMS Simulated Router**, community `sim-router`, Cisco Router template;
- Device 4, **NMS Simulated Server**, community `sim-server`, Net-SNMP Device template;
- Device 5, **NMS Simulated Sensor**, community `sim-sensor`, AKCP Device template.

After reindexing and a forced poller run, all three devices reported Up with 100%
availability. Simulator records, Python requirements, the service unit, and operating
notes are backed up under `snmpsim/` in this repository. No Cacti core file was changed.

## 2026-08-31 — Dynamic Cacti topology module

NMS version 1.6.0 adds a Topology module with the same shared header and sidebar as the
Fault view. The module reads enabled devices by Cacti Site from the core `host`, `sites`,
`poller`, `graph_local`, and `host_snmp_cache` tables. It contains no demonstration device
data and will stop at a configuration-required screen when a site, device, or root device
is missing.

The new `plugin_nms_topology` table stores only visual configuration: root device, parent
device, selected parent SNMP interface index, coordinates, and audit fields. Device facts
remain owned by Cacti. Administrators select a core switch or gateway, drag inventory
devices to the canvas, and optionally confirm the physical parent port from Cacti's indexed
interfaces.

## 2026-08-31 — Traffic automation `ifIP` warning

Device 2 does not return the `ifIP` field for the **SNMP - Interface Statistics**
data query. The unsupported `ifIP is not empty` condition was removed from these
enabled rules:

- Traffic 64 bit Server
- Traffic 64 bit Server Linux

The useful `ifOperStatus is Up` and `ifHwAddr is not empty` checks remain. Cacti's
automation command was run for Device 2 after the change and completed without the
previous warning.

The original database rows are backed up in the VM at:

`/home/cactiadmin/nms-deploy/automation-ifip-before.sql`

This is a Cacti database configuration correction; no Cacti core file was changed.

## 2026-08-31 — Acknowledgement HTTP 500

The acknowledgement handler referenced a session constant that is not available in
Cacti 1.2.31. It now reads the supported `sess_user_id` session value. Cacti had
automatically disabled NMS after the PHP error, so the plugin and its registered hooks
were re-enabled after deployment.

Incident 1 was acknowledged successfully through the live HTTP form as user `admin`
during verification. The request returned to the fault page normally and NMS remained
enabled.

## 2026-08-31 — Device-only Fault view

The NMS Fault page was restricted to Cacti device-status incidents. System notices,
technical-log links, collector faults, RRD faults, and poller-output faults are not
displayed. Summary cards and filtering use the same device-only scope.

## 2026-08-31 — Always show device readings

The main table now lists every enabled Cacti device, including healthy devices. It shows
the current status, availability, response time, poll totals, failed polls, and latest
reading. The All view is the default, so the page is not empty when no faults exist.

Per-device RRD health was added to the same table. Each device reports fresh versus total
RRD sources, stale or missing counts, and the latest RRD file update time.
