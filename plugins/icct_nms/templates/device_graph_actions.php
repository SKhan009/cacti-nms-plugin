<?php
/** Cacti side tools for a graph already filtered through get_allowed_graphs. */
if (is_realm_allowed(27)):
    $graphActions = [
        ['graph.php?action=zoom&local_graph_id='.$graphId.'&rra_id=0','cog.png','Graph details and zoom'],
        ['graph_xport.php?local_graph_id='.$graphId.'&rra_id=0','table_go.png','CSV export of graph data'],
        ['graph.php?action=view&local_graph_id='.$graphId.'&rra_id=all','timeview.png','Time graph view'],
    ];
    if (is_realm_allowed(3)) $graphActions[]=['host.php?action=edit&id='.(int)$id,'server_edit.png','Edit device'];
    if (is_realm_allowed(10)) {
        $templateId=(int)db_fetch_cell_prepared('SELECT graph_template_id FROM graph_local WHERE id=?',[$graphId]);
        if($templateId>0) $graphActions[]=['graph_templates.php?action=template_edit&id='.$templateId,'template_edit.png','Edit graph template'];
    }
    if(read_config_option('realtime_enabled')==='on' && is_realm_allowed(25)) $graphActions[]=['graph_realtime.php?local_graph_id='.$graphId,'chart_curve_go.png','Real-time graph'];
?>
<nav class="device-graph-tools" aria-label="<?= icct_nms_h($graph['title_cache']) ?> graph actions">
<?php foreach($graphActions as [$target,$icon,$label]): ?><a href="<?= icct_nms_h($config['url_path'].$target) ?>" aria-label="<?= icct_nms_h($label) ?>" data-tooltip="<?= icct_nms_h($label) ?>"><img src="<?= icct_nms_h($config['url_path'].'images/'.$icon) ?>" alt="" width="16" height="16"></a><?php endforeach; ?>
</nav>
<?php endif; ?>
