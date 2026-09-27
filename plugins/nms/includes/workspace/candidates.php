<?php
require_once __DIR__.'/../discovery_identity.php';

/** An advertisement must supply a usable literal unicast address before collector verification. */
function nms_workspace_candidate_target($address)
{
    if(!is_string($address) || !filter_var($address,FILTER_VALIDATE_IP))return null;
    $bytes=inet_pton($address);
    if(strlen($bytes)===4) {
        if(in_array(ord($bytes[0]),[0,127],true) || ord($bytes[0])>=224 || (ord($bytes[0])===169 && ord($bytes[1])===254))return null;
    } else {
        if($bytes===inet_pton('::') || $bytes===inet_pton('::1') || ord($bytes[0])===255 || (ord($bytes[0])===254 && (ord($bytes[1])&192)===128))return null;
        // Mapped IPv4 uses IPv4 target rules as well.
        if(substr($bytes,0,12)===str_repeat("\0",10)."\xff\xff")return nms_workspace_candidate_target(inet_ntop(substr($bytes,12)));
    }
    return inet_ntop($bytes);
}

/** Project only permission-filtered observations into candidates, keeping identity claims provisional. */
function nms_workspace_neighbour_candidates($hosts,$snapshots,$identities,$selected=0,$visible_devices=[])
{
    $candidates=[];
    // Discovery assignments control reporters, not membership in native inventory.
    $match_hosts=$hosts;
    foreach($visible_devices as $device)$match_hosts[(int)$device['id']]=$hosts[(int)$device['id']]??$device;
    foreach($snapshots as $snapshot) {
        $reporter=(int)$snapshot['host_id'];$protocol=$snapshot['protocol'];
        if(!isset($hosts[$reporter]) || ($selected && $selected!==$reporter) || !in_array($protocol,['lldp','cdp'],true))continue;
        $source=$hosts[$reporter];
        foreach($snapshot['data']['neighbors']??[] as $key=>$neighbor) {
            $current=!empty($snapshot['valid']) && $snapshot['status']==='success' && ($neighbor['present']??true);
            foreach($neighbor['management_addresses']??[] as $advertisement) {
                $target=nms_workspace_candidate_target($advertisement['address']??null);
                $matches=[];
                if($target)foreach($match_hosts as $id=>$host) {
                    if((int)$host['poller_id']!==(int)$source['poller_id'] || (int)$host['site_id']!==(int)$source['site_id'])continue;
                    $reason=null;
                    if(nms_workspace_candidate_target($host['hostname'])===$target)$reason='Configured management address matches';
                    foreach($identities[$id]['addresses']??[] as $owned)if(nms_nd_address_matchable($owned) && nms_workspace_candidate_target($owned['address'])===$target)$reason='Current reported device address matches';
                    if($reason)$matches[]=['host_id'=>(int)$id,'reason'=>$reason];
                }
                $state=!$current?'Stale or unavailable observation':(!$target?'Address requires review':($matches?'Possible existing device':'Unverified onboarding candidate'));
                $id=hash('sha256',json_encode([$reporter,$protocol,(string)$key,$advertisement['address']??'']));
                $candidates[$id]=['id'=>$id,'reporter_id'=>$reporter,'poller_id'=>(int)$source['poller_id'],'site_id'=>(int)$source['site_id'],
                    'snmp_context'=>(string)($source['snmp_context']??''),'protocol'=>$protocol,'target'=>$target,'advertised_address'=>(string)($advertisement['address']??''),'state'=>$state,'eligible'=>$current && $target!==null,
                    'identity'=>(string)($neighbor['peer_key']??''),'name'=>(string)($neighbor['remote_name']??$neighbor['peer_label']??''),
                    'local_port'=>(string)($neighbor['local_port']??''),'remote_port'=>(string)($neighbor['remote_port']??''),
                    'observed_at'=>(string)($snapshot['succeeded_at']??''),'matches'=>$matches,'confidence'=>'Advertised only; reachability and physical identity are unverified'];
            }
        }
    }
    return array_values($candidates);
}
