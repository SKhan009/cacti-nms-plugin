<?php
/** Imported Syslog assignment changes must preserve exact filters and reject duplicate active mappings. */
$GLOBALS['duplicate']=[];$GLOBALS['writes']=[];
function icct_backend_require_management($realm){}
function icct_backend_require_device_access($id){}
function icct_backend_protocol_enabled($id,$protocol){return true;}
function db_fetch_row_prepared($sql,$args){
    if(str_contains($sql,'INNER JOIN host')){
        if(!str_contains($sql,"h.deleted=''"))throw new RuntimeException('Deleted hosts must not reserve a source');
        return $GLOBALS['duplicate'];
    }
    return [];
}
function icct_backend_category_execute($sql,$args){$GLOBALS['writes'][]=$args;}
function icct_backend_current_user_id(){return 1;}
require __DIR__.'/../../protocols/syslog/services/backend/syslog.php';
require __DIR__.'/../../protocols/syslog/services/syslog_service.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
$input=['source_address'=>'192.0.2.85','transport'=>'both','severity_codes'=>'[3,7]','facility_codes'=>'[16]','match_strings'=>'["QA"]'];
icct_nms_save_syslog(85,[], $input);
$args=$GLOBALS['writes'][0];
check($args[4]===7,'Legacy severity ceiling derives from exact selected levels');
check($args[5]==='[3,7]'&&$args[6]==='[16]'&&$args[7]==='["QA"]','Exact severity, facility and keyword overrides remain intact');
$GLOBALS['duplicate']=['host_id'=>2,'description'=>'Existing router','hostname'=>'192.0.2.2'];
try{icct_nms_save_syslog(85,[],$input);throw new RuntimeException('Duplicate was accepted');}catch(InvalidArgumentException $error){check(str_contains($error->getMessage(),'Existing router'),'Duplicate mapping gives a useful device conflict');}
check(count($GLOBALS['writes'])===1,'Rejected duplicate creates no write');
echo "Syslog assignment compatibility tests passed.\n";
