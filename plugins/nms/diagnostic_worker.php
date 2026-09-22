<?php
/** CLI worker launched only by NMS's existing native poller hook. No service/cron changes. */
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}
require __DIR__ . '/../../include/cli_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/diagnostics_queue.php';
try {
	nms_diag_worker_database();
	if (!nms_database_ready()) throw new RuntimeException('Upgrade NMS through Cacti Plugin Management before running diagnostics.');
	if ((int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory=?', ['nms']) !== 1) exit;
	nms_diag_poll();
} catch (Throwable $error) {
	cacti_log('NMS diagnostic worker: ' . $error->getMessage(), false, 'NMS');
	exit(1);
}
