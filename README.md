# NMS Fault Management Plugin

The `nms` plugin adds dynamic fault management to Cacti 1.2.31 without modifying Cacti core.

Data sources:

- current device status from `host`;
- collector status and heartbeat age from `poller`;
- live RRD file existence and modification time;
- unknown (`U`) values received through the `poller_output` hook;
- current warning, error, and fatal entries from Cacti's configured log.

The plugin stores incident lifecycle and audit events only in its own `plugin_nms_*` tables.

The fault dashboard is rendered as a dedicated NMS interface. It still uses Cacti's
authenticated session and live database, but does not display Cacti's administration
chrome. Administrators can return through the **Cacti Backend** button in the header.

Technical log lines are summarized under **System notices** in plain language. Repeated
messages are combined, and known corrected problems are labelled **Fixed**. The original
Cacti log remains available through the **Technical log** button.

The left navigation sidebar can be opened or collapsed from the menu button in the
header. Acknowledgements are recorded against Cacti's authenticated user and move an
incident from **Open** to **Acknowledged** without modifying Cacti core.
