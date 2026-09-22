<?php
/** Strict static SNMPSim record parser. Executable variation modules are not accepted. */

/** Validate an ASN.1 numeric OID including arc bounds, length and canonical syntax. */
function tp_oid($oid) {
    if(!is_string($oid) || !preg_match('/^(0|1|2)(?:\.(?:0|[1-9][0-9]*))+$/D',$oid)) throw new InvalidArgumentException('Invalid numeric OID.');
    $arcs=explode('.',$oid);
    if(count($arcs)>128 || ($arcs[0]!=='2' && (float)$arcs[1]>39)) throw new InvalidArgumentException('Invalid OID arc range.');
    foreach($arcs as $arc) if(strlen($arc)>10 || (float)$arc>4294967295) throw new InvalidArgumentException('OID arc exceeds 32 bits.');
    return $oid;
}
/** Validate a nonnegative integer without rounding Counter64 values. */
function tp_unsigned($value,$maximum) {
    return preg_match('/^(0|[1-9][0-9]*)$/D',$value) && (strlen($value)<strlen($maximum) || (strlen($value)===strlen($maximum) && strcmp($value,$maximum)<=0));
}
/** Parse bounded static records and identify numeric metrics separately from topology identifiers. */
function tp_records($content) {
    if(!is_string($content) || strlen($content)>2097152 || trim($content)==='') throw new InvalidArgumentException('Upload a nonempty SNMP record of at most 2 MB.');
    $lines=preg_split('/\r\n|\r|\n/',$content);
    if(count($lines)>5000) throw new InvalidArgumentException('A record may contain at most 5000 lines.');
    $records=array(); $label='SNMP reading'; $metrics=0;
    foreach($lines as $n=>$line) {
        $line=trim($line);
        if($line==='') continue;
        if($line[0]==='#') { $label=trim(substr($line,1)); if(strlen($label)>100) throw new InvalidArgumentException('Record labels must be at most 100 bytes.'); continue; }
        $parts=explode('|',$line,3);
        if(count($parts)!==3) throw new InvalidArgumentException('Invalid record on line '.($n+1).'.');
        list($oid,$tag,$value)=$parts; tp_oid($oid);
        if(isset($records[$oid])) throw new InvalidArgumentException('Duplicate OID on line '.($n+1).'.');
        if(strlen($value)>1024 || preg_match('/[\x00-\x1f\x7f]/',$value)) throw new InvalidArgumentException('Invalid record value on line '.($n+1).'.');
        if(!in_array($tag,array('2','4','4x','6','64','65','66','67','70'),true)) throw new InvalidArgumentException('Unsupported static SNMP tag on line '.($n+1).'. Variation modules are not allowed.');
        if($tag==='2' && (!preg_match('/^-?(0|[1-9][0-9]*)$/D',$value) || (float)$value < -2147483648 || (float)$value>2147483647)) throw new InvalidArgumentException('Integer32 is out of range.');
        if(in_array($tag,array('65','66','67','70'),true) && !tp_unsigned($value,$tag==='70'?'18446744073709551615':'4294967295')) throw new InvalidArgumentException('Unsigned SNMP value is out of range.');
        if($tag==='4x' && !preg_match('/^(?:[0-9A-Fa-f]{2})*$/D',$value)) throw new InvalidArgumentException('Invalid hexadecimal octet string.');
        if($tag==='4' && !preg_match('//u',$value)) throw new InvalidArgumentException('Use hexadecimal tag 4x for binary octet strings.');
        if($tag==='6') tp_oid($value);
        if($tag==='64' && !filter_var($value,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) throw new InvalidArgumentException('Invalid IPv4 SNMP value.');
        // Neighbor tables contain identifiers/capabilities, not performance samples.
        $neighbor=strpos($oid,'1.0.8802.1.1.2.')===0 || strpos($oid,'1.3.6.1.4.1.9.9.23.')===0;
        $metric=in_array($tag,array('2','65','66','67','70'),true) && !$neighbor;
        if($metric) $metrics++;
        $records[$oid]=array('oid'=>$oid,'tag'=>$tag,'value'=>$value,'label'=>$label,'metric'=>$metric);
    }
    if(!$records || $metrics>64) throw new InvalidArgumentException('Supply records with at most 64 numeric metrics.');
    if(!isset($records['1.3.6.1.2.1.1.5.0']) || $records['1.3.6.1.2.1.1.5.0']['tag']!=='4' || $records['1.3.6.1.2.1.1.5.0']['value']==='') throw new InvalidArgumentException('A text sysName.0 (1.3.6.1.2.1.1.5.0) is required for live identity verification.');
    if(!isset($records['1.3.6.1.2.1.1.3.0']) || $records['1.3.6.1.2.1.1.3.0']['tag']!=='67') throw new InvalidArgumentException('sysUpTime.0 with TimeTicks tag 67 is required for native SNMP availability.');
    return $records;
}
/** Sort canonical numeric OIDs as SNMPSim expects, preserving safe labels and static values. */
function tp_record_content($records) {
    uksort($records,function($a,$b) {
        $aa=explode('.',$a);$bb=explode('.',$b);
        foreach($aa as $i=>$arc) { if(!isset($bb[$i]))return 1; if((float)$arc!==(float)$bb[$i])return (float)$arc <=> (float)$bb[$i]; }
        return count($aa)<=>count($bb);
    });
    $text='';
    foreach($records as $r) $text.='# '.$r['label']."\n".$r['oid'].'|'.$r['tag'].'|'.$r['value']."\n";
    return $text;
}
/** Validate a lab community which also serves as a safe record filename. */
function tp_community($value) {
    if(!is_string($value) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D',$value)) throw new InvalidArgumentException('Lab community must contain 1–64 letters, digits, underscores or hyphens.');
    return $value;
}

/** Accept only an actual bounded PHP multipart upload; shared by the controller and HTTP QA. */
function tp_uploaded_record($file){
    if(!is_array($file)||($file['error']??null)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??'')||($file['size']??0)>2097152||strtolower(pathinfo($file['name']??'',PATHINFO_EXTENSION))!=='snmprec')throw new InvalidArgumentException('Select a valid .snmprec upload, at most 2 MB.');
    $content=file_get_contents($file['tmp_name']);tp_records($content);return $content;
}
