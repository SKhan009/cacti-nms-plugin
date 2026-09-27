<?php
require_once __DIR__.'/candidates.php';

/** Internal admission evidence; caller must redact inaccessible matches before rendering. */
function nms_workspace_admission_matches($candidate,$hosts,$snapshots)
{
    $target=nms_workspace_candidate_target($candidate['target']??null);
    if(!$target)throw new InvalidArgumentException('A usable candidate address is required.');
    $scoped=[];
    foreach($hosts as $host) {
        if((int)$host['poller_id']!==(int)$candidate['poller_id'] || (int)$host['site_id']!==(int)$candidate['site_id'] || (string)($host['snmp_context']??'')!==(string)($candidate['snmp_context']??''))continue;
        $scoped[(int)$host['id']]=$host;
    }
    $matches=[];
    foreach($scoped as $id=>$host)if(nms_workspace_candidate_target($host['hostname'])===$target) {
        $matches[$id]=['host_id'=>$id,'state'=>'Existing management address','reason'=>'A Cacti record already uses this address. This does not prove physical identity.'];
    }
    // Compare verified candidate identity with current existing identity, never advertised names/MACs alone.
    $synthetic=['id'=>-1,'hostname'=>$target,'site_id'=>$candidate['site_id'],'poller_id'=>$candidate['poller_id'],'snmp_context'=>$candidate['snmp_context']??''];
    $scoped[-1]=$synthetic;
    $evidence=$snapshots;
    if(!empty($candidate['verified_identity']))$evidence[]=['host_id'=>-1,'protocol'=>'identity','valid'=>true,'status'=>'success','data'=>$candidate['verified_identity'],'succeeded_at'=>$candidate['verified_at']??''];
    $identities=nms_nd_device_identities($scoped,$evidence);
    foreach($identities[-1]['matches'] as $match)$matches[$match['host_id']]=$match;
    // A shared/anycast/uncertain reported address is a review blocker, never an identity proof.
    foreach($snapshots as $snapshot) {
        $id=(int)$snapshot['host_id'];
        if(!isset($scoped[$id]) || empty($snapshot['valid']) || $snapshot['status']!=='success' || $snapshot['protocol']!=='identity')continue;
        foreach($snapshot['data']['own_addresses']??[] as $address)if(nms_workspace_candidate_target($address['address']??null)===$target && !isset($matches[$id])) {
            $matches[$id]=['host_id'=>$id,'state'=>'Reported address overlap','reason'=>'An existing device reports this address; shared or virtual ownership needs review.'];
        }
    }
    return array_values($matches);
}

/** No hidden IDs, names, addresses, counts or identity evidence escape to the user. */
function nms_workspace_admission_visible($matches,$visible_ids)
{
    $visible=array_fill_keys(array_map('intval',$visible_ids),true);$rows=[];$restricted=false;
    foreach($matches as $match) {
        if(isset($visible[(int)$match['host_id']]))$rows[]=$match;
        else $restricted=true;
    }
    return ['blocked'=>(bool)$matches,'matches'=>$rows,'restricted_match'=>$restricted,
        'message'=>$restricted?'An existing record outside your device access requires administrator review.':($matches?'Review existing-device evidence before adding this candidate.':'No existing match was found in the available current evidence. This does not establish uniqueness.')];
}

/** Checked SELECT: Cacti's ordinary association reader may use [] on query failure. */
function nms_workspace_admission_rows($sql,$params=[])
{
    $rows=db_execute_prepared($sql,$params,true,false,'Row',false,'db_fetch_assoc_return');
    if(!is_array($rows))throw new RuntimeException('Existing-device inventory could not be checked. Device addition is unavailable.');
    return $rows;
}

/** Internal collector/onboarding guard. Candidate must be rebuilt from trusted current evidence. */
function nms_workspace_admission_check($candidate,$exclude_host_id=0)
{
    nms_require_management(3);
    nms_require_device_access((int)$candidate['reporter_id']);
    if($exclude_host_id)nms_require_device_access((int)$exclude_host_id);
    require_once __DIR__.'/../discovery.php';
    // Deliberately include disabled and non-discovery-assigned records. Never render these raw rows.
    $hosts=nms_workspace_admission_rows("SELECT h.*,d.collection_enabled,p.enabled,p.stale_seconds,d.methods,p.protocol,d.preset_id,
        p.interval_seconds,p.refresh_seconds FROM host h
        LEFT JOIN plugin_nms_discovery_devices d ON d.host_id=h.id
        LEFT JOIN plugin_nms_discovery_presets p ON p.id=d.preset_id
        WHERE h.deleted='' AND h.poller_id=? AND h.site_id=? AND h.snmp_context=?",
        [(int)$candidate['poller_id'],(int)$candidate['site_id'],(string)($candidate['snmp_context']??'')]);
    $byId=[];$visible=[];
    foreach($hosts as $host){$byId[(int)$host['id']]=$host;if(is_device_allowed((int)$host['id']))$visible[]=(int)$host['id'];}
    $snapshots=[];
    if($hosts) {
        $rows=nms_workspace_admission_rows("SELECT s.* FROM plugin_nms_discovery_snapshots s JOIN host h ON h.id=s.host_id
            WHERE h.deleted='' AND h.poller_id=? AND h.site_id=? AND h.snmp_context=? AND s.protocol IN ('identity','lldp')",
            [(int)$candidate['poller_id'],(int)$candidate['site_id'],(string)($candidate['snmp_context']??'')]);
        foreach($rows as $s) {
            $host=$byId[(int)$s['host_id']]??null;
            if(!$host)throw new RuntimeException('Device inventory changed during the admission check. Retry review.');
            if(empty($host['enabled']) || empty($host['collection_enabled']) || $host['disabled']!=='' || $s['status']!=='success' || !hash_equals((string)$s['config_hash'],nms_nd_hash($host)))continue;
            $s['data']=json_decode($s['data_json'],true,512,JSON_THROW_ON_ERROR);
            if(!nms_nd_evidence_fresh($s['data']['collected']??0,time(),(int)$host['stale_seconds']))continue;
            $s['valid']=true;$snapshots[]=$s;
        }
    }
    $matches=nms_workspace_admission_matches($candidate,$hosts,$snapshots);
    if($exclude_host_id)$matches=array_values(array_filter($matches,static fn($m)=>(int)$m['host_id']!==(int)$exclude_host_id));
    return nms_workspace_admission_visible($matches,$visible);
}
