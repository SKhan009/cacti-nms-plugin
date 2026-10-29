<?php
/** Render the shared preset/device fields and device-scoped history without a live DB. */
set_error_handler(static function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
$queries=[];$binding=['enabled'=>1,'source_address'=>'192.0.2.85','severity_codes'=>'[3,4]','facility_codes'=>'[16]','match_strings'=>'["link down"]'];
$events=[['event_time'=>'2026-10-29 19:30:00','severity_code'=>3,'source_ip'=>'192.0.2.85','facility_code'=>16,'facility'=>'local0','program'=>'qa','message'=>'<script>unsafe</script> link down','repeat_count'=>1,'last_seen'=>'2026-10-29 19:30:00']];
function db_fetch_row_prepared($sql,$args){return $GLOBALS['binding'];}
function db_fetch_cell_prepared($sql,$args){$GLOBALS['queries'][]=[$sql,$args];return count($GLOBALS['events']);}
function db_fetch_assoc_prepared($sql,$args){$GLOBALS['queries'][]=[$sql,$args];return $GLOBALS['events'];}
function icct_backend_protocol_enabled($id,$protocol){return true;}
function icct_nms_h($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function syslog_view_check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
require __DIR__.'/../../protocols/syslog/services/backend/syslog.php';
$template=__DIR__.'/../../protocols/syslog/templates/syslog.php';
$syslog=$binding;$presetMode=true;ob_start();require $template;$html=ob_get_clean();
syslog_view_check(!str_contains($html,'name="source_address"')&&!str_contains($html,'name="transport"'),'Preset fields contain no device endpoint');
syslog_view_check(str_contains($html,'value="[3,4]"')&&str_contains($html,'value="[16]"'),'Preset severity and facility selections render the saved values');
syslog_view_check(!str_contains($html,'Syslog Console'),'Preset has no standalone console action');
$presetMode=false;$syslog=[];$id=0;ob_start();require $template;$html=ob_get_clean();
syslog_view_check(str_contains($html,'name="source_address" value=""'),'Unsaved device renders without a missing-hostname warning');
$host=['hostname'=>'192.0.2.85'];ob_start();require $template;$html=ob_get_clean();
syslog_view_check(str_contains($html,'name="source_address" value="192.0.2.85"'),'A new device uses its saved hostname for source matching');
$syslog=$binding;ob_start();require $template;$html=ob_get_clean();
syslog_view_check(str_contains($html,'value="[3,4]"')&&!str_contains($html,'Syslog Console'),'Device overrides are retained without a console action');
$id=85;$old=$host;$_GET=['syslog_search'=>'link down'];ob_start();require __DIR__.'/../../protocols/syslog/templates/device_view_syslog.php';$html=ob_get_clean();
syslog_view_check(str_contains($html,'Saved Syslog configuration')&&str_contains($html,'Error, Warning')&&str_contains($html,'Local 0')&&str_contains($html,'link down'),'Read-only view shows saved filters and source configuration');
syslog_view_check(str_contains($html,'device-syslog-severity severity-major'),'Received messages show the correct severity badge');
syslog_view_check(str_contains($html,'&lt;script&gt;unsafe&lt;/script&gt;')&&!str_contains($html,'<script>unsafe'),'Received messages are escaped');
syslog_view_check(!str_contains($html,'method="post"')&&!str_contains($html,'Ingest Worker'),'Device history has no configuration writes or standalone worker panel');
foreach($queries as [$sql,$args])syslog_view_check($args[0]===85&&$args[1]===85,'Event queries remain scoped to the current device');
$binding=[];$events=[];$_GET=['syslog_search'=>['invalid']];ob_start();require __DIR__.'/../../protocols/syslog/templates/device_view_syslog.php';$html=ob_get_clean();
syslog_view_check(str_contains($html,'Syslog is not configured')&&str_contains($html,'No Syslog or availability events'),'Unconfigured devices and malformed searches render safely');
echo "Syslog preset, device setup and read-only view rendering passed.\n";
