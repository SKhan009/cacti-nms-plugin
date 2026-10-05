<?php
/** ICCT-owned ssh schema services, derived from the existing ICCT NMS implementation. */

/** Reused Inventory service: ssh require schema. */
function icct_backend_ssh_require_schema()
{
    icct_backend_require_database();
    if (
        db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?', [
            'ssh_schema_version'
        ]) !== '1'
    ) {
        throw new RuntimeException(
            'SSH schema upgrade required. Use Cacti Plugin Management; no fallback schema is used.'
        );
    }
}
