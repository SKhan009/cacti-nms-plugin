# VM configuration changes

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
