<?php
/** Historical records for the already-authorized device; never expose credentials. */
$recordPage = static fn($key) => max(1, min(1000000, (int)($_GET[$key] ?? 1)));
$recordPager = static function($key, $page, $total) use ($id) {
    $pages = max(1, (int)ceil($total / 25));
    $url = static function($target) use ($id, $key) {
        $params = ['id'=>$id, 'view'=>1, 'changes_page'=>max(1,(int)($_GET['changes_page'] ?? 1)), 'diagnostics_page'=>max(1,(int)($_GET['diagnostics_page'] ?? 1))];
        $params[$key] = $target;
        return 'device.php?'.http_build_query($params).'#view-records';
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
<h2>Records</h2>
<?php if (!is_realm_allowed(3)): ?>
<p>Device management permission is required to read configuration and diagnostic records.</p>
<?php else:
    $prefix = 'configuration_history_'.(int)$id.'_';
    $totalChanges = (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_icct_nms_meta WHERE LEFT(meta_key,?)=?',[strlen($prefix),$prefix]);
    $changesPage = min($recordPage('changes_page'), max(1,(int)ceil($totalChanges/25)));
    $changeRows = db_fetch_assoc_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE LEFT(meta_key,?)=? ORDER BY meta_key DESC LIMIT 25 OFFSET '.(($changesPage-1)*25),[strlen($prefix),$prefix]);
?>
<h3>Configuration changes</h3>
<p>Recorded changes and configuration snapshots, newest first. Passwords and private keys are excluded. Changes before history recording began are unavailable.</p>
<div class="site-table-wrap"><table class="site-table"><thead><tr><th>Time</th><th>User</th><th>Action</th><th>Changed settings</th></tr></thead><tbody>
<?php foreach ($changeRows as $row): $event=json_decode($row['meta_value'],true); if(!is_array($event)) continue; $changes=$event['changes'] ?? []; ?>
<tr><td><?= $display($event['time'] ?? '') ?></td><td><?= $display($event['user'] ?? '') ?></td><td><?= $display($event['action'] ?? '') ?></td><td><details><summary><?= count($changes) ?> changed settings</summary><table class="site-table"><thead><tr><th>Setting</th><th>Before</th><th>After</th></tr></thead><tbody><?php foreach ($changes as $field=>$change): ?><tr><td><?= $display($field) ?></td><td><?= $display($change['before'] ?? '') ?></td><td><?= $display($change['after'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table></details><details><summary>Configuration snapshot</summary><table class="site-table"><thead><tr><th>Setting</th><th>Saved value</th></tr></thead><tbody><?php foreach(($event['snapshot'] ?? []) as $field=>$value): ?><tr><td><?= $display($field) ?></td><td><?= $display($value) ?></td></tr><?php endforeach; ?></tbody></table></details></td></tr>
<?php endforeach; if(!$changeRows): ?><tr><td colspan="4">No configuration records saved for this device.</td></tr><?php endif; ?>
</tbody></table></div>
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
<div class="site-table-wrap"><table class="site-table"><thead><tr><th>Requested</th><th>Method</th><th>Status</th><th>Finished</th><th>Reading</th></tr></thead><tbody>
<?php foreach($runs as $run): $result=json_decode($run['result_json'] ?? '',true); ?>
<tr><td><?= $display($run['requested_at']) ?></td><td><?= $display($labels[$run['tool']] ?? $run['tool']) ?></td><td><?= $display($run['status']) ?></td><td><?= $display($run['finished_at']) ?></td><td><?php if(is_array($result) && isset($result['output'])): ?><details><summary>View result #<?= (int)$run['id'] ?></summary><pre class="device-record-output"><?= icct_nms_h((string)$result['output']) ?></pre></details><?php else: ?>No saved output.<?php endif; ?></td></tr>
<?php endforeach; if(!$runs): ?><tr><td colspan="5">No diagnostic runs saved for this device.</td></tr><?php endif; ?>
</tbody></table></div>
<?php $recordPager('diagnostics_page',$runsPage,$totalRuns); endif; endif; ?>
</section>
