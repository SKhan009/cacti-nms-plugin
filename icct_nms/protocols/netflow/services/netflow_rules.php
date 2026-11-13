<?php

/** SQL expressions are fixed here. Rule filters and thresholds are always data. */
function icct_nms_netflow_rule_metrics()
{
    $syn = "protocol=6 AND tcp_flags LIKE '%S%' AND tcp_flags NOT LIKE '%A%'";
    return [
        'bandwidth' => ['Bandwidth', 'Mbps', 'COALESCE(SUM(bytes),0)*8.000000/300000000'],
        'destinations' => ['Distinct destination IPs', 'IPs', 'COUNT(DISTINCT dst_addr)'],
        'ports' => ['Distinct destination ports', 'ports', 'COUNT(DISTINCT NULLIF(dst_port,0))'],
        'smb_destinations' => [
            'SMB/RPC destinations',
            'IPs',
            'COUNT(DISTINCT CASE WHEN protocol IN(6,17) AND dst_port IN(135,139,445) THEN dst_addr END)',
        ],
        'syn_rate' => [
            'TCP SYN rate',
            'packets/s',
            "COALESCE(SUM(CASE WHEN $syn THEN packets ELSE 0 END),0)/300",
        ],
        'distributed_syn_rate' => [
            'Distributed TCP SYN rate',
            'packets/s',
            "CASE WHEN COUNT(DISTINCT CASE WHEN $syn THEN src_addr END)>1 " .
            "THEN SUM(CASE WHEN $syn THEN packets ELSE 0 END)/300 ELSE 0 END",
        ],
    ];
}

/** Live overview uses the same metric expressions as the alarm rules. */
function icct_nms_netflow_live_readings($id, $windowEnd = null)
{
    $metrics = icct_nms_netflow_rule_metrics();
    $distributed = $metrics['distributed_syn_rate'][2];
    unset($metrics['distributed_syn_rate']);
    $columns = [];
    foreach ($metrics as $key => [, , $expression]) {
        $columns[] = "$expression AS $key";
    }
    $window = 'host_id=? AND last_seen BETWEEN FROM_UNIXTIME(?) AND FROM_UNIXTIME(?)';
    $end = $windowEnd === null ? time() : (int) $windowEnd;
    $args = [(int) $id, $end - 300, $end];
    $row = db_fetch_row_prepared(
        'SELECT COUNT(*) AS flows,UNIX_TIMESTAMP(MAX(last_seen)) AS latest_epoch,' .
            implode(',', $columns) .
            " FROM plugin_icct_nms_netflow_flows WHERE $window",
        $args,
    );
    if (!$row) {
        throw new RuntimeException('Unable to read recent NetFlow measurements.');
    }
    $values = [];
    if ((int) $row['flows'] > 0) {
        $peak = db_fetch_row_prepared(
            'SELECT COALESCE(MAX(rate),0) AS rate FROM (' .
                "SELECT $distributed AS rate FROM plugin_icct_nms_netflow_flows WHERE $window GROUP BY dst_addr" .
                ') AS rates',
            $args,
        );
        if (!$peak || !is_numeric($peak['rate'])) {
            throw new RuntimeException('Unable to read recent distributed SYN measurements.');
        }
        $values = array_map('floatval', array_intersect_key($row, $metrics));
        $values['distributed_syn_rate'] = (float) $peak['rate'];
    }
    return [
        'values' => $values,
        'latest_epoch' => (int) $row['latest_epoch'],
        'flows' => (int) $row['flows'],
    ];
}

function icct_nms_netflow_rules($id)
{
    $json = $id
        ? db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?', [
            'netflow_fault_rules_' . (int) $id,
        ])
        : null;
    return $json ? json_decode($json, true, 16, JSON_THROW_ON_ERROR) : [];
}

function icct_nms_netflow_rule_revision(array $rules)
{
    return hash('sha256', json_encode($rules, JSON_THROW_ON_ERROR));
}

function icct_nms_netflow_rule_lock($id, callable $work)
{
    $lock = 'icct_netflow_rules_' . (int) $id;
    if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?,5)', [$lock]) !== 1) {
        throw new RuntimeException('NetFlow rules are busy. Try again.');
    }
    try {
        return $work();
    } finally {
        db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}

function icct_nms_netflow_validate_rules($rows)
{
    if (!is_array($rows) || array_values($rows) !== $rows || count($rows) > 20) {
        throw new InvalidArgumentException('Use no more than 20 custom NetFlow rules per device.');
    }
    $rules = $ids = $conditions = [];
    $metrics = icct_nms_netflow_rule_metrics();
    foreach ($rows as $row) {
        if (!is_array($row)) {
            throw new InvalidArgumentException('Invalid NetFlow rule.');
        }
        $key = $row['id'] ?? '';
        $name = $row['name'] ?? '';
        $metric = $row['metric'] ?? '';
        $severity = $row['severity'] ?? '';
        if (
            !is_string($key) ||
            !preg_match('/^[a-f0-9]{24}$/D', $key) ||
            isset($ids[$key]) ||
            !is_string($name) ||
            !mb_check_encoding($name, 'UTF-8') ||
            mb_strlen(trim($name)) > 100 ||
            trim($name) === '' ||
            preg_match('/[\x00-\x1f\x7f]/', $name) ||
            !is_string($metric) ||
            !isset($metrics[$metric]) ||
            !in_array($severity, ['Information', 'Warning', 'Minor', 'Major', 'Critical'], true)
        ) {
            throw new InvalidArgumentException(
                'Enter an alarm name, traffic metric and severity for each rule.',
            );
        }
        $threshold = $row['threshold'] ?? '';
        if (
            !is_numeric($threshold) ||
            !is_finite((float) $threshold) ||
            $threshold <= 0 ||
            $threshold > 1000000000
        ) {
            throw new InvalidArgumentException(
                'Enter a threshold greater than 0 and no higher than 1,000,000,000.',
            );
        }
        $rule = [
            'id' => $key,
            'name' => trim($name),
            'metric' => $metric,
            'threshold' => (float) $threshold,
            'severity' => $severity,
            'enabled' => ($row['enabled'] ?? false) === true,
        ];
        foreach (['src_addr', 'dst_addr'] as $field) {
            $ip = $row[$field] ?? '';
            if (
                !is_string($ip) ||
                (trim($ip) !== '' && !filter_var(trim($ip), FILTER_VALIDATE_IP))
            ) {
                throw new InvalidArgumentException(
                    'Enter a valid source or destination IP address.',
                );
            }
            $rule[$field] = trim($ip) === '' ? '' : inet_ntop(inet_pton(trim($ip)));
        }
        $port = $row['dst_port'] ?? '';
        if (
            $port !== '' &&
            ((!is_int($port) && !is_string($port)) ||
                !ctype_digit((string) $port) ||
                (int) $port < 1 ||
                (int) $port > 65535)
        ) {
            throw new InvalidArgumentException('Enter a destination port from 1 to 65535.');
        }
        $rule['dst_port'] = $port === '' ? '' : (string) (int) $port;
        if (
            $metric === 'distributed_syn_rate' &&
            ($rule['dst_addr'] === '' || $rule['src_addr'] !== '')
        ) {
            throw new InvalidArgumentException(
                'For distributed TCP SYN rate, enter a destination IP and leave the source IP blank.',
            );
        }
        $condition = json_encode([
            $metric,
            $rule['threshold'],
            $rule['src_addr'],
            $rule['dst_addr'],
            $rule['dst_port'],
        ]);
        if (isset($conditions[$condition])) {
            throw new InvalidArgumentException(
                'Two rules use the same metric, threshold and filters.',
            );
        }
        $ids[$key] = $conditions[$condition] = true;
        $rules[] = $rule;
    }
    return $rules;
}

function icct_nms_netflow_save_rules($id, $input)
{
    icct_backend_require_management(3);
    icct_backend_require_device_access($id);
    $rules = icct_nms_netflow_validate_rules(
        json_decode((string) ($input['netflow_rules'] ?? ''), true, 16, JSON_THROW_ON_ERROR),
    );
    return icct_nms_netflow_rule_lock($id, function () use ($id, $input, $rules) {
        $old = icct_nms_netflow_rules($id);
        if (($input['netflow_revision'] ?? '') !== icct_nms_netflow_rule_revision($old)) {
            throw new RuntimeException(
                'NetFlow rules changed in another session. Reload the device before saving.',
            );
        }
        $enabled = array_column(array_filter($rules, static fn($rule) => $rule['enabled']), 'id');
        icct_backend_category_execute('START TRANSACTION');
        try {
            icct_backend_category_execute(
                'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ' .
                    'ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
                ['netflow_fault_rules_' . (int) $id, json_encode($rules, JSON_THROW_ON_ERROR)],
            );
            foreach ($old as $rule) {
                if ($rule['enabled'] && !in_array($rule['id'], $enabled, true)) {
                    icct_backend_category_execute(
                        "UPDATE plugin_icct_nms_netflow_events SET state='Resolved',closed_at=NOW(),updated_at=NOW(), " .
                            "message=CONCAT(message,' Rule disabled or removed.') WHERE host_id=? AND event_key=? AND state='Active'",
                        [(int) $id, 'rule_' . $rule['id']],
                    );
                }
            }
            icct_backend_category_execute('COMMIT');
        } catch (Throwable $error) {
            db_execute('ROLLBACK');
            throw $error;
        }
        return icct_nms_netflow_rule_revision($rules);
    });
}

function icct_nms_netflow_custom_findings($id, $window)
{
    $metrics = icct_nms_netflow_rule_metrics();
    $findings = [];
    foreach (icct_nms_netflow_rules($id) as $rule) {
        if (!$rule['enabled']) {
            continue;
        }
        [$label, $unit, $expression] = $metrics[$rule['metric']];
        $where = $window;
        $args = [(int) $id];
        $scope = [];
        foreach (
            ['src_addr' => 'source', 'dst_addr' => 'destination', 'dst_port' => 'destination port']
            as $key => $name
        ) {
            if ($rule[$key] === '') {
                continue;
            }
            $where .= " AND $key=?";
            $args[] = $rule[$key];
            $scope[] = $name . ' ' . $rule[$key];
        }
        $row = db_fetch_row_prepared(
            "SELECT $expression AS reading FROM plugin_icct_nms_netflow_flows WHERE $where",
            $args,
        );
        if (!$row || !is_numeric($row['reading'])) {
            throw new RuntimeException('Unable to read a custom NetFlow rule measurement.');
        }
        if ((float) $row['reading'] >= $rule['threshold']) {
            $findings['rule_' . $rule['id']] = [
                $rule['severity'],
                $rule['name'] .
                "\n" .
                $label .
                ' reached ' .
                round((float) $row['reading'], 6) .
                ' ' .
                $unit .
                ' over 5 minutes (threshold ' .
                $rule['threshold'] .
                ' ' .
                $unit .
                ')' .
                ($scope ? ' for ' . implode(', ', $scope) : ' across this exporter') .
                '.',
            ];
        }
    }
    return $findings;
}
