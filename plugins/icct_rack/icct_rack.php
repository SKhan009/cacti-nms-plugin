<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */

chdir('../..');
include('./include/auth.php');
include_once($config['base_path'] . '/plugins/icct_rack/lib/functions.php');

$racks = icct_rack_get_racks(true);
$rack_id = isset_request_var('rack_id') ? (int)get_filter_request_var('rack_id') : 0;

if ($rack_id <= 0 && cacti_sizeof($racks)) {
    $rack_id = (int)$racks[0]['id'];
}

$rack = $rack_id > 0 ? icct_rack_get_rack($rack_id) : [];
if (!$rack || ($rack['enabled'] ?? '') !== 'on') {
    /* FIX 2026-09-15: A disabled rack must not be reachable through a guessed rack_id. */
    $rack_id = cacti_sizeof($racks) ? (int)$racks[0]['id'] : 0;
    $rack = $rack_id > 0 ? icct_rack_get_rack($rack_id) : [];
}
$placements = $rack ? icct_rack_get_placements($rack_id) : [];
$can_manage = icct_rack_can_manage();
$refresh_seconds = (int)read_config_option('icct_rack_refresh_seconds');
if ($refresh_seconds < 0) {
    $refresh_seconds = 30;
}

$plugin_url = $config['url_path'] . 'plugins/icct_rack';
$csrf_token = function_exists('csrf_get_tokens') ? csrf_get_tokens() : '';

top_header();
print '<link rel="stylesheet" type="text/css" href="' . html_escape($plugin_url . '/css/icct_rack.css') . '">';
?>
<div class="icct-rack-page">
    <div class="icct-rack-toolbar">
        <label for="icct-rack-selector">Rack</label>
        <select id="icct-rack-selector"<?php print cacti_sizeof($racks) ? '' : ' disabled'; ?>>
            <?php foreach ($racks as $row) { ?>
                <option value="<?php print (int)$row['id']; ?>"<?php print ((int)$row['id'] === $rack_id) ? ' selected' : ''; ?>>
                    <?php print icct_rack_h(($row['icct_name'] !== '' ? $row['icct_name'] . ' / ' : '') . $row['code'] . ' - ' . $row['name']); ?>
                </option>
            <?php } ?>
        </select>
        <span class="spacer"></span>
        <span>Auto refresh: <strong><?php print $refresh_seconds > 0 ? (int)$refresh_seconds . 's' : 'Off'; ?></strong></span>
        <span>Last refresh: <strong id="icct-rack-refreshed"><?php print date('Y-m-d H:i:s'); ?></strong></span>
        <?php if ($can_manage) { ?>
            <a href="<?php print html_escape($plugin_url . '/icct_rack_admin.php'); ?>"><i class="fas fa-cog"></i> Manage racks</a>
        <?php } ?>
    </div>

    <?php if (!$rack) { ?>
        <div class="icct-rack-empty">
            <strong>No enabled rack has been created yet.</strong><br>
            <?php if ($can_manage) { ?>
                Open <a href="<?php print html_escape($plugin_url . '/icct_rack_admin.php'); ?>">Manage racks</a> and create the first rack.
            <?php } else { ?>
                Ask a Cacti administrator to create and enable a rack.
            <?php } ?>
        </div>
    <?php } else {
        $counts = icct_rack_status_counts($placements);
        $front_used = 0;
        $rear_used = 0;
        foreach ($placements as $p) {
            if ($p['face'] === 'rear') {
                $rear_used += (int)$p['height_u'];
            } else {
                $front_used += (int)$p['height_u'];
            }
        }
    ?>
        <div class="icct-rack-meta">
            <div class="icct-rack-meta-card"><span>Rack</span><strong><?php print icct_rack_h($rack['code'] . ' - ' . $rack['name']); ?></strong></div>
            <div class="icct-rack-meta-card"><span>ICCT / Site</span><strong><?php print icct_rack_h($rack['icct_name'] ?: '-'); ?></strong></div>
            <div class="icct-rack-meta-card"><span>Location / Room</span><strong><?php print icct_rack_h(trim($rack['location'] . ' ' . $rack['room']) ?: '-'); ?></strong></div>
            <div class="icct-rack-meta-card"><span>Capacity</span><strong><?php print (int)$rack['rack_units']; ?>U</strong></div>
            <div class="icct-rack-meta-card"><span>Front occupied</span><strong><?php print $front_used; ?>U</strong></div>
            <div class="icct-rack-meta-card"><span>Rear occupied</span><strong><?php print $rear_used; ?>U</strong></div>
        </div>

        <div class="icct-status-summary">
            <span class="icct-status-pill status-up">Up <span data-summary-key="up"><?php print (int)$counts['up']; ?></span></span>
            <span class="icct-status-pill status-down">Down <span data-summary-key="down"><?php print (int)$counts['down']; ?></span></span>
            <span class="icct-status-pill status-recovering">Recovering <span data-summary-key="recovering"><?php print (int)$counts['recovering']; ?></span></span>
            <span class="icct-status-pill status-error">Error <span data-summary-key="error"><?php print (int)$counts['error']; ?></span></span>
            <span class="icct-status-pill status-unknown">Unknown <span data-summary-key="unknown"><?php print (int)$counts['unknown']; ?></span></span>
            <span class="icct-status-pill status-disabled">Disabled <span data-summary-key="disabled"><?php print (int)$counts['disabled']; ?></span></span>
            <span class="icct-status-pill status-missing">Missing <span data-summary-key="missing"><?php print (int)$counts['missing']; ?></span></span>
        </div>

        <div class="icct-rack-legend">
            <span><i class="status-up"></i> Reachable</span>
            <span><i class="status-down"></i> Unreachable</span>
            <span><i class="status-recovering"></i> Recovering</span>
            <span><i class="status-error"></i> Error</span>
            <span><i class="status-unknown"></i> Unknown</span>
            <span><i class="status-disabled"></i> Disabled</span>
            <span><i class="status-missing"></i> Device removed from Cacti</span>
        </div>

        <?php if ($can_manage) { ?>
            <div class="icct-note">Drag a device to another free U position on the same rack face or the other face. Overlap and rack-boundary validation is enforced by the backend.</div>
        <?php } ?>

        <div class="icct-rack-faces">
            <?php foreach (['front' => 'Front view', 'rear' => 'Rear view'] as $face => $face_title) { ?>
                <div class="icct-rack-face-wrap">
                    <div class="icct-rack-face-title"><span><?php print icct_rack_h($face_title); ?></span><span><?php print (int)$rack['rack_units']; ?>U</span></div>
                    <div class="icct-rack-cabinet" data-face="<?php print icct_rack_h($face); ?>" style="height:<?php print ((int)$rack['rack_units'] * 38); ?>px">
                        <?php for ($u = (int)$rack['rack_units']; $u >= 1; $u--) {
                            $top = ((int)$rack['rack_units'] - $u) * 38;
                        ?>
                            <div class="icct-rack-urow" data-u="<?php print $u; ?>" data-face="<?php print icct_rack_h($face); ?>" style="top:<?php print $top; ?>px">
                                <span class="u-label">U<?php print $u; ?></span>
                            </div>
                        <?php } ?>

                        <?php foreach ($placements as $p) {
                            if ($p['face'] !== $face) {
                                continue;
                            }
                            $host_exists = $p['host_description'] !== null;
                            $status = $host_exists
                                ? icct_rack_status_info(['status' => $p['host_status'], 'disabled' => $p['host_disabled']])
                                : ['key' => 'missing', 'label' => 'Missing from Cacti', 'class' => 'status-missing'];
                            $top = ((int)$rack['rack_units'] - ((int)$p['start_u'] + (int)$p['height_u'] - 1)) * 38 + 2;
                            $height = max(30, ((int)$p['height_u'] * 38) - 4);
                            $display = trim((string)$p['label']) !== '' ? $p['label'] : ($p['host_description'] ?: 'Missing Cacti device #' . (int)$p['host_id']);
                            $subtitle = ($p['host_hostname'] ?: 'Host ID ' . (int)$p['host_id']) . ' • U' . (int)$p['start_u'] . '-' . ((int)$p['start_u'] + (int)$p['height_u'] - 1);
                        ?>
                            <div class="icct-device <?php print icct_rack_h($status['class']); ?>"
                                 data-placement-id="<?php print (int)$p['id']; ?>"
                                 data-host-id="<?php print (int)$p['host_id']; ?>"
                                 data-height-u="<?php print (int)$p['height_u']; ?>"
                                 draggable="<?php print $can_manage ? 'true' : 'false'; ?>"
                                 style="top:<?php print $top; ?>px;height:<?php print $height; ?>px"
                                 title="<?php print icct_rack_h($display . ' - ' . $status['label']); ?>">
                                <div class="icct-device-line1">
                                    <i class="<?php print icct_rack_h(icct_rack_category_icon($p['category'])); ?>"></i>
                                    <span><?php print icct_rack_h($display); ?></span>
                                    <span class="icct-device-badge"><?php print icct_rack_h($status['label']); ?></span>
                                </div>
                                <?php if ($height >= 55) { ?>
                                    <div class="icct-device-line2"><?php print icct_rack_h($subtitle); ?></div>
                                <?php } ?>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            <?php } ?>
        </div>

        <div class="icct-device-panel" id="icct-device-panel">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                <strong>Device details</strong>
                <button type="button" id="icct-device-panel-close">Close</button>
            </div>
            <div class="icct-device-panel-grid">
                <div><div class="label">Description</div><div class="value" data-device-field="description"></div></div>
                <div><div class="label">Hostname / IP</div><div class="value" data-device-field="hostname"></div></div>
                <div><div class="label">Status</div><div class="value" data-device-field="status"></div></div>
                <div><div class="label">SNMP sysName</div><div class="value" data-device-field="snmp_sysName"></div></div>
                <div><div class="label">SNMP location</div><div class="value" data-device-field="snmp_sysLocation"></div></div>
                <div><div class="label">Last error</div><div class="value" data-device-field="status_last_error"></div></div>
            </div>
            <div style="margin-top:10px"><a data-device-link href="#">Open in Cacti device page</a></div>
        </div>
    <?php } ?>
</div>
<div id="icct-rack-config" hidden
     data-base-url="<?php print icct_rack_h($plugin_url); ?>"
     data-rack-id="<?php print (int)$rack_id; ?>"
     data-refresh-seconds="<?php print (int)$refresh_seconds; ?>"
     data-can-manage="<?php print $can_manage ? '1' : '0'; ?>"
     data-csrf-token="<?php print icct_rack_h($csrf_token); ?>"></div>
<script src="<?php print html_escape($plugin_url . '/js/icct_rack.js'); ?>"></script>
<?php
bottom_footer();
