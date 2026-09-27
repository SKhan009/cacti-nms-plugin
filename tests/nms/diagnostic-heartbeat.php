<?php
/** Real subprocess wait/timeout/cleanup; no network or Cacti writes. */
require __DIR__.'/../../plugins/nms/includes/diagnostics.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
$ticks=[];
$result=nms_diag_run_command([PHP_BINARY,'-r','usleep(6200000); echo "done";'],8,function()use(&$ticks){$ticks[]=hrtime(true);});
check($result['exit']===0 && $result['stdout']==='done','Long worker completes with original output and exit code');
$gaps=[];for($i=1;$i<count($ticks);$i++)$gaps[]=($ticks[$i]-$ticks[$i-1])/1e9;
check(count($ticks)>=6 && max($gaps)<2,'Heartbeat stays fresh beyond five-second admission threshold');
$ticks=[];$result=nms_diag_run_command([PHP_BINARY,'-r','sleep(5);'],1,function()use(&$ticks){$ticks[]=true;});
check($result['timed_out'] && $result['exit']===124 && count($ticks)>0,'Heartbeat does not bypass command timeout');
$marker=tempnam(sys_get_temp_dir(),'nms-heartbeat-');unlink($marker);
$start=hrtime(true);$caught=false;
try{nms_diag_run_command([PHP_BINARY,'-r','usleep(800000);file_put_contents($argv[1],"unexpected");',$marker],5,function(){throw new RuntimeException('lost ownership');});}
catch(RuntimeException $e){$caught=$e->getMessage()==='lost ownership';}
usleep(900000);
try{check($caught && !file_exists($marker) && (hrtime(true)-$start)/1e9<3,'Heartbeat failure terminates/reaps child and propagates ownership failure');}
finally{if(file_exists($marker))unlink($marker);}
echo "4 real subprocess assertions passed.\n";
