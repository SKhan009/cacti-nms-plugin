<?php
/** Parsed uploads remain session-local until the user explicitly chooses Save/Proceed. */
function icct_mib_upload_session_token($token){
    if(!is_string($token)||!preg_match('/^[a-f0-9]{32}$/D',$token))throw new InvalidArgumentException('Invalid pending upload.');
    return $token;
}
function icct_mib_upload_session_store($token,$draft){
    icct_backend_require_management(3);$token=icct_mib_upload_session_token($token);
    if(($draft['owner']??0)!==icct_backend_current_user_id())throw new InvalidArgumentException('Upload access denied.');
    foreach($_SESSION['icct_mib_uploads']??[] as $key=>$entry)if(time()-($entry['created']??0)>3600)unset($_SESSION['icct_mib_uploads'][$key]);
    if(count(array_filter($_SESSION['icct_mib_uploads']??[],fn($entry)=>isset($entry['draft'])))>=4)throw new InvalidArgumentException('Close another pending upload before reading more files.');
    $_SESSION['icct_mib_uploads'][$token]=['created'=>time(),'owner'=>icct_backend_current_user_id(),'draft'=>$draft];
}
function icct_mib_upload_session_commit($token,$automatic,$repositoryAcknowledgment=''){
    icct_backend_require_management(3);$token=icct_mib_upload_session_token($token);
    $entry=$_SESSION['icct_mib_uploads'][$token]??null;
    if(!$entry||($entry['owner']??0)!==icct_backend_current_user_id()||time()-$entry['created']>3600)throw new InvalidArgumentException('The pending upload expired or was cancelled. Read the file again.');
    if(!empty($entry['saved_id']))return icct_mib_wizard_get($entry['saved_id']);
    $draft=$entry['draft'];
    if($automatic&&!icct_mib_upload_summary($draft)['can_configure'])throw new InvalidArgumentException('Automatic configuration is unavailable. Choose Repository Only.');
    $stored=icct_mib_save($draft,icct_mib_plan($draft,['type_id'=>$draft['type_id'],'template_name'=>icct_mib_default_name($draft)]),$repositoryAcknowledgment);
    // Keep a small receipt so retries never duplicate an explicitly saved upload.
    unset($_SESSION['icct_mib_uploads'][$token]['draft']);$_SESSION['icct_mib_uploads'][$token]['saved_id']=$stored['id'];
    return $stored;
}
function icct_mib_upload_session_discard($token){
    icct_backend_require_management(3);$token=icct_mib_upload_session_token($token);
    unset($_SESSION['icct_mib_uploads'][$token]);
}
