# Cacti-first NMS implementation and verification

Status: in progress. This is an evidence ledger, not a completion claim.

## Latest checkpoint: inventory ownership and native query types (working tree 1.10.1)

The previous goal turn was **progress**: it changed simulator collector handling and
verified local contracts. This continuation re-read the full objective and current
worktree, then made further implementation changes without narrowing deployment gates.

- `includes/inventory.php` now validates the enabled native process collector and
  restricts its device query by `host.poller_id`, including when a specific host is
  requested. Missing identities and differing CLI collector overrides are rejected.
  Native retries are validated rather than coerced to a private default.
- All inventory writes now use the existing checked-write helper. The collector no
  longer globally deletes inventory based on absent/deleted rows in its host cache:
  a remote/offline cache can be partial. Retention/deletion remains an explicit
  management concern. No actual stored records were deleted during this work.
- SNMP-disabled devices are not probed and report `unconfigured`. Failed/skipped
  reads preserve prior successful values/timestamps and baselines. Raw backend
  diagnostics are not copied into plugin error storage, avoiding credential leakage.
- Serial rule evaluation previously allowed a fresh failed-read status to clear a
  serial-change incident. It now requires an actual successful observation to clear
  such an incident. Explicit failed-read policies still match and refresh alarms.
- Edit-device serial display now distinguishes unavailable states and preserves the
  valid serial string `0` instead of treating it as empty.
- Device data-query options and attachment validation now resolve the native input
  method's `type_id` through `data_input`, rather than assuming input row ID 2 means
  SNMP. New regression fixtures cover an SNMP method with a different ID and a script
  method with ID 2, as well as the native attachment and reindex API boundary.

Validation after these changes: all **20 standalone PHP test files passed** in the
PHP 8.0.30 WASM harness, and all **65 PHP files** passed syntax checks. Output was
inspected, not just exit codes. Both Node section/theme tests and `git diff --check`
passed. Database-writing integration/SQL tests were excluded, not counted as passes.
Python code did not change in this continuation; its prior results are historical.

The collector boundary was checked against the official Cacti 1.2.31
[process configuration](https://raw.githubusercontent.com/Cacti/cacti/release/1.2.31/include/global.php)
and [poller entry point](https://raw.githubusercontent.com/Cacti/cacti/release/1.2.31/poller.php).
This source inspection is not a native multi-collector deployment test. Remote plugin
table availability/synchronization, collector reassignment races, mapped-OID changes,
baseline/audit concurrency, and actionable post-poll diagnostics still need review.

SSH to `cactiadmin@127.0.0.1:2222` was rechecked and still denied. Authorized access
and target confirmation remain outstanding; no VM files/database/core files were
changed. No schema migration was needed for this continuation. Native migrations,
actual device/poller/UI tests, FCAPS integration verification and VM synchronization
remain open. The complete objective has not been achieved.

## Earlier checkpoint: explicit simulator collector (working tree 1.10.1)

The immediately preceding response drafted a prompt; it did not implement or deploy
the goal. This continuation made concrete local code changes. On reinspection,
`INFO`, schema readiness and the lifecycle/freshness fixtures already agreed on
1.10.1; both previously reported failing contracts passed before these edits.
Other pre-existing work, including topology configuration changes, was preserved.

- Simulator configuration now requires an explicit integer `poller_id`. Imported
  devices resolve it against an existing enabled native Cacti collector, rather than
  assuming ID 1. Each imported community/template remains independently mapped.
- The import-based create action validates that collector along with its reviewed
  connection settings. Existing Cacti devices are not silently rewritten when the
  simulator configuration changes.
- A PHP probe checks the native `$config['poller_id']` before sending SNMP. It refuses
  to claim reachability from a different collector and directs the operator to that
  collector's native poll cycle. Native SNMP timeout/retry settings replace the
  simulator's fixed probe values. No file sample is substituted on failure.
- The Linux bundle generator accepts required `--poller-id`; portable/systemd
  configuration tests and install documentation cover the setting. No responder or
  service was installed, started or stopped.
- New collector tests cover two imported devices, non-default collector 7, invalid
  IDs, missing/disabled collectors, wrong/absent process identity, native timeout/
  retries and failed/unknown responses. These are doubles, not real network tests.

Verification at this checkpoint: 18 standalone PHP test files passed in the PHP
8.0.30 WebAssembly harness; output was inspected for success/failure text. The
topology SQL test printed its `--run-isolated` requirement and was **not executed**;
native graph/topology integration tests were excluded. All 63 PHP files present at
lint time passed syntax checks in that harness. Six Python tests, both Node source/
theme tests (10 stylesheets, 940 rules), and `git diff --check` passed. None of these
results proves native RHEL/FPM, MariaDB migrations, actual polling or browser parity.

Current read-only SSH verification still rejects
`cactiadmin@127.0.0.1:2222`. Requested authorized access and target confirmation.
No VM files, database records, core code or core schema were changed this turn.

The simulator collector change does not close the separate production inventory
collector-ownership audit: `nms_collect_inventory_values()` still needs its native
collector routing and write-failure review. Other open gates below, including native
query input-ID assumptions, FCAPS integration evidence, incident retirement, and
verified VM deployment, remain required. This is not a completed release.

## Scope and gates

- [ ] Diagnose live entry-page, recache and notification errors independently.
- [ ] Separate equipment categories from Cacti trees; preserve explicit legacy mappings and rule history.
- [ ] Per-device classification, reusable template defaults, native template class, operational groups.
- [ ] Capability/protocol/scope foundation and honest FCAPS availability.
- [ ] Topology relationships separate from layout, with endpoint identity and provenance.
- [ ] Verify serial input/prefill, native graph parity and dynamic optional SNMPSim.
- [ ] Audit permissions, CSRF, paths, PHP 8.0 and plugin lifecycle migrations.
- [ ] Native database install/upgrade/repeat-upgrade and authenticated integration tests.
- [ ] Back up, reconcile, deploy and verify the active VM; compare code hashes.
- [ ] Document confirmed versions, tests, limitations and rollback.

## Initial inspection (2026-09-03)

- Local plugin reports 1.9.35 and has substantial pre-existing uncommitted work. Preserve it.
- Local pre-change source backup: `/tmp/nms-goal-backup.OlP8gC/nms-before.tar.gz`.
- Ports 2222 and 8080 on localhost are reachable. Batch SSH for both `cactiadmin` and
  the log's `bstc` account was denied. Requested the correct SSH account/key.
- The supplied log reports Apache/PHP-FPM as apache, PHP 8.0.30 and MariaDB 10.5.22.
  These are historical log observations, not current VM verification.
- Current category reads join host templates to graph_tree through plugin mappings,
  not actual tree membership. Setup also guesses category by template name and deletes
  legacy metrics. Neither behavior meets the new preservation requirements.
- Directory-only 403 is not evidence that an authenticated PHP entry page fails.

No VM files, core files, or live database records have been changed by this goal yet.

## Implementation checkpoint (working version 1.10.0)

This continuation is **progress**, not a verified wait: it changed plugin code,
added failure/recovery regression coverage, and completed new local checks. The
overall objective is not complete; the gates above remain open pending their full
native/integration evidence.

Local code now includes:

- Independent equipment categories, explicit legacy tree-ID mapping, per-device
  classification, template suggestions, native template-class display, and separate
  many-to-many operational groups. Existing trees are not changed by migration.
- Independent manual topology relationships with query/index/identity information,
  provenance and recoverable archive. Legacy layout parents are not fabricated links.
- A read-only capability/integration evidence view. This is not yet the complete
  configurable per-category/model/protocol/scope foundation required by the goal.
- Checked schema writes under a database-scoped upgrade lock, individual missing-column
  repair, readiness withdrawal/publication, and explicit refusal of ambiguous legacy
  category namespaces or unsupported legacy rule metrics. `status_not_up` has a known
  semantics-preserving translation; other old metrics are retained with actionable IDs.
- Poller hooks skip an incomplete NMS schema and isolate plugin exceptions while
  preserving Cacti's original payload. They do not run DDL or claim successful capture
  when storage fails. Samples retain native collection timestamps and reject replay
  that would overwrite a newer value.
- Incident recovery requires an explicitly evaluated fresh clear condition. Stale,
  down, unknown, missing-source or absent-rule cases do not falsely resolve retained
  incidents. Hidden inventory severity defaults have been removed; legacy implicit
  incidents are retained without a fabricated refresh/recovery.
- Timestamp-aware device labels and counts, topology status, serial displays and
  serial suggestions. Manual metadata remains separate from observed serial values.
- Native visibility filters in fault configuration counts/catalogues. Unused sample
  aggregation was removed from the configuration catalogue rather than exposing raw
  values that the form did not use.
- Explicit simulator configuration selection, required managed-service paths,
  portable manual mode and separate Linux lifecycle tooling. Source code still needs
  the collector/default audit below before the dynamic-device gate can be closed.
- Updated README, generic installation, simulator guide and `UPGRADE_1.10.0.md`,
  including table ownership, partial-DDL behavior, compatibility limits and rollback.

## Verification completed at this checkpoint

- 14 standalone PHP test files passed in the **PHP 8.0.30 WebAssembly harness**:
  categories, core form options, database lifecycle, device metadata, fault recovery,
  foundation, freshness, graph items, inventory counts, offline assets, navigation,
  portable configuration, SNMPSim configuration and template workspace.
- Syntax checks passed for all 52 plugin/test PHP files in that harness.
- 6 Python simulator configuration/activation-marker tests passed with bundled Python.
- Node device-section and theme tests passed; topology JS syntax and `git diff --check`
  passed. These are source/contract tests, not a rendered browser acceptance run.
- The PHP WASM CLI may exit zero after a fatal error. Test output was inspected for
  its actual pass messages; the lint runner explicitly required a successful lint
  message for each file rather than trusting exit status alone.
- The native database-writing graph integration test was deliberately not run without
  a backed-up test installation. No native MariaDB migration, RHEL PHP/FPM, SELinux,
  actual simulator/poller or deployed-code test has passed for this new release yet.

## VM evidence and access

An earlier authenticated browser inspection of the forwarded lab reported Cacti
1.2.31, NMS 1.9.35, PHP 8.0.30, MariaDB 10.5.29, NET-SNMP 5.9.1, RRDtool 1.7.2 and
an aarch64 RHEL kernel. Only NMS appeared installed in Plugin Management. Category
switching worked on that older deployed version. These observations do not verify
the new code or prove this is the same server as the supplied bstc/MariaDB 10.5.22 log.
Its displayed technical-support timestamp also needs VM clock/cache verification.

The latest read-only SSH check still rejects public-key authentication for
`cactiadmin@127.0.0.1:2222`. Earlier `bstc` authentication was also denied. Requested
the authorized account/key and confirmation of whether this forwarded VM is the
deployment target or a separate bstc server must be updated. No password guessing
or alternative privileged execution was attempted. No VM backup/deploy has occurred.

The local pre-change archive still exists at
`/tmp/nms-goal-backup.OlP8gC/nms-before.tar.gz`; it is a temporary local archive, not
a VM/database backup or proof of production rollback readiness.

## Next required work (do not shrink the goal)

1. Complete actual configurable category/model/capability/protocol/scope policy,
   not merely an informational FCAPS view. Evaluate available integrations on the
   confirmed target and keep future collectors explicitly unavailable.
2. Add a reviewed lifecycle for retained out-of-scope and legacy implicit-policy
   incidents (configuration retirement is not a successful device recovery). Review
   checked incident/audit writes and concurrent reconciliation behavior.
3. Finish authorization/CSRF review against the installed native source, including
   category-wide writes with partially visible devices, all template/import actions,
   and whether read-only requests should ever trigger global reconciliation.
4. Audit remaining simulator collector ID/defaults and on-demand probe locality;
   remove fixed collector assumptions through native configuration or explicit
   validated settings. Review literal systemd argument escaping and runtime path
   trust, then validate real stopped/running behavior on the confirmed VM.
5. Review concurrent group membership replacement and layout-root updates, native
   interface query namespaces, stale/missing mappings, and serial OID mapping changes.
   Preserve invalid form input on all validation errors, including Unclassified and
   explicit template-suggestion selections on Add device.
6. Audit remaining function comments, stale timestamp labels, hardcoded native form
   choices, and documentation claims. Verify full native graph-template option/backend
   parity rather than treating routing/source tests as proof.
7. Perform native MariaDB fresh install, tree-based upgrade, supported older-metric
   migration and repeat-upgrade/failure tests on backed-up data. Resolve ambiguous
   mappings or unsupported legacy semantics explicitly before production migration.
8. Resolve VM access/target, confirm active paths/versions/service identities and log
   locations, reconcile VM-only changes, back up code/database, define exact scoped
   rollback, deploy verified code and compare hashes. Complete browser, native PHP,
   poller/interface/fault-recovery, simulator and SELinux acceptance checks.

No completion or blocked-goal status is claimed while meaningful local work remains.
