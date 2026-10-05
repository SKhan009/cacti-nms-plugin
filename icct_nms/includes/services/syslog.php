<?php
/**
 * Syslog ingestion, normalization and event-console services.
 *
 * The network receiver is deliberately external (rsyslog on RHEL). It writes newline-delimited
 * JSON into a protected spool. This plugin's CLI worker imports only messages that map to a
 * Cacti host with Syslog enabled in plugin_icct_nms_syslog_devices. Cacti remains authoritative
 * for device identity and access control; this table only stores the Syslog-specific binding.
 */

class IcctSyslogStorageException extends RuntimeException {}
function icct_backend_syslog_execute($sql, $parameters)
{
    if (db_execute_prepared($sql, $parameters) === false) throw new IcctSyslogStorageException('Syslog database write failed; the spool cursor was retained.');
}

function icct_backend_syslog_severity_labels()
{
    return [
        0 => 'Emergency',
        1 => 'Alert',
        2 => 'Critical',
        3 => 'Error',
        4 => 'Warning',
        5 => 'Notice',
        6 => 'Informational',
        7 => 'Debug',
    ];
}

function icct_backend_syslog_nms_severity($severity)
{
    $severity = (int) $severity;
    if ($severity <= 2) return 'critical';
    if ($severity === 3) return 'major';
    if ($severity === 4) return 'warning';
    if ($severity <= 6) return 'info';
    return 'debug';
}

function icct_backend_syslog_facility_labels()
{
    return ['Kernel','User','Mail','System daemon','Security/authentication','Syslog','Line printer','Network news','UUCP','Clock daemon','Security/authentication','FTP','NTP','Log audit','Log alert','Clock daemon','Local 0','Local 1','Local 2','Local 3','Local 4','Local 5','Local 6','Local 7'];
}

/** Shared validation for presets and device overrides. Empty selections accept no messages. */
function icct_backend_syslog_filters(array $input)
{
    $out=[];
    foreach (['severity_codes'=>7,'facility_codes'=>23,'match_strings'=>null] as $field=>$maximum) {
        $default=$field==='severity_codes' ? range(0,(int)($input['max_severity'] ?? 6)) : ($field==='facility_codes' ? range(0,23) : []);
        $raw=$input[$field] ?? null;
        $values=$raw===null || $raw==='' ? $default : (is_array($raw) ? $raw : json_decode((string)$raw,true,8,JSON_THROW_ON_ERROR));
        if (!is_array($values) || $values!==array_values($values) || count($values)>32) throw new InvalidArgumentException('Invalid Syslog filter selection.');
        if ($maximum!==null) {
            foreach ($values as &$value) {
                $valid=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>$maximum]]);
                if($valid===false)throw new InvalidArgumentException('Invalid Syslog code.');
                $value=$valid;
            }
            unset($value); $values=array_values(array_unique($values)); sort($values);
        } else {
            foreach($values as &$value){if(!is_string($value))throw new InvalidArgumentException('Invalid Syslog match string.');$value=trim($value);if($value===''||preg_match('/[\x00-\x1F]/',$value))throw new InvalidArgumentException('Enter valid match strings.');}
            unset($value);$values=array_values(array_unique($values));
            if(mb_strlen(implode(', ',$values))>148)throw new InvalidArgumentException('Match strings must total no more than 148 characters.');
        }
        $out[$field]=$values;
    }
    return $out;
}

function icct_backend_syslog_assignment($host_id)
{
    return db_fetch_row_prepared(
        'SELECT d.*,h.description,h.hostname FROM plugin_icct_nms_syslog_devices d INNER JOIN host h ON h.id=d.host_id WHERE d.host_id=? AND h.deleted=\'\'',
        [(int) $host_id]
    );
}

function icct_backend_syslog_match_device($source_ip, $source_host)
{
    $source_ip = trim((string) $source_ip);
    $source_host = trim((string) $source_host);
    if ($source_ip === '' && $source_host === '') return [];

    $rows = db_fetch_assoc_prepared(
        "SELECT d.*,h.description,h.hostname,h.poller_id
         FROM plugin_icct_nms_syslog_devices d
         INNER JOIN host h ON h.id=d.host_id
         WHERE h.deleted='' AND h.disabled='' AND d.enabled=1 AND (
             (d.source_address<>'' AND (d.source_address=? OR d.source_address=?)) OR
             (d.source_address='' AND (h.hostname=? OR h.hostname=?))
         )
         ORDER BY CASE WHEN d.source_address=? THEN 0 WHEN d.source_address<>'' THEN 1 ELSE 2 END,h.id
         LIMIT 10",
        [$source_ip, $source_host, $source_ip, $source_host, $source_ip]
    );
    if ($rows === false) throw new IcctSyslogStorageException('Syslog binding lookup failed; the spool cursor was retained.');
    foreach ((array) $rows as $row) {
        if (!icct_backend_protocol_enabled((int)$row['host_id'], 'syslog')) continue;
        $expected = trim((string) $row['source_address']);
        if ($expected === '') $expected = trim((string) $row['hostname']);
        if ($expected === '') continue;
        if (filter_var($expected, FILTER_VALIDATE_IP) !== false) {
            if (strcasecmp($expected, $source_ip) === 0) return $row;
            continue;
        }
        // Hostname matching uses the hostname parsed by rsyslog from the message header.
        if ($source_host !== '' && strcasecmp($expected, $source_host) === 0) return $row;
    }
    return [];
}

function icct_backend_syslog_timestamp($value)
{
    try {
        $date = new DateTimeImmutable((string) $value);
        return $date->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    } catch (Throwable $error) {
        return date('Y-m-d H:i:s');
    }
}

function icct_backend_syslog_transport($input_name)
{
    $input_name = strtolower((string) $input_name);
    if (strpos($input_name, 'tcp') !== false) return 'tcp';
    if (strpos($input_name, 'udp') !== false) return 'udp';
    return 'unknown';
}

/**
 * Normalize and store one structured rsyslog record.
 * Returns one of: inserted, repeated, dropped.
 */
function icct_backend_syslog_ingest(array $record, &$reason = null)
{
    $reason = '';
    $source_ip = trim((string) ($record['source_ip'] ?? ''));
    $source_host = trim((string) ($record['hostname'] ?? ''));
    if ($source_ip === '' || filter_var($source_ip, FILTER_VALIDATE_IP) === false) {
        $reason = 'invalid-source-ip';
        return 'dropped';
    }

    $assignment = icct_backend_syslog_match_device($source_ip, $source_host);
    if (!$assignment) {
        // Production default: do not persist arbitrary/unmanaged senders.
        $reason = 'unmapped-source';
        return 'dropped';
    }

    $severity_code = isset($record['severity_code']) ? (int) $record['severity_code'] : 6;
    if ($severity_code < 0 || $severity_code > 7) $severity_code = 6;
    // Legacy bindings inherit newly introduced parameters until explicitly saved.
    if (!isset($assignment['severity_codes'], $assignment['facility_codes'], $assignment['match_strings'])) {
        $presetJson = db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?', ['protocol_defaults']);
        $presetValues = $presetJson ? json_decode($presetJson, true, 32, JSON_THROW_ON_ERROR) : [];
        foreach (['severity_codes', 'facility_codes', 'match_strings'] as $field) {
            if (!isset($assignment[$field]) && isset($presetValues['syslog'][$field])) {
                $assignment[$field] = $presetValues['syslog'][$field];
            }
        }
    }
    $filters=icct_backend_syslog_filters($assignment);
    if (!in_array($severity_code,$filters['severity_codes'],true)) {
        $reason = 'severity-filtered';
        return 'dropped';
    }

    $transport = icct_backend_syslog_transport($record['input_name'] ?? '');
    $expected = (string) $assignment['transport'];
    if ($transport === 'unknown' || ($expected !== 'both' && $transport !== $expected)) {
        $reason = 'transport-filtered';
        return 'dropped';
    }

    $facility_code = isset($record['facility_code']) ? (int) $record['facility_code'] : 0;
    if ($facility_code < 0 || $facility_code > 23) $facility_code = 0;
    if(!in_array($facility_code,$filters['facility_codes'],true)){$reason='facility-filtered';return 'dropped';}
    $facility = substr(trim((string) ($record['facility'] ?? '')), 0, 32);
    $severity = substr(trim((string) ($record['severity'] ?? '')), 0, 16);
    $program = substr(trim((string) ($record['program'] ?? '')), 0, 128);
    $message = trim((string) ($record['message'] ?? ''));
    if ($message === '') {
        $reason = 'empty-message';
        return 'dropped';
    }
    if (strlen($message) > 16384) $message = substr($message, 0, 16384);
    if($filters['match_strings']){
        $matched=false;foreach($filters['match_strings'] as $keyword)if(mb_stripos($message,$keyword)!==false){$matched=true;break;}
        if(!$matched){$reason='keyword-filtered';return 'dropped';}
    }
    $event_time = icct_backend_syslog_timestamp($record['timestamp'] ?? 'now');
    $now = date('Y-m-d H:i:s');
    $nms_severity = icct_backend_syslog_nms_severity($severity_code);
    $fingerprint = hash('sha256', implode('|', [
        (int) $assignment['host_id'],
        $facility_code,
        $severity_code,
        strtolower($program),
        preg_replace('/\s+/', ' ', $message),
    ]));

    $window = 60;
    $saved = db_fetch_cell_prepared(
        'SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
        ['syslog_dedupe_seconds']
    );
    if (is_numeric($saved)) $window = max(0, min(3600, (int) $saved));
    $threshold = date('Y-m-d H:i:s', time() - $window);

    if ($window > 0) {
        $existing = db_fetch_row_prepared(
            'SELECT id FROM plugin_icct_nms_syslog_events WHERE host_id=? AND fingerprint=? AND last_seen>=? ORDER BY id DESC LIMIT 1',
            [(int) $assignment['host_id'], $fingerprint, $threshold]
        );
        if ($existing) {
            icct_backend_syslog_execute(
                'UPDATE plugin_icct_nms_syslog_events SET repeat_count=repeat_count+1,last_seen=?,received_at=? WHERE id=?',
                [$now, $now, (int) $existing['id']]
            );
            $reason = 'deduplicated';
            return 'repeated';
        }
    }

    icct_backend_syslog_execute(
        'INSERT INTO plugin_icct_nms_syslog_events
        (host_id,event_time,received_at,last_seen,source_ip,source_host,transport,facility_code,facility,severity_code,severity,nms_severity,program,message,fingerprint)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            (int) $assignment['host_id'],
            $event_time,
            $now,
            $now,
            $source_ip,
            substr($source_host, 0, 255),
            $transport,
            $facility_code,
            $facility,
            $severity_code,
            $severity,
            $nms_severity,
            $program,
            $message,
            $fingerprint,
        ]
    );
    $reason = 'stored';
    return 'inserted';
}

/** Return enabled Syslog bindings for operator diagnostics. */
function icct_backend_syslog_bindings()
{
    return db_fetch_assoc(
        "SELECT d.host_id,d.enabled,d.source_address,d.transport,d.max_severity,h.description,h.hostname " .
        "FROM plugin_icct_nms_syslog_devices d INNER JOIN host h ON h.id=d.host_id " .
        "WHERE h.deleted='' ORDER BY h.description,h.id"
    );
}

function icct_backend_syslog_worker_state($source_path)
{
    $key = hash('sha256', (string) $source_path);
    $row = db_fetch_row_prepared(
        'SELECT * FROM plugin_icct_nms_syslog_ingest_state WHERE source_key=?',
        [$key]
    );
    return [$key, is_array($row) ? $row : []];
}

/** Reset only the read cursor for a spool. Existing Syslog events are preserved. */
function icct_backend_syslog_worker_state_reset($source_path)
{
    $key = hash('sha256', (string) $source_path);
    icct_backend_syslog_execute(
        'DELETE FROM plugin_icct_nms_syslog_ingest_state WHERE source_key=?',
        [$key]
    );
    return true;
}

function icct_backend_syslog_worker_state_save($key, $path, $inode, $offset, $last_record, $ingested, $dropped, $errors)
{
    icct_backend_syslog_execute(
        'INSERT INTO plugin_icct_nms_syslog_ingest_state
        (source_key,source_path,source_inode,source_offset,last_record_at,records_ingested,records_dropped,errors,updated_at)
        VALUES(?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE source_path=VALUES(source_path),source_inode=VALUES(source_inode),source_offset=VALUES(source_offset),
        last_record_at=VALUES(last_record_at),records_ingested=VALUES(records_ingested),records_dropped=VALUES(records_dropped),errors=VALUES(errors),updated_at=NOW()',
        [$key, $path, (string) $inode, (int) $offset, $last_record ?: null, (int) $ingested, (int) $dropped, (int) $errors]
    );
}

/**
 * Import a finite chunk of a local NDJSON spool through the web request path.
 * This is intended for Windows development/testing where PHP CLI may be blocked by
 * application-control policy. Production RHEL uses the systemd CLI worker instead.
 */
function icct_backend_syslog_import_spool_once($file, $max_records = 5000)
{
    $file = (string) $file;
    $max_records = max(1, min(20000, (int) $max_records));
    if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException('Syslog test spool is missing or unreadable: ' . $file);
    }

    [$key, $state] = icct_backend_syslog_worker_state($file);
    $saved_inode = (string) ($state['source_inode'] ?? '');
    $offset = (int) ($state['source_offset'] ?? 0);
    $ingested_total = (int) ($state['records_ingested'] ?? 0);
    $dropped_total = (int) ($state['records_dropped'] ?? 0);
    $errors_total = (int) ($state['errors'] ?? 0);
    $last_record = $state['last_record_at'] ?? null;

    clearstatcache(true, $file);
    $stat = @stat($file);
    if (!$stat) {
        throw new RuntimeException('Unable to stat Syslog test spool.');
    }
    $inode = isset($stat['ino']) ? (string) $stat['ino'] : (string) ($stat['dev'] ?? '') . ':' . (string) ($stat['mtime'] ?? '');
    $size = (int) ($stat['size'] ?? 0);
    if ($saved_inode !== '' && ($saved_inode !== $inode || $size < $offset)) {
        $offset = 0;
    }

    $handle = @fopen($file, 'rb');
    if (!$handle) {
        throw new RuntimeException('Unable to open Syslog test spool.');
    }
    if ($offset > 0 && fseek($handle, $offset, SEEK_SET) !== 0) {
        $offset = 0;
        rewind($handle);
    }

    $processed = 0;
    $inserted = 0;
    $repeated = 0;
    $dropped = 0;
    $errors = 0;
    $drop_reasons = [];
    $error_messages = [];
    try {
        while (!feof($handle) && $processed < $max_records) {
            $line_start = ftell($handle);
            $line = fgets($handle);
            if ($line === false) break;
            if (substr($line, -1) !== "\n" && feof($handle)) {
                fseek($handle, $line_start, SEEK_SET);
                break;
            }
            $offset = ftell($handle);
            $line = trim($line);
            if ($line === '') continue;
            // Windows PowerShell 5 may write a UTF-8 BOM at the start of the file.
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
            try {
                $record = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($record)) throw new RuntimeException('Spool record is not a JSON object.');
                $drop_reason = '';
                $result = icct_backend_syslog_ingest($record, $drop_reason);
                if ($result === 'inserted') {
                    $inserted++;
                    $ingested_total++;
                    $last_record = date('Y-m-d H:i:s');
                } elseif ($result === 'repeated') {
                    $repeated++;
                    $ingested_total++;
                    $last_record = date('Y-m-d H:i:s');
                } else {
                    $dropped++;
                    $dropped_total++;
                    $key_reason = $drop_reason !== '' ? $drop_reason : 'policy-drop';
                    $drop_reasons[$key_reason] = ($drop_reasons[$key_reason] ?? 0) + 1;
                }
            } catch (IcctSyslogStorageException $record_error) {
                throw $record_error;
            } catch (Throwable $record_error) {
                $errors++;
                $errors_total++;
                if (count($error_messages) < 3) {
                    $error_messages[] = substr($record_error->getMessage(), 0, 180);
                }
            }
            $processed++;
        }
    } finally {
        fclose($handle);
    }

    icct_backend_syslog_worker_state_save(
        $key,
        $file,
        $inode,
        $offset,
        $last_record,
        $ingested_total,
        $dropped_total,
        $errors_total
    );
    icct_backend_syslog_heartbeat([
        'pid' => getmypid(),
        'file' => $file,
        'status' => 'windows-web-test-import',
        'offset' => $offset,
        'ingested' => $ingested_total,
        'dropped' => $dropped_total,
        'errors' => $errors_total,
        'last_record_at' => $last_record,
    ]);

    return [
        'processed' => $processed,
        'inserted' => $inserted,
        'repeated' => $repeated,
        'dropped' => $dropped,
        'errors' => $errors,
        'offset' => $offset,
        'drop_reasons' => $drop_reasons,
        'error_messages' => $error_messages,
    ];
}

function icct_backend_syslog_heartbeat($payload)
{
    icct_backend_syslog_execute(
        'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
        ['syslog_worker_status', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]
    );
}

function icct_backend_syslog_runtime_status()
{
    $row = db_fetch_row_prepared(
        'SELECT meta_value,updated_at FROM plugin_icct_nms_meta WHERE meta_key=?',
        ['syslog_worker_status']
    );
    $payload = [];
    if ($row && $row['meta_value'] !== '') {
        $decoded = json_decode((string) $row['meta_value'], true);
        if (is_array($decoded)) $payload = $decoded;
    }
    $age = PHP_INT_MAX;
    if ($row && !empty($row['updated_at'])) {
        $stamp = strtotime((string) $row['updated_at']);
        if ($stamp !== false) $age = max(0, time() - $stamp);
    }
    return [
        'online' => $age <= 90,
        'age' => $age,
        'updated_at' => $row['updated_at'] ?? '',
        'payload' => $payload,
    ];
}

function icct_backend_syslog_event_ack($event_id, $ack, $comment = '')
{
    $event = db_fetch_row_prepared(
        'SELECT id,host_id FROM plugin_icct_nms_syslog_events WHERE id=?',
        [(int) $event_id]
    );
    if (!$event) throw new InvalidArgumentException('Syslog event was not found.');
    icct_backend_require_device_access((int) $event['host_id']);
    if ($ack) {
        $comment = substr(trim((string) $comment), 0, 255);
        icct_backend_syslog_execute(
            'UPDATE plugin_icct_nms_syslog_events SET acknowledged=1,acknowledged_by=?,acknowledged_at=NOW(),ack_comment=? WHERE id=?',
            [icct_backend_current_user_id(), $comment, (int) $event_id]
        );
        cacti_log('ICCT NMS Syslog event ' . (int) $event_id . ' acknowledged by user ' . icct_backend_current_user_id(), false, 'ICCT NMS');
    } else {
        icct_backend_syslog_execute(
            "UPDATE plugin_icct_nms_syslog_events SET acknowledged=0,acknowledged_by=0,acknowledged_at=NULL,ack_comment='' WHERE id=?",
            [(int) $event_id]
        );
        cacti_log('ICCT NMS Syslog event ' . (int) $event_id . ' acknowledgement cleared by user ' . icct_backend_current_user_id(), false, 'ICCT NMS');
    }
}

function icct_backend_syslog_event($event_id)
{
    $row = db_fetch_row_prepared(
        "SELECT e.*,h.description,h.hostname,u.username AS ack_username
         FROM plugin_icct_nms_syslog_events e
         INNER JOIN host h ON h.id=e.host_id
         LEFT JOIN user_auth u ON u.id=e.acknowledged_by
         WHERE e.id=?",
        [(int) $event_id]
    );
    if (!$row) return [];
    icct_backend_require_device_access((int) $row['host_id']);
    return $row;
}

function icct_backend_syslog_events(array $allowed_host_ids, array $filters, $limit, $offset, &$total)
{
    $allowed_host_ids = array_values(array_unique(array_map('intval', $allowed_host_ids)));
    if (!$allowed_host_ids) {
        $total = 0;
        return [];
    }
    $where = ['e.host_id IN (' . implode(',', array_fill(0, count($allowed_host_ids), '?')) . ')'];
    $params = $allowed_host_ids;

    if (!empty($filters['host_id'])) {
        $where[] = 'e.host_id=?';
        $params[] = (int) $filters['host_id'];
    }
    if (!empty($filters['severity'])) {
        $where[] = 'e.nms_severity=?';
        $params[] = (string) $filters['severity'];
    }
    if (($filters['ack'] ?? '') === 'yes') {
        $where[] = 'e.acknowledged=1';
    } elseif (($filters['ack'] ?? '') === 'no') {
        $where[] = 'e.acknowledged=0';
    }
    if (!empty($filters['search'])) {
        $where[] = '(e.message LIKE ? OR e.program LIKE ? OR e.source_ip LIKE ? OR h.description LIKE ? OR h.hostname LIKE ?)';
        $like = '%' . $filters['search'] . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if (!empty($filters['since'])) {
        $where[] = 'e.event_time>=?';
        $params[] = (string) $filters['since'];
    }

    $where_sql = ' WHERE ' . implode(' AND ', $where);
    $total = (int) db_fetch_cell_prepared(
        'SELECT COUNT(*) FROM plugin_icct_nms_syslog_events e INNER JOIN host h ON h.id=e.host_id' . $where_sql,
        $params
    );
    $sql = "SELECT e.*,h.description,h.hostname,u.username AS ack_username
            FROM plugin_icct_nms_syslog_events e
            INNER JOIN host h ON h.id=e.host_id
            LEFT JOIN user_auth u ON u.id=e.acknowledged_by
            {$where_sql}
            ORDER BY e.event_time DESC,e.id DESC
            LIMIT " . (int) $offset . ',' . (int) $limit;
    return db_fetch_assoc_prepared($sql, $params);
}
