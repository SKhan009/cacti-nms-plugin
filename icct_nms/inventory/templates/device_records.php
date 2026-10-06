<?php
/** Historical records for the already-authorized device; never expose credentials. */
$recordPage = static fn($key) => max(1, min(1000000, (int)($_GET[$key] ?? 1)));
$recordPager = static function($key, $page, $total) use ($id) {
    $pages = max(1, (int)ceil($total / 25));
    $url = static function($target) use ($id, $key) {
        $params = ['id'=>$id, 'view'=>1, 'changes_page'=>max(1,(int)($_GET['changes_page'] ?? 1)), 'diagnostics_page'=>max(1,(int)($_GET['diagnostics_page'] ?? 1)), 'port_events_page'=>max(1,(int)($_GET['port_events_page'] ?? 1))];
        $params[$key] = $target;
        return 'inventory/controllers/device.php?'.http_build_query($params).'#view-records';
    };
    echo '<div class="type-pagination"><span>'.($total ? (($page-1)*25+1).'–'.min($page*25,$total) : '0').' of '.$total.' records</span><span class="type-page-count">Page '.$page.' of '.$pages.'</span>';
    foreach ([$page-1=>'Previous', $page+1=>'Next'] as $target=>$label) {
        $disabled = $target<1 || $target>$pages;
        echo '<a href="'.icct_nms_h($url(max(1,min($pages,$target)))).'"'.($disabled?' aria-disabled="true" tabindex="-1"':'').'>'.$label.'</a>';
    }
    echo '</div>';
};
?>
<section id="view-records" data-device-view-panel="records" hidden>
<h2>Logs</h2>
<?php if (!is_realm_allowed(3)): ?>
<p>Device management permission is required to read configuration and diagnostic records.</p>
<?php else:
    $prefix = 'configuration_history_'.(int)$id.'_';
    $changeFilter="LEFT(meta_key,?)=? AND CASE WHEN JSON_VALID(meta_value) THEN JSON_LENGTH(JSON_EXTRACT(meta_value,'$.changes'))>0 AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(meta_value,'$.action')),'')<>'Initial baseline' ELSE 0 END";
    $totalChanges = (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_icct_nms_meta WHERE '.$changeFilter,[strlen($prefix),$prefix]);
    $changesPage = min($recordPage('changes_page'), max(1,(int)ceil($totalChanges/25)));
    $changeRows = db_fetch_assoc_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE '.$changeFilter.' ORDER BY meta_key DESC LIMIT 25 OFFSET '.(($changesPage-1)*25),[strlen($prefix),$prefix]);
?>
<?php
    $portEventStates=[1=>'UP',2=>'DOWN',3=>'TESTING',4=>'UNKNOWN',5=>'DORMANT',6=>'NOT PRESENT',7=>'LOWER LAYER DOWN'];
    $portEventsTotal=(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_icct_nms_port_alarm_events WHERE host_id=?',[(int)$id]);
    $portEventsPage=min($recordPage('port_events_page'),max(1,(int)ceil($portEventsTotal/25)));
    $portEvents=db_fetch_assoc_prepared('SELECT * FROM plugin_icct_nms_port_alarm_events WHERE host_id=? ORDER BY id DESC LIMIT 25 OFFSET '.(($portEventsPage-1)*25),[(int)$id]);
?>
<h3>Port alarm history</h3>
<div class="site-table-wrap port-alarm-history"><table class="site-table" aria-label="Port alarm history"><thead><tr><th>Time</th><th>Port / ifIndex</th><th>Event</th><th>Previous severity</th><th>Current severity</th><th>Admin / Operational</th><th>Reading time</th></tr></thead><tbody>
<?php foreach($portEvents as $event): ?><tr>
<td><?= icct_nms_h($event['created_at']) ?></td><td><?= icct_nms_h($event['port_name']) ?><small>ifIndex <?= (int)$event['if_index'] ?></small></td>
<td class="<?= $event['event']==='Cleared'?'port-event-cleared':'' ?>"><?= icct_nms_h($event['event']) ?></td>
<td><?= icct_nms_h($event['severity_before']?:'None') ?></td><td><?= icct_nms_h($event['severity_after']?:'None') ?><small><?= icct_nms_h($event['state_after']) ?></small></td>
<td><?= icct_nms_h(($portEventStates[(int)$event['admin_status']]??'Unknown').' / '.($portEventStates[(int)$event['oper_status']]??'Unknown')) ?></td><td><?= icct_nms_h($event['collected_at']??'Unavailable') ?></td>
</tr><?php endforeach; ?><?php if(!$portEvents): ?><tr><td colspan="7">No port alarm events recorded.</td></tr><?php endif; ?></tbody></table></div>
<?php $recordPager('port_events_page',$portEventsPage,$portEventsTotal); ?>
<h3>Configuration changes</h3>
<p>Recorded configuration changes, newest first. Passwords and private keys are excluded. Changes before history recording began are unavailable.</p>
<div class="device-record-list">
<?php foreach ($changeRows as $row): $event=json_decode($row['meta_value'],true); if(!is_array($event)) continue; $changes=$event['changes'] ?? []; ?>
<details class="device-record-item" name="configuration-records">
<summary><span><small>Time</small><?= $display($event['time'] ?? '') ?></span><span><small>User</small><?= $display($event['user'] ?? '') ?></span><span><small>Action</small><?= $display($event['action'] ?? '') ?></span><span><small>Changes</small><?= count($changes) ?> changed settings</span></summary>
<div class="device-record-body"><h4>Changed settings</h4>
<?php if($changes): ?><div class="site-table-wrap"><table class="site-table"><thead><tr><th>Setting</th><th>Before</th><th>After</th></tr></thead><tbody><?php foreach ($changes as $field=>$change): ?><tr><td><?= $display($field) ?></td><td><?= $display($change['before'] ?? '') ?></td><td><?= $display($change['after'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p>No settings changed in this snapshot.</p><?php endif; ?>

</div></details>
<?php endforeach; if(!$changeRows): ?><p class="device-record-empty">No configuration changes recorded for this device.</p><?php endif; ?>
</div>
<?php $recordPager('changes_page',$changesPage,$totalChanges); ?>
<h3>Diagnostic readings</h3>
<p>Your saved diagnostic runs for this device. Historical results describe the configuration at the time of the run.</p>
<?php
    $owner=(int)($_SESSION['sess_user_id'] ?? 0);
    $canReadDiagnostics=true;
    try { icct_backend_diag_authorize_job(['host_id'=>$id,'user_id'=>$owner]); }
    catch (RuntimeException $e) { $canReadDiagnostics=false; }
    if (!$canReadDiagnostics): ?>
<p>Diagnostic permission is required to read saved results.</p>
<?php else:
    $totalRuns=(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_icct_nms_diagnostic_jobs WHERE host_id=? AND user_id=?',[$id,$owner]);
    $runsPage=min($recordPage('diagnostics_page'),max(1,(int)ceil($totalRuns/25)));
    $runs=db_fetch_assoc_prepared('SELECT id,tool,status,requested_at,started_at,finished_at,result_json FROM plugin_icct_nms_diagnostic_jobs WHERE host_id=? AND user_id=? ORDER BY id DESC LIMIT 25 OFFSET '.(($runsPage-1)*25),[$id,$owner]);
    $labels=icct_backend_diag_labels();
?>
<div class="device-record-list">
<?php foreach($runs as $run): $result=json_decode($run['result_json'] ?? '',true); ?>
<details class="device-record-item" name="diagnostic-records">
<summary><span><small>Requested</small><?= $display($run['requested_at']) ?></span><span><small>Method</small><?= $display($labels[$run['tool']] ?? $run['tool']) ?></span><span><small>Status</small><span class="device-status <?= ['complete'=>'online','running'=>'in-use','queued'=>'other','failed'=>'offline','error'=>'offline','expired'=>'other','cancelled'=>'disabled'][$run['status']] ?? 'disabled' ?>"><?= $display(ucfirst($run['status'])) ?></span></span><span><small>Finished</small><?= $display($run['finished_at']) ?></span></summary>
<div class="device-record-body"><h4>Result #<?= (int)$run['id'] ?></h4><?php if(is_array($result) && isset($result['output'])): ?><pre class="device-record-output"><?= icct_nms_h((string)$result['output']) ?></pre><?php else: ?><p>No saved output.</p><?php endif; ?></div></details>
<?php endforeach; if(!$runs): ?><p class="device-record-empty">No diagnostic runs saved for this device.</p><?php endif; ?>
</div>
<?php $recordPager('diagnostics_page',$runsPage,$totalRuns); endif; endif; ?>
</section>
