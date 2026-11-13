<?php

/** Automatic generation delegates each committed stage to the native Cacti wizard service. */
function icct_mib_generation_input($bundle, $stage, $object = null)
{
    if (!in_array($stage, [2, 3, 4], true)) {
        throw new InvalidArgumentException('Choose a valid generation stage.');
    }
    $input = [
        'step' => $stage - 1,
        'type_id' => $bundle['type_id'],
        'template_name' => $bundle['name'],
        'selected' => [],
        'records' => [],
    ];
    if ($stage === 2) {
        $objects = icct_mib_objects($bundle);
        $existing = icct_mib_existing_source_indexes($objects);
        $targets =
            $object === null ? array_keys($objects) : icct_mib_creation_targets($objects, $object);
        foreach ($targets as $i) {
            if (!empty($objects[$i]['numeric']) && !isset($existing[$i])) {
                $input['selected'][$i] = 1;
            }
        }
        if (!$input['selected']) {
            throw new InvalidArgumentException(
                'No new supported OIDs remain. Existing Cacti data sources will not be duplicated.',
            );
        }
    }
    return $input;
}

function icct_mib_generation_results($bundle, $stage)
{
    $labels = [2 => 'Data Source', 3 => 'Graph Template', 4 => 'Device Template'];
    $label = $labels[$stage];
    $results = [];
    if ((int) ($bundle['wizard_step'] ?? 0) < $stage - 1) {
        return $results;
    }
    if ($stage === 4) {
        $results[] = [
            'name' => $bundle['name'],
            'oid' => '',
            'id' => (int) $bundle['host_template_id'],
            'kind' => $label,
        ];
    } else {
        foreach ($bundle['rows'] as $row) {
            $results[] = [
                'name' => $row['name'],
                'oid' => $row['oid'],
                'id' => (int) $row[$stage === 2 ? 'data_template_id' : 'graph_template_id'],
                'kind' => $label,
            ];
        }
    }
    return $results;
}

/** Session-bound review snapshots never write Cacti templates. */
function icct_mib_generation_draft_get($token, $id, $phase)
{
    if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/D', $token)) {
        throw new InvalidArgumentException('Review expired. Select the OIDs again.');
    }
    $draft = $_SESSION['icct_mib_generation_' . $token] ?? null;
    if (
        !$draft ||
        $draft['id'] !== $id ||
        $draft['phase'] !== $phase ||
        time() - ($draft['last_access'] ?? $draft['time']) > 14400
    ) {
        throw new InvalidArgumentException('Review expired. Select the OIDs again.');
    }
    $draft['last_access'] = time();
    $_SESSION['icct_mib_generation_' . $token] = $draft;
    return $draft;
}
function icct_mib_generation_draft_put($id, $phase, $review)
{
    foreach ($_SESSION as $key => $value) {
        if (
            strpos($key, 'icct_mib_generation_') === 0 &&
            is_array($value) &&
            time() - ($value['last_access'] ?? ($value['time'] ?? 0)) > 14400
        ) {
            unset($_SESSION[$key]);
        }
    }
    $token = bin2hex(random_bytes(16));
    $_SESSION['icct_mib_generation_' . $token] = [
        'id' => $id,
        'phase' => $phase,
        'time' => time(),
        'last_access' => time(),
        'review' => $review,
    ];
    return $token;
}
function icct_mib_generation_prepare($original, $review, $confirmed)
{
    if (!is_array($review['selected'] ?? null) || !is_array($review['records'] ?? null)) {
        throw new InvalidArgumentException('Invalid OID review.');
    }
    if (array_diff_key($review['selected'], $original)) {
        throw new InvalidArgumentException(
            'One or more OIDs were already generated or are no longer available. Select the OIDs again.',
        );
    }
    foreach ($review['selected'] as $value) {
        if (!in_array($value, [1, '1', true], true)) {
            throw new InvalidArgumentException('Invalid OID selection.');
        }
    }
    $selected = $review['selected'];
    if (!$selected) {
        throw new InvalidArgumentException('Select at least one supported OID.');
    }
    if (array_diff_key($original, $selected) && !$confirmed) {
        throw new InvalidArgumentException('Confirm the unchecked OIDs before continuing.');
    }
    return [
        'selected' => $selected,
        'records' => array_intersect_key($review['records'], $selected),
    ];
}
