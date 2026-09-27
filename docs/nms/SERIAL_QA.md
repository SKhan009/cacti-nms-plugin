# Serial device QA — 2026-09-27

Scope: NMS serial Add/Edit Device, preset-to-connection synchronization, native Cacti associations and serial graph pipeline. Tested in authenticated Chrome against the RHEL QA Cacti instance at `http://127.0.0.1:8080/cacti`. The server clock reports 2026-09-21; use that date when correlating server logs/backups. This is not certification of every NMS page or physical device.

## Findings and fixes

- The deployed instance still contained the older serial stepper. Synchronized the current single-page serial form and its PHP/JS/CSS dependencies. Nine targeted serial form/graph files now have matching local/deployed SHA-256 hashes.
- The shared-connection settings link was intercepted by the serial edit redirect. Deployed the `shared_settings` redirect exception and verified the destination in Chrome.
- Creating a disabled serial device failed with “Your Cacti account cannot access this device.” The transaction rolled back. Native Cacti `is_device_allowed()` applies the user's `hide_disabled` display preference before its ACL check. The NMS fallback now evaluates an existing disabled, non-deleted device with native Cacti policies and joins, without that display filter. Anonymous/invalid users and denied ACL results remain rejected. Successfully created and edited a disabled fixture after the fix.

Deployment backups: `/var/lib/cacti/nms-serial-form-qa-20260921-222640` and `/var/lib/cacti/nms-serial-graph-repair/functions.php.before-qa` on the QA server. Other pre-existing workspace changes were preserved.

## Browser checks

| Control or workflow | Result |
| --- | --- |
| Device Template | Installed options load; selecting ACME updates review; None restores the choice. |
| Site | Installed sites load; Topology Lab Location 01 survives save/reload. |
| Node | Unassigned state works; this installation has no configured nodes. |
| Data Collector | Main Poller loads; selecting a shared connection locks its collector. Only one installed collector is available. |
| Status / threads | Disabled and two threads save to the native host record. |
| Segment / device type | Empty segment displays no preset types; Network exposes Switch. |
| New / reused connection | Dependent fields switch correctly; reused bus displays its saved settings rather than unsaved custom values. |
| Transport | Direct/transparent RTU-over-TCP switches gateway port and explanatory fields. |
| Serial profile | Demo preset populates 9600, 8, even, 1, none, 1000 ms, one retry. |
| Custom serial settings | Enable/edit parity, stop bits and flow control; effective values and review update. Saved 19200/8/odd/2/RTS-CTS snapshot reloads correctly. |
| Equipment profile | Demo profile renders its real register offset, function, type, range and reference. |
| Graph template selector | Search/filter/select/add/remove updates the pending association and review. |
| Data query selector | Search/filter/select/add/remove and reindex selection work; removed before saving so an unrelated query was not executed. |
| Create reading graphs | Checkbox participates in review; existing demo graph is shown as saved/being graphed. Native provisioning is verified separately below. |
| Duplicate shared-bus address | Rejected with a useful error, input retained, no orphan host/connection. |
| Create/edit persistence | Disabled fixture saved; unit changed 1→2 and reading interval 300→600; reload and database agree. |
| Shared settings navigation | Opens the connection page and displays the saved fixture bus. |

Database checks confirmed core hostname, site, disabled state, two threads, serial unit and equipment interval. The fixture connection stored 19200/8/odd/2/RTS-CTS while preset 3 remained unchanged at 9600/8/even/1/none. The fixture used a nonexistent direct port and remained disabled throughout; no hardware read was requested for it.

## Automated and native checks

- `disabled-device-access.php`: listed device access; disabled ACL allow/deny; anonymous and invalid-user denial. This regression uses authorization/database test doubles and asserts the fallback retains deletion, disabled-state and native-policy restrictions.
- `serial-profiles.php`: profile validation, bounded timing, independent snapshots, unicast addresses, collector-scoped endpoints, canonical gateway ownership, baud/parity/flow options and invalid override rejection.
- `serial-graph-schedule.php`: fast reader/slow poller, native fast polling, interval multiples and heartbeat checks.
- `configuration-page-admission.php`: 18 actual controller GET/POST admission cases.
- `serial-device-create.php` with `serial-profiles-integration.php`, against the deployed plugin: preset CRUD, unchanged connection snapshots, explicit refresh/replay protection, shared-bus uniqueness, stale assignments, collector mismatch, equipment assignment, separate equipment models per bus, native device create/update, rollback and no network probes. Temporary serial tables isolate component fixtures; native test hosts are removed in cleanup.
- The first integration invocation used a staged plugin path and hit duplicate function loading when a native hook loaded the deployed plugin. Reran against one deployed plugin copy; all assertions passed.
- Native graph verification: device 64 returns numeric sample **23**; graph **208** and source **216** remain unique; actual RRD step is **300 seconds**, heartbeat **600 seconds**, reader interval **60 seconds**. Wrong collector and wrong model revision return unknown. Native graph rendering succeeds. Final rendering was run as the Apache user to match ownership of the test output.
- PHP lint passed for deployed serial PHP updates and the access fix.

## Cleanup and limits

Removed the browser-created fixture host 76, its serial/equipment assignments and its unshared connection 12 using the native device deletion API plus a guarded connection cleanup. Verified none remain. Integration test hosts were also removed. Browser is left on the existing serial demo.

Live alternate-collector selection and populated site/node dependencies could not be exercised with the installed inventory. Actual RS-232/RS-422/RS-485 hardware, gateway serial settings, every third-party Cacti template/query and other NMS dropdowns are outside this QA result. Serial communication values must match the physical device configuration; a preset is a reusable starting point, not automatic hardware configuration.
