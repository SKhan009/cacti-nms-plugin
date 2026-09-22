<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */

chdir('../..');
include('./include/auth.php');
include_once($config['base_path'] . '/plugins/icct_rack/lib/functions.php');

if (!icct_rack_can_manage()) {
    raise_message('icct_rack_denied', 'You do not have permission to manage ICCT rack topology.', MESSAGE_LEVEL_ERROR);
    header('Location: ' . $config['url_path'] . 'plugins/icct_rack/icct_rack.php');
    exit;
}

function icct_rack_admin_redirect($query = '') {
    $target = 'icct_rack_admin.php' . ($query !== '' ? '?' . ltrim($query, '?') : '');
    header('Location: ' . $target);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* FIX 2026-09-15: Explicitly verify every administration write request. */
    if (!function_exists('csrf_check_tokens') || !csrf_check_tokens($_POST['__csrf_magic'] ?? '')) {
        raise_message('icct_rack_csrf_error', 'Invalid or expired security token.', MESSAGE_LEVEL_ERROR);
        icct_rack_admin_redirect();
    }

    $action = icct_rack_request_string('action');

    if ($action === 'save_rack') {
        $rack_id = icct_rack_request_int('id');
        $data = [
            'code' => icct_rack_request_string('code'),
            'name' => icct_rack_request_string('name'),
            'icct_name' => icct_rack_request_string('icct_name'),
            'room' => icct_rack_request_string('room'),
            'location' => icct_rack_request_string('location'),
            'rack_units' => icct_rack_request_int('rack_units', 42),
            'description' => icct_rack_request_string('description'),
            'enabled' => isset($_POST['enabled']) ? 'on' : '',
        ];
        $result = icct_rack_save_rack($data, $rack_id);
        if (!$result['ok']) {
            icct_rack_raise_errors('icct_rack_save_error', $result['errors']);
            icct_rack_admin_redirect('edit_rack=' . (int)$rack_id);
        }
        raise_message('icct_rack_saved', 'Rack saved successfully.', MESSAGE_LEVEL_INFO);
        icct_rack_admin_redirect('edit_rack=' . (int)$result['id']);
    }

    if ($action === 'delete_rack') {
        $rack_id = icct_rack_request_int('id');
        $result = icct_rack_delete_rack($rack_id);
        if (!$result['ok']) {
            icct_rack_raise_errors('icct_rack_delete_error', $result['error']);
        } else {
            raise_message('icct_rack_deleted', 'Rack deleted successfully.', MESSAGE_LEVEL_INFO);
        }
        icct_rack_admin_redirect();
    }

    if ($action === 'save_placement') {
        $placement_id = icct_rack_request_int('id');
        $data = [
            'rack_id' => icct_rack_request_int('rack_id'),
            'host_id' => icct_rack_request_int('host_id'),
            'label' => icct_rack_request_string('label'),
            'start_u' => icct_rack_request_int('start_u'),
            'height_u' => icct_rack_request_int('height_u', 1),
            'face' => icct_rack_request_string('face', 'front'),
            'category' => icct_rack_request_string('category', 'generic'),
            'asset_tag' => icct_rack_request_string('asset_tag'),
            'power_feed' => icct_rack_request_string('power_feed'),
            'notes' => icct_rack_request_string('notes'),
        ];
        $result = icct_rack_save_placement($data, $placement_id);
        if (!$result['ok']) {
            icct_rack_raise_errors('icct_rack_place_error', $result['errors']);
            icct_rack_admin_redirect('rack_id=' . (int)$data['rack_id'] . '&edit_placement=' . (int)$placement_id);
        }
        raise_message('icct_rack_place_saved', 'Device placement saved successfully.', MESSAGE_LEVEL_INFO);
        icct_rack_admin_redirect('rack_id=' . (int)$data['rack_id']);
    }

    if ($action === 'delete_placement') {
        $placement_id = icct_rack_request_int('id');
        $rack_id = icct_rack_request_int('rack_id');
        $result = icct_rack_delete_placement($placement_id);
        if (!$result['ok']) {
            icct_rack_raise_errors('icct_rack_place_delete_error', $result['error']);
        } else {
            raise_message('icct_rack_place_deleted', 'Device placement removed.', MESSAGE_LEVEL_INFO);
        }
        icct_rack_admin_redirect('rack_id=' . (int)$rack_id);
    }

    if ($action === 'save_settings') {
        $refresh = icct_rack_request_int('refresh_seconds', 30);
        $allowed = [0, 15, 30, 60, 120, 300];
        if (!in_array($refresh, $allowed, true)) {
            $refresh = 30;
        }
        set_config_option('icct_rack_refresh_seconds', (string)$refresh);
        icct_rack_audit('settings.update', 'settings', 0, ['refresh_seconds' => $refresh]);
        raise_message('icct_rack_settings_saved', 'Rack topology settings saved.', MESSAGE_LEVEL_INFO);
        icct_rack_admin_redirect();
    }
}

$racks = icct_rack_get_racks(false);
$selected_rack_id = isset_request_var('rack_id') ? (int)get_filter_request_var('rack_id') : 0;
$edit_rack_id = isset_request_var('edit_rack') ? (int)get_filter_request_var('edit_rack') : 0;
$edit_placement_id = isset_request_var('edit_placement') ? (int)get_filter_request_var('edit_placement') : 0;

if ($selected_rack_id <= 0 && cacti_sizeof($racks)) {
    $selected_rack_id = (int)$racks[0]['id'];
}

$edit_rack = $edit_rack_id > 0 ? icct_rack_get_rack($edit_rack_id) : [];
$selected_rack = $selected_rack_id > 0 ? icct_rack_get_rack($selected_rack_id) : [];
$edit_placement = $edit_placement_id > 0 ? icct_rack_get_placement($edit_placement_id) : [];
$placements = $selected_rack ? icct_rack_get_placements($selected_rack_id) : [];
$available_hosts = icct_rack_get_available_hosts($edit_placement_id);
$categories = icct_rack_categories();
$audit = icct_rack_get_recent_audit(50);
$refresh_seconds = (int)read_config_option('icct_rack_refresh_seconds');
$plugin_url = $config['url_path'] . 'plugins/icct_rack';
$csrf_token = function_exists('csrf_get_tokens') ? csrf_get_tokens() : '';

top_header();
print '<link rel="stylesheet" type="text/css" href="' . html_escape($plugin_url . '/css/icct_rack.css') . '">';
?>
<div class="icct-rack-page">
    <div class="icct-rack-toolbar">
        <a href="<?php print html_escape($plugin_url . '/icct_rack.php' . ($selected_rack_id ? '?rack_id=' . $selected_rack_id : '')); ?>"><i class="fas fa-eye"></i> View rack topology</a>
        <span class="spacer"></span>
        <strong>ICCT Rack Topology Administration</strong>
    </div>

    <div class="icct-note">
        Cacti <code>host</code> remains the canonical device inventory. This plugin stores only rack metadata and placement references to Cacti host IDs; it does not duplicate SNMP credentials or device monitoring configuration.
    </div>

    <div class="icct-admin-grid">
        <div>
            <div class="icct-card">
                <h3><?php print $edit_rack ? 'Edit rack' : 'Create rack'; ?></h3>
                <?php form_start('icct_rack_admin.php', 'icct_rack_edit'); ?>
                <input type="hidden" name="action" value="save_rack">
                <!-- FIX 2026-09-15: Keep the CSRF token explicit instead of relying only on output rewriting. -->
                <input type="hidden" name="__csrf_magic" value="<?php print icct_rack_h($csrf_token); ?>">
                <input type="hidden" name="id" value="<?php print $edit_rack ? (int)$edit_rack['id'] : 0; ?>">
                <div class="icct-form-grid">
                    <label for="rack_code">Rack code *</label>
                    <input id="rack_code" name="code" type="text" maxlength="64" required value="<?php print icct_rack_h($edit_rack['code'] ?? ''); ?>" placeholder="e.g. ICCT01-R01">

                    <label for="rack_name">Rack name *</label>
                    <input id="rack_name" name="name" type="text" maxlength="128" required value="<?php print icct_rack_h($edit_rack['name'] ?? ''); ?>" placeholder="Communication Rack 1">

                    <label for="rack_icct">ICCT / Site</label>
                    <input id="rack_icct" name="icct_name" type="text" maxlength="128" value="<?php print icct_rack_h($edit_rack['icct_name'] ?? ''); ?>" placeholder="ICCT-01">

                    <label for="rack_room">Room / Shelter</label>
                    <input id="rack_room" name="room" type="text" maxlength="128" value="<?php print icct_rack_h($edit_rack['room'] ?? ''); ?>" placeholder="Communication Shelter">

                    <label for="rack_location">Location</label>
                    <input id="rack_location" name="location" type="text" maxlength="255" value="<?php print icct_rack_h($edit_rack['location'] ?? ''); ?>" placeholder="Site / vehicle / bay">

                    <label for="rack_units">Rack size (U) *</label>
                    <input id="rack_units" name="rack_units" type="number" min="6" max="60" required value="<?php print (int)($edit_rack['rack_units'] ?? 42); ?>">

                    <label for="rack_description">Description</label>
                    <textarea id="rack_description" name="description"><?php print icct_rack_h($edit_rack['description'] ?? ''); ?></textarea>

                    <label for="rack_enabled">Enabled</label>
                    <label style="font-weight:normal"><input id="rack_enabled" name="enabled" type="checkbox" value="on" <?php print (!$edit_rack || ($edit_rack['enabled'] ?? '') === 'on') ? 'checked' : ''; ?>> Show in topology viewer</label>

                    <div class="icct-form-actions">
                        <?php if ($edit_rack) { ?><a class="icct-button" href="icct_rack_admin.php">New rack</a><?php } ?>
                        <button type="submit">Save rack</button>
                    </div>
                </div>
                <?php form_end(); ?>
            </div>

            <div class="icct-card">
                <h3>Racks</h3>
                <?php if (!cacti_sizeof($racks)) { ?>
                    <div>No racks created.</div>
                <?php } else { ?>
                    <table class="icct-table">
                        <thead><tr><th>Code / Name</th><th>ICCT</th><th>U</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($racks as $r) { ?>
                            <tr>
                                <td><strong><?php print icct_rack_h($r['code']); ?></strong><br><?php print icct_rack_h($r['name']); ?></td>
                                <td><?php print icct_rack_h($r['icct_name'] ?: '-'); ?></td>
                                <td><?php print (int)$r['rack_units']; ?></td>
                                <td><?php print $r['enabled'] === 'on' ? 'Enabled' : 'Disabled'; ?></td>
                                <td class="actions">
                                    <a href="icct_rack_admin.php?rack_id=<?php print (int)$r['id']; ?>&edit_rack=<?php print (int)$r['id']; ?>">Edit</a>
                                    &nbsp;|&nbsp;
                                    <a href="icct_rack.php?rack_id=<?php print (int)$r['id']; ?>">View</a>
                                    <div style="margin-top:5px">
                                        <?php form_start('icct_rack_admin.php', 'delete_rack_' . (int)$r['id']); ?>
                                        <input type="hidden" name="action" value="delete_rack">
                                        <!-- FIX 2026-09-15: Protect rack deletion with the Cacti CSRF token. -->
                                        <input type="hidden" name="__csrf_magic" value="<?php print icct_rack_h($csrf_token); ?>">
                                        <input type="hidden" name="id" value="<?php print (int)$r['id']; ?>">
                                        <button type="submit" onclick="return confirm('Delete this rack? It must have no device placements.');">Delete</button>
                                        <?php form_end(); ?>
                                    </div>
                                </td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                <?php } ?>
            </div>

            <div class="icct-card">
                <h3>Viewer settings</h3>
                <?php form_start('icct_rack_admin.php', 'icct_rack_settings'); ?>
                <input type="hidden" name="action" value="save_settings">
                <!-- FIX 2026-09-15: Protect settings changes with the Cacti CSRF token. -->
                <input type="hidden" name="__csrf_magic" value="<?php print icct_rack_h($csrf_token); ?>">
                <div class="icct-form-grid">
                    <label for="refresh_seconds">Status refresh</label>
                    <select id="refresh_seconds" name="refresh_seconds">
                        <?php foreach ([0 => 'Off', 15 => '15 seconds', 30 => '30 seconds', 60 => '1 minute', 120 => '2 minutes', 300 => '5 minutes'] as $value => $label) { ?>
                            <option value="<?php print (int)$value; ?>"<?php print $refresh_seconds === (int)$value ? ' selected' : ''; ?>><?php print icct_rack_h($label); ?></option>
                        <?php } ?>
                    </select>
                    <div class="icct-form-actions"><button type="submit">Save settings</button></div>
                </div>
                <?php form_end(); ?>
            </div>
        </div>

        <div>
            <div class="icct-card">
                <h3><?php print $edit_placement ? 'Edit device placement' : 'Place Cacti device in rack'; ?></h3>
                <?php if (!$selected_rack) { ?>
                    <div>Create a rack first.</div>
                <?php } else { ?>
                    <?php form_start('icct_rack_admin.php', 'icct_rack_placement'); ?>
                    <input type="hidden" name="action" value="save_placement">
                    <!-- FIX 2026-09-15: Protect placement changes with the Cacti CSRF token. -->
                    <input type="hidden" name="__csrf_magic" value="<?php print icct_rack_h($csrf_token); ?>">
                    <input type="hidden" name="id" value="<?php print $edit_placement ? (int)$edit_placement['id'] : 0; ?>">
                    <div class="icct-form-grid">
                        <label for="placement_rack">Rack *</label>
                        <select id="placement_rack" name="rack_id" required>
                            <?php foreach ($racks as $r) { ?>
                                <option value="<?php print (int)$r['id']; ?>"<?php print ((int)$r['id'] === (int)($edit_placement['rack_id'] ?? $selected_rack_id)) ? ' selected' : ''; ?>><?php print icct_rack_h($r['code'] . ' - ' . $r['name']); ?></option>
                            <?php } ?>
                        </select>

                        <label for="placement_host">Cacti device *</label>
                        <select id="placement_host" name="host_id" required>
                            <option value="">-- Select device --</option>
                            <?php foreach ($available_hosts as $h) { ?>
                                <option value="<?php print (int)$h['id']; ?>"<?php print ((int)$h['id'] === (int)($edit_placement['host_id'] ?? 0)) ? ' selected' : ''; ?>><?php print icct_rack_h($h['description'] . ' [' . $h['hostname'] . ']'); ?></option>
                            <?php } ?>
                        </select>

                        <label for="placement_label">Rack label</label>
                        <input id="placement_label" name="label" type="text" maxlength="128" value="<?php print icct_rack_h($edit_placement['label'] ?? ''); ?>" placeholder="Optional display label">

                        <label for="placement_start_u">Start U *</label>
                        <input id="placement_start_u" name="start_u" type="number" min="1" max="60" required value="<?php print (int)($edit_placement['start_u'] ?? 1); ?>">

                        <label for="placement_height_u">Height (U) *</label>
                        <input id="placement_height_u" name="height_u" type="number" min="1" max="20" required value="<?php print (int)($edit_placement['height_u'] ?? 1); ?>">

                        <label for="placement_face">Rack face *</label>
                        <select id="placement_face" name="face">
                            <option value="front"<?php print (($edit_placement['face'] ?? 'front') === 'front') ? ' selected' : ''; ?>>Front</option>
                            <option value="rear"<?php print (($edit_placement['face'] ?? '') === 'rear') ? ' selected' : ''; ?>>Rear</option>
                        </select>

                        <label for="placement_category">Device category *</label>
                        <select id="placement_category" name="category">
                            <?php foreach ($categories as $key => $label) { ?>
                                <option value="<?php print icct_rack_h($key); ?>"<?php print (($edit_placement['category'] ?? 'generic') === $key) ? ' selected' : ''; ?>><?php print icct_rack_h($label); ?></option>
                            <?php } ?>
                        </select>

                        <label for="placement_asset">Asset tag</label>
                        <input id="placement_asset" name="asset_tag" type="text" maxlength="128" value="<?php print icct_rack_h($edit_placement['asset_tag'] ?? ''); ?>">

                        <label for="placement_power">Power feed</label>
                        <input id="placement_power" name="power_feed" type="text" maxlength="128" value="<?php print icct_rack_h($edit_placement['power_feed'] ?? ''); ?>" placeholder="A / B / UPS-1 etc.">

                        <label for="placement_notes">Notes</label>
                        <textarea id="placement_notes" name="notes"><?php print icct_rack_h($edit_placement['notes'] ?? ''); ?></textarea>

                        <div class="icct-form-actions">
                            <?php if ($edit_placement) { ?><a class="icct-button" href="icct_rack_admin.php?rack_id=<?php print (int)$selected_rack_id; ?>">New placement</a><?php } ?>
                            <button type="submit">Save placement</button>
                        </div>
                    </div>
                    <?php form_end(); ?>
                <?php } ?>
            </div>

            <div class="icct-card">
                <h3>Placed devices<?php print $selected_rack ? ' — ' . icct_rack_h($selected_rack['code']) : ''; ?></h3>
                <?php if ($selected_rack && cacti_sizeof($placements)) { ?>
                    <table class="icct-table">
                        <thead><tr><th>Device</th><th>Position</th><th>Category</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($placements as $p) {
                            $status = $p['host_description'] !== null
                                ? icct_rack_status_info(['status' => $p['host_status'], 'disabled' => $p['host_disabled']])
                                : ['label' => 'Missing from Cacti'];
                        ?>
                            <tr>
                                <td><strong><?php print icct_rack_h($p['label'] ?: ($p['host_description'] ?: 'Missing Cacti device')); ?></strong><br><?php print icct_rack_h($p['host_hostname'] ?: 'Host ID ' . (int)$p['host_id']); ?></td>
                                <td><?php print icct_rack_h(ucfirst($p['face'])); ?> U<?php print (int)$p['start_u']; ?>-<?php print (int)$p['start_u'] + (int)$p['height_u'] - 1; ?></td>
                                <td><?php print icct_rack_h($categories[$p['category']] ?? $p['category']); ?></td>
                                <td><?php print icct_rack_h($status['label']); ?></td>
                                <td class="actions">
                                    <a href="icct_rack_admin.php?rack_id=<?php print (int)$selected_rack_id; ?>&edit_placement=<?php print (int)$p['id']; ?>">Edit</a>
                                    <div style="margin-top:5px">
                                        <?php form_start('icct_rack_admin.php', 'delete_placement_' . (int)$p['id']); ?>
                                        <input type="hidden" name="action" value="delete_placement">
                                        <!-- FIX 2026-09-15: Protect placement removal with the Cacti CSRF token. -->
                                        <input type="hidden" name="__csrf_magic" value="<?php print icct_rack_h($csrf_token); ?>">
                                        <input type="hidden" name="id" value="<?php print (int)$p['id']; ?>">
                                        <input type="hidden" name="rack_id" value="<?php print (int)$selected_rack_id; ?>">
                                        <button type="submit" onclick="return confirm('Remove this device from the rack view? The Cacti device itself will NOT be deleted.');">Remove</button>
                                        <?php form_end(); ?>
                                    </div>
                                </td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                <?php } else { ?>
                    <div>No devices placed in the selected rack.</div>
                <?php } ?>
            </div>
        </div>
    </div>

    <div class="icct-card">
        <h3>Recent rack audit</h3>
        <table class="icct-table">
            <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>Source IP</th><th>Details</th></tr></thead>
            <tbody>
            <?php foreach ($audit as $a) { ?>
                <tr>
                    <td><?php print icct_rack_h($a['created_at']); ?></td>
                    <td><?php print icct_rack_h($a['username'] ?: ('User #' . (int)$a['user_id'])); ?></td>
                    <td><?php print icct_rack_h($a['action']); ?></td>
                    <td><?php print icct_rack_h($a['entity_type'] . ' #' . $a['entity_id']); ?></td>
                    <td><?php print icct_rack_h($a['ip_address']); ?></td>
                    <td class="icct-audit-details"><?php print icct_rack_h($a['details']); ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<?php
bottom_footer();
