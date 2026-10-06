<?php
require __DIR__ . '/../../inventory/diagnostics/services/backend/diagnostics.php';
function diagnosticParameterAssert($condition,$message){if(!$condition)throw new RuntimeException($message);}
$input=['diagnostic_profile_name'=>'Device parameters','diagnostic_tools'=>['arp','pathchar'],'arp_interface'=>'enp0s8','pathchar_hops'=>12,'pathchar_timeout'=>75];
$profile=icct_backend_diag_profile_validate($input);
$row=$profile+['hostname'=>'192.0.2.1'];
[$arp,$timeout]=icct_backend_diag_arguments($row,'arp','ip');
diagnosticParameterAssert($arp===['neigh','show','dev','enp0s8']&&$timeout===5,'ARP interface did not reach command arguments.');
[$path,$timeout]=icct_backend_diag_arguments($row,'pathchar','pchar');
diagnosticParameterAssert($path===['-n','-H','12','-R','3','-I','128','192.0.2.1']&&$timeout===75,'Pchar hop and time limits were lost.');
[$path,$timeout]=icct_backend_diag_arguments($row,'pathchar','pathchar');
diagnosticParameterAssert($path===['-n','192.0.2.1']&&$timeout===75,'Pathchar received unsupported pchar flags.');
foreach([['arp_interface'=>'eth0;id'],['arp_interface'=>'-evil'],['pathchar_hops'=>31],['pathchar_timeout'=>121],['pathchar_timeout'=>9]] as $bad){
 try{icct_backend_diag_profile_validate(array_replace($input,$bad));throw new LogicException('Invalid diagnostic parameter accepted.');}catch(InvalidArgumentException $expected){}
}
$changed=$row;$changed['arp_interface']='eth0';
diagnosticParameterAssert(icct_backend_diag_signature($row)!==icct_backend_diag_signature($changed),'Changed parameters did not invalidate queued diagnostics.');
echo "ARP filter, pchar hops, time limits, invalid parameters and queued-job signatures passed.\n";
