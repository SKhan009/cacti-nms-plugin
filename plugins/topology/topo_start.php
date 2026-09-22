<?php
/** Native Cacti guide: device segments → port profiles → assign devices. */
require(__DIR__.'/../../include/auth.php');
require_once(__DIR__.'/includes/ui.php');
require_once(__DIR__.'/includes/flow.php');
$error='';
try{tp_ready();if($_SERVER['REQUEST_METHOD']==='POST'){
    if(($_POST['tp_action']??'')!=='bulk')throw new InvalidArgumentException('Unknown assignment action.');
    $count=tp_assign_devices($_POST['category_id']??'',$_POST['profile_id']??'',$_POST['host_ids']??array());
    raise_message('topology_saved',$count.' devices assigned with physical ports.',MESSAGE_LEVEL_INFO);
    header('Location: topo_start.php?step=3&category_id='.tp_id($_POST['category_id']));exit;
}}catch(Throwable $e){$error=$e->getMessage();}
top_header();
if($error)tp_notice('Assignment error',$error);
try{tp_ready();tp_flow_page();}catch(Throwable $e){tp_notice('Setup selection required',$e->getMessage());}
tp_footer();
