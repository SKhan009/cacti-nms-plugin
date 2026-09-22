<?php
/** Native simulator upload/provision UI; records and actual observations are kept distinct. */
require(__DIR__.'/../../include/auth.php');
require_once(__DIR__.'/includes/ui.php');
require_once(__DIR__.'/includes/browse.php');
require_once(__DIR__.'/includes/flow.php');
require_once(__DIR__.'/includes/simulator.php');
$error='';
$tab=$_GET['tab'] ?? (($_GET['action'] ?? '')==='upload'?'upload':'imports');
if(!is_string($tab) || !in_array($tab,array('imports','upload'),true)) $tab='imports';
try {
    tp_ready();
    if(isset($_GET['example'])){http_response_code(404);throw new InvalidArgumentException('Example downloads have been removed. Upload your SNMP record.');}
    if($_SERVER['REQUEST_METHOD']==='POST') {
        $action=$_POST['tp_action'] ?? '';
        if($action==='upload') {
            $content=tp_uploaded_record($_FILES['record']??null);
            $id=tp_sim_stage($_POST['name'] ?? '',tp_scope_id(),$_POST['category_id'] ?? '',$_POST['community'] ?? '',$content);
            raise_message('topology_import','Record '.$id.' uploaded. Simulator activation and automatic device/data-source creation queued.',MESSAGE_LEVEL_INFO);
        } elseif($action==='retry') {
            tp_sim_retry($_POST['id']??'');
            raise_message('topology_import','Automatic provisioning queued again.',MESSAGE_LEVEL_INFO);
        } elseif($action==='reload') {
            tp_sim_reload(tp_sim_config());
            raise_message('topology_import','Simulator activation queued.',MESSAGE_LEVEL_INFO);
        } elseif($action==='probe') {
            $row=db_fetch_row_prepared('SELECT * FROM plugin_topology_imports WHERE id=?',array(tp_id($_POST['id'] ?? '')));
            if(!$row) throw new InvalidArgumentException('Import not found.');
            tp_unit($row['unit_id']); if($row['host_id']) tp_host($row['host_id']);
            tp_sim_probe($row,tp_sim_config());
            raise_message('topology_import','Live SNMP identity verified at '.date('Y-m-d H:i:s').'.',MESSAGE_LEVEL_INFO);
        } else throw new InvalidArgumentException('Unknown simulator action.');
        $redirect='topo_sim.php';
        if(isset($_POST['flow_step'])){$c=tp_flow_context();$redirect=tp_flow_target($redirect,4,$c['site'],$c['unit']);}
        header('Location: '.$redirect);exit;
    }
} catch(Throwable $e) { $error=$e->getMessage(); }
top_header();
if($error && ($_POST['tp_action'] ?? '')==='upload') $tab='upload';
try {if(isset($_GET['flow_step'])||isset($_POST['flow_step']))tp_flow_editor(3);}catch(Throwable $e){tp_notice('Setup selection required',$e->getMessage());}
tp_sim_tabs($tab);
print '<link rel="stylesheet" href="'.tp_h(tp_url('plugins/topology/assets/simulator.css')).'?v=063">';
if($error)tp_notice('Simulator error',$error);
try {
    tp_ready();
    $c=tp_sim_config();tp_sim_templates($c);
    $state='Unknown';
    if(is_file($c['status_file']) && time()-filemtime($c['status_file'])<45) {
        $raw=trim(file_get_contents($c['status_file']));
        if(in_array($raw,array('active','inactive','failed','activating','deactivating'),true)) $state=ucfirst($raw);
    }
    $toolbar=array(array('id'=>'tp-sim-retry','class'=>'fa fa-sync','callback'=>true,'href'=>'#tp-sim-reload','title'=>'Retry Simulator Activation'));
    tp_table('Simulator',array('Service','Endpoint','SNMP','Collector'),array(array(tp_h($state),tp_h($c['address'].':'.$c['port']),'v2c',tp_h($c['poller_id']))),$toolbar,'',false);
    print '<form id="tp-sim-reload" method="post" action="topo_sim.php" hidden><input type="hidden" name="tp_action" value="reload"></form>';
    print '<script '.CactiSecureHeaders::getNonceAttribute().'>$(function(){const retry=$("#tp-sim-retry");retry.attr({"role":"button","aria-label":"Retry Simulator Activation","title":"Retry Simulator Activation"});retry.on("click",function(e){e.preventDefault();if(this.getAttribute("aria-disabled")==="true")return;this.setAttribute("aria-disabled","true");document.getElementById("tp-sim-reload").requestSubmit();});retry.on("keydown",function(e){if(e.key===" "){e.preventDefault();this.click();}});});</script>';
    if($tab==='upload') {
        tp_form('Upload Static SNMP Record','upload',array(
            'name'=>tp_field('Device Name',$_POST['name'] ?? ''),
            'unit_id'=>array('method'=>'hidden','value'=>tp_scope_id()),
            'category_id'=>tp_select('Device Segment',tp_category_options(),$_POST['category_id'] ?? ''),
            'community'=>tp_field('Lab Community',$_POST['community'] ?? '','Unique simulator record name: letters, digits, underscores and hyphens only.',64),
            'record'=>array('method'=>'file','friendly_name'=>'SNMP Record','description'=>'Static .snmprec file, maximum 2 MB and 64 numeric metrics. Requires sysName and sysUpTime.','accept'=>'.snmprec')
        ),true);
    } elseif($tab==='imports') {
        $rows=array();$units=array_column(tp_units(),null,'id');$categories=tp_category_options();$filter=tp_browse_options();$imports=array();
        foreach(db_fetch_assoc('SELECT * FROM plugin_topology_imports ORDER BY id DESC') as $row) {
            if(!isset($units[$row['unit_id']])||($row['host_id']&&!is_device_allowed($row['host_id'])))continue;
            $siteId=$row['host_id']?(int)db_fetch_cell_prepared('SELECT site_id FROM host WHERE id=?',array($row['host_id'])):(int)$row['site_id'];$row['site_label']=tp_site_options()[$siteId]??'Unassigned';if($filter['list_site']&&$siteId!==$filter['list_site'])continue;
            if(!tp_browse_matches($filter['search'],array($row['name'],$row['site_label'],$categories[$row['category_id']]??'',$row['community'],$row['state'])))continue;
            $imports[]=$row;
        }
        $total=count($imports);$pages=max(1,(int)ceil($total/$filter['rows']));$filter['page']=min($filter['page'],$pages);
        tp_browse_filter('Import Filters',$filter,array('tab'=>'imports'));
        foreach(array_slice($imports,($filter['page']-1)*$filter['rows'],$filter['rows']) as $row) {
            $id=(int)$row['id'];$host=(int)$row['host_id'];$u=$units[$row['unit_id']];
            $actions='<div class="tp-sim-actions"><form method="post" action="topo_sim.php"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="tp_action" value="probe"><button type="submit" class="ui-button ui-corner-all ui-widget">Test SNMP</button></form>';
            if(!$host&&$row['state']==='failed') $actions.='<form method="post" action="topo_sim.php"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="tp_action" value="retry"><button type="submit" class="ui-button ui-corner-all ui-widget">Retry Provisioning</button></form>';
            if($host) $actions.='<a href="'.tp_h(tp_url('host.php?action=edit&id='.$host)).'">Open Cacti Device</a>';
            $actions.='</div>';
            $rows[]=array('<span class="tp-sim-name">'.tp_h($row['name']).'</span>','<span class="tp-sim-location">'.tp_h($row['site_label']).'</span>',tp_h($categories[$row['category_id']]??'Category unavailable'),'<span class="tp-sim-community">'.tp_h($row['community']).'</span>',tp_h(ucfirst($row['state'])).($row['last_error']?'<br>'.tp_h($row['last_error']):''),(int)$row['record_count'].' / '.(int)$row['metric_count'],$actions);
        }
        tp_table('Simulator Imports',array('Device','Site','Segment','Lab Community','Provisioning','Records / Metrics','Actions'),$rows,'topo_sim.php?tab=upload','No matching imports.',false);
        tp_browse_pager($filter,$total,array('tab'=>'imports'));
    }
} catch(Throwable $e) { tp_notice('Simulator configuration required',$e->getMessage()); }
tp_footer();
