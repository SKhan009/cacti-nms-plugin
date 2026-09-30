<?php
/** ICCT-owned database services, derived from the existing ICCT NMS implementation. */

/** Reused Inventory service: database ready. */
function icct_backend_database_ready()
{
    return db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?', [
        'icct_nms_schema_version'
    ]) === '1.1.0';
}

/** Reused Inventory service: require database. */
function icct_backend_require_database()
{
    if (!icct_backend_database_ready()) {
        http_response_code(503);
        die(
            'ICCT NMS database upgrade is required. Back up the database and run the ICCT NMS upgrade from Cacti Plugin Management. No fallback schema is used.'
        );
    }
}
