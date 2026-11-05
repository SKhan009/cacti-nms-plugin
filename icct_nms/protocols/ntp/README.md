# Read-only NTP monitoring

NTP presets populate new inventory assignments. Each saved device keeps its own snapshot;
editing a preset does not change existing assignments. Collection is disabled when the
inventory device or its NTP protocol is disabled. No assignments are created by installation.

Supported methods are explicit:

- **Local collector chrony:** an inventory address of `localhost`, `127.0.0.1` or `::1` on its
  assigned Cacti collector. Reads `/usr/bin/chronyc -n -c tracking`.
- **Chrony over SSH:** the same fixed command on a remote Linux device, using its existing
  SSH assignment, encrypted credential and approved host key. The existing unprivileged SSH
  service schedules this independently of SSH health monitoring; it never enables that switch.

There is no transport, credential or parser fallback. Other NTP implementations and vendor
SNMP time MIBs are unsupported until they have their own explicit validated adapter. An
optional expected source accepts an IP address, avoiding a dependency on public DNS. A blank
expected source monitors whichever source chrony actually selected.

Chrony CSV reports seconds. The UI converts measured offset, RMS offset and root delay to
milliseconds. RMS offset is not labelled jitter. Unsynchronized/local-reference clocks have
no valid offset measurements; failed or stale collection shows no current sample. A measured
zero remains zero. The latest 25 observations are displayed, with 30-day history retention.
NTP health feeds the existing alarm journal, including acknowledgement and recovery.

The installer includes `chrony` in offline RHEL package bundles and verifies `/usr/bin/chronyc`.
It does not edit `/etc/chrony.conf`, start/restart `chronyd`, choose public time sources, or set
clocks. Configure the organization's internal LAN time service outside this plugin. The
collector issues only `tracking`; it never issues `makestep`, `settime`, `add server` or
`chronyd` configuration commands. Neither collection method needs Internet access.

Device clock status uses dedicated `ntp_monitors`/`ntp_monitor_state`/`ntp_monitor_history`
tables. Retired server-probe tables are left untouched and never used as device status.
The Debian client-tools package profile does not install or replace a clock daemon; this
adapter requires an already provisioned chrony service there.

Upgrade through Cacti Plugin Management to create the NTP tables and preset defaults before
assigning NTP to a device. Local collection uses Cacti's poller hook; SSH collection uses the
existing credential service. Collection interval must be a multiple of the Cacti poller
interval, and the stale interval must cover collection plus command timeout. Failed reads,
source mismatch, excessive offset and stale observations raise a warning without changing time.

NTP integration tests refuse a database outside the disposable `icct_discovery_qa_` namespace.
Network fixtures run `chronyd -x`, which prevents clock adjustment. Fixture data never enters
live inventory.
