<?php
/** Separate reachability checks from bandwidth measurements while sharing saved results. */
$diagnostic_groups = [
    'device' => ['title' => 'Device diagnostics', 'description' => 'Check device replies, delays and the network path.',
        'tools' => ['ping', 'traceroute', 'traceroute_icmp', 'traceroute_tcp', 'mtr_icmp', 'mtr_tcp', 'nping_icmp', 'nping_tcp', 'hping3_icmp', 'hping3_tcp', 'arp']],
    'bandwidth' => ['title' => 'Bandwidth tests', 'description' => 'Measure transfer speed or estimate path capacity. iPerf3 and Netperf need a test server on remote devices.',
        'tools' => ['iperf3', 'netperf', 'pathchar']],
];
foreach ($diagnostic_groups as $group_key => $group) {
    $group_id = $group_key === 'device' ? 'diagnostic-run' : 'bandwidth-run';
    $host_id = $group_key === 'device' ? 'nmsDiagnosticHost' : 'nmsBandwidthHost';
    $tool_id = $group_key === 'device' ? 'nmsDiagnosticTool' : 'nmsBandwidthTool';
?>
<section id="<?php print $group_id; ?>" class="nms-panel nms-diagnostic-readiness" data-diagnostic-group="<?php print $group_key; ?>">
    <div class="nms-panel-head"><div>
        <h2><?php print nms_h($group['title']); ?></h2>
        <p><?php print nms_h($group['description']); ?></p>
    </div></div>
    <div class="nms-diagnostic-readiness-grid">
        <?php foreach ($group['tools'] as $key) { ?>
        <article tabindex="0" data-diagnostic-tool="<?php print nms_h($key); ?>" data-nms-tip="<?php print nms_h($nms_diagnostic_readiness[$key]); ?>">
            <h3><?php print nms_h(nms_diag_labels()[$key]); ?></h3>
            <span class="nms-help-icon" aria-hidden="true">?</span>
        </article>
        <?php } ?>
    </div>
    <?php if (!$devices) { ?>
    <p class="nms-empty">Assign a diagnostic profile to a device to run these checks.</p>
    <?php } else { ?>
    <form method="post" action="diagnostics.php?node_id=<?php print $node_id; ?>#<?php print $group_id; ?>" class="nms-config-form nms-diagnostic-run-form" data-diagnostic-form="<?php print $group_key; ?>">
        <input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
        <input type="hidden" name="nms_action" value="run_diagnostic">
        <label>Device
            <select id="<?php print $host_id; ?>" name="host_id" required>
            <?php foreach ($devices as $device) { ?>
                <option value="<?php print (int)$device['id']; ?>" data-collector="<?php print (int)$device['poller_id']; ?>" data-tools="<?php print nms_h($device['tools']); ?>" <?php print (int)$device['id'] === $selected_diagnostic_host_id ? 'selected' : ''; ?>><?php print nms_h($device['description'] . ' · ' . $device['hostname']); ?></option>
            <?php } ?>
            </select>
        </label>
        <label>Test
            <select id="<?php print $tool_id; ?>" name="tool" required>
            <?php foreach ($group['tools'] as $key) { ?>
                <option value="<?php print nms_h($key); ?>" <?php print $key === $selected_diagnostic_tool ? 'selected' : ''; ?>><?php print nms_h(nms_diag_labels()[$key]); ?></option>
            <?php } ?>
            </select>
        </label>
        <p class="nms-empty" data-no-tools hidden>This device’s profile has no tests enabled in this section.</p>
        <button type="submit" class="nms-diagnostic-run-button"><?php print $group_key === 'device' ? 'Run device diagnostic' : 'Run bandwidth test'; ?></button>
    </form>
    <?php } ?>
</section>
<?php } ?>

<?php if ($diagnostic_job && in_array($diagnostic_job['status'], ['queued', 'running'], true)) { ?>
    <section id="nms-diagnostic-status" class="nms-panel nms-diagnostic-status" aria-live="polite" data-job-id="<?php print (int) $diagnostic_job['id']; ?>">
        <div class="nms-panel-head"><div><h2 data-diagnostic-progress-title>Starting test…</h2><p>Collector <?php print (int) $diagnostic_job['poller_id']; ?> · <?php print nms_h(nms_diag_labels()[$diagnostic_job['tool']] ?? 'Diagnostic'); ?></p></div></div>
        <div class="nms-diagnostic-panel-body"><p data-diagnostic-progress-message>Your test will start promptly. The result appears here automatically when it finishes.</p><a href="diagnostics.php?section=run&amp;job_id=<?php print (int) $diagnostic_job['id']; ?>">Refresh result</a></div>
    </section>
<?php } ?>
<?php if (is_array($result)) { ?>
	<section id="diagnostic-result" class="nms-panel nms-diagnostic-result">
		<div class="nms-panel-head">
			<div>
				<h2><?php print nms_h((nms_diag_labels()[$result["tool"] ?? ""] ?? "Diagnostic") . " result"); ?></h2>
				<p class="nms-diagnostic-result-meta">
					<span><?php print nms_h($result["target"] ?? ""); ?></span>
					<span>Execution host: <?php print nms_h($result["execution_host"] ?? "Not started"); ?></span>
					<span>Profile: <?php print nms_h(($result["profile"] ?? "") ?: "Not available"); ?></span>
					<span>Exit code: <?php print isset($result["exit"]) ? (int) $result["exit"] : "Not started"; ?></span>
				</p>
			</div>
		</div>
        <?php require_once __DIR__ . '/../../includes/diagnostic_summary.php'; ?>
        <div class="nms-diagnostic-panel-body" data-result-tabs>
            <div class="nms-result-tabs" role="tablist" aria-label="Result view">
                <button type="button" role="tab" id="result-summary-tab" aria-controls="result-summary" aria-selected="true">Description</button>
                <button type="button" role="tab" id="result-technical-tab" aria-controls="result-technical" aria-selected="false" tabindex="-1">Technical output</button>
            </div>
            <div id="result-summary" role="tabpanel" aria-labelledby="result-summary-tab">
                <?php $description = nms_diag_description($result); ?>
                <p class="nms-result-status nms-result-status-<?php print nms_h($description['tone']); ?>" role="status"><?php print nms_h($description['status']); ?></p>
                <dl class="nms-result-metrics">
                    <?php foreach ($description['metrics'] as $label => $value) { ?>
                    <div><dt><?php print nms_h($label); ?></dt><dd><?php print nms_h($value); ?></dd></div>
                    <?php } ?>
                </dl>
                <?php foreach ($description['lines'] as $explanation) { ?><p><?php print nms_h($explanation); ?></p><?php } ?>
            </div>
            <div id="result-technical" role="tabpanel" aria-labelledby="result-technical-tab" hidden>
                <?php if (preg_match('/\A\$ ([^\r\n]+)(?:\r?\n|$)/', (string) ($result['output'] ?? ''), $recorded_command)) { ?>
                <p><strong>Manual sudo command</strong> — run on the execution host shown above.</p>
                <pre class="nms-diagnostic-output"><?php print nms_h('sudo ' . $recorded_command[1]); ?></pre>
                <?php if (!empty($result['self_test']) && in_array($result['tool'] ?? '', ['iperf3', 'netperf'], true)) { ?>
                <p>This test used a temporary local server that has stopped. Start a matching server on the recorded address and port before repeating this command.</p>
                <?php } ?>
                <p><strong>Recorded collector command and output</strong> — the collector ran this without sudo.</p>
                <?php } ?>
                <pre class="nms-diagnostic-output"><?php print nms_h(($result["output"] ?? "") ?: "No output returned."); ?></pre>
            </div>
        </div>
        <script>
        (function () {
            var root = document.querySelector('[data-result-tabs]');
            var tabs = Array.from(root.querySelectorAll('[role="tab"]'));
            function select(tab) {
                tabs.forEach(function (item) {
                    var active = item === tab;
                    item.setAttribute('aria-selected', String(active));
                    item.tabIndex = active ? 0 : -1;
                    document.getElementById(item.getAttribute('aria-controls')).hidden = !active;
                });
            }
            tabs.forEach(function (tab, index) {
                tab.addEventListener('click', function () { select(tab); });
                tab.addEventListener('keydown', function (event) {
                    if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(event.key) < 0) return;
                    event.preventDefault();
                    var next = tabs[event.key === 'Home' ? 0 : event.key === 'End' ? 1 : 1 - index];
                    select(next); next.focus();
                });
            });
        }());
        </script>
	</section>
<?php } ?>

<?php require __DIR__ . '/history.php'; ?>
<?php
$runner_states = [];
foreach ($devices as $device) {
    $collector_id = (int) $device['poller_id'];
    if (!array_key_exists($collector_id, $runner_states)) $runner_states[$collector_id] = nms_diag_runner($collector_id);
}
?>
<script type="application/json" id="nms-diagnostic-runners"><?php print json_encode($runner_states, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
