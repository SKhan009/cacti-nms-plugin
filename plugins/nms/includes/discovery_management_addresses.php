<?php
/** Parse advertised management addresses; these are not reachability or ownership proof. */
function nms_nd_management_address($bytes,$family,$source)
{
    if(!is_string($bytes) || !in_array($family,[4,6],true) || strlen($bytes)!==($family===4?4:16))throw new RuntimeException('Malformed advertised management address.');
    $address=inet_ntop($bytes);
    if($bytes===str_repeat("\0",strlen($bytes)))return null;
    $scoped=$family===6 && ord($bytes[0])===254 && (ord($bytes[1])&192)===128;
    $unicast=$family===4?ord($bytes[0])<224:ord($bytes[0])!==255;
    return ['address'=>$address,'family'=>$family,'source'=>$source,'requires_scope'=>$scoped,'eligible_target'=>$unicast && !$scoped,'verified'=>false];
}

/** LLDP remote management address index: TimeMark, LocalPort, RemIndex, AF, length, octets. */
function nms_nd_lldp_management_addresses($values)
{
    $root='1.0.8802.1.1.2.1.4.2.1.';$rows=[];$errors=[];
    foreach($values as $oid=>$value) {
        if(strpos($oid,$root)!==0)continue;
        try {
            $suffix=substr($oid,strlen($root));
            if(!preg_match('/^[0-9]+(?:\.[0-9]+)+$/D',$suffix))throw new RuntimeException('Malformed LLDP management-address index.');
            $parts=explode('.',$suffix);$family=(int)($parts[4]??0);$length=(int)($parts[5]??0);
            if(count($parts)!==6+$length || $length<1 || $length>31)throw new RuntimeException('Malformed LLDP management-address length.');
            if(!in_array($family,[1,2],true))continue;
            $bytes='';foreach(array_slice($parts,6) as $octet){if(strlen($octet)>3 || (int)$octet>255)throw new RuntimeException('Invalid LLDP address octet.');$bytes.=chr((int)$octet);}
            $address=nms_nd_management_address($bytes,$family===1?4:6,'LLDP management address');
            if($address)$rows[implode('.',array_slice($parts,1,3))][$address['address']]=$address;
        }catch(RuntimeException $e){$errors[$e->getMessage()]=true;}
    }
    foreach($rows as &$row)$row=array_values($row);unset($row);
    return ['rows'=>$rows,'errors'=>array_keys($errors)];
}

/** CDP's ip(1) CiscoNetworkProtocol is explicitly IPv4; other protocols are not guessed. */
function nms_nd_cdp_management_addresses($values,$root,$index)
{
    $addresses=[];$errors=[];
    foreach([[19,20,'CDP primary management address'],[3,4,'CDP advertised network address']] as [$typeColumn,$addressColumn,$source]) {
        try {
            $type=nms_nd_value($values,$root.'.'.$typeColumn.'.'.$index,2,false);
            $bytes=nms_nd_value($values,$root.'.'.$addressColumn.'.'.$index,4,false);
            if($type===null || $bytes===null)continue;
            if((int)$type!==1){$errors[]='Advertised CDP address protocol is not supported for onboarding.';continue;}
            $address=nms_nd_management_address($bytes,4,$source);
            if($address && !isset($addresses[$address['address']]))$addresses[$address['address']]=$address;
        }catch(RuntimeException $e){$errors[]=$e->getMessage();}
    }
    return ['addresses'=>array_values($addresses),'errors'=>array_values(array_unique($errors))];
}
