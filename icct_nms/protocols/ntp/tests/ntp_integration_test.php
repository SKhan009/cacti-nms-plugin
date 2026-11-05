<?php

/** Native round trips are permitted only in the disposable integration database. */
require '/var/www/html/cacti/include/cli_check.php';
if (!str_starts_with((string) db_fetch_cell('SELECT DATABASE()'), 'icct_discovery_qa_')) {
    throw new RuntimeException('Disposable NTP QA database required.');
}
api_plugin_load_realms();
require_once $config['base_path'] . '/lib/auth.php';
$_SESSION = ['sess_user_id' => 1];
require_once __DIR__ . '/../../../shared/services/bootstrap.php';
icct_nms_backend();
require_once __DIR__ . '/../services/ntp_collector.php';
require_once __DIR__ . '/../../shared/services/protocol_seed.php';
require_once __DIR__ . '/../../shared/services/protocol_service.php';
function ntpIntegration($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$id = 2;
// Reset this fixture's history only after the disposable-database guard above.
db_execute('DELETE FROM plugin_icct_nms_ntp_monitor_history WHERE host_id=2');
$preset = icct_nms_protocol_presets()['ntp'];
icct_nms_ntp_save($id, $preset);
ntpIntegration(
    icct_nms_ntp_current($id)['status'] === 'Not collected',
    'Invented initial reading.',
);
icct_nms_ntp_collect($id, 'local');
$current = icct_nms_ntp_current($id);
ntpIntegration(
    $current['sample'] !== null,
    'Actual local chrony tracking was not collected: ' . $current['error'],
);
ntpIntegration(
    $current['sample']['jitter_ms'] !== null &&
        $current['sample']['peer_delay_ms'] !== null &&
        $current['sample']['jitter_samples'] >= 3,
    'Actual selected-source jitter or peer delay missing.',
);
$storedSample = json_decode(
    db_fetch_cell(
        'SELECT sample_json FROM plugin_icct_nms_ntp_monitor_history WHERE host_id=2 ORDER BY id DESC LIMIT 1',
    ),
    true,
    16,
    JSON_THROW_ON_ERROR,
);
ntpIntegration(
    $storedSample['jitter_ms'] === $current['sample']['jitter_ms'] &&
        $storedSample['peer_delay_ms'] === $current['sample']['peer_delay_ms'],
    'Per-device history lost measurements.',
);
$observed = icct_nms_ntp_parse(icct_backend_diag_run_command(icct_nms_ntp_command(), 5)['stdout']);
ntpIntegration(
    $current['sample']['source_id'] === $observed['source_id'],
    'Collected a different clock source.',
);
ntpIntegration(
    (int) db_fetch_cell(
        'SELECT COUNT(*) FROM plugin_icct_nms_ntp_monitor_history WHERE host_id=2',
    ) === 1,
    'History missing or duplicated.',
);

// Protocol presets change drafts only. Alarm policy belongs to the shared FCAPS editor.
$original = icct_nms_ntp_assignment($id);
icct_nms_save_protocol_preset(array_replace($preset, ['preset_protocol' => 'ntp', 'timeout' => 6]));
ntpIntegration(
    icct_nms_ntp_assignment($id)['timeout'] === $original['timeout'],
    'Preset edited assignment.',
);
require_once __DIR__ . '/../../../fcaps/services/fcaps_service.php';
$host = db_fetch_row_prepared('SELECT * FROM host WHERE id=?', [$id]);
$rules = icct_nms_ntp_fault_starters();
$rules[] = array_replace($rules[0], [
    'parameter' => 'source_matches',
    'expected_source' => '192.0.2.250',
    'condition' => 'equals',
    'maximum' => 0,
    'name' => 'QA expected source',
    'severity' => 'Major',
]);
$rules = icct_nms_fault_validate($rules, [], []);
icct_nms_save_faults($id, ['fault_rules' => json_encode($rules, JSON_THROW_ON_ERROR)]);
$observations = icct_nms_ntp_fault_observations($host, $rules);
ntpIntegration(
    $observations[3]['state'] === 'Major' && $observations[3]['value'] === 0,
    'Expected-source threshold or severity lost.',
);
foreach (range(1, 2) as $repeat) {
    icct_nms_alarm_record($host, $rules[3], $observations[3]);
}
ntpIntegration(
    (int) db_fetch_cell(
        "SELECT COUNT(*) FROM plugin_icct_nms_alarms WHERE source='NTP' AND name='QA expected source' AND host_id=2",
    ) === 1,
    'Duplicate NTP alarm.',
);
ntpIntegration(
    icct_nms_ntp_current($id)['status'] === 'Synchronized',
    'Alarm policy changed collection state.',
);
try {
    icct_nms_fault_validate([$rules[0], $rules[0]], [], []);
    throw new LogicException('Duplicate threshold accepted.');
} catch (InvalidArgumentException $expected) {
}
// Lifecycle is idempotent and does not replace an operator threshold.
require_once __DIR__ . '/../../../shared/services/ntp_fault_migration.php';
$beforeRules = icct_nms_fault_rules($id);
icct_nms_schema_ntp_faults();
ntpIntegration($beforeRules === icct_nms_fault_rules($id), 'Repeated migration changed rules.');

// Real native graph bindings remain unchanged when several MIB presets are selected.
$associated = array_map(
    'intval',
    array_column(
        db_fetch_assoc_prepared(
            'SELECT graph_template_id FROM host_graph WHERE host_id=? UNION SELECT graph_template_id FROM graph_local WHERE host_id=?',
            [$id, $id],
        ),
        'graph_template_id',
    ),
);
$binding = array_values(
    array_filter(
        icct_nms_fault_catalogue(),
        fn($row) => in_array((int) $row['template_id'], $associated, true),
    ),
)[0];
$key = 'graph_fault_presets_' . $binding['template_id'];
$priorPresets = db_fetch_cell_prepared(
    'SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
    [$key],
);
$mib = [];
foreach ([1, 2] as $n) {
    $mib[] = array_replace($rules[0], [
        'source' => 'rrd',
        'parameter' => $binding['data_source_name'],
        'template_id' => (int) $binding['template_id'],
        'metric_id' => (int) $binding['metric_id'],
        'maximum' => 80 + $n,
        'preset_key' => hash('sha256', 'private-mib-' . $n),
        'name' => 'Private MIB ' . $n,
    ]);
}
try {
    db_execute_prepared(
        'REPLACE INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',
        [$key, json_encode($mib, JSON_THROW_ON_ERROR)],
    );
    $inherited = icct_nms_fault_presets($id);
    ntpIntegration(count($inherited) === 2, 'MIB preset picker did not fetch native associations.');
    $selected = array_merge($rules, $mib);
    $selected[count($rules)]['enabled'] = false;
    icct_nms_save_faults($id, ['fault_rules' => json_encode($selected, JSON_THROW_ON_ERROR)]);
    $savedMib = array_values(
        array_filter(icct_nms_fault_rules($id), fn($rule) => $rule['preset_key'] !== ''),
    );
    ntpIntegration(
        count($savedMib) === 2 && !$savedMib[0]['enabled'] && $savedMib[1]['enabled'],
        'MIB selection override duplicated or reset.',
    );
    ntpIntegration(
        $savedMib[1]['source'] === 'rrd' &&
            $savedMib[1]['metric_id'] === (int) $binding['metric_id'],
        'MIB native binding converted.',
    );
} finally {
    if ($priorPresets) {
        db_execute_prepared('UPDATE plugin_icct_nms_meta SET meta_value=? WHERE meta_key=?', [
            $priorPresets,
            $key,
        ]);
    } else {
        db_execute_prepared('DELETE FROM plugin_icct_nms_meta WHERE meta_key=?', [$key]);
    }
    icct_nms_save_faults($id, ['fault_rules' => json_encode($rules, JSON_THROW_ON_ERROR)]);
}

// Render the shared editor and inventory pane with an actual collected observation.
$ntp = $preset;
$presetMode = false;
ob_start();
require __DIR__ . '/../templates/settings.php';
$editor = ob_get_clean();
$display = static fn($value) => icct_nms_h(trim((string) $value) === '' ? '—' : $value);
ob_start();
require __DIR__ . '/../templates/device_view_ntp.php';
$pane = ob_get_clean();
$document = new DOMDocument();
$previousErrors = libxml_use_internal_errors(true);
try {
    $document->loadHTML('<html><body>' . $editor . $pane . '</body></html>');
    $xpath = new DOMXPath($document);
    foreach (icct_nms_ntp_fields() as $field) {
        ntpIntegration(
            $xpath->query('//*[@name="' . $field . '"]')->length === 1,
            'Missing or duplicated NTP editor field.',
        );
    }
    ntpIntegration(
        $xpath->query('//*[@data-device-view-panel="ntp"]')->length === 1,
        'Inventory NTP pane missing.',
    );
    ntpIntegration(str_contains($pane, 'Synchronized'), 'Collected status hidden in device view.');
} finally {
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);
}
db_execute(
    'UPDATE plugin_icct_nms_ntp_monitor_state SET last_attempt=DATE_SUB(NOW(),INTERVAL 1 DAY) WHERE host_id=2',
);
$stale = icct_nms_ntp_current($id);
ntpIntegration(
    $stale['status'] === 'Stale' && $stale['sample'] === null,
    'Stale measurement leaked into current data.',
);
icct_backend_protocol_state_write($id, 'ntp', false);
ntpIntegration(icct_nms_ntp_current($id)['status'] === 'Disabled', 'Disabled NTP still active.');
ntpIntegration(
    icct_nms_ntp_due('local', $config['poller_id']) === [],
    'Disabled device scheduled.',
);
icct_backend_protocol_state_write($id, 'ntp', true);
db_execute("UPDATE host SET hostname='192.0.2.250' WHERE id=2");
try {
    icct_nms_ntp_save($id, $preset);
    throw new LogicException('Remote device accepted local collection.');
} catch (InvalidArgumentException $expected) {
}
db_execute("UPDATE host SET hostname='127.0.0.1' WHERE id=2");
icct_nms_ntp_save($id, $preset);
// An unsupported transport cannot substitute a local result for an SSH failure.
icct_backend_protocol_state_write($id, 'ssh', false);
db_execute(
    "UPDATE plugin_icct_nms_ntp_monitors SET method='ssh',revision=revision+1 WHERE host_id=2",
);
icct_nms_ntp_collect($id, 'ssh');
$error = db_fetch_row_prepared('SELECT * FROM plugin_icct_nms_ntp_monitor_state WHERE host_id=2');
ntpIntegration(
    $error['status'] === 'Unavailable' && $error['sample_json'] === null,
    'SSH failure substituted measurements.',
);
ntpIntegration(icct_nms_ntp_current($id)['sample'] === null, 'Failed data shown as current.');
icct_backend_protocol_state_write($id, 'ssh', true);
echo "PASS: native assignments, independent preset snapshots, actual chrony collection, FCAPS severity and deduplicated alarms, stale/disabled data, endpoint protection, history and SSH failure without fallback.\n";
