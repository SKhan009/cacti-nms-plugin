<?php
/** Bounded address planning; never enumerate large IPv6 subnets. */

/** Increment a packed address without integer-size assumptions. */
function nms_scan_increment($packed)
{
    for ($i=strlen($packed)-1;$i>=0;$i--) {
        $byte=ord($packed[$i]);$packed[$i]=chr(($byte+1)&255);
        if($byte<255)return $packed;
    }
    return null;
}

/** Expand a small IPv6 CIDR or inclusive range, rejecting oversized ranges before allocation. */
function nms_scan_ipv6_range($range, $limit)
{
    if(strpos($range,'%')!==false)throw new InvalidArgumentException('Scoped IPv6 targets require an interface and are not supported by this scan.');
    if(strpos($range,'/')!==false) {
        $parts=explode('/',$range);
        if(count($parts)!==2 || !filter_var($parts[0],FILTER_VALIDATE_IP,FILTER_FLAG_IPV6) || !preg_match('/^[0-9]{1,3}$/D',$parts[1]))throw new InvalidArgumentException('Invalid IPv6 CIDR.');
        $prefix=(int)$parts[1];
        if($prefix<116 || $prefix>128)throw new InvalidArgumentException('IPv6 scans accept /116 through /128 only (at most 4096 addresses); supply explicit targets for larger networks.');
        $count=1 << (128-$prefix);
        if($count>$limit)throw new InvalidArgumentException('Combined scan exceeds 4096 addresses.');
        $packed=inet_pton($parts[0]);
        for($i=0;$i<16;$i++){$bits=max(0,min(8,$prefix-$i*8));$packed[$i]=chr(ord($packed[$i]) & (255 << (8-$bits)));}
        $targets=[];for($i=0;$i<$count;$i++){$targets[]=inet_ntop($packed);$packed=nms_scan_increment($packed);}
        return $targets;
    }
    $parts=explode('-',$range);
    if(count($parts)!==2 || !filter_var(trim($parts[0]),FILTER_VALIDATE_IP,FILTER_FLAG_IPV6) || !filter_var(trim($parts[1]),FILTER_VALIDATE_IP,FILTER_FLAG_IPV6))throw new InvalidArgumentException('Invalid IPv6 target or range.');
    $start=inet_pton(trim($parts[0]));$end=inet_pton(trim($parts[1]));
    if(strcmp($start,$end)>0)throw new InvalidArgumentException('IPv6 range end precedes its start.');
    $targets=[];
    while(strcmp($start,$end)<=0) {
        if(count($targets)>=$limit)throw new InvalidArgumentException('Combined scan exceeds 4096 addresses.');
        $targets[]=inet_ntop($start);$start=nms_scan_increment($start);if($start===null)break;
    }
    return $targets;
}

/** Inclusive full-address IPv4 ranges, bounded before returning any scan work. */
function nms_scan_ipv4_range($range,$limit)
{
    $parts=array_map('trim',explode('-',$range));
    if(count($parts)!==2 || !filter_var($parts[0],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4) || !filter_var($parts[1],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))throw new InvalidArgumentException('Invalid IPv4 address range.');
    $start=inet_pton($parts[0]);$end=inet_pton($parts[1]);
    if(strcmp($start,$end)>0)throw new InvalidArgumentException('IPv4 range end precedes its start.');
    $targets=[];
    while(strcmp($start,$end)<=0) {
        if(count($targets)>=$limit)throw new InvalidArgumentException('Combined scan exceeds 4096 addresses.');
        $targets[]=inet_ntop($start);$start=nms_scan_increment($start);if($start===null)break;
    }
    return $targets;
}

/** Plan unique canonical targets; IPv4 native syntax is delegated to Cacti's bounded adapter. */
function nms_scan_targets($text, $ipv4_expand)
{
    if(!is_string($text) || trim($text)==='' || strlen($text)>16384 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$text))throw new InvalidArgumentException('Enter bounded IP targets or ranges.');
    $targets=[];$ranges=preg_split('/[,\r\n]+/',trim($text));
    if(count($ranges)>4096)throw new InvalidArgumentException('Too many target ranges.');
    foreach($ranges as $range) {
        $range=trim($range);if($range==='')continue;
        if(filter_var($range,FILTER_VALIDATE_IP))$expanded=[$range];
        elseif(strpos($range,':')!==false)$expanded=nms_scan_ipv6_range($range,4096);
        elseif(preg_match('/^([0-9.]+)\s*-\s*([0-9.]+)$/D',$range,$endpoints) && strpos($endpoints[2],'.')!==false)$expanded=nms_scan_ipv4_range($range,4096);
        else $expanded=$ipv4_expand($range,4096);
        foreach($expanded as $ip) {
            $packed=@inet_pton($ip);
            if($packed===false)throw new InvalidArgumentException('Range produced an invalid address.');
            if((strlen($packed)===16 && (ord($packed[0])===255 || (ord($packed[0])===254 && (ord($packed[1])&192)===128))) || (strlen($packed)===4 && ord($packed[0])>=224))throw new InvalidArgumentException('Multicast, broadcast and unscoped link-local IPv6 targets cannot be scanned.');
            $ip=inet_ntop($packed);$targets[$ip]=$ip;
            if(count($targets)>4096)throw new InvalidArgumentException('Combined scan exceeds 4096 addresses.');
        }
    }
    if(!$targets)throw new InvalidArgumentException('No valid scan targets.');
    return array_values($targets);
}

/** Use core IPv4 rules, checking the target count before iterating. */
function nms_scan_native_ipv4($range,$limit)
{
    global $config;
    require_once $config['base_path'].'/lib/api_automation.php';
    $total=automation_calculate_total_ips($range);$start=automation_calculate_start($range);
    if(!$total || $total>$limit || !$start)throw new InvalidArgumentException('Invalid IPv4 range or more than 4096 targets.');
    $targets=[];
    for($i=0;$i<$total;$i++)$targets[]=$i===0?$start:automation_get_next_host($start,$total,$i,$range);
    return $targets;
}

/** A cursor maps directly to one task without building the full target × method product. */
function nms_scan_task($targets,$methods,$ports,$cursor)
{
    $slots=[];
    foreach($methods as $method)foreach(in_array($method,['tcp','udp'],true)?$ports:[null] as $port)$slots[]=[$method,$port];
    if(!$slots || $cursor<0 || $cursor>=count($targets)*count($slots))return null;
    [$method,$port]=$slots[$cursor % count($slots)];
    return ['ip'=>$targets[intdiv($cursor,count($slots))],'method'=>$method,'port'=>$port];
}
