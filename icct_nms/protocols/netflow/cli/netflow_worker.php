#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../../../../../include/cli_check.php';
require_once __DIR__ . '/../../../shared/services/bootstrap.php';
require_once __DIR__ . '/../services/netflow_service.php';
require_once __DIR__ . '/../services/netflow_collector.php';
require_once __DIR__ . '/../services/netflow_faults.php';
$options = getopt('', ['root:', 'once', 'follow']);
$root = $options['root'] ?? '/var/lib/icct-nms/netflow';
if (!str_starts_with($root, '/') || str_contains($root, '..')) {
    fwrite(STDERR, "Use an absolute collector root.\n");
    exit(2);
}
$children = [];
$lastMaintenance = 0;
try {
    icct_nms_backend();
    date_default_timezone_set('Asia/Kolkata');
    if (
        (int) db_fetch_cell_prepared('SELECT GET_LOCK(?,0)', [
            'icct_netflow_worker_' . icct_backend_inventory_collector_id(),
        ]) !== 1
    ) {
        throw new RuntimeException('A NetFlow worker is already running.');
    }
    if (!is_dir($root) && !mkdir($root, 0700, true)) {
        throw new RuntimeException('Cannot create collector root.');
    }
    do {
        $assignments = icct_nms_netflow_assignments();
        $listeners = [];
        foreach ($assignments as $a) {
            $id = (int) $a['host_id'];
            if (!icct_backend_protocol_enabled($id, 'netflow')) {
                continue;
            }
            $cfg = icct_nms_netflow_assignment($id);
            $port = (int) $cfg['port'];
            $dir = $root . '/' . $port . '/host-' . $id;
            if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
                throw new RuntimeException('Cannot create device capture directory.');
            }
            $listeners[$port][] = [$id, $a['exporter_address'], $dir];
        }
        $failedPorts = [];
        if (!isset($options['once'])) {
            foreach ($children as $port => $child) {
                $hash = hash('sha256', json_encode($listeners[$port] ?? []));
                $running = proc_get_status($child['process'])['running'];
                if (!$running || $child['hash'] !== $hash) {
                    if (!$running) {
                        $failedPorts[] = (int) $port;
                    }
                    if ($running) {
                        proc_terminate($child['process']);
                    }
                    proc_close($child['process']);
                    unset($children[$port]);
                }
            }
            foreach ($listeners as $port => $sources) {
                if (isset($children[$port])) {
                    continue;
                }
                $ipv6 = false;
                foreach ($sources as $source) {
                    if (str_contains($source[1], ':')) {
                        $ipv6 = true;
                    }
                }
                $args = [
                    '/usr/bin/nfcapd',
                    $ipv6 ? '-6' : '-4',
                    '-p',
                    (string) $port,
                    '-b',
                    $ipv6 ? '::' : '0.0.0.0',
                    '-t',
                    '60',
                    '-s',
                    '1',
                ];
                foreach ($sources as [$id, $ip, $dir]) {
                    if ($ipv6 && !str_contains($ip, ':')) {
                        $ip = '::ffff:' . $ip;
                    }
                    array_push($args, '-n', 'host-' . $id . ',' . $ip . ',' . $dir);
                }
                $pipes = [];
                $process = proc_open(
                    $args,
                    [
                        0 => ['file', '/dev/null', 'r'],
                        1 => ['file', '/dev/null', 'a'],
                        2 => ['file', $root . '/listener-' . $port . '.log', 'a'],
                    ],
                    $pipes,
                    null,
                    ['TZ' => 'Asia/Kolkata', 'PATH' => '/usr/bin:/usr/sbin:/bin'],
                );
                if (!is_resource($process)) {
                    throw new RuntimeException('Cannot start collector on port ' . $port);
                }
                $children[$port] = [
                    'process' => $process,
                    'hash' => hash('sha256', json_encode($sources)),
                ];
            }
        }
        $imported = 0;
        $errors = [];
        foreach ($assignments as $a) {
            $id = (int) $a['host_id'];
            $cfg = icct_nms_netflow_assignment($id);
            $dir = $root . '/' . (int) $cfg['port'] . '/host-' . $id;
            try {
                $captureErrors = [];
                foreach (glob($dir . '/nfcapd.20*') ?: [] as $file) {
                    if (
                        !preg_match('/^nfcapd\.\d{12}(?:\d{2})?$/D', basename($file)) ||
                        time() - filemtime($file) < 2
                    ) {
                        continue;
                    }
                    try {
                        $imported += icct_nms_netflow_import_file($a, $file);
                    } catch (Throwable $error) {
                        $captureErrors[] = basename($file) . ': ' . $error->getMessage();
                    }
                }
                icct_nms_netflow_anomalies($id);
                if ($captureErrors) {
                    throw new RuntimeException(implode('; ', $captureErrors));
                }
            } catch (Throwable $e) {
                $errors[] = 'Device ' . $id . ': ' . $e->getMessage();
                icct_backend_category_execute(
                    'INSERT INTO ' .
                        'plugin_icct_nms_netflow_state(host_id,status,last_attempt,last_error,top_json,updated_at) ' .
                        'VALUES(?,\'error\',NOW(),?,\'{}\',NOW()) ON DUPLICATE KEY UPDATE ' .
                        'status=\'error\',last_attempt=NOW(),last_error=VALUES(last_error),updated_at=NOW()',
                    [$id, substr($e->getMessage(), 0, 500)],
                );
            }
        }
        try {
            icct_nms_netflow_sync_faults();
        } catch (Throwable $error) {
            $errors[] = 'Fault journal: ' . $error->getMessage();
        }
        $status = [
            'heartbeat' => time(),
            'ports' => array_keys($listeners),
            'devices' => count($assignments),
            'errors' => $errors,
            'failed_ports' => $failedPorts,
        ];
        icct_backend_category_execute(
            'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON ' .
                'DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
            [
                'netflow_worker_' . icct_backend_inventory_collector_id(),
                json_encode($status, JSON_THROW_ON_ERROR),
            ],
        );
        if (time() - $lastMaintenance > 3600) {
            // Database flow detail and history are retained for 30 days; raw retry files for 2 days.
            foreach (
                [
                    'flows' => 'received_at',
                    'files' => 'imported_at',
                    'buckets' => 'bucket_start',
                    'metrics' => 'bucket_start',
                    'events' => 'updated_at',
                ]
                as $table => $column
            ) {
                icct_backend_category_execute(
                    'DELETE FROM plugin_icct_nms_netflow_' .
                        $table .
                        ' WHERE ' .
                        $column .
                        '<DATE_SUB(NOW(),INTERVAL 30 DAY)',
                    [],
                );
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (
                    $file->isFile() &&
                    preg_match('/^nfcapd\.\d{12}(?:\d{2})?$/D', $file->getFilename()) &&
                    time() - $file->getMTime() > 172800
                ) {
                    unlink($file->getPathname());
                }
            }
            $lastMaintenance = time();
        }
        if (isset($options['once'])) {
            echo json_encode(['imported' => $imported, 'errors' => $errors], JSON_THROW_ON_ERROR) .
                "\n";
            break;
        }
        if ($errors) {
            fwrite(STDERR, implode("\n", $errors) . "\n");
        }
        sleep(5);
    } while (true);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    foreach ($children as $child) {
        proc_terminate($child['process']);
        proc_close($child['process']);
    }
}
