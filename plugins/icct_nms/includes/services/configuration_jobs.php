<?php
/** ICCT-owned configuration jobs services, derived from the existing ICCT NMS implementation. */

/** Reused Inventory service: config job authorize. */
function icct_backend_config_job_authorize(array $job)
{
    $user = (int) $job["user_id"];
    if (
        $user < 1 ||
        db_fetch_cell_prepared("SELECT enabled FROM user_auth WHERE id=?", [
            $user,
        ]) !== "on" ||
        !is_realm_allowed(3, $user) ||
        !is_device_allowed((int) $job["host_id"], $user)
    ) {
        throw new RuntimeException("Configuration permission was revoked.");
    }
    $session = $_SESSION ?? [];
    try {
        $_SESSION = ["sess_user_id" => $user];
        if (!api_user_realm_auth("devices.php")) {
            throw new RuntimeException("ICCT NMS page access was revoked.");
        }
    } finally {
        $_SESSION = $session;
    }
}

/** Reused Inventory service: config target. */
function icct_backend_config_target($host_id, $authorize = true)
{
    $host_id = icct_backend_config_integer($host_id, 1, 16777215, "Device ID");
    if ($authorize) {
        icct_backend_require_management();
        icct_backend_require_device_access($host_id);
    }
    $host = db_fetch_row_prepared(
        "SELECT h.id,h.description,h.hostname,h.site_id,h.poller_id,h.disabled,h.snmp_version,h.snmp_port,h.snmp_timeout,h.snmp_community,h.snmp_username,h.snmp_password,h.snmp_auth_protocol,h.snmp_priv_passphrase,h.snmp_priv_protocol,h.snmp_context,h.snmp_engine_id FROM host h JOIN poller p ON p.id=h.poller_id WHERE h.id=? AND h.deleted='' AND p.disabled=''",
        [$host_id],
    );
    if (!$host || $host["disabled"] !== "") {
        throw new RuntimeException(
            "Device or its collector is missing or disabled.",
        );
    }
    $assignment = db_fetch_row_prepared(
        "SELECT * FROM plugin_icct_nms_config_devices WHERE host_id=?",
        [$host_id],
    );
    if (!$assignment) {
        throw new RuntimeException("Assign an equipment profile first.");
    }
    $profile = db_fetch_row_prepared(
        "SELECT * FROM plugin_icct_nms_config_profiles WHERE id=?",
        [$assignment["profile_id"]],
    );
    if (!$profile) {
        throw new RuntimeException("Equipment profile is unavailable.");
    }
    $fields = icct_backend_equipment_fields(
        $profile["fields_json"],
        $profile["protocol"],
    );
    $serial = null;
    if ($profile["protocol"] === "modbus_rtu") {
        if (!icct_backend_protocol_enabled($host_id, "serial")) {
            throw new RuntimeException(
                "Serial protocol is disabled for this device.",
            );
        }
        $serial = db_fetch_row_prepared(
            "SELECT d.device_address,d.revision AS device_revision,c.* FROM plugin_icct_nms_serial_devices d JOIN plugin_icct_nms_serial_connections c ON c.id=d.connection_id WHERE d.host_id=?",
            [$host_id],
        );
        if (
            !$serial ||
            !$serial["enabled"] ||
            (int) $serial["poller_id"] !== (int) $host["poller_id"]
        ) {
            throw new RuntimeException(
                "Serial connection is missing, disabled or assigned to another collector.",
            );
        }
        $serial["settings"] = json_decode(
            $serial["settings_json"],
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        $serial['settings']=icct_backend_serial_device_settings($host_id,$serial['id'],$serial['settings']);
    } elseif (!(int) $host["snmp_version"]) {
        throw new RuntimeException("Native SNMP access is disabled.");
    }
    $signature = hash(
        "sha256",
        json_encode(
            [$host, $assignment, $profile, $serial],
            JSON_THROW_ON_ERROR,
        ),
    );
    return [
        "host" => $host,
        "assignment" => $assignment,
        "profile" => $profile,
        "fields" => array_column($fields, null, "key"),
        "serial" => $serial,
        "signature" => $signature,
    ];
}
