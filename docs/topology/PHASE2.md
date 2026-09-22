# Phase 2 operation and verification

This is the retained Phase 2 record. Local SNMPv3 results are described in [Phase 3](PHASE3.md). Current location/node setup is described in [LOCATIONS-NODES.md](LOCATIONS-NODES.md); rerun commands below use the current `--unit-id` argument.

## Current deployment

Version 0.2.0 is installed on the project's Cacti 1.2.31/RHEL 9 VM. Site 3, **Topology Lab Node 01**, contains native hosts 9 and 10. Both are classified as Ethernet Switches in Network, with Core and Access roles and LLDP-only preference.

Open **Console → Topology → Connections** and choose the lab node. The diagram and evidence table show one reciprocal **Ethernet1 ↔ Ethernet1** connection. Clicking a device in the diagram opens its native Cacti device editor.

In **Discovery**, the saved schedule is enabled at 300 seconds; stale age is 900 seconds. **Discover Now** queues a request and returns immediately. Reload the page to inspect its queued/running/complete/partial/failed state. Multiple pending requests for the same node share one queued/running job. The service is independent of the normal Cacti poller.

The schedule is stored in Cacti's database and survives VM restart. Its systemd timer is enabled. Disabling the schedule stops future automatic requests; an already queued request remains an authorized request and can still run. Disabling the plugin prevents worker collection.

## Reading the result

| Display | Meaning |
|---|---|
| Reciprocal | Current evidence from both endpoint devices |
| One-sided | Current evidence from only one endpoint |
| Historical / stale | Retained evidence is missing, expired, configuration-invalid or affected by collection failure |
| Interface Up / Down / other | Live IF-MIB operStatus from a recent successful collection, independent of neighbor evidence |
| Cacti Status | Latest core Cacti availability result, independent of the topology request |
| Unresolved | Advertised peer/port cannot be matched uniquely to a visible configured endpoint |

The diagram's solid lines indicate current evidence; dashed lines indicate historical evidence. An LLDP/CDP entry is an observation, not a physical cable continuity measurement. If no rows are returned, the neighbor table may be empty or excluded by the SNMP view. Optional fields without mandatory identities cause a failed snapshot rather than a false empty result.

Snapshot replacement is per host/protocol. A failure never replaces stored evidence with fixture data and never advances its last-success time. A successful empty table marks prior observations missing. Missing observation history is retained for seven days; entries older than seven days are excluded from reconciliation even if collection never recovers. Endpoint configuration changes invalidate stored evidence. Hidden devices and devices moved out of the selected node are excluded.

## Matching rules

LLDP uses the exact chassis ID/subtype and port ID/subtype. Local port number is a table index, not an assumed IF-MIB index. This release maps interfaceName, interfaceAlias and MAC port identifiers through live IF-MIB. Other subtypes remain unresolved unless a future explicit mapping is added. These objects and their indexes are defined in the [IEEE LLDP MIB](https://www.ieee802.org/1/files/public/MIBs/LLDP-MIB-200505060000Z.mib).

CDP uses the exact global advertised Device-ID and remote Device-ID. Its remote Port-ID matches an exact local interface name advertised through CDP or IF-MIB. Repeater port identifiers without a real matching ifIndex remain unresolved. See Cisco's [CISCO-CDP-MIB](https://raw.githubusercontent.com/cisco/cisco-mibs/main/v2/CISCO-CDP-MIB.my).

Reverse and dual-protocol observations merge only when both native device/interface endpoints match. Parallel endpoint pairs remain separate. Each protocol remains independent: failure of one selected protocol is displayed alongside any success of another explicitly selected protocol.

## Verified

- Upgraded Phase 1 without replacing native devices, templates, data sources, graphs or RRD history.
- Native browser workflow saved the policy, queued manual discovery and showed completed jobs. Scheduled discovery also ran and recovered failed evidence.
- Live LLDP-only pair produced one reciprocal link and two source observations.
- Live CDP-only pair produced one reciprocal link and two source observations.
- Live dual-protocol pair produced one link with four preserved observations.
- Requesting CDP against LLDP-only fixtures and LLDP against CDP-only fixtures failed explicitly without switching protocols.
- Stopping the dedicated simulator caused both protocol snapshots to fail while retaining their exact prior evidence and successful timestamps. The native Connections page displayed a historical link, unknown/stale interface status and explicit collection errors. Recovery restored reciprocal evidence.
- Regression checks covered LLDP local port 101 mapping to ifIndex 1 by exact name, duplicate chassis identities, parallel links, one-sided evidence, incomplete rows, optional-only SNMP views, stale/future timestamps, seven-day expiry and interface-down status separate from neighbor evidence.
- Transport checks reject time/object limits, non-increasing OIDs and a timeout after a partial table.
- Restricted-user queue access, hidden-peer exclusion and invalidation after a core endpoint change were checked.
- Existing SNMPSim and normal Cacti polling remain operational; original NMS code and Cacti core PHP files match pre-change backups.
- Private plugin files remain protected by Apache; SELinux remains enforcing.

The tests use the project lab and static fixtures. Real equipment and SNMPv3 operation have not been validated. There is no manual link/layout editor, LLDP configuration push, network scan, VLAN/routing inference or CNMS integration in this phase.

## Re-run checks

Pure regression checks:

```sh
php /var/www/html/cacti/plugins/topology/tests/neighbors_test.php
php /var/www/html/cacti/plugins/topology/tests/discovery_transport_test.php
```

Read the current persisted two-switch lab:

```sh
sudo php /var/www/html/cacti/plugins/topology/tests/discovery_live.php \
  --cacti-root=/var/www/html/cacti --unit-id=3 --host-a=9 --host-b=10 --mode=baseline
```

Live LLDP requests to the installed lab (the community strings below identify simulator records only):

```sh
sudo php /var/www/html/cacti/plugins/topology/tests/discovery_live.php \
  --cacti-root=/var/www/html/cacti --unit-id=3 --host-a=9 --host-b=10 \
  --mode=lldp --community-a=topology-switch-a --community-b=topology-switch-b
```

For CDP or both, first serve the matching example records under explicit test community names and pass those names with `--mode=cdp` or `--mode=both`. These test modes make live requests with copies of the selected host settings; they do not change native device configuration. The `failed` mode is different: it tests snapshot failure persistence and requires deliberately stopping the dedicated simulator after a successful baseline. Always restore the simulator, activation timer and discovery timer afterward, and run a recovery job.

CLI test assertions report nonzero exit codes without triggering Cacti's fatal-plugin shutdown guard. Temporary QA community records used during release testing are removed afterward. The six downloadable examples remain in the plugin.
