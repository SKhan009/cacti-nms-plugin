<section class="device-panel alarm-page" id="alarm-page" data-management="<?= $management
    ? '1'
    : '0' ?>">
<header class="alarm-page-heading"><div><p><a href="dashboard/controllers/topology.php">Dashboard</a> / Alarms</p><h1>Alarms</h1></div><button type="button" class="icon-button" id="alarm-export" aria-label="Export alarms"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V3m-4 4 4-4 4 4M5 10H3v11h18V10h-2"/></svg></button></header>
<form id="alarm-filters" class="alarm-filters">
<label class="field"><span>Device</span><select name="device"><option value="">All</option></select></label>
<label class="field"><span>Severity</span><select name="severity"><option value="">All</option><?php foreach (
    ['Critical', 'Major', 'Minor', 'Warning', 'Information'] as $v
): ?><option><?= $v ?></option><?php endforeach; ?></select></label>
<label class="field"><span>Ack Status</span><select name="ack"><option value="">All</option><option value="1">Ack</option><option value="0">Not Ack</option></select></label>
<label class="field"><span>Escalation Status</span><select name="esc"><option value="">All</option><option value="1">Esc</option><option value="0">Not Esc</option></select></label>
<button type="reset" class="button" id="alarm-clear">Clear All</button>
<label class="field alarm-search"><span class="sr-only">Search alarms</span><input name="search" placeholder="Search With Device Name or IP Address or Alarm Name" type="search"></label>
</form>
<p id="alarm-status" role="status" aria-live="polite"></p>
<div class="alarm-table-wrap"><table class="alarm-table"><thead><tr><th><button type="button" id="alarm-sort">Alarm Occurred ↓</button></th><th>Device Name &amp;<br> Short Name</th><th>IP Address &amp; Type<br> of Network Element</th><th>Severity &amp;<br> Ack Status</th><th>Escalation<br> &amp; Status</th><th>Alarm Name<br> (Count)</th><th>Alarm Description &amp; Fix</th><th>Actions</th></tr></thead><tbody id="alarm-rows"></tbody></table></div>
<footer class="alarm-pagination"><label>Items per page: <select id="alarm-page-size"><option>10</option><option>25</option><option>50</option><option>100</option></select></label><span id="alarm-page-count"></span><div><button type="button" id="alarm-prev" aria-label="Previous page" class="pagination-control"><svg class="pagination-arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 5 7 12 15 19Z"/></svg></button><span id="alarm-page-number"></span><button type="button" id="alarm-next" aria-label="Next page" class="pagination-control"><svg class="pagination-arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 5 17 12 9 19Z"/></svg></button></div></footer>
<form id="alarm-token" hidden><?php icct_nms_token(); ?></form>
<script type="application/json" id="alarm-data"><?= json_encode(
    $alarmRows,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE,
) ?></script>

<dialog id="alarm-action-dialog"><form id="alarm-action-form"><h2 id="alarm-action-title"></h2><p id="alarm-action-help"></p><div id="alarm-ticket-fields" hidden><label class="field">Ticket Severity<select name="ticket_severity"><option>High</option><option>Medium</option><option>Low</option></select></label><label class="field">Ticket Name<input name="ticket_name" maxlength="320"></label></div><label class="field">Remarks<textarea name="note" maxlength="1000" rows="3"></textarea></label><div class="message-actions"><button type="button" class="button" id="alarm-action-cancel">Cancel</button><button class="button primary" type="submit">Save</button></div></form></dialog>
</section>
