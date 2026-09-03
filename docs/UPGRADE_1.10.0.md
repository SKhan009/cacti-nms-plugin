# NMS 1.10.0 upgrade and verification gates

Status: development work, not a verified deployment release. `INFO` identifies the
working version, not proof that migration or VM acceptance tests have passed.
See [the evidence ledger](GOAL_PROGRESS.md) before deploying.

## Ownership and classification

The plugin does not alter Cacti core source or core table schemas. Core devices,
sites, templates, template classes, trees, data queries, data sources, poller items,
RRDs and graphs remain authoritative. Graph/tree membership is not a classification
or a physical connection.

| Plugin storage | Information missing from core that NMS retains |
| --- | --- |
| `plugin_nms_categories` | Editable equipment-category catalogue, separate from native template class |
| `plugin_nms_category_migration` | Explicit old tree-ID to equipment-category-ID mapping and original name |
| `plugin_nms_device_classification` | One reviewed category/type/role assignment per native host ID |
| `plugin_nms_category_templates` | A category suggestion for future reviewed device creation, not membership |
| `plugin_nms_groups`, `plugin_nms_group_members` | Independent many-to-many operational labels and memberships |
| `plugin_nms_relationships` | Connection endpoints, native query/index identity, relationship type, provenance and audit; manual edges have no discovered last-seen time |
| `plugin_nms_topology` | Existing presentation coordinates and legacy layout anchors; not evidence of connectivity |
| `plugin_nms_device_metadata` | Manual serial number and editor/time, distinct from observed inventory |
| `plugin_nms_device_inventory` | Latest string observation, baseline, attempt/success times and collection error; strings cannot be stored in RRDs |
| `plugin_nms_device_parameters` | Latest raw Cacti hook sample for rule evaluation, not another numeric time series |
| `plugin_nms_fault_rules`, `plugin_nms_incidents`, `plugin_nms_events` | NMS policy, native-object applicability filters and incident/audit lifecycle; optional external integrations must be evaluated separately |
| `plugin_nms_snmprec_imports`, `plugin_nms_snmprec_oids` | Import provenance, OID definitions and generated native-object references; sample values are not live readings |
| `plugin_nms_managed_objects`, `plugin_nms_meta` | Explicit plugin-created object ownership and lifecycle markers |

Existing incident and event history is retained. There is no automatic category or
group deletion in the new UI. Manual connections can be archived and restored by
recording the same endpoints/type again. Uninstall remains destructive to plugin
tables; it is not an upgrade or rollback method. Retention controls for large event
histories still need an explicit operator policy.

## Migration behavior

1. Back up before any lifecycle upgrade. Pause relevant web writes and poller hooks
   during deployment; advisory locks serialize upgrades, not every application request.
2. Setup obtains a database-scoped schema lock and withdraws the readiness marker.
   Each SQL write is checked. DDL commits implicitly in MariaDB: do not claim that
   the whole install is one rollback-safe transaction.
3. Older missing columns are checked individually, allowing a retry after partial DDL.
4. The known `status_not_up` rule is translated to `core:status != up`, retaining its
   ID, severity, enabled state and history. Other unsupported legacy metrics stop
   the upgrade with the affected IDs; none are deleted or silently disabled.
5. A nonempty older `plugin_nms_device_categories` table stops migration because its
   ID namespace may not be a tree namespace. Establish the source version and an
   explicit mapping before proceeding. Do not drop that table to bypass the check.
6. For the known tree-based version, references in template suggestions, rules and
   imports receive an explicit mapping. Missing trees receive a clearly labeled
   preserved category; similarly named categories are not silently merged.
7. Category reference updates and existing-host assignment snapshots run in a data
   transaction under a category lock. Joined updates avoid collisions between old
   and newly allocated numeric IDs. Core trees are never renamed or deleted.
8. The catalogue is seeded with editable initial values. Existing host assignments
   are independent of later template-default changes. Explicit Unclassified stays
   unclassified rather than inheriting a template suggestion.
9. Readiness is published only after all schema operations succeed. Ordinary views
   return an actionable upgrade-required response when it is absent. Poller hooks
   log the problem, skip NMS work, and leave Cacti's native payload untouched.

Legacy `category_id` URLs refer to the old namespace. The new selector uses
`equipment_category_id`; old bookmarks must be reviewed, not silently interpreted
as new categories with coincidentally equal IDs.

## Current monitoring boundaries

The latest local continuation scopes text inventory to the enabled native process
collector and checks every inventory write. A partial collector host cache no longer
causes deletion of other retained inventory. This uses the existing inventory table;
no core or plugin schema change was needed for these fixes. SNMP-disabled inventory
uses the existing string status column with `unconfigured` rather than inventing a
live read failure. Failed, unknown and unconfigured observations cannot clear an
existing serial-change incident; an explicitly matching failure rule still runs.
Core input-method row IDs are no longer used as protocol identity in query selectors
or attachment validation. Verify these changes on the native database and pollers.

Category/type/role, operational groups, native input-method evidence and FCAPS
availability now have separate UI sections. The capability view does not establish
that a model supports a protocol simply because it belongs to a category.

Saved rules have an Applicability form backed by `scope_*` columns on the existing
plugin rule table. Filters combine a native device template, exact declared device
type, native input method, optional enabled SNMP version, host, query/index, and exact
local data source. A template is a collection profile, not verified physical model
identity. Native interface-cache evidence distinguishes interface scope; an operator
must select the actual source for a component/service declaration. No category
implies protocol support. Empty filters explicitly preserve legacy category-wide
breadth; missing schema fields are errors rather than an automatic broader scope.

New applicability columns are added individually under the schema lock. Native
poller-item SNMP versions are used for numeric-source restrictions; scripts are not
guessed to use SNMP. Core availability cannot be labeled SNMP-only simply because
the host has SNMP credentials. Category-wide policy changes require native management
permission and device visibility. Source/target filter behavior still needs native
database/browser/poller acceptance testing before this configuration gate is closed.

Web views do not reconcile incidents or run the retired automatic imported-template
renaming routine. Policy edits are evaluated by the next native post-poll cycle.
Existing native names and RRD data-source identities are not rewritten on a visit.

Only actual configured Cacti polling supplies numeric measurements. Collection
timestamps are retained, future timestamps are rejected, and older replayed samples
cannot replace newer values. Raw counters are not traffic rates; native RRD graphs
perform rate calculations. Parameter faults require valid current evidence; a gap
in collection is not recovery. Inventory rules likewise require fresh check/core
timestamps. Hidden inventory changed/failed defaults have been removed: add explicit
reviewed category rules. Existing implicit-policy incidents are retained for review,
not refreshed or falsely resolved. A complete reviewed retirement/reconfiguration
workflow for retained out-of-scope incidents remains a release gate.

Thold, Syslog and RouterConfigs must be verified on the target. An installed plugin
is not automatically an NMS integration. No LLDP/CDP discovery, incoming trap receiver,
NetFlow/IPFIX accounting, device configuration backup, or managed-device security
collector is claimed by the current foundation.

Physical interface/query IDs are not transport port numbers. Manual topology edges
store both query and index, with the native interface identity used to detect index
reuse. Legacy parent-layout information is retained but is not drawn as a verified
physical edge. Cross-site relationships remain explicit records.

## Environment and optional simulator

Use [generic installation](GENERIC_INSTALL.md) for core/plugin separation and
[the simulator guide](../snmpsim/README.md) for explicit environment configuration.
Current working-tree simulator configuration additionally requires `poller_id`, the
existing enabled native Cacti collector for its endpoint. Add it explicitly to old
administrator-managed JSON before import/add/probe workflows; it is not inferred
from a default collector or existing device. Existing host settings are preserved.
The web probe refuses a different native collector identity and uses Cacti's own
timeout/retry configuration. Real remote-collector and stopped-responder verification
remain deployment gates, not outcomes established by configuration validation.
There is no implicit `/etc` configuration search, administrator account, bind address
or responder port. Systemd integration is optional and Linux-specific; normal NMS
monitoring does not require Python or SNMPSim.

| Environment | Evidence / support boundary |
| --- | --- |
| Supplied RHEL log | Apache 2.4.57, PHP-FPM account `apache`, PHP 8.0.30, MariaDB 10.5.22; historical evidence, not the exact deployment target |
| Forwarded lab, earlier authenticated inspection | Cacti 1.2.31, PHP 8.0.30, MariaDB 10.5.29, aarch64 RHEL kernel, NMS 1.9.35; new code not deployed |
| Local PHP 8.0.30 WebAssembly harness | Standalone contracts and syntax checks; not native PHP extensions, native SQL, SELinux, or a real poll cycle |
| Native RHEL deployment | Pending access, backup, version confirmation and integration tests |
| Other Linux, macOS, Windows Cacti hosts | Portable paths/manual simulator design only; no blanket integration support claim |

## Diagnose the supplied log as separate issues

- Directory 403: inspect active Apache mapping, index/authorization configuration,
  file traversal and SELinux records. Test authenticated `nms.php`; do not enable
  directory listing. The old listing lacks an index file but this alone is not a
  proven root cause.
- Category 500: obtain its exact current PHP fatal/database error. Successful
  switching in the older forwarded lab does not prove the separate logged server
  or this new migration is fixed.
- DQ 51 recache: identify its native query/script, input parameters and poller identity;
  inspect actual output and return status. `U` is unknown, not zero. Do not patch core
  code or discard a query solely because the log contains recache warnings.
- Notification receivers: the SNMP agent warning concerns a configured outgoing
  notification path, not proof of incoming trap reception. Configure only an actual
  authorized destination, or document that the integration is intentionally unused.

## Backup, reconcile, deploy and roll back

Confirm the target host and active Cacti base path first. Preserve local and VM-only
changes and inventory environment/runtime files before deciding what to copy.
Back up plugin code, all plugin tables and any core records an integration test may
change, using an approved database backup method with credentials kept out of logs.
Keep backups and manifests outside the web root. Record exact paths and timestamps.

Deploy only reviewed plugin code. Exclude `.git`, development artifacts, bytecode,
secrets and runtime simulator records. Do not broadly synchronize or delete the
target directory. Reapply administrator-owned source permissions and grant write
access only to required runtime storage. Keep SELinux enforcing; derive labels and
policy from the actual installed service, not a guessed account or blanket bypass.

Use Cacti's supported plugin upgrade lifecycle. Refresh worker opcode caches only
through the actual service administrator. Verify deployed code hashes with explicit
configuration/runtime exclusions and test authenticated views and actual poll cycles.

Rollback requires the matching pre-upgrade plugin code AND plugin database backup.
Restoring old code alone after category ID translation is unsafe. Quiesce NMS writes,
restore the scoped backup through an approved method, restore lifecycle registration,
then verify core polling and native graphs. Account for changes made since backup:
never restore an entire production Cacti database over unrelated newer work without
explicit approval. DDL failure may require repair/retry or this scoped restore.

## Required acceptance evidence

Do not release until native PHP lint, fresh database installation, legacy upgrade,
repeat upgrade, failure handling, preserved rule targeting, permissions/CSRF,
serial persistence, native template round trips, new/existing devices, interface
readings, real fault/recovery cycles, simulator stopped/running behavior, SELinux,
and local/VM hash comparison have actual recorded results. Mock SQL and a PHP WASM
harness do not satisfy the native MariaDB/RHEL gates.
