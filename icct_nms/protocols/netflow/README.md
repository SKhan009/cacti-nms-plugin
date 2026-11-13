# NetFlow and IPFIX

The wire collector is nfdump/nfcapd (BSD/GPL licensed, installed as a system package); this plugin does not copy FlowView's database or code. nfcapd decodes NetFlow v5/v7/v9 and IPFIX. Each approved exporter IP is associated with one native Cacti host ID. Protocol settings are inherited from Presets when first added, then saved independently per device. Disabling or removing the assignment stops collecting that source; saved history remains readable until retention expires.

## Deployment

1. Upgrade ICCT NMS using native Plugin Management to create the owned flow tables.
2. Run `sudo bash protocols/netflow/rhel/install_netflow_rhel.sh /var/www/html/cacti/plugins/icct_nms` from the plugin root. Install the nfdump RPM and its dependencies from approved offline RHEL media first. The installer verifies local binaries, performs no network/package-manager calls, and enables `icct-nms-netflow.service` as the apache account.
3. Under Presets → Protocols, configure collector IP/hostname reachable from exporters, UDP port, version, exporter timing, reporting classification, and anomaly thresholds.
4. Add NetFlow under the device's Protocol Config and specify the actual source IP seen by the collector. Exporter IPs are unique: NAT sources shared by multiple devices cannot be distinguished and require distinct exporter source addresses.
5. Configure the router/switch to export to the saved collector/port. Saving in NMS does not push vendor configuration to equipment. The nfcapd receiver accepts all four wire formats; the selected version describes intended exporter configuration, while records display their actually decoded version.
6. Permit the configured UDP port only from approved exporter networks in the VM firewall. The installer does not change firewall policy. The service supports IPv4 and IPv6 exporter transport and starts listeners only for enabled devices assigned to this Cacti poller; install the same package and service on each remote poller to collect its local devices.
7. Open Device View → NetFlow / IPFIX, or the matching NetFlow link from its topology device summary.

## Readings

Capture rotation is 60 seconds and the importer checks every 5 seconds. Device readings refresh every 30 seconds. Raw captures are retained for two days for retry; decoded records, five-minute totals, and anomaly events are retained for 30 days. Files and counters are committed in one transaction and duplicate files do not increment totals. nfdump applies advertised sampling; the importer does not scale those counters again. The saved device sampling rate is used only for records without advertised sampling information. Time display and capture decode use Asia/Kolkata.

WAN ifIndex overrides take priority. Otherwise, two endpoints in configured LAN prefixes are classified LAN; exactly one inside is WAN; neither is unclassified. These rules are configurable classifications, not inferred physical links. Flow conversations are not treated as topology adjacency.

Application identity is taken from exporter metadata where present. Without it, application identity is Not reported; destination ports never substitute for exporter evidence.

Anomaly rules evaluate a five-minute window: destination/port fan-out, SYN-only packet rate, SMB/RPC fan-out suggesting worm-like scanning, and bandwidth above a configured limit or three times a usable prior baseline. These are investigation indicators, not proof of malware or an IDS replacement. Historical events retain their timestamps and resolve only when fresh flow records are present. Thresholds belong to each device. Each saved anomaly episode also appears once in the shared Fault console as NetFlow, reusing acknowledgement, escalation and immutable history. The worker and poller repair pending journal updates from durable anomaly rows after failures; collector outages do not clear faults. Source-IP rankings identify top talkers.

## Verification

`tests/send_flows.py` constructs real UDP datagrams in all required versions. Run only against an isolated test collector/port, for example `python3 tests/send_flows.py --port 22055`. Run `sudo bash tests/run_wire_test.sh` from this directory for a reproducible loopback UDP capture followed by native connection-local database assertions. It verifies correct versions, timestamps, counters, retry idempotence, device permissions, filtering, configured/advertised sampling behavior and anomaly detection/recovery. It does not change saved device data. Set `NF_TEST_PORT` if the default test port 22065 is occupied.

### Local RHEL device exporter

`rhel/local_exporter.sh` and `rhel/icct-nms-local-exporter.service` provide the local lab host exporter using the already installed `nfpcapd` and `nfreplay` binaries. This deployment captures actual interface `enp0s8` traffic, expires flows at 30/10 seconds, and sends NetFlow v9 from `127.0.0.77` to `127.0.0.1:22066`. Those values match this device's existing assignment; adjust them explicitly for another machine. No fault rule is required. This measures this host's visible interface traffic, not the entire network.

Install the script as `/usr/local/libexec/icct-nms-local-exporter.sh` and the unit under `/etc/systemd/system`, then enable the unit. Runtime flow files are deleted after export and discarded on service restart. Packet payload logging is not enabled. The existing receiver rotates/imports on its own interval, so allow roughly 1–2 minutes before readings appear. Stop/disable `icct-nms-local-exporter` to stop local capture without disabling the collector.

### Capture replay protection

Capture-file hashes provide a quick retry check. A unique `payload_key` also identifies the complete decoded capture, independent of filename, file headers, record order, and application/service enrichment. Identity includes host, direction, protocol, interfaces, counters, timestamps, sampling and record multiplicity. Opposite-direction and later observations remain separate records.

For an existing installation, back up `plugin_icct_nms_netflow_flows`, `plugin_icct_nms_netflow_files`, `plugin_icct_nms_netflow_buckets` and `plugin_icct_nms_netflow_state`; stop the collector and run `php protocols/netflow/cli/deduplicate_captures.php` from the plugin directory before restarting it. The CLI adds the unique payload index, removes only complete repeated captures, adjusts counters transactionally, and retains file receipts to prevent replay. Re-running it is safe. Incomplete/partially expired captures are left untouched. This does not merge individual similar records from different captures.
