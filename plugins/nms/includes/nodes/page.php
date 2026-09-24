<?php
/** Authenticated Device management Nodes tab; schema upgrades remain lifecycle-only. */
$error = '';
$node_id = 0;
try { $node_id = nms_node_id($_GET['node_id'] ?? 0); } catch (Throwable $e) { http_response_code(400); $error = $e->getMessage(); }
$removing = isset($_GET['remove']);
$editing = isset($_GET['edit']) || isset($_GET['new']);
$values = ['node_id'=>0,'site_id'=>'','name'=>'','code'=>'','description'=>'','members'=>[]];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrf_check_tokens($_POST['__csrf_magic'] ?? '')) throw new RuntimeException('Invalid request token.');
        if (($_POST['nms_action'] ?? '') === 'remove_node') {
            nms_node_remove($_POST);
            header('Location: devices.php?tab=nodes&removed=1'); exit;
        }
        if (($_POST['nms_action'] ?? '') !== 'save_node') throw new InvalidArgumentException('Unsupported node action.');
        $saved = nms_node_save($_POST);
        header('Location: devices.php?tab=nodes&node_id='.$saved.'&saved=1'); exit;
    } catch (Throwable $e) { $error=$e->getMessage(); $editing=!$removing; foreach ($values as $key=>$default) {
            if ($key === 'members') $values[$key] = is_array($_POST[$key] ?? null) ? array_filter($_POST[$key], 'is_scalar') : [];
            elseif (isset($_POST[$key]) && is_scalar($_POST[$key])) $values[$key] = $_POST[$key];
        } }
}
$nodes = nms_nodes_list();
$selected = null;
if ($node_id) {
    try { $selected=nms_node_get($node_id); } catch (Throwable $e) { http_response_code(404); $error=$e->getMessage(); $editing=false; }
}
$members = $selected ? nms_node_members($node_id) : [];
$health = nms_node_health($members,time()-max(60,nms_poller_interval()*2));
$alarms = [];
if ($selected) {
    $visible=nms_visible_host_sql();
    $alarms=db_fetch_assoc_prepared("SELECT i.id,i.host_id,i.title,i.severity,i.status,i.last_seen,h.description FROM plugin_nms_incidents i JOIN host h ON h.id=i.host_id JOIN plugin_nms_node_devices m ON m.host_id=h.id WHERE m.node_id=? AND h.deleted='' AND $visible AND i.status IN ('open','acknowledged') ORDER BY i.last_seen DESC",[$node_id]);
}
if ($editing && !$error && $selected) $values=['node_id'=>$node_id,'site_id'=>$selected['site_id'],'name'=>$selected['name'],'code'=>$selected['code'],'description'=>$selected['description'],'members'=>array_column($members,'id')];
$can_manage=is_realm_allowed(3);
if ($editing && !$can_manage) { $editing=false; $error='You do not have node management permission.'; }
if ($removing && !$can_manage) { $removing=false; $error='You do not have node management permission.'; }
$sites=db_fetch_assoc('SELECT id,name FROM sites ORDER BY name');
$visible=nms_visible_host_sql();
$candidates=$can_manage ? db_fetch_assoc("SELECT h.id,h.description,h.hostname,h.site_id,m.node_id,n.name AS node_name FROM host h LEFT JOIN plugin_nms_node_devices m ON m.host_id=h.id LEFT JOIN plugin_nms_nodes n ON n.id=m.node_id WHERE h.deleted='' AND $visible ORDER BY h.description") : [];
nms_prepare_page('devices','NMS · Nodes','css/nms-devices.css,css/nms-nodes.css','js/nms-nodes.js');
require __DIR__.'/../../templates/app_header.php';
require __DIR__.'/../../templates/devices/nodes.php';
require __DIR__.'/../../templates/app_footer.php';
