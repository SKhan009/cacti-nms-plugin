# Version 0.8.0 — simplified device setup

Deployed to the local Cacti VM on 2026-09-07.

- Three native steps: Device Categories → Port Profiles → Assign Devices.
- Site, Node and reference-device creation removed from topology setup. Sites remain native Cacti device metadata.
- Searchable native device list with category/Site/row filters and atomic bulk category + physical-profile assignment.
- Topology opens directly, with category filtering, existing saved positions and connected physical ports. Occupied ports stay occupied when their peer is filtered out.
- Removed Node prerequisites from inventory, physical ports, discovery and simulator upload.
- Fixed cable geometry after Cacti reveals AJAX-loaded content.
- Kept native tabs, + controls and action buttons; no replacement UI framework.

## Validation performed

All PHP files parsed successfully on the VM. Disposable database migration tests passed preservation of native devices, credentials, Sites, graphs, data sources, physical ports/mappings, cables and positions, plus migration idempotency. Bulk assignment passed atomic rollback, category/profile validation, no-Site device assignment, connected-port preservation, category filtering and read-only permission rejection.

Native guide/list rendering and escaping checks passed. Browser verification confirmed immediate device listing, native controls, automatic category filtering, applying the existing profile to both lab switches in one submission, preserved canvas positions and cable, and simulator upload without Site/Node fields.

Live discovery job 42 completed: two LLDP collections succeeded, zero failed. Persisted reciprocal evidence, endpoint-change invalidation and hidden-peer authorization checks passed. Both existing simulator imports remain provisioned; 48 native data sources and 48 graphs remain registered. NMS 1.10.35 and Topology 0.8.0 remain enabled.

## Backup and scope

Pre-upgrade database and plugin backups are on the VM in `/var/backups/topology-simple-flow-20260907/`. No Cacti core files or Site/device configuration fields were modified. New device category and port profile definitions remain operator-managed. This release validation uses the existing local simulator pair; it does not claim additional real-device validation.
