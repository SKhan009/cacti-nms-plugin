<?php /** Shared heading and navigation keep all diagnostic sections in the same position. */ ?>
<header class="nms-heading"><div><p class="nms-eyebrow">NMS / Protocol checks</p><h1>Protocol checks</h1></div></header>
<nav class="nms-page-tabs nms-diagnostics-tabs" aria-label="Protocol checks sections">
<?php foreach(['diagnosis'=>'Device diagnosis','run'=>'Run a diagnostic & history','profiles'=>'Diagnostic profiles'] as $key=>$label) { ?>
<a href="diagnostics.php?section=<?php print $key; ?><?php if($key==='run' && $node_id)print '&amp;node_id='.(int)$node_id; ?>" <?php if($section===$key)print 'class="selected" aria-current="page"'; ?>><?php print nms_h($label); ?></a>
<?php } ?></nav>
