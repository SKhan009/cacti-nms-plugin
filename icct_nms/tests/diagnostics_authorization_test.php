<?php
require __DIR__ . '/../inventory/diagnostics/services/backend/diagnostics_queue.php';
$allowed=true;$realm=41;$enabled='on';$device=true;
function db_fetch_cell_prepared($sql,$args){if(str_contains($sql,'user_auth'))return $GLOBALS['enabled'];if(str_contains($sql,'plugin_realms')){if($args!==['icct_nms','diagnostics.php'])throw new Exception('Wrong plugin realm');return $GLOBALS['realm'];}throw new Exception('Unexpected query');}
function is_realm_allowed($realm,$user){if($user!==7)throw new Exception('Session permission cache used');return $realm===3||$GLOBALS['allowed'];}
function is_device_allowed($host,$user){return $host===2&&$user===7&&$GLOBALS['device'];}
$_SESSION=['sess_user_id'=>99];
icct_backend_diag_authorize_job(['user_id'=>7,'host_id'=>2]);
if($_SESSION['sess_user_id']!==99)throw new Exception('Session replaced');
foreach(['allowed','realm','enabled','device'] as $field){$prior=$GLOBALS[$field];$GLOBALS[$field]=$field==='enabled'?'':false;try{icct_backend_diag_authorize_job(['user_id'=>7,'host_id'=>2]);throw new Exception('Revoked '.$field.' accepted');}catch(RuntimeException $expected){}$GLOBALS[$field]=$prior;}
echo "PASS: explicit owner and registered plugin realm, revoked realm/device/account rejection\n";
