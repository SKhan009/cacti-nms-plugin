<?php

/** Canonical binary equality keeps IPv6 spelling and IPv4-mapped peers from changing identity. */
function icct_nms_syslog_ip($value)
{
    if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_IP)) {
        throw new InvalidArgumentException('A numeric Syslog sender IP address is required.');
    }
    $packed = inet_pton($value);
    if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
        $packed = substr($packed, 12);
    }
    return inet_ntop($packed);
}

/** Reject invalid dates rather than substituting the importer clock for a sender timestamp. */
function icct_backend_syslog_timestamp($value)
{
    if (
        !is_string($value) ||
        !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,9})?(?:Z|[+-]\d\d:\d\d)$/D', $value)
    ) {
        throw new InvalidArgumentException('Invalid Syslog timestamp.');
    }
    $date = new DateTimeImmutable($value);
    $errors = DateTimeImmutable::getLastErrors();
    if ($errors && ($errors['warning_count'] || $errors['error_count'])) {
        throw new InvalidArgumentException('Invalid Syslog date.');
    }
    return $date->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
}

/** Validate the receiver envelope; preserve missing sender time as receiver time with provenance. */
function icct_nms_syslog_normalize(array $record)
{
    $out = ['source_ip' => icct_nms_syslog_ip($record['source_ip'] ?? null)];
    foreach (['severity_code' => 7, 'facility_code' => 23] as $field => $maximum) {
        $value = $record[$field] ?? null;
        if (
            (!is_int($value) && !is_string($value)) ||
            !preg_match('/^[0-9]+$/D', (string) $value) ||
            (int) $value > $maximum
        ) {
            throw new InvalidArgumentException('Invalid Syslog ' . $field . '.');
        }
        $out[$field] = (int) $value;
    }
    foreach (['hostname' => 255, 'program' => 128, 'message' => 16384] as $field => $limit) {
        $text = $record[$field] ?? '';
        if (
            !is_string($text) ||
            strlen($text) > $limit ||
            str_contains($text, "\0") ||
            !mb_check_encoding($text, 'UTF-8')
        ) {
            throw new InvalidArgumentException('Invalid or oversized Syslog ' . $field . '.');
        }
        $out[$field] = trim($text);
    }
    if ($out['message'] === '') {
        throw new InvalidArgumentException('Empty Syslog message.');
    }
    $out['transport'] = icct_backend_syslog_transport($record['input_name'] ?? '');
    if ($out['transport'] === 'unknown') {
        throw new InvalidArgumentException('Unknown Syslog transport.');
    }
    $out['receiver_time'] = isset($record['received_timestamp'])
        ? icct_backend_syslog_timestamp($record['received_timestamp'])
        : null;
    $out['timestamp_kind'] = 'legacy-reported';
    $out['raw_message'] = $record['raw_message'] ?? null;
    $time = $record['timestamp'] ?? null;
    if ($out['raw_message'] !== null) {
        if (
            !is_string($out['raw_message']) ||
            strlen($out['raw_message']) > 65535 ||
            !preg_match('/^<([0-9]{1,3})>/', $out['raw_message'], $priority) ||
            (int) $priority[1] !== $out['facility_code'] * 8 + $out['severity_code']
        ) {
            throw new InvalidArgumentException('Invalid Syslog priority or raw message.');
        }
        if (preg_match('/^<[0-9]+>1 (\S+) /', $out['raw_message'], $header)) {
            $time = $header[1] === '-' ? null : $header[1];
            $out['timestamp_kind'] = $time === null ? 'receiver' : 'device';
        } else {
            $out['timestamp_kind'] = preg_match(
                '/^<[0-9]+>[A-Z][a-z]{2} [ 0-9][0-9] \d\d:\d\d:\d\d /',
                $out['raw_message'],
            )
                ? 'receiver-interpreted'
                : 'receiver';
            if ($out['timestamp_kind'] === 'receiver') {
                $time = null;
            }
        }
        if ($out['receiver_time'] === null) {
            throw new InvalidArgumentException('Receiver timestamp missing from Syslog envelope.');
        }
    }
    $out['event_time'] =
        $time === null && $out['timestamp_kind'] === 'receiver'
            ? $out['receiver_time']
            : icct_backend_syslog_timestamp($time);
    return $out;
}
