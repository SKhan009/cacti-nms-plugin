<?php
/**
 * Basic Information form and saved observations. The controller supplies saved values.
 */
?>
<form method="post" class="device-form" id="device-form" <?= !empty($cloneId)
    ? 'data-clone="true"'
    : "" ?>>
    <?php icct_nms_token(); ?>
    <div class="titlebar">
        <h1><?= icct_nms_h($title) ?></h1>
        <div class="title-actions">
            <a class="button" href="inventory.php">Cancel</a>
            <?php if (!$readonly): ?>
            <button class="button primary" type="button" data-wizard-save><?= !empty($cloneId)
                ? "Create Clone"
                : "Save" ?></button>
            <?php endif; ?>
        </div>
    </div>
    <?php if (
        !empty($cloneId)
    ): ?><p class="clone-instructions">Enter a new hostname/IP address, then select Create Clone to add the device to Inventory.</p><?php endif; ?>
    <nav class="steps" aria-label="Device configuration">
        <ol>
            <li class="current">
                <span class="step-marker"></span>
                <span>
                    Basic Information
                    <small>1/7</small>
                </span>
            </li>
            <li>
                <a href="<?= $id ? "protocol.php?id=" . $id : "#protocol" ?>">
                    Protocol Config
                    <small>2/7</small>
                </a>
            </li>
            <li>
                <a href="<?= $id
                    ? "protocol.php?id=" . $id . "#diagnostics"
                    : "#diagnostics" ?>">
                    Device Diagnostics
                    <small>3/7</small>
                </a>
            </li>
            <li <?= $id ? "" : 'aria-disabled="true"' ?>><?php if (
    $id
): ?><a href="protocol.php?id=<?= $id ?>#graphs">Graphs<small>4/7</small></a><?php else: ?><a href="#graphs">Graphs<small>4/7</small></a><?php endif; ?></li>
            <li <?= $id ? "" : 'aria-disabled="true"' ?>><?php if ($id): ?><a href="protocol.php?id=<?= $id ?>#data-query">Data Query<small>5/7</small></a><?php else: ?><a href="#data-query">Data Query<small>5/7</small></a><?php endif; ?></li>
            <?php foreach (
                ["Port Config", "FCAPS"]
                as $i => $label
            ): ?>
            <li aria-disabled="true">
                <span>
                    <?= $label ?>
                    <small><?= $i + 6 ?>/7</small>
                </span>
            </li>
            <?php endforeach; ?>
        </ol>
    </nav>
    <fieldset class="device-fields" <?= $readonly ? "disabled" : "" ?>>
        <div class="enable-field">
            <span class="field-label">Enable/Disable Device</span>
            <label class="switch-label">
                <input class="switch-input" type="checkbox" name="enabled" <?= $values[
                    "enabled"
                ]
                    ? "checked"
                    : "" ?> />
                <span class="switch-track"></span>
                <span>Enable</span>
            </label>
        </div>
        <div class="form-grid">
            <?php
            icct_nms_input(
                "Device Name",
                "description",
                $values["description"],
                "text",
                "required",
            );
            icct_nms_input(
                "Short Name",
                "short_name",
                $values["short_name"],
                "text",
                'maxlength="8" data-auto-short="' .
                    ($shortNameAuto ? "1" : "0") .
                    '"',
            );
            icct_nms_input(
                "Hostname/IP Address",
                "hostname",
                $values["hostname"],
                "text",
                "required",
            );
            $categories = [0 => "Unclassified"];
            $segmentTypes = [0 => ""];
            foreach (
                db_fetch_assoc(
                    "SELECT id,name FROM plugin_icct_nms_categories ORDER BY sort_order,name",
                )
                as $c
            ) {
                $categories[$c["id"]] = $c["name"];
                $segmentTypes[$c["id"]] = icct_backend_category_device_type(
                    $c["id"],
                    $id,
                );
            }
            icct_nms_select(
                "Segment",
                "category_id",
                $categories,
                $values["category_id"],
            );
            $typeProfiles=icct_nms_device_types();
            $typeChoices=[''=>'None'];
            foreach ($typeProfiles as $profile) if ((int)$profile['category_id']===(int)$values['category_id']) $typeChoices[$profile['name']]=$profile['name'];
            icct_nms_select('Device Type','device_type',$typeChoices,$values['device_type']);
            ?>
            <div id="device-type-info" class="device-type-info" hidden>
                <img id="assigned-type-icon" alt="Device type icon" width="32" height="32">
                <div><span id="assigned-type-name"></span><small>No. of Ports: <span id="assigned-type-ports"></span></small></div>
                <img id="assigned-type-image" alt="Device type image" hidden>
            </div>
            <script type="application/json" id="device-type-profiles"><?= json_encode(array_map(static function($p) { $p['icon_asset']=icct_nms_type_icon_asset($p['icon']); $p['image_asset']=!empty($p['image'])?icct_nms_type_asset(array_replace($p,['display_modes'=>['network'=>'image']])):''; return $p; },array_values($typeProfiles)),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES) ?></script>
            <?php
            icct_nms_core_select(
                "Device Site Location",
                "site_id",
                $values["site_id"],
            );
            $racks = [0 => "Unassigned"];
            $rackData = db_fetch_assoc(
                "SELECT r.*,n.site_id FROM plugin_icct_nms_racks r JOIN plugin_icct_nms_rack_nodes n ON n.id=r.node_id ORDER BY r.name",
            );
            foreach ($rackData as $r) {
                $racks[$r["id"]] = $r["name"];
            }
            icct_nms_select("Rack Name", "rack_id", $racks, $values["rack_id"]);
            $positions = ["" => "Unassigned"];
            for ($u = 1; $u <= 100; $u++) {
                $positions[$u . ":1"] = $u . "U";
            }
            if (
                $values["rack_position"] &&
                !isset($positions[$values["rack_position"]])
            ) {
                $positions[$values["rack_position"]] =
                    $rack["start_unit"] .
                    "U–" .
                    ($rack["start_unit"] + $rack["unit_height"] - 1) .
                    "U";
            }
            icct_nms_select(
                "Placement in the Rack",
                "rack_position",
                $positions,
                $values["rack_position"],
            );
            icct_nms_input(
                "MAC Address",
                "mac_address",
                $values["mac_address"],
                "text",
                'data-identity-auto="' .
                    (empty($meta["mac_address"]) &&
                    !empty($observedIdentity["mac_address"])
                        ? "1"
                        : "0") .
                    '"',
            );
            icct_nms_input(
                "Serial Number",
                "serial_number",
                $values["serial_number"],
                "text",
                'data-identity-auto="' .
                    (empty($meta["serial_number"]) &&
                    !empty($observedIdentity["serial_number"])
                        ? "1"
                        : "0") .
                    '"',
            );
            icct_nms_input(
                "Chassis ID",
                "chassis_id",
                $values["chassis_id"],
                "text",
                'data-identity-auto="' .
                    (empty($meta["chassis_id"]) &&
                    !empty($observedIdentity["chassis_id"])
                        ? "1"
                        : "0") .
                    '"',
            );
            icct_nms_core_select(
                "Device Template",
                "host_template_id",
                $values["host_template_id"],
            );
            icct_nms_core_select(
                "Poller Association",
                "poller_id",
                $values["poller_id"],
            );
            icct_nms_core_select(
                "No. of Collection Threads",
                "device_threads",
                $values["device_threads"],
            );
            icct_nms_input(
                "Cross Launch URL",
                "cross_launch_url",
                $values["cross_launch_url"],
                "url",
            );
            ?>
        </div>
        <label class="field notes">
            <span class="notes-heading">
                <span class="field-label">Notes/Description</span>
                <span id="notes-count"></span>
            </span>
            <textarea name="notes" maxlength="<?= max(
                148,
                mb_strlen((string) $values["notes"]),
            ) ?>"><?= icct_nms_h($values["notes"]) ?></textarea>
        </label>
    </fieldset>
    <?php if (!$readonly && empty($wizard)): ?>
    <footer class="form-footer">
        <!-- Basic Information is the first step; Next validates and saves before advancing. -->
        <button class="button previous" type="button" disabled aria-label="Previous step">Previous ←</button>
        <button class="button next" type="submit" aria-label="<?= !empty(
            $cloneId
        )
            ? "Create Clone"
            : "Next step" ?>"><?= !empty($cloneId)
    ? "Create Clone"
    : "Next →" ?></button>
    </footer>
    <?php endif; ?>
</form>
<script type="application/json" id="rack-data">
    <?= json_encode(
        $rackData,
        JSON_HEX_TAG |
            JSON_HEX_AMP |
            JSON_HEX_APOS |
            JSON_HEX_QUOT |
            JSON_THROW_ON_ERROR,
    ) ?>
</script>

<script type="application/json" id="segment-data">
    <?= json_encode(
        $segmentTypes,
        JSON_HEX_TAG |
            JSON_HEX_AMP |
            JSON_HEX_APOS |
            JSON_HEX_QUOT |
            JSON_THROW_ON_ERROR,
    ) ?>
</script>

<script type="application/json" id="identity-endpoint">
    <?= json_encode(
        $old["hostname"] ?? "",
        JSON_HEX_TAG |
            JSON_HEX_AMP |
            JSON_HEX_APOS |
            JSON_HEX_QUOT |
            JSON_THROW_ON_ERROR,
    ) ?>
</script>
