<?php
/** Plugin-owned on-demand jobs consumed by the existing Cacti poller hook. */
require_once __DIR__ . '/diagnostics.php';

/** A recent heartbeat is required; offline collectors never accumulate waiting tests. */
function nms_diag_runner($collector)
{
    $raw = db_fetch_cell_prepared("SELECT meta_value FROM plugin_nms_meta WHERE meta_key=? AND updated_at > DATE_SUB(NOW(), INTERVAL 5 SECOND)", ['diagnostic_runner_' . (int) $collector]);
    return $raw ? json_decode($raw, true) : null;
}

/** Admit one immediate test per collector, bound to current settings. */
function nms_diag_run($host_id, $tool)
{
	nms_require_management(3);
	nms_require_device_access($host_id);
	$row = nms_diag_assignment($host_id, (string) $tool);
	$user = nms_current_user_id();
	if ($user < 1) throw new RuntimeException('Sign in before requesting a diagnostic.');
	if (!(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM poller WHERE id=? AND disabled=''", [(int) $row['poller_id']])) {
		throw new RuntimeException('The assigned collector is missing or disabled.');
	}
	$lock = 'nms_diag_submit';
	if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?, 2)', [$lock]) !== 1) throw new RuntimeException('Diagnostic runner is busy. Retry shortly.');
	try {
		if ((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_nms_diagnostic_jobs WHERE user_id=? AND ((status='queued' AND requested_at > DATE_SUB(NOW(), INTERVAL 15 SECOND)) OR (status='running' AND started_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)))", [$user])) {
			throw new RuntimeException('You already have a pending diagnostic. View its result before requesting another.');
		}
		if ((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_nms_diagnostic_jobs WHERE poller_id=? AND ((status='queued' AND requested_at > DATE_SUB(NOW(), INTERVAL 15 SECOND)) OR (status='running' AND started_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)))", [(int) $row['poller_id']]) >= 1) {
			throw new RuntimeException('This collector is running another test. Try again when it finishes; your test has not been added to a queue.');
		}
        $runner = nms_diag_runner((int) $row['poller_id']);
        if (!$runner) throw new RuntimeException('The collector diagnostic runner is offline. The existing Cacti poller starts it automatically; retry when it is ready. No test was submitted.');
        if (empty($runner['tools'][$tool])) throw new RuntimeException('This tool is not installed on the assigned collector. Choose an available tool.');
		nms_category_execute("INSERT INTO plugin_nms_diagnostic_jobs (host_id,poller_id,user_id,tool,config_hash,status,result_json,requested_at) VALUES (?,?,?,?,?,'queued','',NOW())",
			[(int) $host_id, (int) $row['poller_id'], $user, (string) $tool, nms_diag_signature($row)]);
		return (int) db_fetch_cell('SELECT LAST_INSERT_ID()');
	} finally {
		db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
	}
}

/** Only the requester with current device access may read the stored result. */
function nms_diag_job($id)
{
	$job = db_fetch_row_prepared('SELECT * FROM plugin_nms_diagnostic_jobs WHERE id=? AND user_id=?', [(int) $id, nms_current_user_id()]);
	if (!$job) throw new RuntimeException('Diagnostic request is unavailable for this account.');
	nms_require_device_access((int) $job['host_id']);
	if (($job['status'] === 'queued' && strtotime($job['requested_at']) < time() - 15) || ($job['status'] === 'running' && strtotime($job['started_at']) < time() - 120)) {
		$job['status'] = 'expired';
		$job['result_json'] = json_encode(['output' => 'The collector did not start or finish this test in time. Check its diagnostic runner and retry. This test will not be repeated automatically.']);
	}
	return $job;
}

/** Recheck both NMS realm and native permissions at execution time, without retaining session state. */
function nms_diag_authorize_job($job)
{
	$user = (int) $job['user_id'];
	if ($user < 1 || db_fetch_cell_prepared('SELECT enabled FROM user_auth WHERE id=?', [$user]) !== 'on'
		|| !is_realm_allowed(3, $user) || !is_device_allowed((int) $job['host_id'], $user)) {
		throw new RuntimeException('Request owner no longer has permission to run this diagnostic.');
	}
	$session = $_SESSION ?? [];
	try {
		$_SESSION = ['sess_user_id' => $user];
		if (!api_user_realm_auth('diagnostics.php')) throw new RuntimeException('NMS diagnostic page access has been revoked.');
	} finally {
		$_SESSION = $session;
	}
}

/** One bounded test per worker; locks prevent concurrent or repeated execution. */
function nms_diag_poll()
{
	global $config;
	if (PHP_SAPI !== 'cli') return;
	require_once __DIR__ . '/inventory.php';
	require_once $config['base_path'] . '/lib/auth.php';
	$collector = nms_inventory_collector_id();
	$lock = 'nms_diag_worker_' . $collector;
	if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) return;
	try {
		// After a crashed worker, do not repeat potentially disruptive bandwidth traffic.
		nms_category_execute("UPDATE plugin_nms_diagnostic_jobs SET status='failed',finished_at=NOW(),result_json=? WHERE poller_id=? AND ((status='running' AND started_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)) OR (status='queued' AND requested_at < DATE_SUB(NOW(), INTERVAL 15 SECOND)))",
			[json_encode(['output' => 'Request expired or its worker stopped. Run a new test if still needed.']), $collector]);
		if (db_fetch_cell_prepared("SELECT id FROM plugin_nms_diagnostic_jobs WHERE poller_id=? AND status='running' LIMIT 1", [$collector])) return;
		$job = db_fetch_row_prepared("SELECT * FROM plugin_nms_diagnostic_jobs WHERE poller_id=? AND status='queued' ORDER BY id LIMIT 1", [$collector]);
		if (!$job) return;
		try {
			nms_diag_authorize_job($job);
			$row = nms_diag_assignment((int) $job['host_id'], $job['tool']);
			if ((int) $row['poller_id'] !== $collector || !hash_equals($job['config_hash'], nms_diag_signature($row))) {
				throw new RuntimeException('Device, profile or collector changed after submission. Run a new test.');
			}
			nms_category_execute("UPDATE plugin_nms_diagnostic_jobs SET status='running',started_at=NOW() WHERE id=? AND status='queued'", [$job['id']]);
			$result = nms_diag_execute($row, $job['tool']);
			$status = $result['exit'] === 0 && empty($result['protocol_error']) ? 'complete' : 'failed';
		} catch (Throwable $error) {
			$status = 'failed';
			$result = ['tool' => $job['tool'], 'target' => 'Device ' . (int) $job['host_id'], 'profile' => '',
				'exit' => null, 'output' => $error->getMessage(), 'collector_id' => $collector, 'execution_host' => gethostname() ?: 'Unknown'];
		}
		nms_category_execute('UPDATE plugin_nms_diagnostic_jobs SET status=?,result_json=?,finished_at=NOW() WHERE id=?',
			[$status, json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), $job['id']]);
	} finally {
		db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
	}
}

/** In the isolated worker only, use Cacti's existing primary connection for jobs and ACLs. */
function nms_diag_worker_database()
{
	global $config, $remote_db_cnn_id, $database_hostname, $database_port, $database_default,
		$rdatabase_hostname, $rdatabase_port, $rdatabase_default;
	if (PHP_SAPI !== 'cli') throw new RuntimeException('The diagnostic worker is CLI-only.');
	if ((int) ($config['poller_id'] ?? 0) > 1) {
		if (($config['connection'] ?? '') !== 'online' || !is_object($remote_db_cnn_id)) {
			throw new RuntimeException('Primary Cacti database unavailable; remote diagnostics wait without using stale local permissions.');
		}
		// Cacti database_sessions already holds this connection. No credentials are copied or changed.
		$database_hostname = $rdatabase_hostname;
		$database_port = $rdatabase_port;
		$database_default = $rdatabase_default;
		// Do not reuse authentication/settings values primed from the local replica.
		$config['config_options_array'] = [];
	}
}

/** Launch a separate worker so a bandwidth test never holds up native polling. */
function nms_diag_dispatch()
{
	global $config;
	if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux') return;
	require_once $config['base_path'] . '/lib/poller.php';
	// Both executable and script are installation paths, never request parameters.
	exec_background(PHP_BINARY, ['-q', dirname(__DIR__) . '/diagnostic_listener.php']);
}
