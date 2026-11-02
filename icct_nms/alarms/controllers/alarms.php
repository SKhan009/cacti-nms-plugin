<?php
require dirname(__DIR__,2).'/../../include/auth.php';
require_once dirname(__DIR__,2).'/shared/services/bootstrap.php';
require_once dirname(__DIR__,2).'/inventory/services/inventory.php';
require_once dirname(__DIR__,2).'/graphs/services/graph_service.php';
try{
 icct_nms_backend();
 if($_SERVER['REQUEST_METHOD']==='POST'){
  header('Content-Type: application/json');icct_nms_post();icct_nms_alarm_action($_POST);echo json_encode(['ok'=>true]);exit;
 }
 if(isset($_GET['history'])){
  $key=$_GET['history'];$row=is_string($key)&&preg_match('/^[a-f0-9]{64}$/D',$key)?db_fetch_row_prepared('SELECT host_id,graph_id FROM plugin_icct_nms_alarms WHERE alarm_key=?',[$key]):[];
  if(!$row)throw new RuntimeException('Alarm unavailable.');icct_nms_alarm_require_access($row);
  header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode(db_fetch_assoc_prepared('SELECT event,actor_id,note,created_at,UNIX_TIMESTAMP(created_at) AS created_epoch FROM plugin_icct_nms_alarm_history WHERE alarm_key=? ORDER BY id DESC',[$key]),JSON_THROW_ON_ERROR);exit;
 }
 if(isset($_GET['view'])){
  $alarmDetail=icct_nms_alarm_detail($_GET['view']);
  if(isset($_GET['readings'])){header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode($alarmDetail,JSON_THROW_ON_ERROR);exit;}
 }else $alarmRows=icct_nms_alarm_rows();
 if(isset($_GET['readings'])){header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode($alarmRows,JSON_THROW_ON_ERROR);exit;}
}catch(Throwable $e){if($_SERVER['REQUEST_METHOD']==='POST'||isset($_GET['history'])||isset($_GET['readings'])){http_response_code(400);header('Content-Type: application/json');echo json_encode(['error'=>$e->getMessage()]);exit;}icct_nms_failure($e);}
$title='Alarms';$alarmsPage=true;$management=is_realm_allowed(3);
require dirname(__DIR__,2).'/shared/templates/header.php';
if(isset($alarmDetail)){
 require dirname(__DIR__).'/templates/detail.php';
}else require dirname(__DIR__).'/templates/list.php';
require dirname(__DIR__,2).'/shared/templates/footer.php';
