<?php

/** Passive events are retained independently of the rules that raise alarms. */
function icct_nms_passive_fault_rules($id)
{
    $json = db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?', [
        'passive_fault_rules_' . (int) $id,
    ]);
    return $json ? json_decode($json, true, 32, JSON_THROW_ON_ERROR) : [];
}

function icct_nms_passive_oid($value)
{
    if (!is_string($value)) {
        throw new InvalidArgumentException('Enter a numeric OID.');
    }
    $value = ltrim(trim($value), '.');
    if (strlen($value) > 128 || !preg_match('/^[0-2](?:\.(?:0|[1-9][0-9]{0,9}))+$/D', $value)) {
        throw new InvalidArgumentException('Enter a numeric OID.');
    }
    return $value;
}

function icct_nms_passive_fault_validate($input)
{
    if (!is_array($input) || count($input) > 100) {
        throw new InvalidArgumentException('Use no more than 100 event rules.');
    }
    $rules = $matches = $groups = [];
    foreach ($input as $row) {
        $source = $row['source'] ?? '';
        if (!is_array($row) || !in_array($source, ['syslog', 'trap'], true)) {
            throw new InvalidArgumentException('Choose Syslog or SNMP Traps.');
        }
        $rule = ['source' => $source];
        foreach (
            ['name' => 160, 'message' => 160, 'program' => 128, 'value' => 160, 'group' => 64]
            as $key => $limit
        ) {
            $text = $row[$key] ?? '';
            if (
                !is_string($text) ||
                !mb_check_encoding($text, 'UTF-8') ||
                mb_strlen($text) > $limit ||
                preg_match('/[\x00-\x1f\x7f]/', $text)
            ) {
                throw new InvalidArgumentException('Invalid event rule ' . $key . '.');
            }
            $rule[$key] = trim($text);
        }
        $rule['severity'] = $row['severity'] ?? '';
        if (
            $rule['name'] === '' ||
            !in_array(
                $rule['severity'],
                ['Critical', 'Major', 'Minor', 'Warning', 'Information'],
                true,
            )
        ) {
            throw new InvalidArgumentException('Enter an alarm name and choose its severity.');
        }
        $rule['enabled'] = ($row['enabled'] ?? false) === true;
        if ($source === 'syslog') {
            foreach (['severity_code' => 7, 'facility_code' => 23] as $key => $maximum) {
                $code = $row[$key] ?? '';
                if (
                    $code !== '' &&
                    (!is_scalar($code) || !ctype_digit((string) $code) || (int) $code > $maximum)
                ) {
                    throw new InvalidArgumentException(
                        'Choose a valid Syslog severity or facility.',
                    );
                }
                $rule[$key] = $code === '' ? null : (int) $code;
            }
            if (
                $rule['severity_code'] === null &&
                $rule['facility_code'] === null &&
                $rule['message'] === '' &&
                $rule['program'] === ''
            ) {
                throw new InvalidArgumentException(
                    'Choose at least one condition for each Syslog alarm.',
                );
            }
            $identity = [
                $source,
                $rule['severity_code'],
                $rule['facility_code'],
                mb_strtolower($rule['program']),
                mb_strtolower($rule['message']),
            ];
        } else {
            $rule['oid'] = icct_nms_passive_oid($row['oid'] ?? '');
            $varbind = $row['varbind'] ?? '';
            $rule['varbind'] =
                is_string($varbind) && trim($varbind) === '' ? '' : icct_nms_passive_oid($varbind);
            if (($rule['varbind'] === '') !== ($rule['value'] === '')) {
                throw new InvalidArgumentException(
                    'Enter both the field OID and its expected value, or leave both blank.',
                );
            }
            $identity = [$source, $rule['oid'], $rule['varbind'], $rule['value']];
        }
        $key = json_encode($identity, JSON_THROW_ON_ERROR);
        if (isset($matches[$key])) {
            throw new InvalidArgumentException('Remove duplicate event conditions.');
        }
        $matches[$key] = true;
        if ($rule['group'] !== '') {
            $rule['group'] = mb_strtolower($rule['group']);
            $presentation = [$rule['name'], $rule['severity']];
            if (isset($groups[$rule['group']]) && $groups[$rule['group']] !== $presentation) {
                throw new InvalidArgumentException(
                    'Rules sharing an incident group must use the same name and severity.',
                );
            }
            $groups[$rule['group']] = $presentation;
        }
        $rules[] = $rule;
    }
    return $rules;
}

function icct_nms_save_passive_faults($id, $input)
{
    icct_backend_require_management(3);
    icct_backend_require_device_access($id);
    $rules = icct_nms_passive_fault_validate(
        json_decode((string) ($input['passive_rules'] ?? ''), true, 32, JSON_THROW_ON_ERROR),
    );
    icct_backend_category_execute(
        'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ' .
            'ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
        ['passive_fault_rules_' . (int) $id, json_encode($rules, JSON_THROW_ON_ERROR)],
    );
}

/** First enabled matching rule wins, so overlapping rules cannot raise duplicate alarms. */
function icct_nms_passive_fault_match($event, $rules)
{
    $source = $event['event_origin'];
    $varbinds =
        $source === 'trap' ? json_decode($event['raw_message'], true, 32, JSON_THROW_ON_ERROR) : [];
    foreach ($rules as $rule) {
        if (!$rule['enabled'] || $rule['source'] !== $source) {
            continue;
        }
        if ($source === 'syslog') {
            if (
                ($rule['severity_code'] !== null &&
                    $rule['severity_code'] !== (int) $event['severity_code']) ||
                ($rule['facility_code'] !== null &&
                    $rule['facility_code'] !== (int) $event['facility_code']) ||
                ($rule['program'] !== '' &&
                    strcasecmp($rule['program'], $event['program']) !== 0) ||
                ($rule['message'] !== '' &&
                    mb_stripos($event['message'], $rule['message']) === false)
            ) {
                continue;
            }
        } elseif (
            $rule['oid'] !== $event['program'] ||
            ($rule['varbind'] !== '' &&
                !in_array($rule['value'], $varbinds[$rule['varbind']] ?? [], true))
        ) {
            continue;
        }
        return $rule;
    }
    return null;
}
