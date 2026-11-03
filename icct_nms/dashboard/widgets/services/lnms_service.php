<?php

require_once __DIR__ . '/../../location/services/location_service.php';
/** LNMS map/radar nodes come only from Cacti's configured pollers. No subnet scans or IP geolocation. */
function icct_nms_lnms_site_assignments()
{
    $raw = db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?', [
        'lnms_poller_sites',
    ]);
    $value = $raw ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : [];
    return is_array($value) ? $value : [];
}
function icct_nms_lnms_save_sites($input)
{
    icct_backend_require_management(3);
    $values = $input['poller_sites'] ?? [];
    if (!is_array($values) || count($values) > 1000) {
        throw new InvalidArgumentException('Select a site for each configured LNMS poller.');
    }
    $pollers = array_column(db_fetch_assoc('SELECT id FROM poller'), 'id');
    $sites = array_column(db_fetch_assoc('SELECT id FROM sites'), 'id');
    $clean = [];
    foreach ($values as $id => $site) {
        if (
            !ctype_digit((string) $id) ||
            !in_array((int) $id, array_map('intval', $pollers), true) ||
            !is_scalar($site) ||
            !ctype_digit((string) $site)
        ) {
            throw new InvalidArgumentException('Select a configured poller and saved site.');
        }
        $site = (int) $site;
        if ($site && !in_array($site, array_map('intval', $sites), true)) {
            throw new InvalidArgumentException('Select an existing Cacti site.');
        }
        if ($site) {
            $clean[(int) $id] = $site;
        }
    }
    icct_backend_category_execute(
        'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON ' .
            'DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
        ['lnms_poller_sites', json_encode($clean, JSON_THROW_ON_ERROR)],
    );
    return 'LNMS poller locations saved.';
}
function icct_nms_lnms_poller_status($row, $interval, $now)
{
    if (trim((string) ($row['disabled'] ?? '')) !== '') {
        return 'Disabled';
    }
    $seen = strtotime((string) ($row['last_update'] ?? ''));
    if (!$seen || $seen <= 0 || $seen > $now + 60) {
        return 'Unknown';
    }
    return $now - $seen <= max(120, $interval * 3) ? 'Online' : 'Offline';
}
function icct_nms_lnms_data($physicalLinks = [])
{
    global $config;
    $local = (int) ($config['poller_id'] ?? 1);
    $assignments = icct_nms_lnms_site_assignments();
    $sites = array_column(
        db_fetch_assoc('SELECT id,name,latitude,longitude FROM sites'),
        null,
        'id',
    );
    $hosts = icct_nms_inventory();
    $gnss = icct_nms_gnss_readings();
    $nodes = [];
    $counts = ['total' => 0, 'online' => 0, 'offline' => 0, 'disabled' => 0, 'other' => 0];
    $interval = max(60, (int) read_config_option('poller_interval'));
    $now = time();
    foreach (
        db_fetch_assoc('SELECT id,name,hostname,disabled,last_update FROM poller ORDER BY id') as $poller
    ) {
        $id = (int) $poller['id'];
        $isLocal = $id === $local;
        $siteId = (int) ($assignments[$id] ?? 0);
        $locationSource = $siteId ? 'Assigned' : 'Unassigned';
        // A unique literal endpoint match may supply a native site. Shared agent addresses stay ambiguous.
        if (!$siteId) {
            $matches = array_values(
                array_filter(
                    $hosts,
                    static fn ($host) => strcasecmp(
                        trim((string) ($host['hostname'] ?? '')),
                        trim((string) $poller['hostname']),
                    ) === 0,
                ),
            );
            if (count($matches) === 1) {
                $siteId = (int) $matches[0]['site_id'];
                $locationSource = 'Device site';
            }
        }
        $site = $sites[$siteId] ?? null;
        $coordinates = $site
            ? icct_nms_map_coordinates($site['latitude'], $site['longitude'])
            : null;
        $fix = $gnss[$id] ?? null;
        if ($fix) {
            $coordinates = $fix['coordinates'];
            $locationSource = 'GNSS · ' . $fix['status'];
        }
        $status = icct_nms_lnms_poller_status($poller, $interval, $now);
        $node = [
            'id' => $id,
            'name' => trim((string) $poller['hostname']) ?: ($poller['name'] ?: 'Poller ' . $id),
            'address' => $poller['hostname'],
            'status' => $status,
            'local' => $isLocal,
            'lnms_path' => $isLocal
                ? (string) ($config['url_path'] ?? '/') . 'plugins/icct_nms/'
                : null,
            'site_id' => $site ? (int) $site['id'] : null,
            'site_name' => $site['name'] ?? 'Unassigned',
            'coordinates' => $coordinates,
            'location_source' => $fix ? $locationSource : ($site ? $locationSource : 'Unassigned'),
            'location_status' =>
                $fix['status'] ?? ($coordinates ? 'Site coordinates' : 'Unassigned'),
            'last_fix' => $fix['last_fix'] ?? null,
            'last_seen' => $poller['last_update'],
            'collector_url' => is_realm_allowed(3)
                ? (string) ($config['url_path'] ?? '/') . 'pollers.php?action=edit&id=' . $id
                : null,
        ];
        $nodes[] = $node;
        $counts['total']++;
        $counts[
            $status === 'Online'
                ? 'online'
                : ($status === 'Offline'
                    ? 'offline'
                    : ($status === 'Disabled'
                        ? 'disabled'
                        : 'other'))
        ]++;
    }
    $center = null;
    foreach ($nodes as $node) {
        if ($node['local']) {
            $center = $node;
            break;
        }
    }
    return [
        'nodes' => $nodes,
        'center' => $center,
        'counts' => $counts,
        'links' => icct_nms_lnms_links($nodes, $hosts, $physicalLinks),
        'updated' => date(DATE_ATOM),
    ];
}

/** Cross-collector links require an existing manual or fresh CDP/LLDP device connection. */
function icct_nms_lnms_links($nodes, $hosts, $physicalLinks)
{
    $pollers = array_column($nodes, null, 'id');
    $devices = array_column($hosts, null, 'id');
    $out = [];
    foreach ($physicalLinks as $link) {
        $a = $devices[$link['source']] ?? null;
        $b = $devices[$link['target']] ?? null;
        if (!$a || !$b) {
            continue;
        }
        $source = (int) $a['poller_id'];
        $target = (int) $b['poller_id'];
        if ($source === $target || !isset($pollers[$source], $pollers[$target])) {
            continue;
        }
        $readings = [
            icct_nms_link_reading(
                $a['id'],
                $link['source_port'] ?? '',
                (int) ($link['source_ifindex'] ?? 0),
            ),
            icct_nms_link_reading($b['id'], $link['target_port'] ?? ''),
        ];
        $states = array_column($readings, 'status');
        $status = in_array('Down', $states, true)
            ? 'Down'
            : (in_array('Disabled', $states, true)
                ? 'Disabled'
                : (count($states) === 2 && count(array_filter($states, fn ($s) => $s === 'Up')) === 2
                    ? 'Up'
                    : 'Unknown'));
        $out[] = [
            'source' => $source,
            'target' => $target,
            'source_device' => (int) $a['id'],
            'target_device' => (int) $b['id'],
            'label' => $link['label'] ?? 'Configured connection',
            'status' => $status,
            'protocol' => $link['protocol'] ?? 'manual',
        ];
    }
    return $out;
}
