<?php
/** Standalone loopback PHP development-server test only; never accessible through Apache. */
if(PHP_SAPI!=='cli-server'||getenv('TOPOLOGY_HTTP_QA')!=='1'){http_response_code(403);exit;}
require_once(dirname(__DIR__, 2).'/plugins/topology/includes/records.php');
header('Content-Type: application/json');
try{$records=tp_records(tp_uploaded_record($_FILES['record']??null));echo json_encode(array('records'=>count($records),'metrics'=>count(array_filter($records,function($r){return $r['metric'];}))));}
catch(Throwable $e){http_response_code(400);echo json_encode(array('error'=>$e->getMessage()));}
