<?php
require __DIR__.'/../../plugins/nms/includes/discovery_snmp.php';
class AddressWalkSession {
    private $responses;
    private $error=0;
    public $calls=0;
    function __construct($responses) {$this->responses=$responses;}
    function getnext($oids) {$this->calls++;$row=array_shift($this->responses);$this->error=$row===null?4:0;return $row===null?false:$row;}
    function getErrno(){return $this->error;}
    function getError(){return 'Timeout';}
}
$root='1.3.6.1.2.1.4.20.1.2';
$row=[$root.'.10.0.0.1'=>(object)['type'=>2,'value'=>'1']];
$end=['1.3.6.1.2.1.4.21.1.1.0'=>(object)['type'=>2,'value'=>'1']];
$budget=100;
$values=nms_nd_snmp_subtree(new AddressWalkSession([$row,$end]),$root,microtime(true)+5,$budget);
if(nms_nd_own_addresses($values)[0]['address']!=='10.0.0.1') throw new RuntimeException('SNMP walk not passed to own-address parser');
foreach ([[$row],[$row,$row]] as $responses) {
    $budget=100;
    try {nms_nd_snmp_subtree(new AddressWalkSession($responses),$root,microtime(true)+5,$budget);throw new LogicException('Partial/invalid address table accepted');}
    catch(RuntimeException $e) {}
}
$session=new AddressWalkSession([$row]);$budget=100;
try {nms_nd_snmp_subtree($session,$root,microtime(true)-1,$budget);throw new LogicException('Expired walk accepted');}
catch(RuntimeException $e) {}
if($session->calls!==0) throw new RuntimeException('SNMP called after deadline');
echo "PASS: complete SNMP own-address walk; timeout, repeated OID and expired deadline discard incomplete tables\n";
