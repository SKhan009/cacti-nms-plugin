<?php
require_once __DIR__.'/networks.php';
require_once __DIR__.'/audit.php';

/** Stable pair ordering keeps opposite-side reviews in one record. */
function nms_identity_review_pair($a,$b)
{
    $a=nms_workspace_integer($a,1,16777215,'first device');
    $b=nms_workspace_integer($b,1,16777215,'second device');
    if($a===$b)throw new InvalidArgumentException('Select two different device records.');
    return [min($a,$b),max($a,$b)];
}

/** Bind a decision to exactly the visible host configuration and identity evidence reviewed. */
function nms_identity_review_hash($a,$b,$hosts,$identities)
{
    [$a,$b]=nms_identity_review_pair($a,$b);$data=[];
    foreach([$a,$b] as $id) {
        if(!isset($hosts[$id],$identities[$id]))throw new RuntimeException('Current identity evidence is unavailable for this pair.');
        $h=$hosts[$id];$data[]=[(int)$id,(string)$h['hostname'],(int)$h['poller_id'],(int)$h['site_id'],$identities[$id]];
    }
    return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
}

/** Read only pairs for which both devices remain visible. */
function nms_identity_reviews()
{
    $rows=db_fetch_assoc("SELECT r.* FROM plugin_nms_identity_reviews r JOIN host a ON a.id=r.host_a JOIN host b ON b.id=r.host_b
        WHERE a.deleted='' AND b.deleted='' AND ".nms_visible_host_sql('a.id').' AND '.nms_visible_host_sql('b.id'));
    $result=[];foreach($rows as $row)$result[$row['host_a'].':'.$row['host_b']]=$row;
    return $result;
}

/** A review is an audited human decision, not a device merge or an admission bypass. */
function nms_identity_review_save($input)
{
    nms_require_management(3);
    [$a,$b]=nms_identity_review_pair($input['host_a']??0,$input['host_b']??0);
    nms_require_device_access($a);nms_require_device_access($b);
    $decision=$input['decision']??null;$note=$input['note']??'';
    if(!is_string($decision) || !in_array($decision,['same','separate','unresolved'],true))throw new InvalidArgumentException('Select a review decision.');
    if(!is_string($note) || strlen($note)>512 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$note))throw new InvalidArgumentException('Review note must be at most 512 bytes of text.');
    $revision=nms_workspace_integer($input['revision']??0,0,2147483647,'review revision');
    $discovery=nms_topology_discovery(null,true);
    $identities=nms_nd_device_identities($discovery['hosts'],$discovery['snapshots']);
    $hash=nms_identity_review_hash($a,$b,$discovery['hosts'],$identities);
    if(!is_string($input['evidence_hash']??null) || !hash_equals($hash,$input['evidence_hash']))throw new RuntimeException('Identity evidence changed. Reload and review both devices again.');
    $lock='nms_identity_review_'.$a.'_'.$b;
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,2)',[$lock])!==1)throw new RuntimeException('This pair is being reviewed. Reload and try again.');
    try {
        db_execute('START TRANSACTION');
        try {
            $old=db_fetch_row_prepared('SELECT * FROM plugin_nms_identity_reviews WHERE host_a=? AND host_b=? FOR UPDATE',[$a,$b]);
            if((int)($old['revision']??0)!==$revision)throw new RuntimeException('Another reviewer changed this decision. Reload before saving.');
            nms_category_execute('INSERT INTO plugin_nms_identity_reviews(host_a,host_b,decision,note,evidence_hash,revision,user_id,updated_at) VALUES (?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE decision=VALUES(decision),note=VALUES(note),evidence_hash=VALUES(evidence_hash),revision=VALUES(revision),user_id=VALUES(user_id),updated_at=NOW()',[$a,$b,$decision,trim($note),$hash,$revision+1,nms_current_user_id()]);
            foreach([$a,$b] as $id)nms_workspace_audit('identity_review',['decision'=>$decision,'revision'=>$revision+1],$id);
            if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit review.');
        }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
    }finally{db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}
