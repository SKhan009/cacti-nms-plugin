<?php
require_once __DIR__ . '/connections.php';
$error=''; $styles = ($_GET['section'] ?? '') === 'types';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrf_check_tokens($_POST['__csrf_magic'] ?? '')) throw new InvalidArgumentException('Session expired. Reload and try again.');
        nms_connection_save($_POST);
        $_SESSION['nms_connection_notice'] = ($_POST['nms_action'] ?? '') === 'connection_delete' ? 'Connection deleted.' : 'Connection saved.';
        header('Location: topology.php?tab=connections' . ($styles ? '&section=types' : ''), true, 303); exit;
    } catch (Throwable $e) { $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Save failed. Check permissions and configuration.'; }
}
$notice=$_SESSION['nms_connection_notice'] ?? ''; unset($_SESSION['nms_connection_notice']);
$options = nms_relationship_options();
$site=nms_single_topology_site();
if ($site) foreach ($options as $key=>$value) if ((int)db_fetch_cell_prepared('SELECT site_id FROM host WHERE id=?', [(int)explode(':',$key)[0]]) !== $site) unset($options[$key]);
$rows=array_values(array_filter(nms_connection_rows(), static fn($r)=>isset($options[explode(':',$r['a'])[0].':0:'], $options[explode(':',$r['b'])[0].':0:'])));
$edit=['id'=>0,'a'=>'','b'=>'','type'=>'Ethernet','label'=>'','speed_mbps'=>0];
foreach ($rows as $r) if ((string)$r['id'] === ($_GET['edit'] ?? '')) $edit=$r;
if ($error && ($_POST['nms_action'] ?? '') === 'connection_save') $edit=array_merge($edit,array_intersect_key($_POST,$edit));
$search = is_string($_GET['search'] ?? null) ? trim(substr($_GET['search'],0,150)) : '';
$filtered = array_values(array_filter($rows, static fn($r)=>$search === '' || stripos(($options[$r['a']] ?? $r['a']).' '.($options[$r['b']] ?? $r['b']).' '.$r['type'].' '.$r['label'],$search) !== false));
$total=count($filtered); $pages=max(1,(int)ceil($total/20));
$page=max(1,min($pages,(int)($_GET['page'] ?? 1))); $listed=array_slice($filtered,($page-1)*20,20);
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
?>
<main class="nms-shell nms-topology-config nms-connections-page">
<h1><?php print $styles ? 'Connection types' : 'Edit topology'; ?></h1>
<p>Configure connections in the NMS map. This does not change device ports or network settings.</p>
<nav class="nms-preset-tabs"><a href="topology.php">View topology</a><a href="topology.php?tab=connections" class="<?php print !$styles?'active':''; ?>">Manual connections</a><a href="topology.php?tab=connections&amp;section=types" class="<?php print $styles?'active':''; ?>">Connection types</a><a href="topology.php?tab=appearance">Device appearance</a></nav>
<?php if ($error || $notice) { ?><p class="nms-action-feedback <?php print $error?'error':''; ?>" role="<?php print $error?'alert':'status'; ?>"><?php print nms_h($error ?: $notice); ?></p><?php } ?>
<?php if ($styles) { ?>
<p>These styles identify manually configured connection types. They do not represent live health.</p>
<?php foreach (db_fetch_assoc('SELECT * FROM plugin_nms_connection_types ORDER BY name') as $type) { ?>
<form method="post" class="nms-panel nms-connection-form">
<div class="nms-connection-style-title"><h2><?php print nms_h(nms_connection_types()[$type['name']] ?? $type['name']); ?></h2><svg class="nms-connection-preview" viewBox="0 0 240 32" role="img" aria-label="Connection style preview" data-patterns="<?php print nms_h(json_encode(nms_connection_patterns())); ?>"><line x1="14" y1="16" x2="226" y2="16" stroke="<?php print nms_h($type['color']); ?>" stroke-width="3" stroke-dasharray="<?php print nms_h(nms_connection_patterns()[$type['line_style']] ?? ''); ?>"/></svg></div><?php nms_connection_fields('connection_style'); ?><input type="hidden" name="type" value="<?php print nms_h($type['name']); ?>">
<div class="nms-connection-grid"><label>Colour<input type="color" name="color" value="<?php print nms_h($type['color']); ?>"></label><label>Line style<?php nms_connection_select('line_style',['solid'=>'Solid ━━━━━','dashed'=>'Dashed ━ ━ ━','dotted'=>'Dotted • • • •','dash-dot'=>'Dash-dot ━ • ━ •','fine-dotted'=>'Fine dotted ········','short-dashed'=>'Short dashed ┄┄┄┄'],$type['line_style']); ?></label><label>Endpoint symbol<?php nms_connection_select('symbol',['none'=>'None ──','circle'=>'Circle ●','square'=>'Square ■','arrow'=>'Arrow ▶'],$type['symbol']); ?></label></div>
<?php if ($can) { ?><div class="nms-connection-actions nms-style-actions"><button class="nms-catalog-button primary" type="submit">Save style</button></div><?php } ?></form>
<?php } } else { if ($can) { ?>
<form method="post" class="nms-panel nms-connection-form" id="connection-form">
<h2><?php print $edit['id']?'Edit connection':'Add connection'; ?></h2>
<?php nms_connection_fields('connection_save',$edit['id']); ?>
<div class="nms-connection-grid">
<?php foreach (['a'=>'Device A / interface','b'=>'Device B / interface'] as $side=>$label) { ?><label><?php print $label; ?><?php nms_connection_select($side,[''=>'Select a device or interface']+$options,$edit[$side]); ?></label><?php } ?>
<label>Connection type<?php nms_connection_select('type',nms_connection_types(),$edit['type']); ?></label>
<label>Label (optional)<input name="label" maxlength="150" value="<?php print nms_h($edit['label']); ?>"></label>
<label>Configured capacity (Mbps)<input name="speed_mbps" type="number" min="0" max="100000000" step="0.001" value="<?php print nms_h($edit['speed_mbps']); ?>"></label>
</div><p>Select a cached interface for monitoring, or a device when its port is unknown. Capacity is an entered value; use 0 when unknown.</p>
<div class="nms-connection-actions"><button class="nms-catalog-button primary" type="submit">Save connection</button><a class="nms-catalog-button" href="topology.php?tab=connections">Cancel</a></div>
</form><?php } ?>
<section class="nms-panel"><div class="nms-panel-head"><h2>Manually configured connections</h2></div><form method="get" class="nms-connection-filter"><input type="hidden" name="tab" value="connections"><label>Find connection <input type="search" name="search" value="<?php print nms_h($search); ?>" placeholder="Device, type or label"></label> <button class="nms-catalog-button primary" type="submit">Filter</button> <a class="nms-catalog-button" href="topology.php?tab=connections">Reset</a></form><div class="nms-catalog-scroll"><table class="nms-table"><thead><tr><th>Device A / interface</th><th>Device B / interface</th><th>Type / label</th><th>Configured Mbps</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($listed as $r) { ?><tr><td><?php print nms_h($options[$r['a']] ?? $r['a'].' — reselect interface'); ?></td><td><?php print nms_h($options[$r['b']] ?? $r['b'].' — reselect interface'); ?></td><td><?php print nms_h($r['type'].' · '.$r['label']); ?></td><td><?php print $r['speed_mbps']>0?nms_h($r['speed_mbps']):'Unknown'; ?></td><td><?php if ($can) { ?><a class="nms-catalog-button" href="topology.php?tab=connections&amp;edit=<?php print (int)$r['id']; ?>#connection-form">Edit</a><details class="nms-connection-delete"><summary>Delete</summary><form method="post"><p>Remove this manual map connection?</p><?php nms_connection_fields('connection_delete',$r['id']); ?><button type="submit" class="nms-catalog-button">Delete connection</button></form></details><?php } ?></td></tr><?php } if (!$listed) { ?><tr><td colspan="5">No manual connections. Add one above.</td></tr><?php } ?>
</tbody></table></div><nav class="nms-connection-pagination" aria-label="Connection pagination"><span>Showing <?php print $total?($page-1)*20+1:0; ?>–<?php print min($page*20,$total); ?> of <?php print $total; ?> · Page <?php print $page; ?> of <?php print $pages; ?></span><div class="nms-connection-actions">
<?php foreach (['Previous'=>$page-1,'Next'=>$page+1] as $label=>$target) { if ($target>=1 && $target<=$pages) { ?><a class="nms-catalog-button" href="<?php print nms_h('topology.php?'.http_build_query(['tab'=>'connections','search'=>$search,'page'=>$target])); ?>"><?php print $label; ?></a><?php } else { ?><button class="nms-catalog-button" disabled><?php print $label; ?></button><?php } } ?></div></nav></section><?php } ?></main>
<?php require __DIR__ . '/../../templates/app_footer.php'; ?>
