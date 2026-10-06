<?php
/** ICCT-owned diagnostics queue services, derived from the existing ICCT NMS implementation. */

require_once __DIR__ . '/diagnostics_redis.php';

/** Reused Inventory service: diag authorize job. */
function icct_backend_diag_authorize_job($job)
{
    $user = (int) $job['user_id'];
    if (
        $user < 1 ||
        db_fetch_cell_prepared('SELECT enabled FROM user_auth WHERE id=?', [$user]) !== 'on' ||
        !is_realm_allowed(3, $user) ||
        !is_device_allowed((int) $job['host_id'], $user)
    ) {
        throw new RuntimeException(
            'Request owner no longer has permission to run this diagnostic.'
        );
    }
    // CLI listeners do not load the web filename map. Check the registered plugin realm
    // for the explicit owner, avoiding stale session caches in a long-running process.
    $realm=(int)db_fetch_cell_prepared("SELECT id FROM plugin_realms WHERE plugin=? AND FIND_IN_SET(?,file)",['icct_nms','diagnostics.php']);
    if(!$realm || !is_realm_allowed($realm+100,$user)){
        throw new RuntimeException('ICCT NMS diagnostic page access has been revoked.');
    }
}

/** Reused Inventory service: diag dispatch. */
function icct_backend_diag_dispatch()
{
    global $config;
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux') {
        return;
    }
    // A managed service owns startup/recovery; do not race it from each poller cycle.
    $launcher = $config['icct_backend_diagnostic_listener_launcher'] ?? 'poller';
    if ($launcher === 'service') {
        return;
    }
    if ($launcher !== 'poller') {
        throw new RuntimeException('Invalid ICCT NMS listener launcher; use poller or service.');
    }
    require_once $config['base_path'] . '/lib/poller.php';
    // Both executable and script are installation paths, never request parameters.
    exec_background(PHP_BINARY, '-q ' . escapeshellarg(ICCT_NMS_ROOT . '/inventory/diagnostics/cli/diagnostic_listener.php'));
}

/** Reused Inventory service: diag execution context. */
function icct_backend_diag_execution_context($job, $collector)
{
    icct_backend_diag_authorize_job($job);
    if (
        (int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory=?', [
            'icct_nms'
        ]) !== 1
    ) {
        throw new RuntimeException('ICCT NMS was disabled; diagnostic result was not accepted.');
    }
    if (!db_fetch_cell_prepared("SELECT id FROM poller WHERE id=? AND disabled=''", [$collector])) {
        throw new RuntimeException(
            'Assigned collector is unavailable; diagnostic result was not accepted.'
        );
    }
    $row = icct_backend_diag_assignment((int) $job['host_id'], $job['tool']);
    if (
        (int) $row['poller_id'] !== $collector ||
        !hash_equals($job['config_hash'], icct_backend_diag_signature($row))
    ) {
        throw new RuntimeException(
            'Device, profile or collector changed after submission. Run a new test.'
        );
    }
    return $row;
}

/** Reused Inventory service: diag job. */
function icct_backend_diag_job($id)
{
    $job = db_fetch_row_prepared(
        'SELECT id,host_id,user_id,poller_id,tool,config_hash,status,requested_at,started_at,finished_at FROM plugin_icct_nms_diagnostic_jobs WHERE id=? AND user_id=?',
        [(int) $id, icct_backend_current_user_id()]
    );
    if (!$job) {
        throw new RuntimeException('Diagnostic request is unavailable for this account.');
    }
    $cached=icct_backend_diag_redis_result($job);
    $job['result_json']=$cached ?? (string)db_fetch_cell_prepared('SELECT result_json FROM plugin_icct_nms_diagnostic_jobs WHERE id=? AND user_id=?',[(int)$id,icct_backend_current_user_id()]);
    icct_backend_require_device_access((int) $job['host_id']);
    if (
        ($job['status'] === 'queued' && strtotime($job['requested_at']) < time() - 15) ||
        ($job['status'] === 'running' && strtotime($job['started_at']) < time() - 120)
    ) {
        $job['status'] = 'expired';
        $job['result_json'] = json_encode([
            'output' =>
                'The collector did not start or finish this test in time. Check its diagnostic runner and retry. This test will not be repeated automatically.'
        ]);
    }
    return $job;
}

/** Reused Inventory service: diag poll. */
function icct_backend_diag_poll()
{
    global $config;
    if (PHP_SAPI !== 'cli') {
        return;
    }

    require_once $config['base_path'] . '/lib/auth.php';
    $collector = icct_backend_inventory_collector_id();
    $lock = 'icct_backend_diag_worker_' . $collector;
    if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
        return;
    }
    try {
        // After a crashed worker, do not repeat potentially disruptive bandwidth traffic.
        icct_backend_category_execute(
            "UPDATE plugin_icct_nms_diagnostic_jobs SET status='failed',finished_at=NOW(),result_json=? WHERE poller_id=? AND ((status='running' AND started_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)) OR (status='queued' AND requested_at < DATE_SUB(NOW(), INTERVAL 15 SECOND)))",
            [
                json_encode([
                    'output' =>
                        'Request expired or its worker stopped. Run a new test if still needed.'
                ]),
                $collector
            ]
        );
        if (
            db_fetch_cell_prepared(
                "SELECT id FROM plugin_icct_nms_diagnostic_jobs WHERE poller_id=? AND status='running' LIMIT 1",
                [$collector]
            )
        ) {
            return;
        }
        $job = db_fetch_row_prepared(
            "SELECT * FROM plugin_icct_nms_diagnostic_jobs WHERE poller_id=? AND status='queued' ORDER BY id LIMIT 1",
            [$collector]
        );
        if (!$job) {
            return;
        }
        try {
            $row = icct_backend_diag_execution_context($job, $collector);
            icct_backend_category_execute(
                "UPDATE plugin_icct_nms_diagnostic_jobs SET status='running',started_at=NOW() WHERE id=? AND status='queued'",
                [$job['id']]
            );
            icct_backend_diag_redis_publish($job['id']);
            $result = icct_backend_diag_execute($row, $job['tool']);
            icct_backend_diag_execution_context($job, $collector);
            $status =
                $result['exit'] === 0 && empty($result['protocol_error']) ? 'complete' : 'failed';
        } catch (Throwable $error) {
            $status = 'failed';
            $result = [
                'tool' => $job['tool'],
                'target' => 'Device ' . (int) $job['host_id'],
                'profile' => '',
                'exit' => null,
                'output' => $error->getMessage(),
                'collector_id' => $collector,
                'execution_host' => gethostname() ?: 'Unknown'
            ];
        }
        icct_backend_category_execute(
            'UPDATE plugin_icct_nms_diagnostic_jobs SET status=?,result_json=?,finished_at=NOW() WHERE id=?',
            [
                $status,
                json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
                $job['id']
            ]
        );
        icct_backend_diag_redis_publish($job['id']);
    } finally {
        db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}

/** Reused Inventory service: diag run. */
function icct_backend_diag_run($host_id, $tool)
{
    icct_backend_require_management(3);
    icct_backend_require_device_access($host_id);
    if (!isset(icct_backend_diag_available_labels()[(string) $tool])) {
        throw new InvalidArgumentException(
            'This diagnostic is no longer available. Choose Ping, Traceroute or MTR.'
        );
    }
    $row = icct_backend_diag_assignment($host_id, (string) $tool);
    $user = icct_backend_current_user_id();
    if ($user < 1) {
        throw new RuntimeException('Sign in before requesting a diagnostic.');
    }
    if (
        (int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory=?', [
            'icct_nms'
        ]) !== 1
    ) {
        throw new RuntimeException(
            'The ICCT NMS plugin is disabled in Cacti. Enable it in Cacti Plugin Management so the poller can start the diagnostic runner. No test was submitted.'
        );
    }
    if (
        !(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM poller WHERE id=? AND disabled=''", [
            (int) $row['poller_id']
        ])
    ) {
        throw new RuntimeException('The assigned collector is missing or disabled.');
    }
    $lock = 'icct_backend_diag_submit';
    if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?, 2)', [$lock]) !== 1) {
        throw new RuntimeException('Diagnostic runner is busy. Retry shortly.');
    }
    try {
        $pending=db_fetch_row_prepared("SELECT id FROM plugin_icct_nms_diagnostic_jobs WHERE user_id=? AND host_id=? AND tool=? AND ((status='queued' AND requested_at > DATE_SUB(NOW(),INTERVAL 15 SECOND)) OR (status='running' AND started_at > DATE_SUB(NOW(),INTERVAL 2 MINUTE))) ORDER BY id DESC LIMIT 1",[$user,(int)$host_id,(string)$tool]);
        if($pending)return (int)$pending['id'];
        if (
            (int) db_fetch_cell_prepared(
                "SELECT COUNT(*) FROM plugin_icct_nms_diagnostic_jobs WHERE user_id=? AND ((status='queued' AND requested_at > DATE_SUB(NOW(), INTERVAL 15 SECOND)) OR (status='running' AND started_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)))",
                [$user]
            )
        ) {
            throw new RuntimeException(
                'You already have a pending diagnostic. View its result before requesting another.'
            );
        }
        if (
            (int) db_fetch_cell_prepared(
                "SELECT COUNT(*) FROM plugin_icct_nms_diagnostic_jobs WHERE poller_id=? AND ((status='queued' AND requested_at > DATE_SUB(NOW(), INTERVAL 15 SECOND)) OR (status='running' AND started_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)))",
                [(int) $row['poller_id']]
            ) >= 1
        ) {
            throw new RuntimeException(
                'This collector is running another test. Try again when it finishes; your test has not been added to a queue.'
            );
        }
        $runner = icct_backend_diag_runner((int) $row['poller_id']);
        if (!$runner) {
            throw new RuntimeException(
                'The collector diagnostic runner is offline. The existing Cacti poller starts it automatically; retry when it is ready. No test was submitted.'
            );
        }
        if (empty($runner['tools'][$tool])) {
            throw new RuntimeException(
                'This tool is not installed on the assigned collector. Choose an available tool.'
            );
        }
        icct_backend_category_execute(
            "INSERT INTO plugin_icct_nms_diagnostic_jobs (host_id,poller_id,user_id,tool,config_hash,status,result_json,requested_at) VALUES (?,?,?,?,?,'queued','',NOW())",
            [
                (int) $host_id,
                (int) $row['poller_id'],
                $user,
                (string) $tool,
                icct_backend_diag_signature($row)
            ]
        );
        $id=(int)db_fetch_cell('SELECT LAST_INSERT_ID()');
        icct_backend_diag_redis_publish($id);
        icct_backend_diag_redis_enqueue((int)$row['poller_id'],$id);
        return $id;
    } finally {
        db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}

/** Reused Inventory service: diag runner. */
function icct_backend_diag_runner($collector)
{
    $row = db_fetch_row_prepared(
        'SELECT meta_value,updated_at FROM plugin_icct_nms_meta WHERE meta_key=? AND updated_at > DATE_SUB(NOW(), INTERVAL 5 SECOND)',
        ['diagnostic_runner_' . (int) $collector]
    );
    if (!$row) {
        return null;
    }
    $runner = json_decode($row['meta_value'], true);
    return is_array($runner) ? array_merge($runner, ['heartbeat_at' => $row['updated_at']]) : null;
}

/** Reused Inventory service: diag worker database. */
function icct_backend_diag_worker_database()
{
    global $config,
        $remote_db_cnn_id,
        $database_hostname,
        $database_port,
        $database_default,
        $rdatabase_hostname,
        $rdatabase_port,
        $rdatabase_default;
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('The diagnostic worker is CLI-only.');
    }
    if ((int) ($config['poller_id'] ?? 0) > 1) {
        if (($config['connection'] ?? '') !== 'online' || !is_object($remote_db_cnn_id)) {
            throw new RuntimeException(
                'Primary Cacti database unavailable; remote diagnostics wait without using stale local permissions.'
            );
        }
        // Cacti database_sessions already holds this connection. No credentials are copied or changed.
        $database_hostname = $rdatabase_hostname;
        $database_port = $rdatabase_port;
        $database_default = $rdatabase_default;
        // Do not reuse authentication/settings values primed from the local replica.
        $config['config_options_array'] = [];
    }
}
