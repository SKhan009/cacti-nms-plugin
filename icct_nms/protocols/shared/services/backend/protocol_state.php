<?php
/** Fresh per-device state shared by UI, scheduled collectors and interactive services. */
function icct_backend_protocol_enabled($id, $protocol)
{
    return (string) db_fetch_cell_prepared(
        "SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?",
        ["protocol_disabled_" . $protocol . "_" . (int) $id],
    ) !== "1";
}
function icct_backend_protocol_state_write($id, $protocol, $enabled)
{
    icct_backend_category_execute(
        "REPLACE INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())",
        [
            "protocol_disabled_" . $protocol . "_" . (int) $id,
            $enabled ? "0" : "1",
        ],
    );
}
