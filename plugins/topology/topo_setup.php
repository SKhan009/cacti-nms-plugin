<?php
require(__DIR__.'/../../include/auth.php');
require_once(__DIR__.'/includes/ui.php');
require_once(__DIR__.'/includes/flow.php');
$error='';
try{tp_ready();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(($_POST['tp_action']??'')!=='category')throw new InvalidArgumentException('Use Assign Devices to classify existing Cacti devices.');
        tp_category_save($_POST['id']??'',$_POST['name']??'',$_POST['description']??'',$_POST['active']??'',$_POST['color']??'');
        raise_message('topology_saved','Device segment saved.',MESSAGE_LEVEL_INFO);
        header('Location: topo_start.php?step=1');exit;
    }
    if(($_GET['edit']??'')!=='category'){header('Location: topo_start.php?step=3');exit;}
}catch(Throwable $e){$error=$e->getMessage();}
top_header();if($error)tp_notice('Category error',$error);
try{tp_ready();tp_flow_editor(1);
    $id=tp_id($_POST['id']??$_GET['id']??0,true);
    $row=$id?db_fetch_row_prepared('SELECT * FROM plugin_topology_categories WHERE id=?',array($id)):array();
    if($id&&!$row)throw new InvalidArgumentException('Category no longer exists.');
        tp_form('Device Segment','category',array(
            'id'=>array('method'=>'hidden','value'=>$_POST['id'] ?? $id),
            'name'=>tp_field('Name',$_POST['name'] ?? ($row['name'] ?? ''),'Examples: Network, Computers, Power.'),
            'description'=>tp_field('Description',$_POST['description'] ?? ($row['description'] ?? ''),'',255),
            'color'=>tp_select('Topology Color',array('#2563eb'=>'Blue','#0f766e'=>'Teal','#7c3aed'=>'Purple','#c2410c'=>'Orange','#be185d'=>'Pink','#64748b'=>'Slate'),$_POST['color'] ?? ($row['color'] ?? '#64748b'),'Category color on the topology diagram. Device health uses separate status colors.'),
            'active'=>tp_select('State',array(1=>'Active',0=>'Archived'),$_POST['active'] ?? ($row['active'] ?? 1),'Archiving retains existing assignments.')
        ));
 }catch(Throwable $e){tp_notice('Configuration required',$e->getMessage());}
tp_footer();
