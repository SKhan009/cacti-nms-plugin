<?php
/** Explicit-user native checks bypass cached session realms, including after collector I/O. */
function nms_workspace_authorize_current($host_id=0,$pages=['devices.php'])
{
    global $user_auth_realm_filenames;
    $user=nms_current_user_id();
    if($user<1||db_fetch_cell_prepared('SELECT enabled FROM user_auth WHERE id=?',[$user])!=='on')throw new RuntimeException('Requesting account is disabled or unavailable.');
    if(!is_realm_allowed(3,$user))throw new RuntimeException('Current device-management permission is required.');
    foreach($pages as $page) {
        $realm=$user_auth_realm_filenames[basename($page)]??null;
        if(!$realm||!is_realm_allowed((int)$realm,$user))throw new RuntimeException('Current workspace/page permission is required.');
    }
    if($host_id && !is_device_allowed((int)$host_id,$user))throw new RuntimeException('Current device access is required.');
}
