# NMS Fault Management Plugin

The `nms` plugin adds dynamic fault management to Cacti 1.2.31 without modifying Cacti core.

Data sources:

- current device status from `host`;
- collector status and heartbeat age from `poller`;
- live RRD file existence and modification time;
- unknown (`U`) values received through the `poller_output` hook;

The plugin stores incident lifecycle and audit events only in its own `plugin_nms_*` tables.

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
