<?php
/** Serial communication controls use only saved connections on the assigned collector. */
$serialValues = $serial
    ? json_decode($serial["settings_json"], true, 32, JSON_THROW_ON_ERROR)
    : [];
$serialInterval = icct_nms_meta("serial_interval_".$id) ?: db_fetch_cell_prepared(
    "SELECT interval_seconds FROM plugin_icct_nms_config_devices WHERE host_id=?",
    [$id],
);
if (!empty($presetMode)) {
    $serialPreset=$protocolPresets['serial'] ?? [];
    $serialValues=[];
    foreach (['serial_interface'=>'interface','serial_protocol'=>'protocol','serial_retries'=>'retries','baud_rate'=>'baud_rate','data_bits'=>'data_bits','stop_bits'=>'stop_bits','parity'=>'parity','flow_control'=>'flow_control'] as $field=>$setting) if (isset($serialPreset[$field])) $serialValues[$setting]=$serialPreset[$field];
    if (isset($serialPreset['response_timeout'])) $serialValues['timeout_ms']=(float)$serialPreset['response_timeout']*1000;
    $serialInterval=$serialPreset['serial_interval'] ?? 60;
}
?>
<section class="serial-communication">
    <h3 class="serial-physical-heading">1. Physical Interface</h3>
    <fieldset class="radio-group serial-physical">
        <legend>Interface Type *</legend>
        <?php foreach (
            ["rs232" => "RS-232", "rs485" => "RS-485"]
            as $value => $label
        ): ?>
        <label><input type="radio" name="serial_interface" value="<?= $value ?>" <?= ($serialValues[
    "interface"
] ??
    "") ===
$value
    ? "checked"
    : "" ?>  /><?= $label ?></label>
        <?php endforeach; ?>
    </fieldset>
    <p class="serial-physical-note" hidden>RS-232/RS-485 and serial line settings are managed by the selected gateway. Select a direct serial port to configure them here.</p>
    <h3>2. Connection Settings</h3>
    <div class="protocol-grid cols-4">
        <?php
        $ports = ["" => "None"];
        foreach ($connections as $connection) {
            $ports[$connection["id"]] =
                $connection["endpoint"] . " · " . $connection["name"];
        }
        icct_nms_select(
            "Serial Port *",
            "connection_id",
            $ports,
            $serial["connection_id"] ?? "",
            (!empty($presetMode) ? "disabled" : "required"),
        );
        icct_nms_select(
            "Baud Rate *",
            "baud_rate",
            ["" => "None"] + array_combine(
                [
                    300,
                    600,
                    1200,
                    2400,
                    4800,
                    9600,
                    19200,
                    38400,
                    57600,
                    115200,
                    230400,
                ],
                [
                    300,
                    600,
                    1200,
                    2400,
                    4800,
                    9600,
                    19200,
                    38400,
                    57600,
                    115200,
                    230400,
                ],
            ),
            $serialValues["baud_rate"] ?? "",
            "required",
        );
        icct_nms_select(
            "Data Bits *",
            "data_bits",
            ["" => "None", 7 => 7, 8 => 8],
            $serialValues["data_bits"] ?? "",
            "required",
        );
        icct_nms_select(
            "Parity *",
            "parity",
            [
                "" => "None",
                "none" => "No parity",
                "even" => "Even",
                "odd" => "Odd",
                "mark" => "Mark",
                "space" => "Space",
            ],
            $serialValues["parity"] ?? "",
            "required",
        );
        icct_nms_select(
            "Stop Bits *",
            "stop_bits",
            ["" => "None", 1 => 1, 2 => 2],
            $serialValues["stop_bits"] ?? "",
            "required",
        );
        icct_nms_select(
            "Flow Control",
            "flow_control",
            ["" => "None", "none" => "No flow control", "rtscts" => "RTS/CTS"],
            $serialValues["flow_control"] ?? "",
        );
        ?>
    </div>
    <div class="serial-gateway protocol-grid cols-4" hidden>
        <?php
        icct_nms_input(
            "Gateway Address",
            "serial_gateway_address",
            "",
            "text",
            "readonly",
        );
        icct_nms_input(
            "Gateway Port",
            "serial_gateway_port",
            "",
            "number",
            "readonly",
        );
        ?>
    </div>
    <h3>3. Communication Settings</h3>
    <div class="protocol-grid cols-3">
        <?php
        icct_nms_input(
            "Response Timeout (Sec) *",
            "response_timeout",
            ($serialValues["timeout_ms"] ?? 3000) / 1000,
            "number",
            'required min="0.1" max="10" step="0.001"',
        );
        icct_nms_input(
            "Retry Count *",
            "serial_retries",
            $serialValues["retries"] ?? 2,
            "number",
            'required min="0" max="3"',
        );
        icct_nms_input(
            "Polling Interval (Sec) *",
            "serial_interval",
            $serialInterval ?: 60,
            "number",
            'required min="1" max="86400"',
        );
        ?>
    </div>
    <h3>4. Serial Protocol *</h3>
    <fieldset class="radio-group">
        <legend class="visually-hidden">Serial Protocol</legend>
        <label><input type="radio" name="serial_protocol" value="modbus_rtu" <?= ($serialValues[
            "protocol"
        ] ??
            "modbus_rtu") ===
        "modbus_rtu"
            ? "checked"
            : "" ?> required />Modbus RTU</label>
        <label><input type="radio" name="serial_protocol" value="modbus_ascii" <?= ($serialValues[
            "protocol"
        ] ??
            "") ===
        "modbus_ascii"
            ? "checked"
            : "" ?> />Modbus ASCII</label>
        <label><input type="radio" name="serial_protocol" value="vendor" disabled title="Unavailable in the installed collector" />Vendor Specific</label>
    </fieldset>
    <h3 class="serial-parameter-heading">Modbus RTU Parameters</h3>
    <div class="serial-modbus protocol-grid cols-4">
        <?php icct_nms_input(
            "Device Bus Address *",
            "device_address",
            $serial["device_address"] ?? "",
            "number",
            !empty($presetMode) ? 'disabled' : 'required min="1" max="247"',
        ); ?>
    </div>
    <input type="hidden" name="connection_revision" value="" />
    <?php if(empty($presetMode)):
    $registerRows=[];
    try{$registerTarget=icct_backend_config_target($id,false);if($registerTarget['profile']['protocol']==='modbus_rtu')$registerRows=array_values($registerTarget['fields']);}catch(Throwable $e){}
    if(!$registerRows)$registerRows=[[]];
    $registerRow=function($row,$number){ ?>
    <tr><td><input aria-label="Register label" name="serial_registers[<?= $number ?>][label]" value="<?= icct_nms_h($row['label']??'') ?>" maxlength="100"></td><td><input aria-label="Zero-based register offset" name="serial_registers[<?= $number ?>][offset]" type="number" min="0" max="65535" value="<?= icct_nms_h($row['offset']??'') ?>"></td><td><select aria-label="Register read function" name="serial_registers[<?= $number ?>][function]"><option value="3">03 Holding register</option><option value="4" <?= ($row['function']??3)===4?'selected':'' ?>>04 Input register</option></select></td><td><select aria-label="Register type" name="serial_registers[<?= $number ?>][type]"><option value="uint16">Unsigned 16-bit</option><option value="int16" <?= ($row['type']??'')==='int16'?'selected':'' ?>>Signed 16-bit</option></select></td><td><input aria-label="Register units" name="serial_registers[<?= $number ?>][unit]" value="<?= icct_nms_h($row['unit']??'') ?>" maxlength="30"></td><td><button type="button" data-remove-serial-register aria-label="Remove register">×</button></td></tr>
    <?php }; ?>
    <h3>Register Readings</h3><p>Enter zero-based offsets from the device's Modbus register map. Saving enables background collection for this device.</p>
    <div class="site-table-wrap"><table class="site-table serial-register-editor"><thead><tr><th>Label</th><th>Offset (zero-based)</th><th>Read function</th><th>Type</th><th>Units</th><th></th></tr></thead><tbody data-serial-register-rows><?php foreach($registerRows as $number=>$row)$registerRow($row,$number); ?></tbody></table></div>
    <button type="button" class="button" data-add-serial-register>+ Add Register</button>
    <template data-serial-register-template><?php $registerRow([],'ROW'); ?></template>
    <?php endif; ?>
</section>
