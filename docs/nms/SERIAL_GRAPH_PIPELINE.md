# Serial graph collection and schedule validation

The serial preset supplies connection settings; the device supplies its unit address and equipment profile. The configuration worker reads the documented fields and stores timestamped samples. Cacti's Script/Command input calls `serial_value.php` with device ID, field key, equipment profile ID and revision. Only a fresh sample for that exact binding is returned. Missing, failed, stale or superseded samples remain `U`, not zero.

The serial reading interval and Cacti poller interval are separate schedules. A 60-second NMS reading interval does not enable 60-second Cacti polling. Native graph provisioning now selects an installed Data Source Profile with:

- a step at least as large as Cacti's poller interval;
- a step that is an exact multiple of that interval;
- a heartbeat at least as long as its step.

Among eligible profiles it prefers the largest step no greater than `max(reading interval, poller interval)`, with the native default breaking ties. If none exists, setup reports the missing compatible profile. Add Serial Device preflights this before creating the device when reading graphs are requested.

## Confirmed QA failure and repair

Device 64 (the retained Modbus simulator) returned 23 correctly, but graph 208 / data source 216 used a 60-second profile on a 300-second Cacti poller. Its `rrd_next_step` remained positive, so cmd.php never selected it. The RRD's last update stopped even though the serial readings stayed fresh.

The repair selected installed profile 1 (300-second step, 600-second heartbeat), aligned the owned templates and poller cache, and rebuilt the RRD using RRDtool's source-prefill support and the selected native profile's archives. Graph and data-source IDs were retained. Original database rows, original RRD and original graph provisioner were backed up on the QA server under `/var/lib/cacti/nms-serial-graph-repair/`. No fabricated samples were inserted. Original higher-resolution history is retained in the backup; the repaired RRD follows the selected native profile's resolution and retention.

New scheduled samples must fill a completed consolidation interval before the chart shows a value. Historical gaps cannot be recovered from a current sample. Do not repair gaps by writing zeros or pretending old observations are current.

## Verification

- `php tests/nms/serial-graph-schedule.php`: fast reader/slow Cacti regression, fast native polling, nonmultiple intervals, missing profile and invalid heartbeat.
- Native input run under the apache account: numeric sample 23.
- Repeated native provisioning: the same graph 208 and data source 216, no duplicate objects.
- Native scheduled Cacti poller: RRD `lastupdate` advances and receives 23; the completed 22:25 QA-server bucket contains `2.3000000000e+01` in AVERAGE. Native graph rendering succeeds.

For other NaN cases inspect, in order: current reading/signature, input output under the poller account, poller-cache binding/countdown, last RRD update, then the graph's selected time range. A stale or model-mismatched sample must remain unknown until its real configuration is corrected.
