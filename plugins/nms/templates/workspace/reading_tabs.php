<?php
/** The sole lower navigation: reading filters and discovery workflows. */
$reading_view=is_string($_GET['view']??null)?$_GET['view']:'all';
if(!in_array($reading_view,['all','interfaces','traffic','problems','serial'],true))$reading_view='all';
$reading_filters=['all'=>['All',count($device_readings)+count($device_discovery_readings)+count($serial_samples)],'interfaces'=>['Interfaces',$interfaces],'discovery'=>['Discovery',count($device_discovery_readings)],'traffic'=>['Traffic',$traffic],'problems'=>['Problems',$unknown+$stale+count($failed)+$serial_problems]];
if($serial_status)$reading_filters['serial']=['Serial',count($serial_samples)];
?>
<nav class="nms-page-tabs nms-unified-reading-tabs" aria-label="Device readings sections">
<?php foreach($reading_filters as $key=>[$label,$count]) {
    $active=$key==='discovery'?$workspace_section==='neighbours':($workspace_section==='overview' && $reading_view===$key);
    $url=$key==='discovery'?nms_workspace_url('neighbours',$workspace_id):nms_workspace_url('overview',$workspace_id,['view'=>$key]); ?>
<a href="<?php print nms_h($url); ?>" <?php if($active)print 'class="selected active" aria-current="page" data-reading-active="'.nms_h($key).'"'; ?>><?php print nms_h($label); ?><?php if($workspace_id) { ?> <span><?php print (int)$count; ?></span><?php } ?></a>
<?php } foreach(['networks'=>'Network discovery','identity'=>'IP addresses & identity','duplicates'=>'Duplicate review','history'=>'History'] as $key=>$label) { ?>
<a href="<?php print nms_h(nms_workspace_url($key,$workspace_id)); ?>" <?php if($workspace_section===$key)print 'class="selected active" aria-current="page"'; ?>><?php print nms_h($label); ?></a>
<?php } ?>
</nav>
