# Unified Device readings delivery

The requested workspace is implemented in NMS 1.11.8 and deployed to the local Cacti QA installation. Use **Device Management → Device readings**. Existing Cacti devices, credentials, collectors and native network definitions remain authoritative.

## Network UI update requested after delivery

Network discovery now shows one **Manage networks in Cacti** button opening Cacti Automation → Networks. The inline Add/Edit network form, schedule controls and supplemental-probe form were removed at the user's request. Network status and saved scan history remain visible. Row-level Edit (Cacti backend) and Start native scan buttons remain aligned side by side; the two extra completion/automatic-add text lines were removed. The collector/services described below remain implemented; their previous inline configuration/probe UI is superseded by this update.

## Diagnosis location update

All diagnosis findings, collector tests, application-service checks and their results now live on `diagnostics.php`. Its default landing page is Device diagnosis; existing Run a diagnostic/history and Diagnostic profiles sections remain available. Device readings has six sections, without Diagnosis, and its History excludes diagnostic/service tables. Old diagnosis links redirect to the dedicated page with the device/result preserved. This supersedes the original seven-section layout below.

## Single readings navigation update

The upper workspace tab row has been removed. One lower bar now contains All, Interfaces, Discovery, Traffic, Problems, Network discovery, IP addresses & identity, Duplicate review and History (plus Serial for serial devices). Discovery contains the existing discovery evidence and neighbour verification/onboarding; there is no separate Neighbours tab. The redundant View dropdown and second Refresh control were removed. Workflow histories remain with their actions; History shows the change log rather than duplicating those panels. Legacy section URLs remain functional.

## Delivered behavior

- Seven persistent sections and a device selector; Network discovery works without selecting a device. Action links use button styling and server-paginated history has one paginator.
- Native network settings and scan controls, plus bounded supplemental IPv4/IPv6 jobs (4,096-task limit), durable batch progress, results and cancellation. Cancelled jobs are terminal; an interrupted pending batch continues from its checkpoint rather than offering a cancelled-job Resume action.
- Management and reported addresses with identity evidence, source/freshness and shared/uncertain distinctions; explicit same/separate/unresolved reviews.
- Duplicate admission for reviewed onboarding, LLDP/CDP candidate verification and native template onboarding. Native automatic-add limitations are shown in the UI.
- Reviewed collector-verified management-IP changes preserving the native device ID and graph/data associations, with audit history. Expired or changed evidence rejects confirmation.
- Reviewed supported consolidation with native asset operations, RRD preservation checks, retained device records and explicit interrupted-state recovery review.
- Combined diagnosis from assigned-profile ping plus saved Cacti, SNMP, interface, neighbour and collector evidence; possible shared-collector failure remains a hypothesis. Saved diagnostic loss/latency/provenance feeds topology separately from lifetime availability.
- Bounded HTTP/HTTPS/DNS/TCP checks with expected criteria, TLS validation, collector execution and structured results. Permission/configuration revalidation, history and additive schema changes cover these workflows.

## Main changed files

Paths below are relative to the repository root. The existing working tree contains unrelated changes that were preserved; no commit or PR was requested.

| Area | Files |
| --- | --- |
| Entry and navigation | `plugins/nms/devices.php`, `templates/devices/tabs.php`, `templates/devices/readings.php`, `includes/navigation.php` |
| Workspace UI | `plugins/nms/templates/workspace/*.php`, `plugins/nms/css/nms-workspace.css` |
| Discovery and identity | `plugins/nms/includes/discovery_identity.php`, `discovery_management_addresses.php`, discovery SNMP/neighbour/network adapters |
| Jobs, review and authorization | `plugins/nms/includes/workspace/*.php`, `plugins/nms/workspace_*.php`, diagnostic listener/queue integration |
| Schema and upgrade | `plugins/nms/includes/database.php`, `plugins/nms/setup.php`, `plugins/nms/INFO` |
| Topology diagnostics | `plugins/nms/includes/topology/diagnostics.php`, topology canvas/map adapters and JS |
| Verification | `tests/nms/workspace-*.php`, identity/topology suites and bounded synthetic fixtures |

## Evidence

The [requirement audit](READINGS_ACCEPTANCE.md) maps every original feature to implementation and evidence. The [implementation log](READINGS_WORKSPACE_IMPLEMENTATION.md) records deployment, failures, corrections, request IDs, cleanup and test classes.

- Recorded focused regression: 255 assertions across 16 pure/simulated suites, all successful.
- Native queue/permission integration: 41 assertions across five suites in the recorded regression. Current deployed authorization suite rerun: all eight assertions passed, including cached-session revocation and pre/post-transport checks. These tests use connection-local ACL tables; service transport is simulated.
- Native scan lifecycle/revalidation: 23 assertions; native consolidation/reference acceptance: 12 checks including real RRD history preservation. Details and fixture scope are in the log.
- Current upgrade invoked twice with native inventory/association/network/plugin-state preservation checks; all passed. This proves the tested current upgrade, not every historical or future version.
- Browser evidence covers every requested section and principal action, native/supplemental scans, duplicate decisions and stale evidence, consolidation transfer/recovery/cancellation, management verification/apply/cancellation, neighbour verification/onboarding/cancellation, diagnosis/services/history, pagination, no-device guidance and responsive tabs.
- SHA-256 comparison: all 54 workspace modules/templates/entrypoints/CSS match deployment. Final scoped fixture cleanup checks found no temporary devices/networks or active test mutations; listener remains running.

## Explicit limits and unperformed variants

- Cacti native automatic addition exposes no safe veto hook in the inspected path. It retains Cacti's duplicate checks; NMS does not claim to prevent identity duplicates there. Use reviewed onboarding for the NMS admission check.
- Consolidation supports the reviewed ordinary local assets described in its preflight. Remote/shared/query-dependent or arbitrary embedded/external references are not universally migrated. Both device records remain; partial native execution requires recovery review.
- Diagnosis uses saved observations with their timestamps; it does not recollect all SNMP/interface/neighbour data or prove a common physical-path root cause.
- No authorized hardware target or remote collector was supplied. Hardware/vendor-MIB, remote routing/collector, shared virtual IP, restricted-view and other live cases are **not run**, with exact required inputs and procedures documented in the acceptance file as requested.
- Live browser-account permission revocation is **not run**; native revocation tests pass. This extra scenario was added during QA, not specified as a separate original delivery gate. No admin access was changed. Trusted-CA HTTPS success was engine-tested; browser TLS rejection was tested. Do not describe that as remote/browser trusted-CA acceptance.
- The full-address 300-target scan was browser-submitted and SQL-verified complete; its final browser reload expired. Separate multi-batch and result-pagination browser cases passed. The exact expired-session variant is not claimed as a browser pass.

Implementation delivery is complete within these explicitly supported boundaries and the original conditional hardware-validation provision. Unsupported paths and unperformed variants above remain labelled as such; this delivery does not certify them.
