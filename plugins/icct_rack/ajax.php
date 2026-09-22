<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */

chdir('../..');
include('./include/auth.php');
include_once($config['base_path'] . '/plugins/icct_rack/lib/functions.php');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function icct_rack_json($payload, $status = 200) {
    http_response_code((int)$status);
    print json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset_request_var('action') ? (string)get_nfilter_request_var('action') : '';

if ($action === 'status') {
    $rack_id = isset_request_var('rack_id') ? (int)get_filter_request_var('rack_id') : 0;
    $rack = icct_rack_get_rack($rack_id);
    if (!$rack || ($rack['enabled'] ?? '') !== 'on') {
        /* FIX 2026-09-15: Status refresh must not read disabled racks. */
        icct_rack_json(['ok' => false, 'error' => 'Rack not found.'], 404);
    }

    $placements = icct_rack_get_placements($rack_id);
    $items = [];
    foreach ($placements as $p) {
        if ($p['host_description'] === null) {
            $status = ['key' => 'missing', 'label' => 'Missing from Cacti'];
        } else {
            $status = icct_rack_status_info([
                'status' => $p['host_status'],
                'disabled' => $p['host_disabled'],
            ]);
        }
        $items[] = [
            'placement_id' => (int)$p['id'],
            'host_id' => (int)$p['host_id'],
            'status_key' => $status['key'],
            'status_label' => $status['label'],
        ];
    }

    icct_rack_json([
        'ok' => true,
        'placements' => $items,
        'summary' => icct_rack_status_counts($placements),
        'refreshed_at' => date('Y-m-d H:i:s'),
    ]);
}

if ($action === 'device') {
    $host_id = isset_request_var('host_id') ? (int)get_filter_request_var('host_id') : 0;
    /* FIX 2026-09-15: Only expose details for a host placed in an enabled rack. */
    $placed = db_fetch_cell_prepared(
        "SELECT COUNT(*) FROM plugin_icct_rack_placements AS p
         INNER JOIN plugin_icct_rack_racks AS r ON r.id = p.rack_id AND r.enabled = 'on'
         INNER JOIN host AS h ON h.id = p.host_id AND h.deleted = ''
         WHERE p.host_id = ?",
        [$host_id]
    );
    if ((int)$placed !== 1) {
        icct_rack_json(['ok' => false, 'error' => 'Device is not placed in an enabled rack.'], 404);
    }
    $host = icct_rack_get_host($host_id);
    if (!$host) {
        icct_rack_json(['ok' => false, 'error' => 'Cacti device not found.'], 404);
    }

    $status = icct_rack_status_info($host);
    $device = [
        'description' => (string)($host['description'] ?? ''),
        'hostname' => (string)($host['hostname'] ?? ''),
        'status' => $status['label'],
        'snmp_sysName' => (string)($host['snmp_sysName'] ?? ''),
        'snmp_sysLocation' => (string)($host['snmp_sysLocation'] ?? ''),
        'status_last_error' => (string)($host['status_last_error'] ?? ''),
    ];

    icct_rack_json([
        'ok' => true,
        'device' => $device,
        'edit_url' => $config['url_path'] . 'host.php?action=edit&id=' . (int)$host_id,
    ]);
}

if ($action === 'move') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        icct_rack_json(['ok' => false, 'error' => 'POST required.'], 405);
    }

    /* FIX 2026-09-15: Explicitly verify the CSRF token for the JSON write endpoint. */
    if (!function_exists('csrf_check_tokens') || !csrf_check_tokens($_POST['__csrf_magic'] ?? '')) {
        icct_rack_json(['ok' => false, 'error' => 'Invalid or expired security token.'], 403);
    }

    if (!icct_rack_can_manage()) {
        icct_rack_json(['ok' => false, 'error' => 'You do not have permission to move rack devices.'], 403);
    }

    $placement_id = icct_rack_request_int('placement_id');
    $rack_id = icct_rack_request_int('rack_id');
    $start_u = icct_rack_request_int('start_u');
    $face = icct_rack_request_string('face', 'front');

    $result = icct_rack_move_placement($placement_id, $rack_id, $start_u, $face);
    if (!$result['ok']) {
        icct_rack_json(['ok' => false, 'errors' => $result['errors'] ?? ['Move failed.']], 422);
    }

    icct_rack_json(['ok' => true]);
}

icct_rack_json(['ok' => false, 'error' => 'Unknown action.'], 400);
