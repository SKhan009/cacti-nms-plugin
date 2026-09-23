<?php
require_once __DIR__ . '/connections.php';
$error=''; $styles = ($_GET['section'] ?? '') === 'types';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrf_check_tokens($_POST['__csrf_magic'] ?? '')) throw new InvalidArgumentException('Session expired. Reload and try again.');
        nms_category_execute('START TRANSACTION');
        // Serialize catalog and connection mutations, including in-use checks.
        db_fetch_assoc('SELECT name FROM plugin_nms_connection_types ORDER BY name FOR UPDATE');
        nms_connection_save($_POST);
        nms_category_execute('COMMIT');
        $_SESSION['nms_connection_notice'] = str_ends_with($_POST['nms_action'] ?? '', '_delete') ? 'Deleted successfully.' : 'Saved successfully.';
        header('Location: topology.php?tab=connections' . ($styles ? '&section=types' : ''), true, 303); exit;
    } catch (Throwable $e) { db_execute('ROLLBACK'); $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Save failed. Check permissions and configuration.'; }
}
$notice=$_SESSION['nms_connection_notice'] ?? ''; unset($_SESSION['nms_connection_notice']);
$options = nms_relationship_options();
$site=nms_single_topology_site();
if ($site) foreach ($options as $key=>$value) if ((int)db_fetch_cell_prepared('SELECT site_id FROM host WHERE id=?', [(int)explode(':',$key)[0]]) !== $site) unset($options[$key]);
$rows=array_values(array_filter(nms_connection_rows(), static fn($r)=>isset($options[explode(':',$r['a'])[0].':0:'], $options[explode(':',$r['b'])[0].':0:'])));
$edit=['id'=>0,'a'=>'','b'=>'','type'=>'Ethernet','label'=>'','speed_mbps'=>0];
foreach ($rows as $r) if ((string)$r['id'] === ($_GET['edit'] ?? '')) $edit=$r;
if ($error && ($_POST['nms_action'] ?? '') === 'connection_save') $edit=array_merge($edit,array_intersect_key($_POST,$edit));
// Reuse the topology's permission- and site-filtered discovery evidence.
foreach ($rows as &$row) {
    $row['source']='Manual'; $row['detected_type']='Not evaluated (manual)'; $row['protocol']='—'; $row['status']='Unknown (manual)';
    $row['a_display']=$options[$row['a']] ?? $row['a'].' — reselect interface';
    $row['b_display']=$options[$row['b']] ?? $row['b'].' — reselect interface';
}
unset($row);
if (!$styles) {
    require_once __DIR__.'/canvas.php';
    $canvas=nms_canvas_data($site);
    $rows=array_merge($rows,nms_connection_discovered_rows($canvas));
}
$search = is_string($_GET['search'] ?? null) ? trim(substr($_GET['search'],0,150)) : '';
$filtered = array_values(array_filter($rows, static fn($r)=>$search === '' || stripos($r['a_display'].' '.$r['b_display'].' '.$r['type'].' '.$r['label'].' '.$r['source'].' '.$r['protocol'].' '.$r['status'].' '.$r['detected_type'],$search) !== false));
$total=count($filtered); $pages=max(1,(int)ceil($total/20));
$page=max(1,min($pages,(int)($_GET['page'] ?? 1))); $listed=array_slice($filtered,($page-1)*20,20);
$types=db_fetch_assoc('SELECT * FROM plugin_nms_connection_types ORDER BY name');
$typeOptions=[]; $typeEdit=['name'=>'','color'=>'#64748b','line_style'=>'solid','symbol'=>'none'];
foreach ($types as $t) { $typeOptions[$t['name']]=nms_connection_types()[$t['name']] ?? $t['name']; if ($t['name']===($_GET['type_edit'] ?? '')) $typeEdit=$t; }
$typeOriginal=$typeEdit['name'];
if ($error && ($_POST['nms_action'] ?? '')==='connection_type_save') {
    $typeOriginal=$_POST['original_type'] ?? '';
    foreach (['color','line_style','symbol'] as $key) $typeEdit[$key]=$_POST[$key] ?? $typeEdit[$key];
    $typeEdit['name']=$_POST['type'] ?? '';
}
$classify=null;
$classifyKey=$error && ($_POST['nms_action'] ?? '')==='connection_classify' ? ($_POST['link_key'] ?? '') : ($_GET['classify'] ?? '');
foreach ($rows as $row) if (($row['link_key'] ?? null)===$classifyKey) $classify=$row;
$can=is_realm_allowed(3);
nms_prepare_page('topology','NMS · Edit topology','css/nms-topology-config.css','js/nms-connection-preview.js');
require __DIR__ . '/../../templates/app_header.php';
function nms_connection_fields($action,$id=0) { global $nms_csrf_token;
    print '<input type="hidden" name="__csrf_magic" value="'.nms_h($nms_csrf_token).'"><input type="hidden" name="nms_action" value="'.nms_h($action).'"><input type="hidden" name="id" value="'.(int)$id.'">';
}
function nms_connection_select($name,$values,$value) {
    print '<select required name="'.nms_h($name).'">';
    foreach ($values as $key=>$label) print '<option value="'.nms_h($key).'"'.((string)$key===(string)$value?' selected':'').'>'.nms_h($label).'</option>';
    print '</select>';
}
function nms_connection_preview($type) {
    print '<svg class="nms-connection-preview" viewBox="0 0 240 32" role="img" aria-label="Connection style preview" data-color="'.nms_h($type['color']).'" data-style="'.nms_h($type['line_style']).'" data-symbol="'.nms_h($type['symbol']).'" data-patterns="'.nms_h(json_encode(nms_connection_patterns())).'"></svg>';
}
function nms_connection_delete_button($action,$id,$type='') {
    print '<form method="post" class="nms-delete-icon-form" data-confirm-delete="Remove this '.($type!==''?'connection type':'manual connection').'?">';
    nms_connection_fields($action,$id);
    print '<input type="hidden" name="type" value="'.nms_h($type).'"><button class="nms-catalog-button nms-bin-button" type="submit" aria-label="Delete '.nms_h($type ?: 'connection').'" title="Delete"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7"/></svg></button></form>';
}
?>
<main class="nms-shell nms-topology-config nms-connections-page">
<h1><?php print $styles ? 'Connection types' : 'Edit topology'; ?></h1>
<p>Configure connections in the NMS map. This does not change device ports or network settings.</p>
<nav class="nms-preset-tabs"><a href="topology.php">View topology</a><a href="topology.php?tab=connections" class="<?php print !$styles?'active':''; ?>">Connections</a><a href="topology.php?tab=connections&amp;section=types" class="<?php print $styles?'active':''; ?>">Connection types</a><a href="topology.php?tab=appearance">Device appearance</a></nav>
<?php if ($error || $notice) { ?><p class="nms-action-feedback <?php print $error?'error':''; ?>" role="<?php print $error?'alert':'status'; ?>"><?php print nms_h($error ?: $notice); ?></p><?php } ?>
<?php if ($styles) { ?>
<p>These styles identify manually configured connection types. They do not represent live health.</p>
<?php if ($can) { ?><div class="nms-connection-actions"><button type="button" class="nms-catalog-button primary" data-open-dialog="type-dialog">+ Add connection type</button></div><?php } ?>
<div class="nms-type-cards">
<?php foreach ($types as $type) { ?><article class="nms-panel nms-type-card"><h2><?php print nms_h($typeOptions[$type['name']]); ?></h2>
<?php nms_connection_preview($type); ?>
<p><?php print nms_h(ucfirst($type['line_style']).' · '.ucfirst($type['symbol']).' endpoints'); ?></p>
<?php if ($can) { ?><div class="nms-connection-actions"><a class="nms-catalog-button" href="<?php print nms_h('topology.php?tab=connections&section=types&type_edit='.rawurlencode($type['name'])); ?>">Edit</a><?php nms_connection_delete_button('connection_type_delete',0,$type['name']); ?></div><?php } ?></article><?php } ?>
</div>
<?php if ($can) { ?><dialog id="type-dialog" class="nms-connection-dialog" aria-labelledby="type-dialog-title" data-auto-open="<?php print $typeOriginal!=='' || ($error && ($_POST['nms_action'] ?? '')==='connection_type_save')?'1':'0'; ?>">
<header><h2 id="type-dialog-title"><?php print $typeOriginal!==''?'Edit':'Add'; ?> connection type</h2><button type="button" data-close-dialog aria-label="Close" class="nms-popup-close"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg></button></header>
<form method="post" class="nms-connection-form"><?php nms_connection_fields('connection_type_save'); ?><input type="hidden" name="original_type" value="<?php print nms_h($typeOriginal); ?>">
<?php if ($error) { ?><p role="alert"><?php print nms_h($error); ?></p><?php } ?>
<div class="nms-connection-grid"><label>Name<input required name="type" maxlength="24" value="<?php print nms_h($typeEdit['name']); ?>"></label><label>Colour<input type="color" name="color" value="<?php print nms_h($typeEdit['color']); ?>"></label>
<label>Line style<?php nms_connection_select('line_style',['solid'=>'Solid ━━━━━','dashed'=>'Dashed ━ ━ ━','dotted'=>'Dotted • • • •','dash-dot'=>'Dash-dot ━ • ━ •','fine-dotted'=>'Fine dotted ········','short-dashed'=>'Short dashed ┄┄┄┄'],$typeEdit['line_style']); ?></label><label>Endpoint symbol<?php nms_connection_select('symbol',['none'=>'None ──','circle'=>'Circle ●','square'=>'Square ■','arrow'=>'Arrow ▶'],$typeEdit['symbol']); ?></label></div>
<div class="nms-style-actions"><?php nms_connection_preview($typeEdit); ?></div>
<div class="nms-connection-actions nms-style-actions"><button class="nms-catalog-button primary" type="submit">Save type</button><button class="nms-catalog-button" type="button" data-close-dialog>Cancel</button></div></form></dialog>
<?php } } else { if ($can) { ?>
<dialog id="connection-dialog" class="nms-connection-dialog" aria-labelledby="connection-dialog-title" data-auto-open="<?php print $edit['id'] || ($error && ($_POST['nms_action'] ?? '')==='connection_save')?'1':'0'; ?>">
<header><h2 id="connection-dialog-title"><?php print $edit['id']?'Edit connection':'Add connection'; ?></h2><button type="button" data-close-dialog aria-label="Close" class="nms-popup-close"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg></button></header>
<form method="post" class="nms-connection-form" id="connection-form">
<?php if ($error) { ?><p role="alert"><?php print nms_h($error); ?></p><?php } ?>
<?php nms_connection_fields('connection_save',$edit['id']); ?>
<div class="nms-connection-grid">
<?php foreach (['a'=>'Device A / interface','b'=>'Device B / interface'] as $side=>$label) { ?><label><?php print $label; ?><?php nms_connection_select($side,[''=>'Select a device or interface']+$options,$edit[$side]); ?></label><?php } ?>
<label>Connection type<?php nms_connection_select('type',$typeOptions,$edit['type']); ?></label>
<label>Label (optional)<input name="label" maxlength="150" value="<?php print nms_h($edit['label']); ?>"></label>
<label>Configured capacity (Mbps)<input name="speed_mbps" type="number" min="0" max="100000000" step="0.001" value="<?php print nms_h($edit['speed_mbps']); ?>"></label>
</div><p>Select a cached interface for monitoring, or a device when its port is unknown. Capacity is an entered value; use 0 when unknown.</p>
<div class="nms-connection-actions"><button class="nms-catalog-button primary" type="submit">Save connection</button><button class="nms-catalog-button" type="button" data-close-dialog>Cancel</button></div>
</form></dialog><?php } ?>
<?php if ($can && $classify) { ?>
<dialog id="classification-dialog" class="nms-connection-dialog" aria-labelledby="classification-title" data-auto-open="1">
<header><h2 id="classification-title">Classify link</h2><button type="button" data-close-dialog aria-label="Close" class="nms-popup-close"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></header>
<form method="post" class="nms-connection-form">
<?php nms_connection_fields('connection_classify'); ?>
<input type="hidden" name="link_key" value="<?php print nms_h($classify['link_key']); ?>">
<p><?php print nms_h($classify['a_display'].' ↔ '.$classify['b_display']); ?></p>
<p>Assign the carrier or link technology, such as VSAT or LOS. Detected interface types and status remain unchanged.</p>
<?php if ($error) { ?><p role="alert"><?php print nms_h($error); ?></p><?php } ?>
<div class="nms-connection-grid"><label>Connection type<?php nms_connection_select('type',['__unclassified__'=>'Unclassified']+$typeOptions,$error ? ($_POST['type'] ?? '__unclassified__') : ($classify['type']==='Unclassified'?'__unclassified__':$classify['type'])); ?></label></div>
<div class="nms-connection-actions nms-style-actions"><button type="submit" class="nms-catalog-button primary">Save classification</button><button type="button" data-close-dialog class="nms-catalog-button">Cancel</button></div>
</form></dialog><?php } ?>
<section class="nms-panel"><div class="nms-panel-head"><h2>Device connections</h2><?php if ($can) { ?><button type="button" class="nms-catalog-button" data-open-dialog="connection-dialog">+ Add connection</button><?php } ?></div><form method="get" class="nms-connection-filter"><input type="hidden" name="tab" value="connections"><label>Find connection <input type="search" name="search" value="<?php print nms_h($search); ?>" placeholder="Device, type or label"></label> <button class="nms-catalog-button primary" type="submit">Filter</button> <a class="nms-catalog-button" href="topology.php?tab=connections">Reset</a></form><div class="nms-catalog-scroll"><table class="nms-table"><thead><tr><th>Device A / interface</th><th>Device B / interface</th><th>Discovery protocol</th><th>Detected interface type</th><th>Assigned connection type / label</th><th>Status</th><th>Capacity (Mbps)</th><th>Source</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($listed as $r) { ?><tr><td><?php print nms_h($r['a_display']); ?></td><td><?php print nms_h($r['b_display']); ?></td><td><?php print nms_h($r['protocol']); ?></td><td><?php print nms_h($r['detected_type']); ?></td><td><?php print nms_h($r['type'].($r['label']!==''?' · '.$r['label']:'')); ?></td><td><?php print nms_h($r['status']); ?></td><td><?php print $r['speed_mbps']>0?nms_h($r['speed_mbps']):'Unknown'; ?></td><td><?php print nms_h($r['source']); ?></td><td><?php if ($can && $r['source']==='Manual') { ?><div class="nms-connection-row-actions"><a class="nms-catalog-button" href="topology.php?tab=connections&amp;edit=<?php print (int)$r['id']; ?>">Edit</a><?php nms_connection_delete_button('connection_delete',$r['id']); ?></div><?php } elseif ($r['source']!=='Manual') { ?><?php if ($can) { ?><a class="nms-catalog-button" href="<?php print nms_h('topology.php?tab=connections&classify='.$r['link_key']); ?>">Classify link</a><?php } else { ?><span class="nms-readonly">Read only</span><?php } ?><?php } ?></td></tr><?php } if (!$listed) { ?><tr><td colspan="9">No matching connections. Use Add connection to create one.</td></tr><?php } ?>
</tbody></table></div><nav class="nms-connection-pagination" aria-label="Connection pagination"><span>Showing <?php print $total?($page-1)*20+1:0; ?>–<?php print min($page*20,$total); ?> of <?php print $total; ?> · Page <?php print $page; ?> of <?php print $pages; ?></span><div class="nms-connection-actions">
<?php foreach (['Previous'=>$page-1,'Next'=>$page+1] as $label=>$target) { if ($target>=1 && $target<=$pages) { ?><a class="nms-catalog-button" href="<?php print nms_h('topology.php?'.http_build_query(['tab'=>'connections','search'=>$search,'page'=>$target])); ?>"><?php print $label; ?></a><?php } else { ?><button class="nms-catalog-button" disabled><?php print $label; ?></button><?php } } ?></div></nav></section><?php } ?></main>
<?php require __DIR__ . '/../../templates/app_footer.php'; ?>
