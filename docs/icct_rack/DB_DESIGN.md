# Database Design

## Existing Cacti tables reused

### `host`
Canonical device record. The rack plugin references `host.id` and reads Cacti's existing description, hostname/IP, disabled flag and monitoring status. It does not duplicate SNMP credentials or availability state.

### `user_auth` and Cacti Realm tables
Used by Cacti authentication/authorization. The plugin registers View and Manage realms and stores only the acting `user_id` in its audit trail.

### `plugin_config`
Managed by Cacti Plugin Management. The plugin does not manually recreate it.

### `settings`
Used for `icct_rack_refresh_seconds` and `icct_rack_schema_version`.

## Plugin-owned tables

### `plugin_icct_rack_racks`
One row per physical rack/shelter rack.

Important columns: `code`, `name`, `icct_name`, `room`, `location`, `rack_units`, `enabled`.

### `plugin_icct_rack_placements`
Maps one Cacti device (`host_id`) to one physical location in a rack.

Important columns: `rack_id`, `host_id`, `start_u`, `height_u`, `face`, `category`, `asset_tag`, `power_feed`, `notes`.

A unique index on `host_id` prevents one Cacti device being accidentally shown in multiple racks. Range-overlap validation is performed in PHP because a simple SQL unique constraint cannot represent multi-U overlap.

### `plugin_icct_rack_audit`
Append-only application audit for rack and placement configuration changes: acting Cacti user, action, entity, JSON details, source IP and timestamp.

## Why no `rack_unit` table?

Rack U positions are deterministic from `rack_units`. Creating 42 rows for every 42U rack would add data without adding information. Occupied U ranges are calculated from `start_u + height_u - 1`.

## Why no separate device-status table?

Cacti already determines device availability. Duplicating it would create synchronization problems. The viewer joins placements to Cacti `host` status and refreshes it periodically.

## Future integration points

The current `icct_name` field is intentionally a simple site/ICCT identifier for the first rack module. When the broader ICCT inventory/site data model is finalized, it can be migrated to a project-owned `icct_id` reference without changing Cacti's `host` table.
