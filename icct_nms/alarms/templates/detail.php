<?php
$alarm=$alarmDetail['alarm'];$ack=$alarmDetail['acknowledgement'];
$detailValue=static fn($value)=>icct_nms_h(trim((string)$value)!==''?$value:'-');
$tableValue=static fn($value)=>icct_nms_h(trim((string)$value)!==''?$value:'');
$actorLabel=($ack['actor_username']??$ack['actor_name']??(!empty($ack['actor_id'])?'User '.$ack['actor_id']:''));if(!empty($ack['actor_email']))$actorLabel.=' ('.$ack['actor_email'].')';
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
<div><dt>Ack Status</dt><dd data-detail="ack_status" class="<?= !empty($alarm['acknowledged'])?'alarm-status-checked':'' ?>"><?= !empty($alarm['acknowledged'])?'Ack':'Not_Ack' ?></dd></div>
<div><dt>Escalation Status</dt><dd data-detail="escalation_status" class="<?= !empty($alarm['escalated'])?'alarm-status-checked':'' ?>"><?= !empty($alarm['escalated'])?'Esc':'Not_Esc' ?></dd></div>
</dl>
<section class="alarm-acknowledgement"><h2>Acknowledgement &amp; Remarks</h2><dl><div><dt>Remarks</dt><dd data-detail="remarks"><?= $detailValue($ack['note']??'') ?></dd></div><div><dt>Acknowledge By</dt><dd data-detail="acknowledge_by"><?= $detailValue($actorLabel) ?></dd></div></dl></section>
<div class="alarm-detail-tabs" role="tablist" aria-label="Alarm records">
<button type="button" role="tab" id="alarm-history-tab" aria-controls="alarm-history" aria-selected="true">Alarm History</button>
<button type="button" role="tab" id="alarm-escalation-tab" aria-controls="alarm-escalation" aria-selected="false" tabindex="-1" <?= !$alarmDetail['ticket']['timeline']?'disabled':'' ?>>Escalation</button>
</div>
<section id="alarm-history" role="tabpanel" aria-labelledby="alarm-history-tab" class="alarm-detail-panel">
<div class="alarm-history-scroll"><table class="alarm-history-table"><thead><tr><th>Alarm<br>Occurred</th><th>Severity &amp;<br>Ack</th><th>Description &amp; Fix</th><th>Ack &amp; Remarks</th><th>Count</th></tr></thead><tbody id="alarm-detail-history">
<?php foreach(array_slice($alarmDetail['history'],0,100) as $event): $snapshot=$event['snapshot'];$severity=$snapshot['severity']??(isset($severityColors[$event['note']])?$event['note']:''); ?>
<tr title="<?= $tableValue($event['event']) ?>"><td><?= !empty($event['created_epoch'])?icct_nms_alarm_format_time($event['created_epoch']):'' ?></td><td><?php if(isset($severityColors[$severity])): ?><span class="alarm-severity" style="background:<?= $severityColors[$severity] ?>;color:<?= $severity==='Information'?'#fff':'#161616' ?>"><?= $severityNames[$severity]??$severity ?></span><?php endif; ?></td><td><span><?= $tableValue($snapshot['description']??'') ?></span><small><?= $tableValue($snapshot['fix']??'') ?></small><?php if(!$snapshot): ?><small class="alarm-legacy-note">Historical content was not recorded.</small><?php endif; ?></td><td><span class="<?= !empty($snapshot['acknowledged'])?'alarm-status-checked':'' ?>"><?= isset($snapshot['acknowledged'])?(!empty($snapshot['acknowledged'])?'Ack':'Not Ack'):'' ?></span><small><?= $tableValue($event['event']==='Acknowledged'?$event['note']:($snapshot['ack_note']??'')) ?></small></td><td><?= $tableValue($snapshot['occurrences']??'') ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<footer class="alarm-history-pagination">
<label>Items per page: <select id="alarm-history-size" aria-label="Alarm history items per page"><option>10</option><option>25</option><option>50</option><option selected>100</option></select></label>
<span id="alarm-history-range"><?= count($alarmDetail['history'])?'1–'.min(100,count($alarmDetail['history'])).' of '.count($alarmDetail['history']).' items':'0 items' ?></span>
<div><select id="alarm-history-page" aria-label="Alarm history page"><option>1</option></select><span id="alarm-history-pages">of <?= max(1,(int)ceil(count($alarmDetail['history'])/100)) ?> pages</span></div>
<button type="button" id="alarm-history-prev" aria-label="Previous alarm history page" disabled class="pagination-control"><svg class="pagination-arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 5 7 12 15 19Z"/></svg></button><button type="button" id="alarm-history-next" aria-label="Next alarm history page" <?= count($alarmDetail['history'])<=100?'disabled':'' ?> class="pagination-control"><svg class="pagination-arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 5 17 12 9 19Z"/></svg></button>
</footer></section>
<section id="alarm-escalation" role="tabpanel" aria-labelledby="alarm-escalation-tab" class="alarm-detail-panel" hidden>
<div class="alarm-ticket-layout"><dl class="alarm-ticket-details">
<?php $ticket=$alarmDetail['ticket']['details']; foreach(['severity'=>'Ticket Severity','name'=>'Ticket Name','remarks'=>'Remarks'] as $field=>$label): ?><div><dt><?= $label ?></dt><dd data-ticket="<?= $field ?>"><?= $tableValue($ticket[$field]??'') ?></dd></div><?php endforeach; ?>
</dl><ol id="alarm-ticket-timeline" class="alarm-ticket-timeline" aria-label="Ticket status history">
<?php foreach($alarmDetail['ticket']['timeline'] as $event): ?><li class="alarm-ticket-event <?= $event['kind'] ?>"><h3><?= $event['label'] ?></h3><time><?= icct_nms_alarm_format_time($event['created_epoch']) ?></time><p><?= $tableValue($event['note']) ?></p></li><?php endforeach; ?>
</ol></div></section>
<script type="application/json" id="alarm-detail-data"><?= json_encode($alarmDetail,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?></script>

</section>
