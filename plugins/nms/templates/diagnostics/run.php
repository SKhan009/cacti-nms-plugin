<section class="nms-panel nms-diagnostic-readiness">
	<div class="nms-panel-head">
		<div>
			<h2>Collector tool requirements</h2>
			<p>Tests start on the selected device’s collector as soon as its runner is ready. Results appear automatically.</p>
		</div>
	</div>

	<div class="nms-diagnostic-readiness-grid">
		<?php foreach (nms_diag_labels() as $key => $label) {
			$tool = $nms_diagnostic_readiness[$key]; ?>
			<article data-diagnostic-tool="<?php print nms_h($key); ?>">
				<h3><?php print nms_h($label); ?></h3>
				<strong data-diagnostic-availability>Checking collector</strong>
				<p><?php print nms_h($tool["purpose"]); ?></p>
				<small><?php print nms_h($tool["requirement"]); ?></small>
			</article>
		<?php } ?>
	</div>
</section>


<?php
/** Render the on-demand diagnostic form, collector readiness and last result. */
?>
<section class="nms-panel">
	<div class="nms-panel-head">
		<div>
			<h2>Run a diagnostic</h2>
			<p>Ping and traceroute test reachability. Collector ARP lookup displays the full collector neighbour cache. iPerf3 and Netperf generate test traffic to the selected device.</p>
		</div>
	</div>

	<?php if (!$devices) { ?>
		<p class="nms-empty">No device has a diagnostic profile. Create a profile, then choose it in Add/Edit device → On-demand diagnostics.</p>
	<?php } else { ?>
		<form method="post" class="nms-config-form nms-diagnostic-run-form">
			<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
			<input type="hidden" name="nms_action" value="run_diagnostic">

			<label>
				Device
				<select id="nmsDiagnosticHost" name="host_id" required>
					<?php foreach ($devices as $device) { ?>
						<option value="<?php print (int) $device["id"]; ?>" data-collector="<?php print (int) $device["poller_id"]; ?>" data-tools="<?php print nms_h($device["tools"]); ?>" <?php print (int) $device["id"] === $selected_diagnostic_host_id ? "selected" : ""; ?>>
							<?php print nms_h($device["description"] . " · " . $device["hostname"] . " · " . $device["profile_name"] . " · Collector " . (int) $device["poller_id"]); ?>
						</option>
					<?php } ?>
				</select>
			</label>

			<label>
				Tool
				<select id="nmsDiagnosticTool" name="tool" required>
					<?php foreach (nms_diag_labels() as $key => $label) { ?>
						<option value="<?php print nms_h($key); ?>" <?php print $key === $selected_diagnostic_tool ? "selected" : ""; ?>><?php print nms_h($label); ?></option>
					<?php } ?>
				</select>
			</label>


			<button type="submit" class="nms-diagnostic-run-button">Run selected test</button>
		</form>
	<?php } ?>
</section>

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
                <button type="button" role="tab" id="result-summary-tab" aria-controls="result-summary" aria-selected="true">Plain-language summary</button>
                <button type="button" role="tab" id="result-technical-tab" aria-controls="result-technical" aria-selected="false" tabindex="-1">Technical output</button>
            </div>
            <div id="result-summary" role="tabpanel" aria-labelledby="result-summary-tab">
                <?php foreach (nms_diag_plain_summary($result) as $explanation) { ?><p><?php print nms_h($explanation); ?></p><?php } ?>
            </div>
            <div id="result-technical" role="tabpanel" aria-labelledby="result-technical-tab" hidden>
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
