#!/usr/bin/env php
<?php
/**
 * Import structured newline-delimited JSON written by rsyslog into ICCT Syslog tables.
 *
 * Usage:
 *   php plugins/icct_nms/protocols/syslog/cli/syslog_worker.php --file=/var/log/icct-nms/remote.ndjson --follow
 *   php plugins/icct_nms/protocols/syslog/cli/syslog_worker.php --file=runtime/syslog/remote.ndjson --once
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require __DIR__ . '/../../../../../include/cli_check.php';
require_once __DIR__ . '/../../../shared/services/bootstrap.php';

$options = getopt('', ['file:', 'once', 'follow', 'sleep::']);
$file = isset($options['file']) ? (string) $options['file'] : '';
if ($file === '') {
    fwrite(STDERR, "--file is required\n");
    exit(2);
}
$follow = isset($options['follow']);
$once = isset($options['once']) || !$follow;
$sleep = isset($options['sleep']) ? (float) $options['sleep'] : 0.5;
if ($sleep < 0.1 || $sleep > 10) $sleep = 0.5;

try {
    icct_nms_backend();
    $lock = 'icct_syslog_'.substr(hash('sha256', $file), 0, 40);
    if ((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,0)', [$lock]) !== 1) throw new RuntimeException('A Syslog importer is already reading this spool.');
    [$key, $state] = icct_backend_syslog_worker_state($file);
    $savedInode = (string) ($state['source_inode'] ?? '');
    $offset = (int) ($state['source_offset'] ?? 0);
    $ingested = (int) ($state['records_ingested'] ?? 0);
    $dropped = (int) ($state['records_dropped'] ?? 0);
    $errors = (int) ($state['errors'] ?? 0);
    $lastRecord = $state['last_record_at'] ?? null;
    $lastHeartbeat = 0;
    $dropReasons = [];
    $lastError = '';

    while (true) {
        if ((int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory=?', ['icct_nms']) !== 1) {
            break;
        }

        clearstatcache(true, $file);
        if (!is_file($file)) {
            if (time() - $lastHeartbeat >= 5) {
                icct_backend_syslog_heartbeat([
                    'pid' => getmypid(),
                    'file' => $file,
                    'status' => 'waiting-for-spool',
                    'ingested' => $ingested,
                    'dropped' => $dropped,
                    'errors' => $errors,
                    'drop_reasons' => $dropReasons,
                    'last_error' => $lastError,
                ]);
                $lastHeartbeat = time();
            }
            if ($once) break;
            usleep((int) ($sleep * 1000000));
            continue;
        }

        $stat = @stat($file);
        if (!$stat) {
            $errors++;
            if ($once) break;
            usleep((int) ($sleep * 1000000));
            continue;
        }
        $inode = isset($stat['ino']) ? (string) $stat['ino'] : (string) ($stat['dev'] ?? '') . ':' . (string) ($stat['mtime'] ?? '');
        $size = (int) ($stat['size'] ?? 0);
        if ($savedInode !== '' && ($inode !== $savedInode || $size < $offset)) {
            $offset = 0;
        }
        $savedInode = $inode;

        $handle = @fopen($file, 'rb');
        if (!$handle) {
            $errors++;
            if ($once) break;
            usleep((int) ($sleep * 1000000));
            continue;
        }
        if ($offset > 0 && fseek($handle, $offset, SEEK_SET) !== 0) {
            $offset = 0;
            rewind($handle);
        }

        $processed = 0;
        while (!feof($handle)) {
            $lineStart = ftell($handle);
            $line = fgets($handle);
            if ($line === false) break;
            // Do not advance past an incomplete line; rsyslog may still be writing it.
            if (substr($line, -1) !== "\n" && feof($handle)) {
                fseek($handle, $lineStart, SEEK_SET);
                break;
            }
            $offset = ftell($handle);
            $line = trim($line);
            if ($line === '') continue;
            // PowerShell-generated UTF-8 files may begin with a BOM.
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
            try {
                $record = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($record)) throw new RuntimeException('Syslog spool record is not a JSON object.');
                $dropReason = '';
                $result = icct_backend_syslog_ingest($record, $dropReason);
                if ($result === 'inserted' || $result === 'repeated') {
                    $ingested++;
                    $lastRecord = date('Y-m-d H:i:s');
                } else {
                    $dropped++;
                    $reasonKey = $dropReason !== '' ? $dropReason : 'policy-drop';
                    $dropReasons[$reasonKey] = ($dropReasons[$reasonKey] ?? 0) + 1;
                }
            } catch (IcctSyslogStorageException $recordError) {
                throw $recordError;
            } catch (Throwable $recordError) {
                $errors++;
                $lastError = substr($recordError->getMessage(), 0, 180);
            }
            $processed++;
            if ($processed >= 2000) break;
        }
        fclose($handle);

        icct_backend_syslog_worker_state_save(
            $key,
            $file,
            $savedInode,
            $offset,
            $lastRecord,
            $ingested,
            $dropped,
            $errors
        );
        if (time() - $lastHeartbeat >= 5 || $processed > 0) {
            icct_backend_syslog_heartbeat([
                'pid' => getmypid(),
                'file' => $file,
                'status' => 'running',
                'offset' => $offset,
                'ingested' => $ingested,
                'dropped' => $dropped,
                'errors' => $errors,
                'last_record_at' => $lastRecord,
                'drop_reasons' => $dropReasons,
                'last_error' => $lastError,
            ]);
            $lastHeartbeat = time();
        }

        if ($once) break;
        usleep((int) ($sleep * 1000000));
    }
} catch (Throwable $error) {
    cacti_log('ICCT NMS Syslog worker: ' . $error->getMessage(), false, 'ICCT NMS');
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
