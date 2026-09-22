-- DESTRUCTIVE. Run only if you intentionally want to erase ALL rack data.
DROP TABLE IF EXISTS plugin_icct_rack_audit;
DROP TABLE IF EXISTS plugin_icct_rack_placements;
DROP TABLE IF EXISTS plugin_icct_rack_racks;
DELETE FROM settings WHERE name IN ('icct_rack_refresh_seconds', 'icct_rack_schema_version');
