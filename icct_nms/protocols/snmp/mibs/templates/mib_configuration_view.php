<?php
/** Pure read-only navigation: no forms, draft tokens or generation actions. */
$viewSteps=[1=>'Uploaded MIBs / OIDs',2=>'Data Sources',3=>'Graph Templates',4=>'Device Template'];
$viewUrl='protocols/snmp/mibs/controllers/mib_repository.php?view='.rawurlencode($viewBundle['id']);
$viewRows=$viewBundle['rows']??[];$viewObjects=icct_mib_objects($viewBundle);
$viewProfiles=array_column(icct_mib_profiles(),'name','id');$viewMethods=icct_mib_collection_names();
$renderViewDetails=function($details){ ?>
<dl class="mib-readonly-details"><?php foreach($details as $label=>$value): ?><div><dt><?= icct_nms_h($label) ?></dt><dd><?= icct_nms_h($value===null||$value===''?'—':(string)$value) ?></dd></div><?php endforeach; ?></dl>
<?php };
?>
<section class="mib-generation mib-configuration-view" aria-label="Read-only MIB configuration">
<header class="mib-generation-heading"><div><h1><?= icct_nms_h($viewBundle['name']) ?></h1><small class="mib-readonly-label">Read-only configuration</small></div><a class="button" href="protocols/snmp/mibs/controllers/mib_repository.php">Back to Repository</a></header>
<nav class="mib-generation-stepper" aria-label="Configuration steps"><?php foreach($viewSteps as $number=>$label): ?><div class="<?= $viewStage===$number?'active':'' ?>"><span class="mib-generation-step-icon" aria-hidden="true"><?= $number ?></span><a href="<?= icct_nms_h($viewUrl.'&stage='.$number) ?>" <?= $viewStage===$number?'aria-current="step"':'' ?>><?= $label ?></a><small><?= $number ?>/4</small></div><?php endforeach; ?></nav>
<?php if($viewStage===1): ?>
<h2>Uploaded MIBs</h2>
<?php foreach($viewBundle['files'] as $file): ?><article class="mib-readonly-card"><h3><?= icct_nms_h($file['name']) ?></h3><?php $renderViewDetails(['MIB Module'=>$file['module']??'','Device Type'=>$viewBundle['type_name']??'','Uploaded'=>$viewBundle['created_at']??$viewBundle['created']??'']); ?></article><?php endforeach; ?>
<h2>Selected OIDs</h2>
<?php if(!$viewRows): ?><p class="mib-readonly-empty">No saved OID selection for this configuration.</p><?php else: ?>
<div class="mib-readonly-table"><table><thead><tr><th>Name</th><th>OID</th><th>Syntax</th><th>Access</th><th>Data Source</th></tr></thead><tbody>
<?php $viewSelected=array_column($viewRows,null,'object_index');foreach($viewObjects as $index=>$object): if(!isset($viewSelected[$index]))continue; ?><tr><td><?= icct_nms_h($object['symbol']) ?></td><td><?= icct_nms_h($object['base_oid']) ?></td><td><?= icct_nms_h($object['syntax']?:'—') ?></td><td><?= icct_nms_h($object['access']?:'—') ?></td><td><?= isset($viewSelected[$index])?'Selected':(!empty($object['numeric'])?'Not selected':'Not supported') ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif;elseif($viewStage===2): ?>
<h2>Data Sources</h2>
<?php if(!$viewRows): ?><p class="mib-readonly-empty">No data sources have been generated for this configuration.</p><?php endif; ?>
<?php foreach($viewRows as $row): $viewSettings=$row['settings']??[];$nativeName=db_fetch_cell_prepared('SELECT name FROM data_template WHERE id=?',[(int)$row['data_template_id']]); ?>
<article class="mib-readonly-card"><h3><?= icct_nms_h($viewSettings['data_name']??$row['name']) ?></h3><?php $renderViewDetails(['Cacti Template'=>$nativeName?:'Template unavailable','Polling OID'=>$row['oid'],'Collection Method'=>$viewMethods[$viewSettings['resolved_method']??'get']??($viewSettings['resolved_method']??'—'),'Polling Profile'=>$viewProfiles[$viewSettings['profile_id']??0]??'—','Data Source Active'=>($viewSettings['active']??'1')==='0'?'Disabled':'Enabled','Data Source Type'=>[1=>'Gauge',2=>'Counter',3=>'Derive',4=>'Absolute'][$viewSettings['ds_type']??0]??'—','Parameter Label'=>$viewSettings['label']??$row['name'],'Units'=>$viewSettings['units']??'','RRD Minimum'=>$viewSettings['min']??'U','RRD Maximum'=>$viewSettings['max']??'U']); ?>
<h4>Fault Configuration</h4>
<?php if(empty($viewSettings['fault_presets'])): ?><p>No fault presets configured.</p><?php endif; ?>
<?php foreach($viewSettings['fault_presets']??[] as $fault): ?><section class="mib-readonly-fault"><h4><?= icct_nms_h($fault['name']) ?></h4><?php $renderViewDetails(['Enable Alarm'=>!empty($fault['enabled'])?'Enabled':'Disabled','Comparator'=>['above'=>'> Above','below'=>'< Below','at_or_above'=>'≥ At or above','at_or_below'=>'≤ At or below','equals'=>'= Equals','not_equals'=>'≠ Not equal'][$fault['condition']]??$fault['condition'],'Threshold'=>$fault['threshold']??'','Severity'=>$fault['severity']??'','Description'=>$fault['description']??'','Fix'=>$fault['corrective_action']??'']); ?></section><?php endforeach; ?>
</article><?php endforeach; ?>
<?php elseif($viewStage===3): ?>
<h2>Graph Templates</h2>
<?php $hasGraphs=false;foreach($viewRows as $row): if(empty($row['graph_template_id']))continue;$hasGraphs=true;$viewSettings=$row['settings']??[];$nativeName=db_fetch_cell_prepared('SELECT name FROM graph_templates WHERE id=?',[(int)$row['graph_template_id']]); ?>
<article class="mib-readonly-card"><h3><?= icct_nms_h($viewSettings['graph_name']??$row['name']) ?></h3><?php $renderViewDetails(['Cacti Template'=>$nativeName?:'Template unavailable','Data Source'=>$viewSettings['data_name']??$row['name'],'OID'=>$row['oid'],'Graph Legend'=>$viewSettings['legend']??'','Vertical Label / Units'=>$viewSettings['units']??'','Graph Item'=>[4=>'Line 1',5=>'Line 2',6=>'Line 3',7=>'Area'][$viewSettings['graph_type']??0]??'—','Consolidation Function'=>[1=>'Average',2=>'Minimum',3=>'Maximum',4=>'Last'][$viewSettings['cf']??0]??'—','Graph Color'=>$viewSettings['color']??'','Statistics'=>!empty($viewSettings['statistics'])?'Show':'Hide','Lower Threshold Line'=>$viewSettings['threshold_low']??'','Upper Threshold Line'=>$viewSettings['threshold_high']??'']); ?></article><?php endforeach; ?>
<?php if(!$hasGraphs): ?><p class="mib-readonly-empty">No graph templates have been generated for this configuration.</p><?php endif; ?>
<?php else: ?>
<h2>Device Template</h2>
<?php if(empty($viewBundle['host_template_id'])): ?><p class="mib-readonly-empty">No device template has been generated for this configuration.</p><?php else: $nativeName=db_fetch_cell_prepared('SELECT name FROM host_template WHERE id=?',[(int)$viewBundle['host_template_id']]); ?>
<article class="mib-readonly-card"><?php $renderViewDetails(['Template Name'=>$nativeName?:'Template unavailable','Device Type'=>$viewBundle['type_name']??'']); ?><h3>Associated Graph Templates</h3><ul><?php foreach($viewRows as $row): if(empty($row['graph_template_id']))continue; ?><li><?= icct_nms_h($row['settings']['graph_name']??$row['name']) ?></li><?php endforeach; ?></ul></article>
<?php endif;endif; ?>
<footer class="mib-readonly-navigation"><?php if($viewStage>1): ?><a class="button" href="<?= icct_nms_h($viewUrl.'&stage='.($viewStage-1)) ?>">Previous ←</a><?php endif; ?><?php if($viewStage<4): ?><a class="button primary" href="<?= icct_nms_h($viewUrl.'&stage='.($viewStage+1)) ?>">Next →</a><?php endif; ?></footer>
</section>
