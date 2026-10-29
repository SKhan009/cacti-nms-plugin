<?php
/** Read-only, device-scoped received Syslog history; device access is already checked. */
$syslogSearch = mb_substr(trim(is_scalar($_GET['syslog_search']??'')?(string)($_GET['syslog_search']??''):''), 0, 200);
$syslogPage = max(1, (int)($_GET['syslog_page'] ?? 1));
$syslogTotal = 0;
$syslogEvents = icct_backend_syslog_events([(int)$id], ['host_id'=>(int)$id, 'search'=>$syslogSearch], 50, ($syslogPage-1)*50, $syslogTotal);
$syslogPages = max(1, (int)ceil($syslogTotal/50));
if ($syslogPage > $syslogPages) {
    $syslogPage = $syslogPages;
    $syslogEvents = icct_backend_syslog_events([(int)$id], ['host_id'=>(int)$id, 'search'=>$syslogSearch], 50, ($syslogPage-1)*50, $syslogTotal);
}
$syslogPageUrl = static fn($page) => 'inventory/controllers/device.php?'.http_build_query(['id'=>(int)$id,'view'=>1,'syslog_search'=>$syslogSearch,'syslog_page'=>$page]).'#view-syslog';
$syslogSeverityNames = icct_backend_syslog_severity_labels();
$syslogFacilityNames = icct_backend_syslog_facility_labels();
$syslogViewAssignment=icct_backend_syslog_assignment((int)$id);
$syslogViewSettings=$syslogViewAssignment?icct_backend_syslog_filters($syslogViewAssignment):null;
$syslogLabelList=static function($codes,$labels){return $codes?implode(', ',array_map(static fn($code)=>$labels[$code]??(string)$code,$codes)):'None selected';};
?>
<section id="view-syslog" data-device-view-panel="syslog" hidden>
<h2>Syslog / Device Event History</h2>
<?php if($syslogViewAssignment): ?>
<dl class="device-detail-grid" aria-label="Saved Syslog configuration">
<?php foreach([
'Status'=>!empty($syslogViewAssignment['enabled'])&&icct_backend_protocol_enabled((int)$id,'syslog')?'Enabled':'Disabled',
'Source Address'=>$syslogViewAssignment['source_address']?:($old['hostname']??''),
'Severity'=>$syslogLabelList($syslogViewSettings['severity_codes'],$syslogSeverityNames),
'Facility'=>count($syslogViewSettings['facility_codes'])===24?'All facilities':$syslogLabelList($syslogViewSettings['facility_codes'],$syslogFacilityNames),
'Trigger Keywords / Match Strings'=>$syslogViewSettings['match_strings']?implode(', ',$syslogViewSettings['match_strings']):'All messages',
] as $label=>$value): ?><div><dt><?= icct_nms_h($label) ?></dt><dd><?= icct_nms_h($value) ?></dd></div><?php endforeach; ?>
</dl>
<?php else: ?><p>Syslog is not configured for this device. Received event history is retained.</p><?php endif; ?>
<form method="get" action="inventory/controllers/device.php#view-syslog" class="device-syslog-search">
<input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="view" value="1">
<label for="device-syslog-search">Search messages</label>
<input id="device-syslog-search" type="search" name="syslog_search" value="<?= icct_nms_h($syslogSearch) ?>" placeholder="Search message, program or source IP">
<button class="button" type="submit">Search</button><?php if($syslogSearch!==''): ?><a class="button" href="inventory/controllers/device.php?id=<?= (int)$id ?>&amp;view=1#view-syslog">Clear</a><?php endif; ?><a class="button" href="<?= icct_nms_h($syslogPageUrl($syslogPage)) ?>">Refresh</a>
</form>
<p role="status"><?= $syslogTotal ?> events</p>
<div class="site-table-wrap"><table class="site-table device-syslog-table" aria-label="Syslog and availability events for this device">
<thead><tr><th>Event Time</th><th>Severity</th><th>Origin</th><th>Source IP</th><th>Facility / Program</th><th>Message</th><th>Count</th><th>Last Seen</th></tr></thead>
<tbody><?php foreach($syslogEvents as $event): ?><tr>
<td><?= icct_nms_h($event['event_time']) ?></td>
<td><span class="device-syslog-severity severity-<?= icct_nms_h(icct_backend_syslog_nms_severity($event['severity_code'])) ?>"><?= icct_nms_h($syslogSeverityNames[(int)$event['severity_code']] ?? 'Unknown') ?></span></td>
<td><?= icct_nms_h(($event['event_origin']??'syslog')==='availability'?'Availability':'Device Syslog') ?></td>
<td><?= icct_nms_h($event['source_ip']) ?></td>
<td><?= icct_nms_h($syslogFacilityNames[(int)$event['facility_code']] ?? $event['facility']) ?><small><?= icct_nms_h($event['program']) ?></small></td>
<td class="device-syslog-message"><?= icct_nms_h($event['message']) ?></td>
<td><?= (int)$event['repeat_count'] ?></td><td><?= icct_nms_h($event['last_seen']) ?></td>
</tr><?php endforeach; ?><?php if(!$syslogEvents): ?><tr><td colspan="8">No Syslog or availability events<?= $syslogSearch !== '' ? ' match your search' : ' for this device' ?>.</td></tr><?php endif; ?></tbody></table></div>
<nav class="type-pagination" aria-label="Syslog pages">
<?php if($syslogPage>1): ?><a class="button" href="<?= icct_nms_h($syslogPageUrl($syslogPage-1)) ?>">Previous</a><?php endif; ?>
<span>Page <?= $syslogPage ?> of <?= $syslogPages ?></span>
<?php if($syslogPage<$syslogPages): ?><a class="button" href="<?= icct_nms_h($syslogPageUrl($syslogPage+1)) ?>">Next</a><?php endif; ?>
</nav>
</section>
