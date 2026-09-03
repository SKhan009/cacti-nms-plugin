# Topology configuration and compact template lists — 2026-09-03

## Deployment

Deployed NMS 1.10.1 to the local RHEL VM after approval for the full local plugin upgrade, including the previously undeployed 1.10.0 schema migration. Plugin status is enabled. Existing SNMP simulator runtime and records were not overwritten.

Recovery backup on the VM: `/var/backups/nms-racks-20260903.shZDj2/` contains the previous plugin directory and full `cacti.sql` dump. Do not restore the database casually: restoration would also replace later user changes.

Counts remained unchanged across the upgrade: 6 hosts, 379 graph templates, 11 Cacti trees, 11 fault rules.

## Functionality

- Topology submenu contains network topology, rack topology and topology configuration.
- Physical capacity uses existing equipment categories and optional exact device-type profiles. Configured port counts are not discovered interfaces or live port status.
- Nodes and vehicles support configurable rack counts and individual rack capacities. Device placement validates site, access, unit bounds and overlaps; occupied racks cannot be removed by shrinking the rack count.
- New planning data resides in four plugin tables: port profiles, rack nodes, racks and rack devices. Cacti device identities remain in core storage.
- Native template list controls retain Cacti pagination, filtering and selection handlers while using compact monochrome styling and aligned checkbox columns.

## Verification

- PHP syntax checks passed for the deployed PHP source.
- JavaScript syntax and monochrome theme checks passed.
- Topology validation, database lifecycle, navigation, template workspace, freshness and offline asset tests passed.
- Isolated MariaDB tests passed 4/5/8-rack resizing, overlap/bounds checks, capacity changes, site validation, placement moves and transaction recovery. The isolated database was dropped afterward.
- Live database integration tests passed and rolled back their fixtures.
- Browser QA: template row-count selection, next-page navigation and select-all/unselect-all worked. Compact lists showed no horizontal overflow, and selected rows used neutral gray.
- Browser QA: created a temporary node with four 12U racks; submission displayed a success message and the rack view rendered correctly with no horizontal overflow. Removed that exact temporary node and its four empty racks afterward; no Cacti device was deleted.
- Network topology and device inventory loaded after the upgrade with no captured browser errors. Historical PHP log entries predate this deployment; no new entries appeared during these checks.

The BLR rack-view requirement informed the configurable rack display. This does not claim complete BLR compliance, automatic physical-port discovery, or validation of every pre-existing plugin workflow.
