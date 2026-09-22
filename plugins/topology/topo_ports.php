<?php
/** Native Cacti profile, device-port and interface-mapping editors. */
require(__DIR__.'/../../include/auth.php');
require_once(__DIR__.'/includes/ui.php');
require_once(__DIR__.'/includes/browse.php');
require_once(__DIR__.'/includes/flow.php');
require_once(__DIR__.'/includes/physical.php');
$error='';$unitId=0;
try {
    tp_ready();
    $unitId=tp_scope_id();
    if($_SERVER['REQUEST_METHOD']==='POST') {
        switch($_POST['tp_action']??'') {
            case 'profile': tp_profile_save($_POST['id']??'',$_POST['category_id']??'',$_POST['name']??'',$_POST['prefix']??'',$_POST['first_number']??'',$_POST['port_count']??'',$_POST['connector']??'');break;
            case 'apply': tp_ports_apply($unitId,$_POST['host_id']??'',$_POST['profile_id']??'');break;
            case 'map': tp_port_map($unitId,$_POST['port_id']??'',$_POST['if_index']??'');break;
            default: throw new InvalidArgumentException('Unknown physical port action.');
        }
        raise_message('topology_ports_saved','Physical port configuration saved.',MESSAGE_LEVEL_INFO);
        if(tp_flow_return(($_POST['tp_action']??'')==='profile'?2:3,$unitId))exit;
        header('Location: topo_ports.php'.($unitId?'?unit_id='.$unitId:''));exit;
    }
} catch(Throwable $e) {$error=$e->getMessage();}
top_header();
if($error) tp_notice('Port configuration error',$error);
try {
    tp_ready();$mode=$_POST['tp_action']??$_GET['edit']??'';if(isset($_GET['flow_step'])||isset($_POST['flow_step']))tp_flow_editor($mode==='profile'?2:3,$unitId);
    tp_submenu(array('ports'=>array('label'=>'Device Ports','url'=>'topo_ports.php'),'profile'=>array('label'=>'Category Profiles','url'=>'topo_ports.php?edit=profile')),$mode==='profile'?'profile':'ports','Physical ports');
    if($mode==='profile') {
        $id=tp_id($_POST['id']??$_GET['id']??0,true);$r=$id?db_fetch_row_prepared('SELECT * FROM plugin_topology_port_profiles WHERE id=?',array($id)):array();
        if($id&&!$r) throw new InvalidArgumentException('Profile no longer exists.');
        $v=function($k,$default='')use($r){return $_POST[$k]??$r[$k]??$default;};
        tp_form($id?'Edit Category Port Profile':'New Category Port Profile','profile',array(
            'id'=>array('method'=>'hidden','value'=>$id),
            'category_id'=>tp_select('Device Segment',tp_category_options(),$v('category_id')),
            'name'=>tp_field('Profile Name',$v('name'),'Example: 24-port access switch.'),
            'prefix'=>tp_field('Port Label Prefix',$v('prefix'),'Example: Ethernet or Gi1/0/',64),
            'first_number'=>tp_field('First Port Number',$v('first_number','1'),'0–65406',5),
            'port_count'=>tp_field('Number of Physical Ports',$v('port_count'),'1–128',3),
            'connector'=>tp_field('Connector',$v('connector'),'Example: RJ45, SFP or LC.',32)
        ));
        $rows=array();foreach(db_fetch_assoc('SELECT p.*,c.name AS category FROM plugin_topology_port_profiles p JOIN plugin_topology_categories c ON c.id=p.category_id ORDER BY c.name,p.name') as $r) $rows[]=array(tp_h($r['category']),'<a class="ui-button ui-corner-all ui-widget" href="topo_ports.php?edit=profile&amp;id='.(int)$r['id'].'">'.tp_h($r['name']).'</a>',tp_h($r['prefix'].$r['first_number'].' – '.$r['prefix'].($r['first_number']+$r['port_count']-1)),tp_h($r['connector']));
        tp_table('Segment Port Profiles',array('Segment','Profile','Physical Ports','Connector'),$rows);
    } elseif($mode==='apply') {
        $host=tp_physical_host($_POST['host_id']??$_GET['host_id']??'',$unitId);$options=array(''=>'Select a category profile',0=>'Clear physical ports (disconnect cables first)');
        foreach(db_fetch_assoc_prepared('SELECT id,name,port_count FROM plugin_topology_port_profiles WHERE category_id=? ORDER BY name',array($host['category_id'])) as $p) $options[$p['id']]=$p['name'].' — '.$p['port_count'].' ports';
        tp_form('Physical Ports — '.$host['description'],'apply',array(
            'unit_id'=>array('method'=>'hidden','value'=>$unitId),'host_id'=>array('method'=>'hidden','value'=>$host['id']),
            'profile_id'=>tp_select('Category Port Profile',$options,$_POST['profile_id']??'','Applies this profile to the device. Existing matching port labels keep their connections and interface mappings.')
        ));
    } elseif($mode==='map') {
        $p=tp_physical_port($_POST['port_id']??$_GET['port_id']??'',$unitId);$h=tp_physical_host($p['host_id'],$unitId);
        tp_form($h['description'].' — '.$p['label'],'map',array(
            'unit_id'=>array('method'=>'hidden','value'=>$unitId),'port_id'=>array('method'=>'hidden','value'=>$p['id']),
            'if_index'=>tp_select('Cacti Interface',tp_interface_options($h['id']),$_POST['if_index']??$p['if_index']??'','Map an interface from the native Cacti data query cache. Port numbers are never assumed to be ifIndex values.')
        ));
    } else {
        if(!$unitId)tp_browse_page('ports');
        if($unitId) {
            $rows=array();$portRows=array();
            $filter=tp_browse_options();tp_browse_filter('Filter Devices',$filter,array(),true);
            $matches=tp_browse_data('ports',$filter);
            $total=count($matches);$filter['page']=min($filter['page'],max(1,(int)ceil($total/$filter['rows'])));
            foreach(array_slice($matches,($filter['page']-1)*$filter['rows'],$filter['rows']) as $match) {$h=$match['host'];
                $ports=db_fetch_assoc_prepared('SELECT p.*,f.name AS profile FROM plugin_topology_ports p LEFT JOIN plugin_topology_port_profiles f ON f.id=p.profile_id WHERE p.host_id=? ORDER BY p.ordinal',array($h['id']));
                $rows[]=array(tp_h($h['description']),tp_h($h['category'].' / '.$h['device_type']),count($ports).($ports?' — '.tp_h($ports[0]['profile']):' — Unconfigured'),'<a class="ui-button ui-corner-all ui-widget" href="topo_ports.php?edit=apply&amp;unit_id='.$unitId.'&amp;host_id='.(int)$h['id'].'">Configure Ports</a>');
                foreach($ports as $p) $portRows[]=array(tp_h($h['description']),tp_h($p['label'].' / '.$p['connector']),$p['if_index']?'ifIndex '.(int)$p['if_index']:'Unmapped','<a class="ui-button ui-corner-all ui-widget" href="topo_ports.php?edit=map&amp;unit_id='.$unitId.'&amp;port_id='.(int)$p['id'].'">Map Interface</a>');
            }
            tp_table('Device Port Configuration',array('Device','Category / Type','Applied Profile','Actions'),$rows,'','No matching devices.',false);
            tp_table('Physical Ports',array('Device','Port / Connector','Cacti Interface','Actions'),$portRows);tp_browse_pager($filter,$total);
        }
    }
} catch(Throwable $e) {tp_notice('Configuration required',$e->getMessage());}
tp_footer();
