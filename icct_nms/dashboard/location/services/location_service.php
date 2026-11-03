<?php

/** Configurable GNSS sources owned by native pollers. No receiver secrets are copied here. */
function icct_nms_gnss_transports()
{
    return [
        'snmp' => 'SNMP coordinate OIDs',
        'tcp' => 'NMEA over TCP',
        'udp' => 'NMEA over UDP',
        'serial' => 'NMEA serial / USB',
        'file' => 'Local NMEA file',
        'gpsd' => 'gpsd JSON',
    ];
}
function icct_nms_gnss_validate($input)
{
    $v = [];
    $v['transport'] = $input['transport'] ?? '';
    if (!isset(icct_nms_gnss_transports()[$v['transport']])) {
        throw new InvalidArgumentException('Choose a GNSS transport.');
    }
    $v['interval'] = icct_backend_topology_integer(
        $input['interval'] ?? 30,
        5,
        3600,
        'GNSS collection interval',
    );
    $v['max_age'] = icct_backend_topology_integer(
        $input['max_age'] ?? 120,
        10,
        86400,
        'GNSS fix maximum age',
    );
    $v['timeout'] = icct_backend_topology_integer($input['timeout'] ?? 3, 1, 10, 'GNSS timeout');
    if ($v['max_age'] < $v['interval']) {
        throw new InvalidArgumentException(
            'Fix maximum age must be at least the collection interval.',
        );
    }
    $v['address'] = trim((string) ($input['address'] ?? ''));
    $v['path'] = trim((string) ($input['path'] ?? ''));
    if (in_array($v['transport'], ['tcp', 'udp', 'gpsd'], true)) {
        if (
            strlen($v['address']) > 253 ||
            ($v['address'] !== '' &&
                !filter_var($v['address'], FILTER_VALIDATE_IP) &&
                !filter_var($v['address'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))
        ) {
            throw new InvalidArgumentException('Enter a GNSS receiver IP or hostname.');
        }
        if ($v['address'] === '' && $v['transport'] !== 'udp') {
            throw new InvalidArgumentException('GNSS receiver address is required.');
        }
        if (
            $v['transport'] === 'udp' &&
            $v['address'] !== '' &&
            !filter_var($v['address'], FILTER_VALIDATE_IP)
        ) {
            throw new InvalidArgumentException('UDP sender filtering requires an IP address.');
        }
        $v['port'] = icct_backend_topology_integer(
            $input['port'] ?? ($v['transport'] === 'gpsd' ? 2947 : 10110),
            1,
            65535,
            'GNSS port',
        );
        $v['bind'] = trim((string) ($input['bind'] ?? '0.0.0.0'));
        if (!filter_var($v['bind'], FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException('Enter a local UDP bind IP.');
        }
    }
    if ($v['transport'] === 'file') {
        if (
            !str_starts_with($v['path'], '/var/lib/icct-nms/gnss/') ||
            str_contains($v['path'], '..') ||
            str_contains($v['path'], "\0")
        ) {
            throw new InvalidArgumentException(
                'Place the NMEA input file under /var/lib/icct-nms/gnss/.',
            );
        }
    }
    if ($v['transport'] === 'serial') {
        if (
            !preg_match(
                '~^/dev/(?:tty(?:USB|ACM|S)[0-9]+|serial/by-id/[A-Za-z0-9_.:+-]+)$~D',
                $v['path'],
            )
        ) {
            throw new InvalidArgumentException(
                'Choose a serial device under /dev/ttyUSB, ttyACM, ttyS or /dev/serial/by-id/.',
            );
        }
        $v['baud'] = icct_backend_topology_integer(
            $input['baud'] ?? 9600,
            1200,
            115200,
            'GNSS baud rate',
        );
        if (!in_array($v['baud'], [1200, 2400, 4800, 9600, 19200, 38400, 57600, 115200], true)) {
            throw new InvalidArgumentException('Choose a supported serial baud rate.');
        }
    }
    if ($v['transport'] === 'snmp') {
        $v['host_id'] = icct_backend_topology_integer(
            $input['host_id'] ?? 0,
            1,
            16777215,
            'GNSS device',
        );
        icct_backend_require_device_access($v['host_id']);
        foreach (['latitude_oid', 'longitude_oid', 'fix_oid'] as $key) {
            $oid = trim((string) ($input[$key] ?? ''));
            if (strlen($oid) > 255 || !preg_match('/^\.?[0-9]+(?:\.[0-9]+)+$/D', $oid)) {
                throw new InvalidArgumentException('Enter a numeric ' . $key . '.');
            }
            $v[$key] = $oid;
        }
        $v['scale'] = icct_backend_topology_integer(
            $input['scale'] ?? 1,
            1,
            1000000000,
            'Coordinate divisor',
        );
        $v['fix_values'] = array_values(
            array_filter(
                array_map('trim', explode(',', (string) ($input['fix_values'] ?? '1'))),
                'strlen',
            ),
        );
        if (
            !$v['fix_values'] ||
            count($v['fix_values']) > 16 ||
            array_filter($v['fix_values'], fn ($x) => strlen($x) > 32)
        ) {
            throw new InvalidArgumentException(
                'Enter valid receiver fix-status values, for example 1 or A.',
            );
        }
    }
    return $v;
}
function icct_nms_gnss_save($input)
{
    icct_backend_require_management(3);
    $poller = icct_backend_topology_integer($input['poller_id'] ?? 0, 1, 4294967295, 'Collector');
    if (!db_fetch_cell_prepared('SELECT id FROM poller WHERE id=?', [$poller])) {
        throw new InvalidArgumentException('Select a configured collector.');
    }
    if (empty($input['enabled'])) {
        icct_backend_category_execute(
            'UPDATE plugin_icct_nms_gnss_sources SET enabled=0,updated_by=?,updated_at=NOW() WHERE poller_id=?',
            [icct_backend_current_user_id(), $poller],
        );
        return;
    }
    $v = icct_nms_gnss_validate($input);
    if (
        $v['transport'] === 'snmp' &&
        (int) db_fetch_cell_prepared('SELECT poller_id FROM host WHERE id=?', [$v['host_id']]) !==
            $poller
    ) {
        throw new InvalidArgumentException('Assign the GNSS device to the selected collector.');
    }
    icct_backend_category_execute(
        'INSERT INTO plugin_icct_nms_gnss_sources(poller_id,enabled,config_json,updated_by,updated_at) VALUES(?,1,?,?,NOW()) ON DUPLICATE KEY UPDATE enabled=1,config_json=VALUES(config_json),updated_by=VALUES(updated_by),updated_at=NOW()',
        [$poller, json_encode($v, JSON_THROW_ON_ERROR), icct_backend_current_user_id()],
    );
}
function icct_nms_gnss_coordinates($lat, $lon)
{
    if (
        !is_numeric($lat) ||
        !is_numeric($lon) ||
        !is_finite((float) $lat) ||
        !is_finite((float) $lon) ||
        (float) $lat < -90 ||
        (float) $lat > 90 ||
        (float) $lon < -180 ||
        (float) $lon > 180
    ) {
        throw new RuntimeException('GNSS coordinates are outside latitude/longitude limits.');
    }
    return [(float) $lat, (float) $lon];
}
function icct_nms_gnss_probe($v, $poller)
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('GNSS collection runs only on its assigned collector.');
    }
    if ($v['transport'] === 'snmp') {
        $host = db_fetch_row_prepared(
            "SELECT * FROM host WHERE id=? AND poller_id=? AND disabled='' AND deleted=''",
            [$v['host_id'], $poller],
        );
        if (!$host || !icct_backend_protocol_enabled($host['id'], 'snmp')) {
            throw new RuntimeException('GNSS SNMP device is unavailable or disabled.');
        }
        $session = icct_backend_nd_discovery_session($host);
        $deadline = microtime(true) + $v['timeout'];
        try {
            $quality = icct_backend_nd_snmp_scalar($session, $v['fix_oid'], $deadline)['value'];
            if (!in_array((string) $quality, $v['fix_values'], true)) {
                throw new RuntimeException('GNSS receiver reports no valid fix.');
            }
            $lat = icct_backend_nd_snmp_scalar($session, $v['latitude_oid'], $deadline)['value'];
            $lon = icct_backend_nd_snmp_scalar($session, $v['longitude_oid'], $deadline)['value'];
            [$lat, $lon] = icct_nms_gnss_coordinates(
                is_numeric($lat) ? $lat / $v['scale'] : null,
                is_numeric($lon) ? $lon / $v['scale'] : null,
            );
            return ['latitude' => $lat, 'longitude' => $lon, 'source_time' => time()];
        } finally {
            $session->close();
        }
    }
    if ($v['transport'] === 'file') {
        $path = realpath($v['path']);
        $base = realpath('/var/lib/icct-nms/gnss');
        if (
            !$path ||
            !$base ||
            !str_starts_with($path, $base . '/') ||
            !is_file($path) ||
            filesize($path) > 65536 ||
            time() - filemtime($path) > $v['max_age']
        ) {
            throw new RuntimeException(
                'GNSS input file is missing, stale or outside the configured folder.',
            );
        }
    }
    $pipes = [];
    $process = proc_open(
        ['/usr/bin/python3', __DIR__ . '/../cli/gnss_probe.py'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Python 3 GNSS reader could not start.');
    }
    fwrite($pipes[0], json_encode($v, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    $output = '';
    $deadline = microtime(true) + $v['timeout'] + 2;
    try {
        while (true) {
            $output .= stream_get_contents($pipes[1]);
            if (!proc_get_status($process)['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('GNSS reader timed out.');
            }
            usleep(50000);
        }
        $output .= stream_get_contents($pipes[1]);
        $fix = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($fix) || isset($fix['error'])) {
            throw new RuntimeException(
                substr($fix['error'] ?? 'No valid GNSS fix received.', 0, 255),
            );
        }
        icct_nms_gnss_coordinates($fix['latitude'] ?? null, $fix['longitude'] ?? null);
        $age = time() - (float) ($fix['source_time'] ?? 0);
        if ($age > $v['max_age'] || $age < -30) {
            throw new RuntimeException('GNSS fix timestamp is stale or in the future.');
        }
        return $fix;
    } finally {
        if (proc_get_status($process)['running']) {
            proc_terminate($process);
        }
        fclose($pipes[1]);
        proc_close($process);
    }
}
function icct_nms_gnss_collect($poller)
{
    $lock = 'icct_gnss_' . (int) $poller;
    if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?,0)', [$lock]) !== 1) {
        return;
    }
    try {
        $source = db_fetch_row_prepared(
            "SELECT s.*,r.last_attempt FROM plugin_icct_nms_gnss_sources s LEFT JOIN plugin_icct_nms_gnss_readings r ON r.poller_id=s.poller_id JOIN poller p ON p.id=s.poller_id WHERE s.poller_id=? AND s.enabled=1 AND p.disabled=''",
            [$poller],
        );
        if (!$source) {
            return;
        }
        $v = json_decode($source['config_json'], true, 32, JSON_THROW_ON_ERROR);
        if (time() - strtotime($source['last_attempt'] ?? '1970-01-01') < $v['interval']) {
            return;
        }
        try {
            $fix = icct_nms_gnss_probe($v, $poller);
            icct_backend_category_execute(
                "INSERT INTO plugin_icct_nms_gnss_readings(poller_id,latitude,longitude,status,last_attempt,last_fix,source_time,last_error) VALUES(?,?,?,'valid',NOW(),NOW(),?,'') ON DUPLICATE KEY UPDATE latitude=VALUES(latitude),longitude=VALUES(longitude),status='valid',last_attempt=NOW(),last_fix=NOW(),source_time=VALUES(source_time),last_error=''",
                [
                    $poller,
                    $fix['latitude'],
                    $fix['longitude'],
                    date('Y-m-d H:i:s', (int) $fix['source_time']),
                ],
            );
        } catch (Throwable $e) {
            icct_backend_category_execute(
                "INSERT INTO plugin_icct_nms_gnss_readings(poller_id,status,last_attempt,last_error) VALUES(?,'no_fix',NOW(),?) ON DUPLICATE KEY UPDATE status='no_fix',last_attempt=NOW(),last_error=VALUES(last_error)",
                [$poller, substr($e->getMessage(), 0, 255)],
            );
        }
    } finally {
        db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}
function icct_nms_gnss_readings()
{
    $out = [];
    foreach (
        db_fetch_assoc(
            'SELECT s.poller_id,s.config_json,r.latitude,r.longitude,r.status,r.last_fix,r.source_time,r.last_error FROM plugin_icct_nms_gnss_sources s LEFT JOIN plugin_icct_nms_gnss_readings r ON r.poller_id=s.poller_id WHERE s.enabled=1',
        ) as $row
    ) {
        $v = json_decode($row['config_json'], true, 32, JSON_THROW_ON_ERROR);
        $fresh =
            $row['status'] === 'valid' &&
            $row['source_time'] &&
            time() - strtotime($row['source_time']) >= -30 &&
            time() - strtotime($row['source_time']) <= (int) $v['max_age'];
        $row['fresh'] = $fresh;
        $row['coordinates'] = $fresh
            ? icct_nms_gnss_coordinates($row['latitude'], $row['longitude'])
            : null;
        $row['status'] = $fresh ? 'Current' : ($row['last_fix'] ? 'Stale / no fix' : 'No fix');
        $out[(int) $row['poller_id']] = $row;
    }
    return $out;
}
