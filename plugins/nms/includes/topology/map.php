<?php
/** Geographic topology reads native Cacti sites and device permissions. */

/** Reject missing/default or out-of-range native site coordinates. */
function nms_map_coordinates($latitude, $longitude)
{
    if (!is_numeric($latitude) || !is_numeric($longitude)) return null;
    $lat = (float) $latitude;
    $lon = (float) $longitude;
    if (!is_finite($lat) || !is_finite($lon) || abs($lat) > 90 || abs($lon) > 180 || ($lat == 0 && $lon == 0)) return null;
    return [$lat, $lon];
}

/** Convert known percentage sources; never confuse CPU load or poll availability with percentages. */
function nms_map_metrics($readings)
{
    $cpu = $readings['cpu_percent'] ?? $readings['5min_cpu'] ?? null;
    if ($cpu === null && isset($readings['ssCpuIdle'])) $cpu = 100 - (float) $readings['ssCpuIdle'];
    $memory = $readings['memory_percent'] ?? null;
    if ($memory === null && isset($readings['mem_total'], $readings['mem_free']) && $readings['mem_total'] > 0) {
        $memory = 100 * (1 - $readings['mem_free'] / $readings['mem_total']);
    }
    $result = ['cpu' => $cpu, 'memory' => $memory, 'packet_loss' => $readings['packet_loss'] ?? null];
    foreach ($result as $key => $value) $result[$key] = is_numeric($value) && $value >= 0 && $value <= 100 ? round((float) $value, 2) : null;
    return $result;
}

/** Group only accessible, nondeleted devices by their native Cacti site. */
function nms_map_data()
{
    $visible = nms_visible_host_sql();
    $rows = db_fetch_assoc("SELECT h.id, h.description, h.hostname, h.snmp_sysDescr, h.status, h.disabled, h.site_id,
        h.cur_time, h.total_polls, h.last_updated, cat.name AS category,
        COALESCE(NULLIF(m.serial_number,''), CASE WHEN inv.status IN ('ok','changed') THEN inv.observed_value END) AS serial,
        s.name AS site_name, s.city, s.state, s.country, s.address1, s.latitude, s.longitude FROM host h
        LEFT JOIN sites s ON s.id = h.site_id
        LEFT JOIN plugin_nms_device_classification cls ON cls.host_id=h.id
        LEFT JOIN plugin_nms_categories cat ON cat.id=cls.category_id
        LEFT JOIN plugin_nms_device_metadata m ON m.host_id=h.id
        LEFT JOIN plugin_nms_device_inventory inv ON inv.host_id=h.id AND inv.inventory_key='serial_number'
        WHERE h.deleted = '' AND $visible ORDER BY s.name, h.description");
    $fresh_seconds = max(60, nms_poller_interval() * 2);
    $readings = [];
    foreach (db_fetch_assoc("SELECT p.host_id,p.parameter_name,p.numeric_value FROM plugin_nms_device_parameters p
        JOIN host h ON h.id=p.host_id WHERE h.deleted = '' AND $visible
        AND p.last_seen >= DATE_SUB(NOW(), INTERVAL $fresh_seconds SECOND)
        AND p.parameter_name IN ('5min_cpu','ssCpuIdle','cpu_percent','memory_percent','mem_total','mem_free','packet_loss')
        ORDER BY p.last_seen DESC,p.local_data_id DESC") as $reading) {
        if (!isset($readings[$reading['host_id']][$reading['parameter_name']])) $readings[$reading['host_id']][$reading['parameter_name']] = $reading['numeric_value'];
    }
    $alarms = [];
    foreach (db_fetch_assoc("SELECT i.host_id,i.title,i.message,i.severity FROM plugin_nms_incidents i
        JOIN host h ON h.id=i.host_id WHERE h.deleted = '' AND $visible AND i.status IN ('open','acknowledged')
        AND NOT EXISTS (SELECT 1 FROM plugin_nms_incidents newer WHERE newer.host_id=i.host_id
        AND newer.status IN ('open','acknowledged') AND (newer.last_seen>i.last_seen OR (newer.last_seen=i.last_seen AND newer.id>i.id)))") as $alarm) {
        $alarms[$alarm['host_id']] = $alarm;
    }
    $sites = [];
    $unlocated = [];
    foreach ($rows as $row) {
        $device = ['id' => (int) $row['id'], 'name' => $row['description'],
            'status' => $row['disabled'] === 'on' ? 'Disabled' : ([0 => 'Unknown', 1 => 'Down', 2 => 'Recovering', 3 => 'Up'][(int) $row['status']] ?? 'Unknown')];
        $metrics = nms_map_metrics($readings[$row['id']] ?? []);
        $fresh_host = !empty($row['last_updated']) && strtotime($row['last_updated']) >= time() - $fresh_seconds;
        $device += $metrics + ['address' => $row['hostname'] ?? '', 'system' => $row['snmp_sysDescr'] ?? '',
            'category' => $row['category'] ?? '', 'serial' => $row['serial'] ?? '',
            'response_ms' => $fresh_host && $device['status'] === 'Up' && ($row['total_polls'] ?? 0) > 0 ? (float) $row['cur_time'] : null,
            'alarm' => $alarms[$row['id']] ?? null];
        $coordinates = nms_map_coordinates($row['latitude'], $row['longitude']);
        if (!$coordinates || !$row['site_name']) {
            $device['site'] = $row['site_name'] ?: 'No site';
            $unlocated[] = $device;
            continue;
        }
        $id = (int) $row['site_id'];
        if (!isset($sites[$id])) $sites[$id] = ['id' => $id, 'name' => $row['site_name'], 'coordinates' => $coordinates, 'location' => implode(', ', array_filter([$row['address1'] ?? '', $row['city'] ?? '', $row['state'] ?? '', $row['country'] ?? ''])), 'devices' => []];
        $sites[$id]['devices'][] = $device;
    }
    return ['sites' => array_values($sites), 'unlocated' => $unlocated];
}

/** Build a bounded WMS request; destination and layer come only from administrator configuration. */
function nms_map_wms_parameters($input, $layer)
{
    if (!isset($input['bbox']) || !is_string($input['bbox'])) throw new InvalidArgumentException('Invalid map bounds.');
    $bbox = explode(',', $input['bbox']);
    if (count($bbox) !== 4) throw new InvalidArgumentException('Invalid map bounds.');
    foreach ($bbox as $v) if (!is_numeric($v) || !is_finite((float) $v) || abs((float) $v) > 20037509) throw new InvalidArgumentException('Invalid map bounds.');
    if ((float) $bbox[0] >= (float) $bbox[2] || (float) $bbox[1] >= (float) $bbox[3]) throw new InvalidArgumentException('Invalid map bounds.');
    $size = [];
    foreach (['width', 'height'] as $key) {
        $value = filter_var($input[$key] ?? 256, FILTER_VALIDATE_INT);
        if ($value === false || $value < 1 || $value > 2048) throw new InvalidArgumentException('Invalid map size.');
        $size[$key] = $value;
    }
    return ['service' => 'WMS', 'version' => '1.1.1', 'request' => 'GetMap', 'layers' => $layer,
        'styles' => '', 'srs' => 'EPSG:3857', 'bbox' => implode(',', $bbox), 'width' => $size['width'], 'height' => $size['height'],
        'format' => 'image/png', 'transparent' => 'false', 'bgcolor' => '0xe8eef1'];
}

/** Relay raster tiles after Cacti authentication without exposing the GeoServer endpoint. */
function nms_map_tile()
{
    global $config;
    $url = $config['nms_geoserver_wms_url'] ?? '';
    $layer = $config['nms_geoserver_layer'] ?? '';
    try {
        if (!$url || !$layer || !preg_match('~^https?://~i', $url) || !function_exists('curl_init')) throw new RuntimeException('GeoServer is not configured.');
        $params = nms_map_wms_parameters($_GET, $layer);
        session_write_close();
        $handle = curl_init($url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($params));
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
        $body = curl_exec($handle);
        $code = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if ($code !== 200 || !is_string($body) || substr($body, 0, 8) !== "\x89PNG\r\n\x1a\n") throw new RuntimeException('GeoServer tile unavailable.');
        header('Content-Type: image/png');
        header('Cache-Control: private, max-age=300');
        print $body;
    } catch (Throwable $error) {
        http_response_code($error instanceof InvalidArgumentException ? 400 : 503);
        header('Content-Type: text/plain');
        print $error instanceof InvalidArgumentException ? $error->getMessage() : 'GeoServer map unavailable.';
    }
}
