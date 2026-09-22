<?php
/** Security regression: incomplete settings must never select a weaker SNMPv3 level. */
if(PHP_SAPI!=='cli') {http_response_code(403);exit;}
require_once(dirname(__DIR__, 2).'/plugins/topology/includes/discovery.php');
$base=array('snmp_version'=>'3','snmp_username'=>'test-user','snmp_auth_protocol'=>'SHA','snmp_password'=>'test-only-auth','snmp_priv_protocol'=>'AES','snmp_priv_passphrase'=>'test-only-priv','snmp_context'=>'');
function profile_check($h,$expected,$label) {
    if(tp_snmp_security($h)!==$expected) throw new RuntimeException($label);
    echo "PASS: $label\n";
}
function profile_reject($h,$label) {
    try {tp_snmp_security($h);} catch(RuntimeException $e) {echo "PASS: $label\n";return;}
    throw new LogicException('Accepted '.$label);
}
profile_check($base,'authPriv','explicit authenticated encryption');
profile_check(array_replace($base,array('snmp_priv_protocol'=>'[None]','snmp_priv_passphrase'=>'')),'authNoPriv','explicit authenticated unencrypted profile');
profile_check(array_replace($base,array('snmp_priv_protocol'=>'[None]','snmp_priv_passphrase'=>'','snmp_auth_protocol'=>'[None]','snmp_password'=>'')),'noAuthNoPriv','explicit unauthenticated profile');
foreach(array(
    'missing authentication key'=>array('snmp_password'=>''),
    'short authentication key'=>array('snmp_password'=>'short'),
    'missing encryption key'=>array('snmp_priv_passphrase'=>''),
    'short encryption key'=>array('snmp_priv_passphrase'=>'short'),
    'privacy without authentication'=>array('snmp_auth_protocol'=>'[None]','snmp_password'=>''),
    'authentication key without algorithm'=>array('snmp_auth_protocol'=>'[None]'),
    'privacy key without algorithm'=>array('snmp_priv_protocol'=>'[None]'),
    'unsupported algorithm'=>array('snmp_auth_protocol'=>'AUTO'),
    'missing security name'=>array('snmp_username'=>''),
    'oversized security name'=>array('snmp_username'=>str_repeat('u',33)),
    'oversized context'=>array('snmp_context'=>str_repeat('c',33))
) as $label=>$changes) profile_reject(array_replace($base,$changes),$label.' rejected');
echo "SECURITY PROFILE TESTS COMPLETE\n";
