<?php
/** Prove table-read limits and incomplete-result rejection independently of an SNMP agent. */
if(PHP_SAPI!=='cli') {http_response_code(403);exit;}
require_once(dirname(__DIR__, 2).'/plugins/topology/includes/discovery.php');
class TableTestSession {
    public $calls=0;
    private $responses;
    private $errno=0;
    /** Supply ordered protocol responses, then a timeout. */
    function __construct($responses) {$this->responses=$responses;}
    /** Return one controlled SNMP result for the requested cursor. */
    function getnext($oids) {$this->calls++;$next=array_shift($this->responses);$this->errno=$next===null?4:0;return $next===null?false:$next;}
    /** Expose the simulated SNMP transport outcome. */
    function getErrno() {return $this->errno;}
    /** Supply only a generic timeout error. */
    function getError() {return 'No response';}
}
function reject_walk($session,$deadline,$budget,$label) {
    try {tp_snmp_subtree($session,'1.2.3',$deadline,$budget);throw new LogicException('Accepted '.$label);}
    catch(RuntimeException $e) {echo 'PASS: '.$label."\n";}
}
$row=array('.1.2.3.1'=>(object)array('type'=>2,'value'=>'1'));
reject_walk(new TableTestSession(array($row)),microtime(true)+5,10,'timeout after one row never publishes a partial table');
reject_walk(new TableTestSession(array($row,$row)),microtime(true)+5,10,'non-increasing OID fails explicitly');
$bounded=new TableTestSession(array($row));reject_walk($bounded,microtime(true)+5,0,'object limit prevents another request');
if($bounded->calls!==0) throw new RuntimeException('Request issued beyond the object limit');
$expired=new TableTestSession(array($row));reject_walk($expired,microtime(true)-1,10,'expired job deadline prevents another request');
if($expired->calls!==0) throw new RuntimeException('Request issued after deadline');
echo "TRANSPORT TESTS COMPLETE\n";
