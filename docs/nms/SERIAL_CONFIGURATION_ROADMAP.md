# Serial monitoring and equipment configuration

Status: seven-phase implementation and scoped verification complete, reviewed 2026-09-27.
Real-equipment acceptance remains dependent on manufacturer details and hardware.
Keep all code and documentation local: no commit or push. This overrides the
original publication step. Work is limited to NMS; GNSS is excluded. Only the
introductory paragraph of section 5.3.2 was supplied, so full compliance is not
claimed.

## Ownership and UI

Cacti remains the source of device IDs, names, sites, collectors, permissions,
data sources and graphs. NMS stores serial extensions and configuration jobs.
Node membership stays manual. A node has no shared device IP; each member keeps
its own connection, protocol and address.

| Location | Purpose |
| --- | --- |
| Presets → Serial profiles | Reusable bus settings and manufacturer/model compatibility |
| Serial connections | One shared direct port or transparent RTU/TCP endpoint per bus |
| Device management → Add serial device / Connection | Native device creation or connection assignment, with a unique unit address |
| Device → Configuration | Equipment profile, current readings, preview, apply, history and numeric graphs |
| Nodes → Configure | Explicit member selection, compatible changes and individual results |

Communication settings and equipment settings are separate. Preset edits do not
change saved connections or send commands. Refreshing copied connection settings
requires an affected-member review and revalidation. Equipment maps require a
manufacturer/model and a reference manual; simulator maps are labelled fixtures.

Seven plugin tables hold serial profiles, connections, device assignments,
equipment profiles, equipment assignments, jobs and latest readings. Their names
are `plugin_nms_serial_profiles`, `plugin_nms_serial_connections`,
`plugin_nms_serial_devices`, `plugin_nms_config_profiles`,
`plugin_nms_config_devices`, `plugin_nms_config_jobs` and
`plugin_nms_serial_readings`. No native Cacti table schema is extended.

## Implemented behavior and acceptance evidence

| Phase | Implemented behavior | Evidence and remaining acceptance |
| --- | --- | --- |
| 1 — Review/design | Reuses native device APIs, lifecycle hooks, realms, collectors, templates and node membership. Shared PHP services separate UI from execution. | Source review and this design record. Manufacturer manuals and the remaining 5.3.2 clauses are still needed. |
| 2 — Presets | Bounded serial settings, explicit model compatibility, versioned defaults and reviewed refresh of connection snapshots. | `serial-profiles.php` and `serial-profiles-integration.php` cover validation, snapshots, affected scope, stale/replayed previews and permissions. Profile creation, affected-member review and explicit refresh/apply passed in the browser; original fixture settings were restored. |
| 3 — Connections/devices | Direct collector port or transparent RTU over TCP; collector ownership, distinct unit addresses, endpoint conflict checks and physical-port locking. Native serial Add uses the actual port/gateway endpoint with network probes disabled. | Profile integration, `serial-transport.py` and `serial-device-create.php` cover ownership, duplicate addresses, collector mismatch, port locking and native creation/cleanup. Browser creation of a labelled device fixture passed. |
| 4 — Monitoring/Cacti | Scheduled due-field reads, signature/freshness checks, lifecycle invalidation, truthful serial status and model-bound native numeric graphs. | `configuration-worker-integration.php`, `configuration-lifecycle.php` and `configuration-graphs-integration.php` cover simulator reads, stale/failed/changed readings, lifecycle, graph provisioning, RRD values/unknown gaps and SVG rendering. Normal service-to-poller execution wrote simulator value 17 to the RRD. Device inventory/Edit, responding/failed node and topology details, and unified serial readings/filter browser checks passed. Temporary-table rendering and labelled synthetic browser fixtures cover stale and unavailable values in Device readings, nodes and topology details. |
| 5 — Equipment configuration | Read, validate, preview, apply once, read back and retain audit. Modbus registers and scoped SNMP writable OIDs share the workflow. | Worker and isolated Net-SNMP tests cover verified writes, changed-before values, scoped credentials, rejected writes, redaction and crash uncertainty. Single-device browser read, out-of-range rejection, preview/apply and automatic read-back passed against the RTU simulator (17 → 24). |
| 6 — Nodes | Manual selection of compatible members, fresh per-device previews, independent admission/results, membership and permission revalidation. Draft selection/value survive reads and refreshes. | `configuration-node.php` covers membership changes, incompatible fields, bounds, replay and permissions. Browser node read 17 → preview 23 → verified read-back 23 passed against a simulator with manually dispatched collector jobs. |
| 7 — Verification/documentation | RHEL Markdown/Word installation guide, persistent lock-directory rule and targeted regression suites. | Six Word pages rendered and inspected. Native graph/worker/node/lifecycle/SNMP/diagnostic tests passed as described below. Restricted-account GET admission, a revoked-permission profile POST, and 18 isolated controller GET/POST cases passed. SSH transport, ticket and service collection checks passed; browser-console acceptance needs trusted HTTPS. Serial changes match QA. Reboot recovery and manufacturer hardware acceptance remain. No Git publication is authorized. |

These bounded results support the requested implementation scope. They do not
establish manufacturer-hardware acceptance or full section 5.3.2 compliance.
The final requirement audit below supersedes earlier pending implementation notes.

## Supported protocols and limits

- Modbus RTU reads FC03/FC04 and single-register writes FC06. Field types are
  signed/unsigned 16-bit values. FC04 fields are read-only. No invented vendor
  adapters, compound encodings or arbitrary command execution are offered.
- Direct serial uses pySerial on the assigned collector. Prefer a stable
  `/dev/serial/by-id/…` path. Character-device identity locks aliases of a port.
- Gateway mode is a transparent RTU byte stream over TCP, not native Modbus TCP.
  Configure the gateway's physical serial settings separately to match the bus.
- SNMP fields use explicit numeric OIDs and integer, unsigned or bounded string
  representations. Write credentials remain in protected administrator-managed
  configuration and are scoped to native host IDs. Isolated QA covers SNMPv2c and SNMPv3 SHA/AES authPriv;
  manufacturer hardware and other SNMPv3 combinations require separate acceptance.
- Reads have bounded retries/timeouts. Writes are never automatically replayed.
  A verified result requires matching read-back; ambiguous results remain
  Unverified. No unsupported backup/restore operation is advertised.
- Numeric graph inputs return `U` for absent, stale, failed, disabled,
  wrong-collector or obsolete-model data. They do not open the device during
  graph rendering. Strings are not graphed.

## Execution and security invariants

Admission and execution both recheck device permissions, disabled state,
collector assignment and configuration signatures. A write needs a fresh,
single-use read and compares the device's current value before applying.
Connection locks span I/O and read-back. Jobs retain operator, device, times and
before/requested/observed values without credentials. Lifecycle hooks and
collector reconciliation invalidate obsolete jobs and readings; deletion retains
audit history. Interrupted writes remain uncertain rather than being retried.

Node batches are not atomic: one rejected member does not roll back another
member's verified change. A node never confers access to otherwise forbidden
devices. Restored UI drafts are filtered against current accessible membership;
execution still performs fresh checks.

## Verification record and deployment boundaries

The following run details are chronological. Later entries supersede earlier
pending deployment and fixture-cleanup notes; the phase table above summarizes
current acceptance. All owned persistent serial fixtures have now been removed,
with configuration audit history retained.

RHEL QA uses PHP 8.0.30, Python 3.9.25 and pySerial 3.4. The transport suite has
13 simulator cases including a pseudo-terminal, fragmented frames, CRC errors,
timeouts, port contention, pre-write checks and lost acknowledgement/read-back.
Temporary-table integration tests isolate plugin records; native device/graph
tests create and remove their own explicitly named objects.

Native graph verification used Cacti APIs to provision graph/input/template and
poller records, create an owned fixture RRD, retain value 17, record stale `U`
and render valid SVG. Cleanup verified removal of owned objects and the RRD.
This API-level test was later supplemented by the successful scheduled-poller
round trip recorded below. Cacti emitted a CLI date-format warning during the
API rendering test; the SVG was valid.

Browser node acceptance used a temporary local RTU simulator. Read job 1 returned
17; write job 2 requested and verified 23; device read job 3 returned 23.
The selection and requested value survived redirect/refresh. The collector
harness dispatched only these fixture jobs, because the QA plugin was already
disabled. The original fixture connection was restored, the temporary gateway
connection removed and the simulator stopped; audit history was retained.
Persistent labelled QA profile/device/node fixtures still need final cleanup.

Earlier tests preserved the QA plugin's disabled state and used manual dispatch.
On the subsequent 2026-09-27 check, its current status was enabled (`1`) before
this acceptance run; this task did not enable it. The browser queued read job 4
and write job 5, and the existing collector automatically completed them. The
UI rejected 101 outside the fixture's 0–100 range, previewed 17 → 24, then showed
Verified with before/requested/observed 17/24/24. Scheduled collection subsequently
refreshed the displayed value to 24 without another manual read. The fixture
connection was restored and the simulator stopped after the check.

The active listener was an apache process with parent PID 1, a held collector
lock and a current heartbeat. The separate systemd listener unit was restarting
successfully every ten seconds: duplicate instances exit when another listener
owns the lock. Thus automatic collector execution is proven for these jobs, but
the launcher conflict was then corrected using the administrator option
`nms_diagnostic_listener_launcher = service`. The default remains poller-owned
for installations without systemd. After deploying the reviewed listener revision,
the old background listener exited between workers and systemd obtained the lock.
MainPID matched the sole apache listener process, the heartbeat was current and
NRestarts stayed at 498 across repeated checks. `diagnostic-launcher.php` verifies
service mode does not dispatch another listener, default/explicit poller mode
still dispatches, and invalid modes fail closed. Updated admission fixtures also
verify disabled-plugin/collector rejection. This proves graceful handover;
reboot recovery and a complete scheduled-poller round trip are separate gates. Some serial UI
files are deployed, but the latest shared status changes are local/test-staged
and still require deployment comparison and browser acceptance. Concurrent
unrelated worktree changes must be preserved during deployment.

Existing node-health, topology-map and diagnostic admission/history/protocol/
summary/loopback regressions passed. `ssh-transport.php` now independently verifies the NMS SSH adapter against an
isolated loopback OpenSSH server: pinned host key, explicit key authentication,
the fixed Linux monitoring command, real CPU/memory/uptime parsing and malformed
output rejection. It uses temporary generated keys and a non-root login account;
its server and files are removed afterward. The first fixture attempt under
`/tmp` failed authentication; using a root-controlled `/run` parent passed without
relaxing OpenSSH StrictModes. This is transport/parser acceptance, not complete
NMS service, ticket, browser-console or scheduled SSH acceptance.
A read-only QA check found `nms-ssh.service` inactive, with its local configuration
and bundled dependencies present. Its source files have no worktree changes.
Neither condition establishes functional SSH acceptance, and the service state
was not changed.

`deployment/nms-serial-tmpfiles.conf` is installed on QA as
`/etc/tmpfiles.d/nms-serial.conf`. `systemd-tmpfiles` and an apache write check
confirmed the 0700 apache-owned `/run/cacti-nms-serial` directory. Package checks
confirmed pySerial and PHP SNMP availability. No full VM reboot was performed.

## Scheduled poller disabled-state check

On the next QA check, the plugin had returned to disabled (`status=4`) without
this task changing it. A temporary graph for the labelled serial device created
native graph 135 and data source 140. Its actual Script/Command entry point
returned `U`. The existing `cacti-poller.timer` ran at VM time 13:55 on September
21 and completed with exit status 0; `rrdtool lastupdate` showed timestamp
1789979102 with value `U`. This proves the scheduled poller records unknown while
NMS is disabled. It does not prove numeric collection through the scheduler.
The graph, data source and owned RRD were removed through native APIs; the prior
fixture connection was restored and the simulator stopped. Reusable fixture
templates remain for final fixture cleanup.

A source hash comparison also confirmed that QA differs from the latest local
serial monitoring, node, configuration page and shared device/topology status
files. Those differences require review before claiming final QA deployment.
Other serial transport/worker/graph files in the comparison matched.

## Serial status deployment and inventory correction

The serial monitoring helper, node-scope validation and shared device/node/map/
canvas status changes were deployed with backups after checking the live file
hashes. Unrelated discovery-address changes were excluded from that deployment.
Browser inspection then exposed a separate inventory bug: its header counted
native no-ping Up and its availability column showed network percentages/latency
for a serial device with a failed read. The header now counts only a current
serial response as Up, and serial rows show their response state instead of
network availability and poll time. Browser verification showed 12 Up devices
rather than 13, with the fixture row showing Read failed and Serial response.

`serial-inventory.php` passed on RHEL for current, missing, stale, failed and
disabled serial states. The staged worker bootstrap collision is now resolved:
component tests use Cacti's process-local install guard to suppress automatic
plugin loading, then load the staged NMS source and real Cacti page realms.
This does not change the live plugin state. The RHEL worker rerun passed the
new inventory totals assertions: missing/failed responses do not count as Up;
a valid simulator response does. Profile refresh, lifecycle and node component
checks also passed with this bootstrap.

A separate `tests/nms/configuration-hooks.php` test uses normal Cacti bootstrap
and the deployed plugin. It verifies the actual registered save/remove hooks
through native hook dispatch, including queued-job/readings invalidation,
assignment cleanup, retained audit history and unchanged running-write
uncertainty. All mutated records are in connection-local temporary tables;
real devices and the global plugin state remain unchanged. This dispatch test
does not replace browser testing of native device save/delete operations.

## Serial preset browser acceptance

The QA browser flow passed for the labelled serial fixture: saving the preset
from 9600 to 19200 left the shared connection at 9600. Review showed both values
and the affected device/address. Applying updated the snapshot to 19200. The
same review/apply flow then restored both preset and connection to 9600; revision
history advanced normally. No hardware was connected or reconfigured.

Browser inspection also found Device Edit showing native no-ping availability
of 100% despite a failed serial read. The overview now labels this field
“Serial response” and displays the evidence-based device state. The local fix
was deployed with an original-file backup and verified in the QA browser:
“STATE Read failed” and “SERIAL RESPONSE Read failed”, with no false percentage.
Network-device availability formatting is unchanged.

## Scheduled numeric poller acceptance

The real collector listener automatically read 17 from the local RTU simulator.
Running the native serial data input as apache returned 17. The existing
cacti-poller.timer then invoked the normal poller at VM time 14:15 on September
21, 2026, finishing successfully at 14:15:02. RRD lastupdate showed
`1789980301: 17`. No manual RRD update or fabricated cache sample was used.
The host and VM clocks differ; these are observed VM timestamps.

Only fixture graph 145, data source 151 and its RRD were removed afterward.
Temporary connection 5 was removed, the original fixture connection restored,
and the simulator process stopped. Reusable fixture templates remain for final
fixture cleanup. The Word and Markdown installation guides now reflect this
scheduled-poller result and the completed preset-refresh browser flow.

## SSH authorization regression

`tests/nms/ssh-tickets.php` passed on RHEL using actual Cacti ACL functions and
connection-local temporary user/session tables. It covers hashed ticket storage,
single consumption, replay/incorrect-token/expired-ticket rejection, disabled
account rejection, RPC versus console separation, browser-session binding and
HTTPS/origin admission. It does not load credentials or open SSH connections.

The existing SSH service was initially enabled but stopped. Its only monitoring
assignment was the QA VM itself (Cacti device 2). A temporary start attempt
exited with “NMS plugin is disabled”; a database read confirmed NMS status 4.
The service was stopped again, restoring its initial running state. No plugin
enablement, credentials or device assignments were changed. This is evidence
of the disabled-plugin guard, not successful live service/browser acceptance.
Real-device manufacturer/manual and collector/gateway details have been requested;
independent verification can continue while those details are unavailable.

## QA deployment comparison and SSH service recovery

A hash comparison found all 22 serial-specific PHP/Python files identical between
the local implementation and QA deployment. Shared files still contain separate
concurrent work and require scoped comparisons before any further deployment.

The QA log traced automatic NMS disablement to a separate diagnostic harness:
at VM time 14:16:21 it redeclared nms_diag_labels, triggering Cacti's shutdown
handler. With that test no longer running, NMS was restored through native
api_plugin_enable. The existing SSH service then collected current CPU, memory
and uptime from Cacti device 2 (the QA VM) at 14:22:26, with status ok and an
empty error. No credentials or SSH assignments were changed. The service was
returned to its original stopped state after the check; NMS remains enabled.

Browser console acceptance attempted the configured HTTPS origin
https://127.0.0.1:8443 and was blocked by ERR_CERT_AUTHORITY_INVALID. The warning
was not bypassed. A trusted certificate or trusted HTTPS endpoint is required
for browser console acceptance. Service collection and ticket-component tests
have passed independently of this browser trust requirement.

## Local status-display verification

The topology tooltip, accessible label, details subtitle and rack status now use
one serial-aware status formatter. The node member table keeps Disabled ahead
of cached serial response evidence. Aggregate node counts retain their own
Up/Down/Recovering/Unknown/Disabled labels, independently of individual member
states.

`tests/nms/serial-inventory.php` passes locally with the real node template and
mixed Responding, Stale, Read failed, Unavailable, Disabled and native Up
fixtures. PHP warnings fail this rendering test. It verifies aggregate counts
and member status cells, including a disabled device with cached Responding
evidence. Cacti escaping, realm and polling-option functions are test doubles;
this is template/component evidence, not live browser acceptance. PHP and
JavaScript syntax checks and `git diff --check` passed.

The two status-display files were then deployed to QA with pre-change hash
checks and backups under `/var/backups/nms-serial-topology-status-20260927`.
Only the prepared status changes were applied to the existing QA files.
Browser acceptance confirmed node 11 shows its serial fixture as Read failed,
with Up 0 / Unknown 1 and the original aggregate labels. Opening that member
from the node topology shows Read failed in both the subtitle and Availability;
serial response time and poll availability remain Not collected. This proves
the failed-state browser path. Current/stale/unavailable and disabled browser
paths remain separate acceptance checks; the mixed-state template test covers
those labels locally.

The user's latest instruction overrides the original Git delivery step:
keep changes local; do not commit or push.

## Remaining acceptance sequence

1. Finish permission-failure browser flows; profile refresh and single-device
   read/validation/preview/apply are verified. Clean owned QA fixtures.
2. Check current local/QA differences and deploy validated NMS changes while
   preserving concurrent work. Verify serial states in device, node and map/
   topology views with current, stale, failed and unavailable evidence.
3. Verify reboot recovery; the native scheduled numeric-poller round trip passed.
   Automatic collector execution and graceful handover to the managed service
   have passed; launcher conflict is resolved.
4. Complete NMS SSH browser console acceptance using a trusted HTTPS endpoint.
   Existing service collection, ticket authorization and isolated transport/parser
   checks have passed. Repeat only affected regression checks.
5. Keep the Markdown and Word guide aligned with subsequent acceptance results.
   Both include the launcher setting and verified browser/SSH/RRD results.
   The guides now distinguish passed SSH service collection from browser console
   acceptance blocked by certificate trust. All six updated Word pages were
   rendered and visually inspected after that correction.
6. Validate the first real device against its manufacturer manual and approved
   collector/gateway, plus approved SNMP writable OIDs/credentials. Review the
   remaining 5.3.2 clauses before claiming compliance.

## Installation guide and external inputs

See [RHEL installation guide](SERIAL_CONFIGURATION_RHEL.md) and the matching
[SERIAL_CONFIGURATION_RHEL.docx](SERIAL_CONFIGURATION_RHEL.docx).

Still needed for real-equipment acceptance: manufacturer/model and register
manual; actual serial collector or gateway; an authorized SNMP device/OID scope;
and the remaining section 5.3.2 requirements. These do not prevent independent
simulator, UI, security or integration work. No production mappings or successful
hardware results will be invented.

## Disabled device browser finding

Disabling QA fixture device 16 through Device Edit exposed a save-order issue.
Cacti's native get_allowed_devices/is_device_allowed functions apply the user's
hide_disabled preference. After the native device save disabled the fixture,
subsequent member-assignment checks rejected it. The detail-render path then
threw an uncaught access exception and Cacti automatically disabled NMS.

The detail controller now returns to permitted inventory with an unavailable/
not-visible message, preserving any existing action error, instead of throwing.
The scoped correction was deployed with a hash guard and backup in
`/var/backups/nms-serial-disabled-20260927`. A fresh browser visit to the disabled
fixture verified graceful denial. Native api_plugin_enable restored NMS, and
api_device_enable_devices restored only the identity-checked QA fixture.
Database verification confirmed monitoring enabled and NMS status 1. PHP syntax
and diff whitespace checks passed.

The update ordering is now corrected: a newly disabled device stays enabled
through the core settings and permission-checked NMS metadata saves, then native
api_device_disable_devices disables monitoring after a final authorization check.
The controller verifies the disabled flag and returns to inventory with the normal
success message, respecting hide_disabled without changing that preference.

QA browser retesting completed the full form and displayed Device updated.
Database assertions confirmed disabled=on, retained node 11 membership, saved
short-name metadata and NMS status 1. An initial overlength short name was
rejected by validation. During deployment QA, an incorrectly scoped temporary
patch was caught by the unexpected redirect and replaced with the correctly
bounded update-action block before the successful retest. The local source did
not contain that temporary deployment error. Native API restoration returned
fixture 16 to enabled monitoring and its original automatic short name. PHP
syntax and diff checks passed. Further disabled-state display checks require an
account that does not hide disabled devices; the existing preference is intact.

## Serial readings workspace acceptance

A fresh collector reply from the owned RTU simulator verified Responding in node
11 (Up 1 / Unknown 0) and in the topology details subtitle and Availability.
Unmeasured response time remained Not collected. This is simulator evidence,
not hardware acceptance.

That browser check exposed an omission in Device readings: the serial fixture
showed SNMPv0, a generic Live reading banner and no serial samples. The existing
readings template now reuses the configuration monitoring helpers for the actual
connection, serial response state, per-field values and observation times. Failed,
stale and unavailable values are not presented as current. Serial last-read time
comes from serial collection evidence rather than native no-ping host updates.
The template provides a link to the existing equipment configuration workflow.

PHP syntax checks and diff whitespace checks passed. The scoped template change
was deployed with a live SHA-256 guard and backup in
`/var/backups/nms-serial-readings-20260927`. Browser verification showed Read failed
with Failed / Not available against the fixture port, then Responding with
Current / 17 from a fresh RTU simulator reply. The existing SNMPv2 readings page
for device 2 still rendered its RRD values. These checks do not prove stale or
restricted-account browser paths. All temporary simulator connections were
restored to fixture connection 1 and the simulator processes stopped. No Git
commit or push was performed.

## Unified serial filters and counts

Serial rows now use the existing readings table and paginator instead of a
separate table. All and Problems totals include serial evidence. Serial is
available in both the view and protocol filters; missing equipment produces an
Unavailable row rather than an empty healthy-looking summary.

`tests/nms/serial-readings-view.php` passed on QA with temporary serial tables,
real collection-freshness helpers and the actual view template. It verifies
current, stale, failed, missing-sample and missing-equipment states; suppression
of obsolete values; no native timestamp fallback; row counts and filter
attributes. Battery/RRD sources are isolated as empty fixtures. An attempted
session-zero ACL assertion was removed because that CLI context does not prove
browser authorization; restricted-account browser acceptance remains pending.
No native devices or persisted serial assignments were changed by this test.

The unified template was deployed with a SHA-256 precondition and backup at
`/var/backups/nms-serial-readings-20260927/readings-before-unified.php`.
Browser acceptance showed All 1 / Serial 1 / Problems 1 for the failed fixture,
a visible failed row under Serial and Problems, and no serial row under
Interfaces. PHP syntax and diff checks passed. Changes remain local with no Git
commit or push.

The RHEL Markdown and Word guides now include unified serial readings/filter
behavior, responding/failed browser evidence, the native disable-save fix and
remaining acceptance limits. Matching paragraphs were checked in both formats.
All six Word pages were rendered and visually inspected after this update;
layout remained clean. No Git publication was performed.

## Deployment audit and native fixture deletion

A read-only SHA-256 audit compared 685 local/QA PHP, Python, JavaScript and CSS
files. All serial-specific implementation and template files matched. Twelve
other differences belonged to concurrent workspace/discovery/IP-identity work;
shared-file diffs showed the serial status changes were present on both sides.
Those differences were preserved rather than overwritten.

Removing owned fixture device 16 through native `api_device_remove` exposed an
orphan `plugin_nms_node_devices` membership. The hook already removed serial
assignments/readings but did not remove node membership. The lifecycle hook now
removes membership too, and reconciliation finds deleted native devices with
only node membership remaining. Configuration job history stays intact.

Extended `configuration-lifecycle.php` passed with temporary native/plugin
tables, including orphan recovery for a non-serial member.
`configuration-hooks.php` passed against the deployed Cacti hook registration
and real dispatch with temporary tables, including node cleanup. The scoped
lifecycle file was deployed with a SHA-256 guard and backup under
`/var/backups/nms-serial-node-cleanup-20260927`.

Fixture device 16 and its empty owned node 11 are now removed. Verification
found no remaining serial assignment, equipment assignment, cached reading or
node membership for that device; all five configuration audit results remain.
NMS is enabled. Browser fixture scripts referring to these IDs must not be
reused as though the fixtures still exist. Unassigned fixture profiles and
related native template artifacts require a separate reference/ownership check
before removal. Syntax and diff checks passed; no Git commit or push occurred.

## Owned fixture reference cleanup completed

A reference audit found no remaining serial/equipment assignments. The owned
connection/profile IDs 1 and equipment profile 1 were checked by exact fixture
name and endpoint. Native input 72 and data template 546 matched their generated
ownership hashes for model 1/revision 1/limit/profile 1; graph template 617 was
NMS-owned and had no graphs, device-template assignments or device assignments.
No other data or graph templates referenced these objects.

After those checks, the fixture connection, profiles and native input/data/graph
templates were removed under the serial mutation and graph-provisioning locks.
All five device-16 configuration audit rows were compared before/after and
retained unchanged. A repeat read-only reference audit returned empty fixture
profile, assignment and native-template lists. The cleanup script initially
stopped before mutation because a CLI helper include was missing; adding the
normal functions/auth bootstrap resolved it. No native-device changes resulted from that initial stop. A later log check
showed Cacti automatically disabled NMS after the missing-helper exception; the
plugin was restored through native api_plugin_enable after the remaining test
process was confirmed absent. No Git commit or push was performed.

## Current requirement audit

The implementation covers native Cacti ownership, versioned serial presets,
collector-owned shared connections, bounded Modbus RTU reads/writes, numeric
graphs, reviewed configuration changes and manual node membership. Current
validation suites for serial profiles, equipment maps and rendered node states
passed again during this audit. The RHEL transport suite passed all 13 cases,
including direct-port aliases, fragmented frames, timeouts, rejected stale
writes, lost acknowledgements and read-back verification. These are simulator
and component results, not real-equipment acceptance.

The latest lifecycle hook and reconciliation tests include removal of node
membership and retention of audit history. Deployment comparison confirms the
serial changes; concurrent discovery/workspace differences remain intentionally
unmodified. Installation Markdown and six-page Word guides are aligned.

Completion remains unproven for the recorded remaining gates: restricted-account
and stale/unavailable UI acceptance, trusted-HTTPS SSH console, QA reboot
recovery and manufacturer-specific equipment acceptance. Reboot coordination has
been requested because this VM is shared; no reboot is authorized by elapsed
time or absence of a reply. Hardware/manual and approved SNMP scope inputs are
still needed. The missing remainder of section 5.3.2 prevents a full compliance
claim. The latest user instruction continues to prohibit Git publication.

## Stale and unavailable browser acceptance

An isolated, randomly named browser-state fixture used native device 41/node 12,
a read-only equipment map and a nonexistent fixture port. Its collection
principal was explicitly invalidated so no collector I/O was issued. A labelled
synthetic cache row, aged by 20 minutes, drove Stale; removing it drove
Unavailable. This tests UI evidence handling, not a physical equipment reply.

Both states rendered correctly in the node member table with Up 0 / Unknown 1,
and in topology subtitle/Availability. Device readings suppressed the old value,
counted one problem and used Not recorded when no collection evidence existed.
Unmeasured topology latency stayed Not collected. The fixture device, node,
connection and profiles were removed afterward through guarded cleanup; no
service restart, reboot, native user permission change or Git publication was
performed. These results supersede the earlier pending stale/unavailable UI
notes. Restricted-account UI, trusted-HTTPS SSH console, coordinated reboot and
real-equipment acceptance remain separate gates.

Cleanup verification for the browser-state fixture caught leftover serial rows:
NMS was disabled after the earlier cleanup-helper exception, so native deletion
hooks did not execute. Logs also recorded a separate concurrent workspace test
failure. No scan-test process was active when NMS was restored through Cacti's
API. Explicit lifecycle cleanup removed the already-deleted device's orphan
rows. Final assertions confirmed device 41, node 12 and all their serial/member
rows absent, with NMS enabled. The stale/unavailable browser observations are
view-rendering evidence; they do not establish collector execution during that
interval.

## Restricted account browser admission checks

A temporary account was created from Cacti’s disabled guest template through
`user_copy`, with native device policy Deny and no device exceptions or groups.
It initially had only console and NMS view access. Real browser sign-in verified
that serial profiles, equipment configuration, node configuration and connection
setup return permission denial without device-management realm 3. Granting realm
3 only to that fixture account still denied device 2: configuration showed an
empty device selector and an inaccessible-device message; connection setup
returned a device-access denial. No equipment command or configuration job was
submitted. These checks cover browser GET admission; denied POST execution is
still a separate acceptance check.

Inspection found the node configuration page left admission exceptions uncaught.
It now catches permission/node-selection failures and returns HTTP 403 instead
of passing them to Cacti’s plugin failure handler. PHP syntax checks passed
locally and on RHEL. The scoped file was deployed after an exact SHA-256 check;
the previous version is backed up under `/var/backups/nms-node-acl-20260927`.
The browser verified the handled denial and NMS remained enabled afterward.

Native `user_remove` removed the fixture account, realms and sessions; database
checks confirmed zero remaining rows for that account and NMS status 1. The
temporary credential script was removed locally and from QA. Existing user
permissions were not changed. No Git commit or push occurred.

## Browser submission after permission revocation

A new temporary account loaded and filled the normal serial-profile form while
it had management access. Its management realm was then revoked through the
native permission reset mechanism before clicking Save profile. The browser
issued the POST; Cacti returned its hidden `cactiRedirect` permission-refresh
response (HTTP 200), without creating the named fixture profile. After refresh,
the page displayed the expected management-permission denial. NMS remained
enabled. This proves rejection of this stale profile submission, not complete
POST coverage of connection, equipment and node actions. The native refresh
response initially left a blank browser page; no Cacti core files were changed.

The native removal function deleted the temporary account and its sessions.
Final checks found no fixture account, session or profile, with NMS status 1.
Credential scripts were removed from both local storage and QA. No equipment
request, service restart, reboot or Git publication was performed.

## Configuration controller admission regression

`tests/nms/configuration-page-admission.php` executes the real device, node and
connection controllers in isolated PHP subprocesses. Eighteen GET/POST cases
cover denied management access for assignment, graph creation, reads, previews,
writes, connection creation/refresh and assignment, plus invalid node input.
Each must return HTTP 403 with the expected message and no fatal error or
storage access. The native realm evaluator and HTML escaping are test doubles;
this is controller coverage, not a substitute for browser authentication.

All 18 cases passed locally and using RHEL PHP 8.0 in an isolated temporary
directory. No Cacti bootstrap, database connection or equipment I/O is used, so
a regression failure cannot invoke the running Cacti plugin-disable handler.
This complements the native-account browser tests and guards the node page’s
handled-denial fix. No additional deployed application change or Git publication
was needed for this test addition.

## Write rejection results before dispatch

The worker previously labelled all write exceptions Unverified, even when
authorization or a changed connection stopped the operation before dispatch.
It now records those known pre-execution rejections as Failed. Exceptions after
dispatch and recovery of an interrupted running write remain Unverified, with
no automatic replay.

The extended temporary-table worker integration suite passed on RHEL. A revoked
operator and a connection revision change each rejected a proposed write of 24
as Failed; an independent simulator read remained 23. Existing read/write,
freshness, graph-value, collector-scope and interrupted-worker checks also passed.
The scoped runner file was deployed after an exact prior-hash check and backed
up under `/var/backups/nms-worker-admission-20260927`; local and QA hashes match.
Only temporary tables and the isolated RTU simulator were used for these tests.

## SNMPv3 configuration verification

The isolated `configuration-snmp.php` fixture now also creates a randomly named
SNMPv3 SHA/AES authPriv user in its private temporary Net-SNMP configuration.
On RHEL, the actual NMS adapter passed encrypted GET, SET with matching read-back,
an independent confirmation GET, denial for a device outside the write
credential scope, and credential redaction in returned results. Existing SNMPv2c
checks passed in the same run. Fixture credentials and agent files are removed
in the test's cleanup block; no configured Cacti device or production agent was
changed. This is local-agent protocol evidence, not manufacturer-hardware
acceptance. No application deployment or Git publication was required.

The final read-only QA status check after the worker admission deployment
returned NMS status 1 (enabled). The prior goal turn made concrete progress by
fixing and testing pre-dispatch result classification; this turn adds verified
SNMPv3 coverage rather than repeating pending acceptance limitations.

## Explicit read retry verification

The transport simulator now counts requests. Three additional cases prove that
one transient CRC failure recovers on the second read, configured retry budgets
of 0/1/3 allow exactly 1/2/4 attempts, and an explicit Modbus device exception is
returned after one attempt even when retries are configured. No writes occur in
these read cases. Existing lost-acknowledgement tests continue to assert exactly
one write followed by read-back.

All 16 transport tests passed on RHEL, including the pySerial pseudoterminal and
physical-port alias locking cases. Locally, 15 passed and the pseudoterminal
case was skipped because that Python runtime lacks pySerial. These are simulator
and OS-port tests, not real-equipment acceptance. Production adapter code did
not need a change; only the test and this evidence record changed. No Git commit
or push was performed.

## Deployment and requirement scope audit

A read-only SHA-256 comparison verified all 22 dedicated configuration PHP,
collector Python, entry-point and serial/configuration template files against
`/var/www/html/cacti/plugins/nms` on QA, with zero mismatches. Remote reads required
sudo because the collector account owns some files; no deployment mutation was
performed. Shared discovery/workspace files were not overwritten.

Current source inspection confirmed bounded profile settings and RTU addresses,
connection ownership and snapshot refresh, native-device creation through the
existing save API, permission and collector revalidation, expiring single-use
write evidence, cached numeric unknown values, and manual node selection with
independent results. This is source/deployment evidence; functional evidence is
recorded in the phase table and test sections above.

The original requirement asks us to identify missing manufacturer information
and distinguish simulator results. It does not require inventing a device map or
claiming real-hardware acceptance. Manufacturer validation therefore remains an
explicit deployment limitation. Reboot recovery and trusted-HTTPS browser-console
acceptance are additional operational checks, not newly added serial feature
requirements. The final completion decision still needs the full phase audit;
these boundaries must not be used to omit a requested implementation feature.

## Final requirement audit

The previous turn made progress by verifying all 22 dedicated deployment files.
The final pass inspected the current validation, shared-connection, native-device,
equipment, job, node, monitoring, graph, lifecycle and controller sources. It also
inspected UI routes/templates, collector entry points and the tests cited below.
Pure profile/equipment validation and all 18 controller admission cases passed
again locally. QA returned enabled=1, schema_ready=true, all seven extension
tables, and both expected native save/remove hooks. No fixture or equipment
operation was needed for this final read-only QA check.

| Requested requirement | Implementation and verification evidence | Result |
| --- | --- | --- |
| Review reusable device, preset, node and collector components; present UI/database plan | Ownership/UI and seven-table design above; existing device-manager, node, template and native API reuse | Complete |
| Identify missing manuals/hardware; exclude GNSS and full compliance claims | Scope and supported-protocol limits, RHEL guide and missing-input record | Complete; real hardware not claimed |
| Named/described serial profiles with protocol, baud, data bits, parity, stop bits, flow, timeout/retries and model metadata | `validation.php`, profile page/template; pure validation and profile integration tests | Complete for implemented Modbus RTU |
| Presets are defaults; existing connections unaffected; review affected connections before refresh | Connection settings snapshots, revision/fingerprint checks, profile integration and browser refresh evidence | Complete |
| Direct collector port and explicit gateway mode, endpoint/port, profile and per-device address | Connection controller/template, direct pySerial and transparent RTU/TCP adapter; native device creation and transport tests | Complete |
| Shared settings, compatible buses, distinct addresses and port exclusion | Unique endpoint/bus-address constraints, assigned collector checks, transaction and physical identity locks; conflict/alias/lock tests | Complete |
| Native Cacti IDs, sites, collectors, permissions, hooks and extension storage | `device.php`, `device_manager.php`, `jobs.php`, lifecycle hooks and seven NMS tables; native API and hook tests | Complete |
| Collector-side reads and native numeric data inputs/templates/graphs | Worker, monitoring, graph provisioning and `serial_value.php`; RTU simulator, RRD/SVG and scheduled poller evidence | Complete |
| Truthful response, failed/stale/unavailable/disabled states; no fabricated live measurements | Sample signatures/freshness, readings/node/topology views; actual simulator and labelled synthetic state tests; graph U checks | Complete |
| Safe deletion and collector/site changes | Save/remove invalidation, reconcile, collector signatures and node-site checks; lifecycle/hook/worker/node tests | Complete |
| Shared device Configuration UI for read, validate, preview, apply and read-back | Device detail link and management route reuse the same controller/template; real browser single-device simulator workflow | Complete |
| Audit operator/device/time/values/result; Verified/Failed/Unverified; separate communication settings | Immutable jobs and history, equipment maps separate from bus profiles; worker/read-back/crash and pre-dispatch rejection tests | Complete |
| Backup/restore only where implemented | No unsupported backup/restore actions advertised; documented limits | Complete |
| SNMP writable OIDs and scoped write credentials use the same workflow | Numeric OID definitions and collector credential references; isolated v2c and v3 SHA/AES authPriv GET/SET/read-back/scope/redaction tests | Complete |
| Manual mixed-protocol nodes with per-member connection/status; compatible selection, preview and independent results | Existing membership retained; node service/controller/template and inventory states; node integration and browser workflow | Complete |
| No shared node IP and preserve membership/topology | Native member identities retained; membership revalidation, node health/topology regression and browser evidence | Complete |
| Validate timing, retries, locking, permissions, updates, graphs and existing protocols | 16 RHEL transport cases; profile, worker, native device/graph/hook, node, diagnostic, SNMP and SSH regression evidence above | Complete within documented test scope |
| RHEL packages, port access, gateway and troubleshooting; Markdown and Word guides | Matching RHEL guides, persistent tmpfiles rule; latest six-page Word render visually inspected | Complete |
| Deploy validated NMS changes to QA | Dedicated file hashes match, schema is ready, native hooks registered; scoped backups recorded above | Complete |
| Commit/push | Superseded by user's explicit keep-local instruction; no publication performed | Intentionally omitted |

No requested implementation item remains open in this seven-phase scope.
Manufacturer maps and hardware are still required before authorizing real-device
use. The shared-VM reboot and trusted-HTTPS SSH console checks remain optional
operational follow-ups, not assertions of successful acceptance. Existing SSH
transport, permissions and actual service collection were verified without
bypassing a certificate error. The full text of section 5.3.2 must still be
reviewed before any compliance claim. All source and documentation remain local.

## Serial profile option update

The profile editor now offers 30 baud rates from 50 through 4,000,000 plus Custom.
Existing nonstandard saved rates remain selectable. Custom input is normalised
and validated before storing the connection defaults. Parity now includes Mark
and Space with actual pySerial M/S mapping. Data bits and stop bits use dropdowns.

Unavailable choices are visible but disabled: data bits 5/6/7, stop bits 1.5,
XON/XOFF (binary RTU bytes must not be consumed as flow-control characters), and
DTR/DSR (not implemented by this Linux adapter). Neither disabled form choices
nor forged requests enable unsupported settings. Mark/Space and unusual/high
rates still require hardware support; this does not implement another serial
application protocol. RTU/TCP gateways must be configured separately.

PHP validation and all 18 transport tests passed on RHEL. New option-mapping
checks use a serial-library test double, not physical Mark/Space hardware.
Existing pseudoterminal tests also passed. The actual QA browser displayed every
new dropdown and disabled choice. Four changed application files were deployed
with prior-hash checks and backups under
`/var/backups/nms-serial-options-20260921-183725`; deployed hashes match local.
Existing connection snapshots were not refreshed and no equipment write was
issued. No Git commit or push was performed.

Reference: [pySerial serial parameters](https://pyserial.readthedocs.io/en/latest/pyserial_api.html).

### Serial preset creation workflow — 27 September 2026

Device management no longer has a Configuration navigation tab or a Device settings subtab. Reusable serial settings remain under **Presets → Serial profiles**. From **Add device → Add serial device**, choose **New connection from serial preset**, select a preset, collector, actual port/gateway and Modbus address, then create the device in one submission. Existing shared connections retain their copied settings and collector; choosing one hides the new-connection fields. Per-device equipment operations and existing result URLs remain available for compatibility.

Verified on the QA VM: native Cacti device creation from a preset, copied settings, actual endpoint identity, duplicate endpoint/unit rejection, existing shared-connection selection, and 18 controller admission checks. Temporary test devices were removed. The retained serial simulator was preserved. No Git commit or push.
