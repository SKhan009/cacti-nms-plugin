<?php
/** Render the saved diagnostic-profile list. */
?>
<section class="nms-panel">
	<div class="nms-panel-head">
		<div>
			<h2>Diagnostic profiles</h2>
			<p>Reusable safe test limits. Assign a profile in a device’s Discovery and connections section.</p>
		</div>
		<a class="nms-panel-action" href="diagnostics.php?section=profiles&amp;profile_new=1" data-nms-config-open>+ Add profile</a>
	</div>

	<div class="nms-table-wrap">
		<table class="nms-table">
			<thead>
				<tr>
					<th>Name</th>
					<th>Enabled tools</th>
					<th>Limits</th>
					<th>Action</th>
				</tr>
			</thead>
			<tbody>
				<?php if (!$profiles) { ?>
					<tr><td colspan="4">No profiles yet.</td></tr>
				<?php } ?>
				<?php foreach ($profiles as $profile) { ?>
					<tr>
						<td><?php print nms_h($profile["name"]); ?></td>
							<td>
								<?php print nms_h(
        	implode(", ", array_map(fn($tool) => nms_diag_labels()[$tool], nms_diag_tools($profile["tools"]))),
        ); ?>
							</td>
							<td>
								<?php print (int) $profile["ping_count"]; ?> pings ·
								<?php print (int) $profile["trace_hops"]; ?> hops ·
								<?php print (int) $profile["bandwidth_seconds"]; ?> seconds
							</td>
							<td>
								<a class="nms-catalog-button"
									href="diagnostics.php?section=profiles&amp;profile_id=<?php print (int) $profile["id"]; ?>"
									data-nms-config-open>Edit</a>
							</td>
					</tr>
				<?php } ?>
			</tbody>
		</table>
	</div>
</section>
