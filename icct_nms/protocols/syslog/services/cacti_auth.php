<?php

/** Attribute web authentication to the main Cacti server, independent of the browser IP. */
function icct_nms_auth_server()
{
    $hosts = db_fetch_assoc("SELECT h.id,h.hostname,d.source_address
        FROM host h JOIN plugin_icct_nms_syslog_devices d ON d.host_id=h.id
        WHERE h.deleted='' AND h.disabled='' AND h.poller_id=1 AND d.enabled=1");
    $addresses = ['localhost', 'localhost.', '127.0.0.1', '::1', strtolower((string) gethostname())];
    foreach (function_exists('net_get_interfaces') ? (net_get_interfaces() ?: []) : [] as $interface) {
        foreach ($interface['unicast'] ?? [] as $address) {
            if (!empty($address['address'])) {
                $addresses[] = strtolower($address['address']);
            }
        }
    }
    $local = array_values(array_filter($hosts, static fn($host) =>
        in_array(strtolower($host['hostname']), $addresses, true) &&
        icct_backend_protocol_enabled($host['id'], 'syslog')));
    return count($local) === 1 ? $local[0] : null;
}

function icct_nms_auth_event($host, $outcome, $username, $ip, $time, $identity)
{
    $failed = $outcome === 'login failed';
    $message = sprintf('Cacti %s for user %s from %s', $outcome,
        json_encode((string) $username, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE),
        filter_var($ip, FILTER_VALIDATE_IP) ?: 'unknown');
    icct_backend_syslog_execute(
        "INSERT INTO plugin_icct_nms_syslog_events
        (host_id,event_origin,event_time,received_at,last_seen,source_ip,source_host,transport,
         facility_code,facility,severity_code,severity,nms_severity,program,message,fingerprint,timestamp_kind)
        VALUES (?,'syslog',?,NOW(),?,?,?,'internal',4,'auth',?,?,?,'cacti-auth',?,?,'receiver')",
        [$host['id'], $time, $time, $host['source_address'], $host['hostname'],
         $failed ? 4 : 6, $failed ? 'warning' : 'informational',
         icct_backend_syslog_nms_severity($failed ? 4 : 6), $message, hash('sha256', $identity)]
    );
}

/** Commit the time boundary and its events together. Leave the current second open for writers. */
function icct_nms_auth_collect()
{
    if (icct_backend_inventory_collector_id() !== 1 || !($host = icct_nms_auth_server())) {
        return;
    }
    icct_backend_syslog_execute('START TRANSACTION', []);
    try {
        icct_backend_syslog_execute("INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at)
            VALUES ('cacti_auth_since',NOW() - INTERVAL 1 DAY,NOW())
            ON DUPLICATE KEY UPDATE meta_key=VALUES(meta_key)", []);
        $since = db_fetch_cell("SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key='cacti_auth_since' FOR UPDATE");
        $until = db_fetch_cell('SELECT NOW()');
        $rows = db_fetch_assoc_prepared('SELECT username,user_id,`time`,result,ip FROM user_log
            WHERE `time`>=? AND `time`<? AND result IN (0,1,2) ORDER BY `time`,username,user_id', [$since, $until]);
        if (!is_array($rows) || !$since || !$until) {
            throw new RuntimeException('Could not read the Cacti authentication log.');
        }
        $outcomes = ['login failed', 'login succeeded', 'cookie login'];
        foreach ($rows as $row) {
            icct_nms_auth_event($host, $outcomes[$row['result']], $row['username'], $row['ip'], $row['time'],
                json_encode([$row['username'], $row['user_id'], $row['time']], JSON_THROW_ON_ERROR));
        }
        icct_backend_syslog_execute("UPDATE plugin_icct_nms_meta SET meta_value=?,updated_at=NOW()
            WHERE meta_key='cacti_auth_since'", [$until]);
        icct_backend_syslog_execute('COMMIT', []);
    } catch (Throwable $error) {
        db_execute('ROLLBACK');
        throw $error;
    }
}

function icct_nms_auth_logout()
{
    try {
        $userId = (int) ($_SESSION['sess_user_id'] ?? 0);
        if (!$userId) {
            return;
        }
        require_once __DIR__ . '/../../../shared/services/bootstrap.php';
        icct_nms_backend();
        $host = icct_nms_auth_server();
        $username = db_fetch_cell_prepared('SELECT username FROM user_auth WHERE id=?', [$userId]);
        if ($host && $username !== false && $username !== null && $username !== '') {
            icct_nms_auth_event($host, 'logout', $username, $_SERVER['REMOTE_ADDR'] ?? '',
                db_fetch_cell('SELECT NOW()'), bin2hex(random_bytes(16)));
        }
    } catch (Throwable $error) {
        cacti_log('ICCT NMS logout audit: ' . $error->getMessage(), false, 'ICCT NMS');
    }
}
