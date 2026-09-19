<section class="nms-panel nms-diagnostic-readiness">
	<div class="nms-panel-head">
		<div>
			<h2>Collector tool readiness</h2>
			<p>Live availability on this collector. A profile controls which checks are available for each device.</p>
		</div>
	</div>

	<div class="nms-diagnostic-readiness-grid">
		<?php foreach (nms_diag_labels() as $key => $label) {
			$tool = $nms_diagnostic_readiness[$key]; ?>
			<article class="<?php print $tool["ready"] ? "ready" : "unavailable"; ?>">
				<h3><?php print nms_h($label); ?></h3>
				<strong><?php print $tool["ready"] ? "Ready on collector" : "Offline package needed"; ?></strong>
				<p><?php print nms_h($tool["purpose"]); ?></p>
				<small><?php print nms_h($tool["requirement"]); ?></small>
				<?php if (!$tool["ready"] && $key === "netperf") { ?>
					<code>sudo dnf install -y ./netperf*.rpm</code>
				<?php } ?>
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
			<p>Ping and traceroute test reachability. ARP reads the collector cache. iPerf3 and Netperf generate test traffic to the selected device.</p>
		</div>
	</div>

	<?php if (!$devices) { ?>
		<p class="nms-empty">No device has a diagnostic profile. Create a profile, then choose it in Add/Edit device → On-demand diagnostics.</p>
	<?php } else { ?>
		<form method="post" class="nms-config-form">
			<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
			<input type="hidden" name="nms_action" value="run_diagnostic">

			<label>
				Device
				<select id="nmsDiagnosticHost" name="host_id" required>
					<?php foreach ($devices as $device) { ?>
						<option value="<?php print (int) $device["id"]; ?>" data-tools="<?php print nms_h($device["tools"]); ?>" <?php print (int) $device["id"] === $selected_diagnostic_host_id ? "selected" : ""; ?>>
							<?php print nms_h($device["description"] . " · " . $device["hostname"] . " · " . $device["profile_name"]); ?>
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

			<p>
				iPerf3 requires an iperf3 server on TCP 5201 at the selected endpoint.
				Netperf requires netserver. Pathchar is optional and commonly unavailable
				on modern RHEL.
			</p>
			<button type="submit">Run selected test</button>
		</form>
	<?php } ?>
</section>

<?php if ($result) { ?>
	<section class="nms-panel nms-diagnostic-result">
		<div class="nms-panel-head">
			<div>
				<h2><?php print nms_h(nms_diag_labels()[$result["tool"]] . " result"); ?></h2>
				<p class="nms-diagnostic-result-meta">
					<span><?php print nms_h($result["target"]); ?></span>
					<span>Profile: <?php print nms_h($result["profile"]); ?></span>
					<span>Exit code: <?php print (int) $result["exit"]; ?></span>
				</p>
			</div>
		</div>
		<pre class="nms-diagnostic-output"><?php print nms_h($result["output"] ?: "No output returned."); ?></pre>
	</section>
<?php } ?>
