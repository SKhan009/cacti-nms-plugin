<?php
$alarm=$alarmDetail['alarm'];$ack=$alarmDetail['acknowledgement'];
$detailValue=static fn($value)=>icct_nms_h(trim((string)$value)!==''?$value:'-');
$tableValue=static fn($value)=>icct_nms_h(trim((string)$value)!==''?$value:'');
$severityNames=['Warning'=>'Warn','Information'=>'Info'];
$severityColors=['Critical'=>'#f94144','Major'=>'#f3722b','Minor'=>'#f7961e','Warning'=>'#42aa8b','Information'=>'#277da2'];
?>
<section class="alarm-detail-page" id="alarm-detail-page" data-alarm-key="<?= icct_nms_h($alarm['alarm_key']) ?>">
<header class="alarm-detail-heading">
<a href="alarms/controllers/alarms.php" class="alarm-back" aria-label="Back to Alarms">←</a><h1 data-detail="name"><?= $detailValue($alarm['name']) ?></h1>
<div class="alarm-detail-actions">

<button type="button" class="icon-button alarm-detail-export" id="alarm-detail-export" aria-label="Export alarm details"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V3m-4 4 4-4 4 4M5 10H3v11h18V10h-2"/></svg></button>
</div></header>
<p id="alarm-detail-feedback" role="status" aria-live="polite"></p>
<dl class="alarm-detail-summary">
<div><dt>Alarm Occurred</dt><dd data-detail="occurred"><?= icct_nms_alarm_format_time($alarm['occurred_epoch']) ?></dd></div>
<div><dt>Device Name</dt><dd><a href="inventory/controllers/device.php?id=<?= (int)$alarm['host_id'] ?>&amp;view=1"><span id="alarm-device-dot" class="device-status-dot <?= $alarm['device_status']==='Up'?'online':($alarm['device_status']==='Down'?'offline':'other') ?>" aria-label="<?= $detailValue($alarm['device_status']) ?>"></span><span data-detail="device_name"><?= $detailValue($alarm['device_name']) ?></span></a></dd></div>
<div><dt>Short Name</dt><dd data-detail="short_name"><?= $detailValue($alarm['short_name']) ?></dd></div>
<div><dt>IP Address</dt><dd data-detail="address"><?= $detailValue($alarm['address']) ?></dd></div>
<div><dt>Type of Network Element</dt><dd data-detail="network_type"><?= $detailValue($alarm['network_type']) ?></dd></div>
<div><dt>Alarm Name &amp; Count</dt><dd data-detail="alarm_count"><?= $detailValue($alarm['name'].' ('.$alarm['occurrences'].')') ?></dd></div>
<div><dt>Ack Status</dt><dd data-detail="ack_status"><?= !empty($alarm['acknowledged'])?'Ack':'Not_Ack' ?></dd></div>
<div><dt>Escalation Status</dt><dd data-detail="escalation_status"><?= !empty($alarm['escalated'])?'Esc':'Not_Esc' ?></dd></div>
</dl>
<section class="alarm-acknowledgement"><h2>Acknowledgement &amp; Remarks</h2><dl><div><dt>Remarks</dt><dd data-detail="remarks"><?= $detailValue($ack['note']??'') ?></dd></div><div><dt>Acknowledge By</dt><dd data-detail="acknowledge_by"><?= $detailValue($ack['actor_name']??(!empty($ack['actor_id'])?'User '.$ack['actor_id']:'')) ?></dd></div></dl></section>
<div class="alarm-detail-tabs" role="tablist" aria-label="Alarm records">
<button type="button" role="tab" id="alarm-history-tab" aria-controls="alarm-history" aria-selected="true">Alarm History</button>
<button type="button" role="tab" id="alarm-escalation-tab" aria-controls="alarm-escalation" aria-selected="false" tabindex="-1" <?= !$alarmDetail['escalations']?'disabled':'' ?>>Escalation</button>
</div>
<section id="alarm-history" role="tabpanel" aria-labelledby="alarm-history-tab" class="alarm-detail-panel">
<div class="alarm-history-scroll"><table class="alarm-history-table"><thead><tr><th>Alarm<br>Occurred</th><th>Severity &amp;<br>Ack</th><th>Description &amp; Fix</th><th>Ack &amp; Remarks</th><th>Count</th></tr></thead><tbody id="alarm-detail-history">
<?php foreach($alarmDetail['history'] as $event): $snapshot=$event['snapshot'];$severity=$snapshot['severity']??(isset($severityColors[$event['note']])?$event['note']:''); ?>
<tr title="<?= $tableValue($event['event']) ?>"><td><?= !empty($event['created_epoch'])?icct_nms_alarm_format_time($event['created_epoch']):'' ?></td><td><?php if(isset($severityColors[$severity])): ?><span class="alarm-severity" style="background:<?= $severityColors[$severity] ?>;color:<?= $severity==='Information'?'#fff':'#161616' ?>"><?= $severityNames[$severity]??$severity ?></span><?php endif; ?></td><td><span><?= $tableValue($snapshot['description']??'') ?></span><small><?= $tableValue($snapshot['fix']??'') ?></small><?php if(!$snapshot): ?><small class="alarm-legacy-note">Historical content was not recorded.</small><?php endif; ?></td><td><?= isset($snapshot['acknowledged'])?(!empty($snapshot['acknowledged'])?'Ack':'Not_Ack'):'' ?><small><?= $tableValue($event['event']==='Acknowledged'?$event['note']:'') ?></small></td><td><?= $tableValue($snapshot['occurrences']??'') ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></section>
<section id="alarm-escalation" role="tabpanel" aria-labelledby="alarm-escalation-tab" class="alarm-detail-panel" hidden><div class="alarm-history-scroll"><table class="alarm-history-table alarm-escalation-table"><thead><tr><th>Alarm Occurred</th><th>Escalated By</th><th>Remarks</th><th>Escalation Status</th></tr></thead><tbody id="alarm-detail-escalations"><?php foreach($alarmDetail['escalations'] as $event): ?><tr><td><?= !empty($event['created_epoch'])?icct_nms_alarm_format_time($event['created_epoch']):'' ?></td><td><?= $tableValue($event['actor_name']??('User '.$event['actor_id'])) ?></td><td><?= $tableValue($event['note']) ?></td><td>Esc</td></tr><?php endforeach; ?></tbody></table></div></section>
<script type="application/json" id="alarm-detail-data"><?= json_encode($alarmDetail,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?></script>

</section>
