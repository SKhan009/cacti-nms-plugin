<?php
/** No database writes: disabled devices retain the same native authorization. */
require __DIR__.'/../../plugins/nms/includes/functions.php';
function is_device_allowed($id) { return $GLOBALS['listed']; }
function auth_valid_user($id) { return $id===1; }
function read_config_option($name) { return 1; }
function get_simple_device_perms($id) { return false; }
function get_policies($id) { return ['fixture']; }
function get_policy_where($method,$policies,$where) {
    if ($policies!==['fixture']) throw new LogicException('Native policies missing');
    return $where.' AND native_acl_fixture=1';
}
function db_fetch_cell($sql) {
    foreach (["h.id=76", "h.deleted=''", "h.disabled='on'", 'native_acl_fixture=1'] as $clause) {
        if (strpos($sql,$clause)===false) throw new LogicException('Missing authorization clause '.$clause);
    }
    $GLOBALS['queries']++;
    return $GLOBALS['allowed'] ? 1 : 0;
}
$GLOBALS['queries']=0;
foreach ([[true,true,1,true],[false,true,1,true],[false,false,1,false],[false,true,0,false],[false,true,99,false]] as [$listed,$allowed,$user,$expected]) {
    $GLOBALS['listed']=$listed; $GLOBALS['allowed']=$allowed;
    $_SESSION=['sess_user_id'=>$user]; $passed=false;
    try { nms_require_device_access(76); $passed=true; } catch(RuntimeException $e) {}
    if ($passed!==$expected) throw new LogicException('Access regression for user '.$user);
}
if ($GLOBALS['queries']!==2) throw new LogicException('Invalid user reached query or normal access queried fallback');
echo "PASS: listed access, disabled allowed/denied ACL, anonymous and invalid-user denial\n";
