# Node phase 6 — QA and VM rollout

Verified 24 September 2026 on Cacti-RHEL9, NMS 1.10.99. The VM clock reads 21 September; job and log timestamps use that clock. Scope is NMS only; no GNSS or rack changes.

## Deployment and regression evidence

- Compared SHA-256 of all 153 owned PHP/JS/CSS files against the VM: no missing, extra or mismatched source files.
- PHP syntax: all 121 owned PHP files passed locally and on target PHP. All 16 owned JavaScript files passed Node syntax checks.
- Seven fixture suites passed locally and on the target: nodes, topology-map, diagnostic-admission, diagnostic-history, diagnostic-loopback, diagnostic-protocols and diagnostic-summary.
- Real SQL node integration passed: multiple devices, uniqueness, same-site enforcement, rollback, hidden-member protection, explicit assignment/unassignment, removal confirmation, safe move/remove, native graph preservation and unchanged device connection settings. ACL denial is tested with controlled service fixtures; browser checks use the existing admin session.
- Temporary node fixtures cleaned: zero nodes/members remain, matching the pre-test state. No native host records were removed.
- Existing pre-node SQL/plugin backups and pre-phase45 source backup verified present and nonempty. Rollback restores were not performed.
- Cacti poller processed all 12 hosts, 110 data sources and 107 RRDs. Existing timer remains enabled. HTTP and MariaDB active.
- Browser map rendered India, state labels, neighbouring countries and islands; three native sites and nine unlocated devices remain. No browser console errors observed. Live Nping result showed four replies, zero loss, exit 0 and the collector-local scope explanation. Phase45's node-filter, refresh, drill-down and removal-form checks remain recorded in NODE_SETUP.md.

## Deployment issue repaired

NMS was disabled (plugin status 4), so the runner was absent. Re-enabled it through Cacti's `api_plugin_enable` lifecycle. A forced native poll then exposed that the systemd oneshot poller terminates its background listener at service completion (KillMode=control-group).

Installed and enabled `nms-diagnostic-runner.service`, running the existing listener as apache. It retains control-group cleanup in its own unit. Verified its heartbeat and active state after another poller cycle; NMS status is 1. No Cacti core, credentials, file capabilities, SELinux policy or sudo rules were changed. The service template and install/remove instructions are in deployment/.

An initial QA CLI probe threw an uncaught offline-runner exception, which Cacti's shutdown handler logged and used to disable the already-disabled plugin again. The probe was changed to catch admission errors before rerunning; the plugin was then enabled through its lifecycle. Earlier duplicate-bootstrap warnings belong to the prior upgrade script, not these successful runs. Operational checks now explicitly include enabled state and heartbeat, not only page rendering.

## Live diagnostic results

All tests used the existing authorised profile and collector-local Cacti host. No remote bandwidth traffic was generated. Saved job IDs:

| Jobs | Checks | Result |
| --- | --- | --- |
| 55–58 | Ping; Traceroute UDP, ICMP, TCP | Exit 0, complete |
| 59–60 | MTR ICMP, TCP | Exit 0, complete |
| 61–62 | Nping ICMP, TCP | Exit 0, complete |
| 63–64 | hping3 ICMP, TCP | Exit 0, complete |
| 65 | ARP | Exit 0, complete |
| 66 | iPerf3 first run | Timed out, exit 124; retained as failed |
| 67 | Netperf | Exit 0, complete |
| 68 | Pathchar | Exit 0, complete |
| 69 | iPerf3 retry | Exit 0, complete |

Netperf’s successful run also logged a connection-refused warning during its temporary-server readiness probe; the subsequent test completed, and NMS remained enabled. This startup-log noise is an existing observation.

The iPerf3 timeout did not reproduce on immediate retry; its cause is unconfirmed. It is recorded as an intermittent observation, not hidden or relabelled successful. These runs establish local collector operation, not remote reachability or physical link capacity. The existing readiness indicator may show runner unavailable while a long worker temporarily pauses heartbeat updates; idle readiness returns on completion.

## Remaining scope

Broader roadmap features remain separate: geographic popup node grouping, external-neighbour expansion, node disable and persistent lifecycle audit history. Phase6 validates the node functionality already delivered; it does not certify those unimplemented features or physical network performance.
