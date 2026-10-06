<?php
/** Serial form persistence using the existing locked connection and collection services. */
function icct_nms_serial_register_fields($rows)
{
    if (!is_array($rows) || count($rows)>16) throw new InvalidArgumentException('Define at most 16 serial registers.');
    $fields=[];$seen=[];
    foreach($rows as $row){
        if(!is_array($row)) throw new InvalidArgumentException('Invalid serial register.');
        if(trim((string)($row['label']??''))==='' && trim((string)($row['offset']??''))==='') continue;
        $offset=icct_backend_config_integer($row['offset']??'',0,65535,'Zero-based register offset');
        $function=icct_backend_config_integer($row['function']??'',3,4,'Read function');
        $key='register_'.$function.'_'.$offset;
        if(isset($seen[$key])) throw new InvalidArgumentException('Duplicate register offset and read function.');
        $seen[$key]=true;
        $type=icct_backend_config_choice($row['type']??'', ['uint16','int16'],'Register type');
        $fields[]=['key'=>$key,'label'=>icct_backend_config_text($row['label']??'',100,'Register label'),'unit'=>icct_backend_config_text($row['unit']??'',30,'Unit',false),'offset'=>$offset,'function'=>$function,'type'=>$type,'min'=>$type==='int16'?-32768:0,'max'=>$type==='int16'?32767:65535,'writable'=>false];
    }
    return $fields;
}

function icct_nms_serial_settings($old, $input, $direct)
{
    $settings = $old;
    $settings["protocol"] = icct_backend_config_choice(
        $input["serial_protocol"] ?? "",
        ["modbus_rtu", "modbus_ascii"],
        "serial protocol",
    );
    if (!$direct && $settings["protocol"] !== "modbus_rtu") {
        throw new InvalidArgumentException(
            "Modbus ASCII requires a direct serial port; this gateway uses RTU framing.",
        );
    }
    if ($direct) {
        $settings["interface"] = icct_backend_config_choice(
            $input["serial_interface"] ?? "",
            ["rs232", "rs422", "rs485"],
            "physical interface",
        );
        $settings["baud_rate"] = icct_backend_config_integer(
            $input["baud_rate"] ?? "",
            50,
            4000000,
            "Baud rate",
        );
        $settings["data_bits"] = icct_backend_config_integer(
            $input["data_bits"] ?? "",
            $settings["protocol"] === "modbus_ascii" ? 7 : 8,
            8,
            "Data bits",
        );
        $settings["stop_bits"] = icct_backend_config_integer(
            $input["stop_bits"] ?? "",
            1,
            2,
            "Stop bits",
        );
        $settings["parity"] = icct_backend_config_choice(
            $input["parity"] ?? "",
            ["none", "even", "odd", "mark", "space"],
            "parity",
        );
        $settings["flow_control"] = icct_backend_config_choice(
            $input["flow_control"] ?? "",
            ["none", "rtscts"],
            "flow control",
        );
        if (
            $settings["interface"] === "rs485" &&
            $settings["flow_control"] !== "none"
        ) {
            throw new InvalidArgumentException(
                "RS-485 requires flow control None.",
            );
        }
    }
    $timeout = $input["response_timeout"] ?? "";
    if (
        !is_scalar($timeout) ||
        !preg_match('/^(?:[0-9]+)(?:\.[0-9]{1,3})?$/D', (string) $timeout) ||
        (float) $timeout < 0.1 ||
        (float) $timeout > 10
    ) {
        throw new InvalidArgumentException(
            "Response timeout must be from 0.1 to 10 seconds.",
        );
    }
    $settings["timeout_ms"] = (int) round((float) $timeout * 1000);
    $settings["retries"] = icct_backend_config_integer(
        $input["serial_retries"] ?? "",
        0,
        3,
        "Retry count",
    );
    return $settings;
}

function icct_nms_save_serial($id, $input)
{
    return icct_backend_serial_mutation(function () use ($id, $input) {
        $connection = icct_backend_serial_connection_get(
            $input["connection_id"] ?? 0,
        );
        if (
            (int) $connection["revision"] !==
            (int) ($input["connection_revision"] ?? -1)
        ) {
            throw new RuntimeException(
                "Connection changed. Reload before saving.",
            );
        }
        $settings = icct_nms_serial_settings(
            $connection["settings"],
            $input,
            $connection["transport"] === "direct",
        );
        $interval = icct_backend_config_integer(
            $input["serial_interval"] ?? "",
            1,
            86400,
            "Polling interval",
        );
        $registers=array_key_exists('serial_registers',$input)?icct_nms_serial_register_fields($input['serial_registers']):null;
        // Connection preset IDs are independent of equipment profile IDs.
        $assignment = db_fetch_row_prepared(
            "SELECT * FROM plugin_icct_nms_config_devices WHERE host_id=? FOR UPDATE",
            [$id],
        );
        // Access, collector ownership, bus address and assignment revision are checked before writes.
        icct_backend_serial_assign_locked($id, $input);
        if($registers){
            // A private profile prevents one device's register edits changing another device.
            $profileName='Device '.$id.' serial registers';
            $profileId=(int)db_fetch_cell_prepared('SELECT id FROM plugin_icct_nms_config_profiles WHERE name=?',[$profileName]);
            if($profileId && db_fetch_cell_prepared('SELECT host_id FROM plugin_icct_nms_config_devices WHERE profile_id=? AND host_id<>?',[$profileId,$id])) throw new RuntimeException('Serial reading profile is shared. Create a private profile before editing.');
            icct_backend_category_execute("INSERT INTO plugin_icct_nms_config_profiles(name,manufacturer,model,manual_reference,protocol,fields_json,updated_by,updated_at) VALUES(?,'','','','modbus_rtu',?,?,NOW()) ON DUPLICATE KEY UPDATE fields_json=VALUES(fields_json),revision=revision+1,updated_by=VALUES(updated_by),updated_at=NOW()",[$profileName,json_encode($registers,JSON_THROW_ON_ERROR),icct_backend_current_user_id()]);
            $profileId=(int)db_fetch_cell_prepared('SELECT id FROM plugin_icct_nms_config_profiles WHERE name=?',[$profileName]);
            icct_backend_category_execute('INSERT INTO plugin_icct_nms_config_devices(host_id,profile_id,interval_seconds,updated_by,updated_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE profile_id=VALUES(profile_id),interval_seconds=VALUES(interval_seconds),revision=revision+1,updated_by=VALUES(updated_by),updated_at=NOW()',[$id,$profileId,$interval,icct_backend_current_user_id()]);
        } elseif($registers!==null && $assignment){
            throw new InvalidArgumentException('Keep at least one register in the saved reading profile.');
        }
        // Save a private snapshot; never change the shared collector connection.
        icct_backend_category_execute(
            'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
            ['serial_settings_'.$id,json_encode(['connection_id'=>(int)$connection['id'],'settings'=>$settings],JSON_THROW_ON_ERROR)]
        );
        if ($assignment) {
            icct_backend_category_execute(
                "UPDATE plugin_icct_nms_config_devices SET interval_seconds=?,revision=revision+1,updated_by=?,updated_at=NOW() WHERE host_id=?",
                [$interval, icct_backend_current_user_id(), $id],
            );
        }
        // Retain timing even for a connection without an equipment reading profile.
        icct_backend_category_execute(
            "INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()",
            ["serial_interval_".$id, (string)$interval],
        );
    });
}
