<?php
/** Pure parsing and reconciliation of typed SNMP evidence; no database, DNS or sample fallback. */

/** Require a typed ASN.1 value, retaining octets as bytes rather than guessing printed encodings. */
function tp_value($values,$oid,$type,$required=true) {
    if(!isset($values[$oid])) {
        if(!$required) return null;
        throw new RuntimeException('Required MIB object is absent or excluded by the SNMP view: '.$oid);
    }
    $v=$values[$oid];
    if((int)$v['type']!==$type) throw new RuntimeException('Unexpected ASN.1 type at '.$oid);
    return $v['value'];
}
/** Safely represent arbitrary advertised octets in JSON and HTML-facing evidence. */
function tp_octets($value) {
    if($value===null) return '';
    return preg_match('//u',$value) && !preg_match('/[\x00-\x1f\x7f]/',$value) ? $value : 'hex:'.bin2hex($value);
}
/** Decode one table column, enforcing the exact number of numeric index components. */
function tp_column($values,$root,$column,$parts,$type) {
    $out=array();$prefix=$root.'.'.$column.'.';
    foreach($values as $oid=>$v) if(strpos($oid,$prefix)===0) {
        $index=substr($oid,strlen($prefix));
        if(!preg_match('/^[0-9]+(?:\.[0-9]+){'.($parts-1).'}$/D',$index)) throw new RuntimeException('Malformed MIB table index at '.$oid);
        $out[$index]=tp_value($values,$oid,$type);
    }
    return $out;
}
/** Include every visible row, even when an SNMP view exposes only optional columns. */
function tp_table_indices($values,$root,$parts) {
    $indices=array();$prefix=$root.'.';
    foreach($values as $oid=>$v) if(strpos($oid,$prefix)===0) {
        $suffix=substr($oid,strlen($prefix));
        if(!preg_match('/^[0-9]+\.([0-9]+(?:\.[0-9]+){'.($parts-1).'})$/D',$suffix,$match)) throw new RuntimeException('Malformed table row: '.$oid);
        $indices[$match[1]]=true;
    }
    return array_keys($indices);
}
/** Parse real IF-MIB rows; the LLDP port number is never substituted for ifIndex. */
function tp_interfaces($values) {
    $root='1.3.6.1.2.1.2.2.1';$ports=array();
    foreach(tp_column($values,$root,1,1,2) as $index=>$value) {
        if((string)$index!==(string)$value) throw new RuntimeException('IF-MIB index does not match its row.');
        $name=tp_value($values,'1.3.6.1.2.1.31.1.1.1.1.'.$index,4,false);
        $alias=tp_value($values,'1.3.6.1.2.1.31.1.1.1.18.'.$index,4,false);
        $mac=tp_value($values,$root.'.6.'.$index,4,false);
        $ports[$index]=array('index'=>(int)$index,'name'=>tp_octets($name),'name_hex'=>$name===null?'':bin2hex($name),
            'alias_hex'=>$alias===null?'':bin2hex($alias),'mac_hex'=>$mac===null?'':bin2hex($mac),
            'description'=>tp_octets(tp_value($values,$root.'.2.'.$index,4,false)),
            'admin'=>tp_value($values,$root.'.7.'.$index,2,false),'oper'=>tp_value($values,$root.'.8.'.$index,2,false));
    }
    if(!$ports) throw new RuntimeException('IF-MIB contains no readable ifIndex rows.');
    return $ports;
}
/** Resolve an advertised LLDP port only by its specified identifier subtype and a unique exact match. */
function tp_lldp_ifindex($subtype,$hex,$interfaces) {
    $fields=array(1=>'alias_hex',3=>'mac_hex',5=>'name_hex');
    if(!isset($fields[$subtype]) || $hex==='') return 0;
    $matches=array();
    foreach($interfaces as $i=>$port) if($port[$fields[$subtype]]===$hex) $matches[]=(int)$i;
    return count($matches)===1?$matches[0]:0;
}
/** Require an LLDP subtype and identifier without truncating malformed mandatory identities. */
function tp_lldp_identity($subtype,$id) {
    if(!preg_match('/^[1-7]$/D',(string)$subtype) || $id==='' || strlen($id)>255) throw new RuntimeException('Invalid mandatory LLDP identity.');
    return (int)$subtype.':'.bin2hex($id);
}
/** Parse the IEEE LLDP local and remote tables with their TimeMark/LocalPort/RemoteIndex keys. */
function tp_parse_lldp($values,$interfaces) {
    $local='1.0.8802.1.1.2.1.3';$remote='1.0.8802.1.1.2.1.4.1.1';
    $identity=tp_lldp_identity(tp_value($values,$local.'.1.0',2),tp_value($values,$local.'.2.0',4));
    $ports=array();$neighbors=array();
    foreach(tp_table_indices($values,$local.'.7.1',1) as $number) {
        $id=tp_value($values,$local.'.7.1.3.'.$number,4);
        $sub=(int)tp_value($values,$local.'.7.1.2.'.$number,2);
        $ports[$number]=array('key'=>tp_lldp_identity($sub,$id),'label'=>tp_octets($id),
            'ifindex'=>tp_lldp_ifindex($sub,bin2hex($id),$interfaces));
    }
    // Detect incomplete rows as an error instead of silently dropping their evidence.
    foreach(tp_table_indices($values,$remote,3) as $index) {
        $parts=explode('.',$index);$number=$parts[1];
        if(!isset($ports[$number])) throw new RuntimeException('LLDP neighbor references an unreadable local port.');
        $chassis=tp_value($values,$remote.'.5.'.$index,4);
        $peer=tp_lldp_identity(tp_value($values,$remote.'.4.'.$index,2),$chassis);
        $remotePort=tp_value($values,$remote.'.7.'.$index,4);
        $remoteKey=tp_lldp_identity(tp_value($values,$remote.'.6.'.$index,2),$remotePort);
        $key=hash('sha256',$ports[$number]['key'].'|'.$peer.'|'.$remoteKey);
        $neighbors[$key]=array('key'=>$key,'local_key'=>$ports[$number]['key'],'local_port'=>$ports[$number]['label'],
            'local_ifindex'=>$ports[$number]['ifindex'],'peer_key'=>$peer,'peer_label'=>tp_octets($chassis),
            'remote_key'=>$remoteKey,'remote_port'=>tp_octets($remotePort),
            'remote_name'=>tp_octets(tp_value($values,$remote.'.9.'.$index,4,false)));
    }
    return array('identity'=>$identity,'name'=>tp_octets(tp_value($values,$local.'.3.0',4,false)),
        'ports'=>array_values($ports),'interfaces'=>$interfaces,'neighbors'=>$neighbors);
}
/** Parse Cisco CDP using its advertised global Device-ID and exact interface name identifiers. */
function tp_parse_cdp($values,$interfaces) {
    $root='1.3.6.1.4.1.9.9.23.1';$cache=$root.'.2.1.1';
    if((int)tp_value($values,$root.'.3.1.0',2)!==1) throw new RuntimeException('CDP is disabled on this device.');
    $id=tp_value($values,$root.'.3.4.0',4);
    if($id==='' || strlen($id)>255) throw new RuntimeException('CDP global Device-ID is invalid.');
    $names=tp_column($values,$root.'.1.1.1',6,1,4);$ports=array();$neighbors=array();
    foreach($interfaces as $index=>$port) {
        $ids=array();if($port['name_hex']!=='') $ids[$port['name_hex']]=$port['name'];
        if(isset($names[$index]) && $names[$index]!=='') $ids[bin2hex($names[$index])]=tp_octets($names[$index]);
        foreach($ids as $hex=>$label) $ports[]=array('key'=>$hex,'label'=>$label,'ifindex'=>(int)$index);
    }
    foreach(tp_table_indices($values,$cache,2) as $index) {
        $local=(int)explode('.',$index)[0];$peer=tp_value($values,$cache.'.6.'.$index,4);$port=tp_value($values,$cache.'.7.'.$index,4);
        if($peer==='' || $port==='' || strlen($peer)>255 || strlen($port)>255) throw new RuntimeException('CDP neighbor is missing a valid Device-ID or Port-ID.');
        $key=hash('sha256',$local.'|'.bin2hex($peer).'|'.bin2hex($port));
        $neighbors[$key]=array('key'=>$key,'local_key'=>(string)$local,'local_port'=>'ifIndex '.$local,
            'local_ifindex'=>isset($interfaces[$local])?$local:0,'peer_key'=>bin2hex($peer),'peer_label'=>tp_octets($peer),
            'remote_key'=>bin2hex($port),'remote_port'=>tp_octets($port),
            'remote_name'=>tp_octets(tp_value($values,$cache.'.17.'.$index,4,false)));
    }
    return array('identity'=>bin2hex($id),'name'=>tp_octets($id),'ports'=>$ports,'interfaces'=>$interfaces,'neighbors'=>$neighbors);
}
/** Retain missing observations for seven days, explicitly marking that they were not returned. */
function tp_observation_history($previous,$current,$now) {
    $merged=array();
    foreach($previous as $key=>$old) if(($old['last_seen'] ?? 0)>=$now-604800) { $old['present']=false;$merged[$key]=$old; }
    foreach($current as $key=>$row) { $row['first_seen']=$previous[$key]['first_seen'] ?? $now;$row['last_seen']=$now;$row['present']=true;$merged[$key]=$row; }
    if(count($merged)>2000) throw new RuntimeException('Neighbor history exceeds 2000 observations; collection was not published.');
    return $merged;
}
/** Resolve evidence only within the visible, configured node; preserve parallel ports and ambiguity. */
function tp_evidence_fresh($timestamp,$now,$stale) {
    return $timestamp>0 && $now>=$timestamp && $now-$timestamp<=$stale;
}
/** Reconcile direction and protocol reports by exact native endpoint pairs. */
function tp_reconcile($snapshots,$now,$stale) {
    $identities=array();$links=array();$unresolved=array();
    foreach($snapshots as $key=>$s) if(!empty($s['data']['identity'])) $identities[$s['protocol'].'|'.$s['data']['identity']][]=$key;
    foreach($snapshots as $sourceKey=>$s) foreach(($s['data']['neighbors'] ?? array()) as $observation) {
        if($now-$observation['last_seen']>604800) continue;
        $e=$observation;$e['host_id']=(int)$s['host_id'];$e['protocol']=$s['protocol'];
        $e['current']=$s['valid'] && $s['status']==='success' && $observation['present'] && tp_evidence_fresh($observation['last_seen'],$now,$stale);
        $e['state']=!$s['valid']?'Configuration changed':($s['status']!=='success'?'Collection '.$s['status']:(!$observation['present']?'Missing from latest table':($e['current']?'Current':'Stale')));
        $matches=$identities[$s['protocol'].'|'.$observation['peer_key']] ?? array();
        $reason='';$target=null;$remoteIf=0;
        if(count($matches)!==1) $reason=count($matches)?'Ambiguous advertised device identity':'No unique configured device with this advertised identity';
        else {
            $target=$snapshots[$matches[0]];$ports=array();
            foreach($target['data']['ports'] as $p) if($p['key']===$observation['remote_key'] && $p['ifindex']) $ports[$p['ifindex']]=true;
            if(count($ports)!==1) $reason='Remote port does not map uniquely to IF-MIB';
            else $remoteIf=(int)array_key_first($ports);
            if((int)$target['host_id']===(int)$s['host_id']) $reason='Self-reported device identity requires review';
        }
        if(!$observation['local_ifindex']) $reason='Local port does not map uniquely to IF-MIB';
        if($reason) { $e['reason']=$reason;$unresolved[]=$e;continue; }
        $a=array((int)$s['host_id'],(int)$observation['local_ifindex']);$b=array((int)$target['host_id'],$remoteIf);
        if($a>$b) { $swap=$a;$a=$b;$b=$swap; }
        $key=implode(':',$a).'|'.implode(':',$b);
        if(!isset($links[$key])) $links[$key]=array('a'=>$a,'b'=>$b,'evidence'=>array(),'directions'=>array(),'protocols'=>array(),'last_seen'=>0);
        // Stale identities may locate historical evidence, but cannot confirm a current connection.
        $targetFresh=$target['valid'] && $target['status']==='success' && tp_evidence_fresh($target['data']['collected'] ?? 0,$now,$stale);
        if(!$targetFresh && $e['current']) {$e['current']=false;$e['state']='Peer identity is stale or collection failed';}
        $links[$key]['evidence'][]=$e;
        if($e['current']) $links[$key]['directions'][$s['host_id']]=true;
        $links[$key]['protocols'][$s['protocol']]=true;
        $links[$key]['last_seen']=max($links[$key]['last_seen'],$e['last_seen']);
    }
    foreach($links as &$link) {
        $n=count($link['directions']);$link['state']=$n===2?'Reciprocal':($n===1?'One-sided':'Historical / stale');
        $link['current']=$n>0;
    } unset($link);
    ksort($links);
    return array('links'=>array_values($links),'unresolved'=>$unresolved);
}
