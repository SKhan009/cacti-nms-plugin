<?php
require_once __DIR__.'/../diagnostics.php';

/** Configuration describes criteria only; the target always comes from the authorized Cacti host. */
function nms_workspace_service_spec($input)
{
    $kind=$input['kind']??'';
    if(!in_array($kind,['http','https','dns','tcp'],true))throw new InvalidArgumentException('Choose HTTP, HTTPS, DNS or TCP connection.');
    $port=filter_var($input['port']??(['http'=>80,'https'=>443,'dns'=>53,'tcp'=>0][$kind]),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
    $timeout=filter_var($input['timeout']??5,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>10]]);
    if($port===false || $timeout===false)throw new InvalidArgumentException('Use a port from 1 to 65535 and timeout from 1 to 10 seconds.');
    $spec=['kind'=>$kind,'port'=>$port,'timeout'=>$timeout];
    if(in_array($kind,['http','https'],true)) {
        $path=$input['path']??'/';$status=filter_var($input['expected_status']??200,FILTER_VALIDATE_INT,['options'=>['min_range'=>100,'max_range'=>599]]);
        $contains=$input['contains']??'';
        if(!is_string($path)||strlen($path)>1024||!preg_match('~^/(?!/)[^\x00-\x20\x7f?#]*$~D',$path)||$status===false)throw new InvalidArgumentException('Use an absolute URL path without query parameters, fragment or credentials, and an expected HTTP status.');
        if(!is_string($contains)||strlen($contains)>256||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$contains))throw new InvalidArgumentException('Expected response text must be at most 256 bytes.');
        $spec+=['path'=>$path,'expected_status'=>$status,'contains'=>$contains];
    } elseif($kind==='dns') {
        $name=strtolower(rtrim((string)($input['name']??''),'.'));$type=$input['record_type']??'A';$expected=$input['expected_address']??'';
        if(strlen($name)>253||!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D',$name)||!in_array($type,['A','AAAA'],true))throw new InvalidArgumentException('Use a valid DNS query name and A or AAAA record type.');
        $flag=$type==='A'?FILTER_FLAG_IPV4:FILTER_FLAG_IPV6;
        if(!is_string($expected)||!filter_var($expected,FILTER_VALIDATE_IP,$flag))throw new InvalidArgumentException('Specify the expected address for the selected DNS record type.');
        $spec+=['name'=>$name,'record_type'=>$type,'expected_address'=>inet_ntop(inet_pton($expected))];
    }
    return $spec;
}

function nms_workspace_service_target($host)
{
    $target=$host['hostname']??'';
    if(!is_string($target)||strlen($target)>253||$target==='')throw new InvalidArgumentException('Device has no usable service target.');
    if(filter_var($target,FILTER_VALIDATE_IP))return inet_ntop(inet_pton($target));
    if(!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\.?$/iD',$target))throw new InvalidArgumentException('Device hostname must be an IP address or DNS hostname.');
    return strtolower($target);
}

/** Never persist response bodies, request credentials or raw transport errors. */
function nms_workspace_service_http($target,$spec)
{
    if(!function_exists('curl_init'))return ['passed'=>false,'category'=>'unsupported','message'=>'PHP cURL is unavailable on this collector.'];
    $tcp=$spec['kind']==='tcp';$scheme=$tcp?'http':$spec['kind'];$authority=strpos($target,':')!==false?'['.$target.']':$target;
    $curl=curl_init();$body='';$tooLarge=false;
    curl_setopt_array($curl,[CURLOPT_URL=>$scheme.'://'.$authority.':'.$spec['port'].($tcp?'/':$spec['path']),
        CURLOPT_CONNECTTIMEOUT_MS=>$spec['timeout']*1000,CURLOPT_TIMEOUT_MS=>$spec['timeout']*1000,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROXY=>'',CURLOPT_NETRC=>CURL_NETRC_IGNORED,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECT_ONLY=>$tcp,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'Cacti-NMS-Service-Check',
        CURLOPT_WRITEFUNCTION=>function($handle,$data)use(&$body,&$tooLarge){if(strlen($body)+strlen($data)>65536){$tooLarge=true;return 0;}$body.=$data;return strlen($data);}]);
    try {
        $ok=curl_exec($curl);$errno=curl_errno($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        $result=['passed'=>false,'category'=>'transport_error','message'=>'The collector could not complete the connection.',
            'elapsed_ms'=>round((float)curl_getinfo($curl,CURLINFO_TOTAL_TIME)*1000,3),'http_status'=>$tcp?null:$status,'tls_verified'=>$spec['kind']==='https' && $ok!==false];
        if($tooLarge)return array_replace($result,['category'=>'response_limit','message'=>'Response exceeded the 64 KiB check limit; criteria were not accepted.']);
        if($ok===false) {
            $errors=[6=>['name_resolution','Collector could not resolve the device hostname.'],7=>['connection_failed','Connection failed; the service or network path may be unavailable.'],28=>['timeout','The service check exceeded its timeout.'],60=>['tls_validation','HTTPS certificate validation failed.'],51=>['tls_validation','HTTPS certificate identity validation failed.'],35=>['tls_handshake','TLS negotiation failed.'],77=>['tls_configuration','Collector certificate trust configuration is unavailable.']];
            if(isset($errors[$errno]))[$result['category'],$result['message']]=$errors[$errno];
            return $result;
        }
        if($tcp)return array_replace($result,['passed'=>true,'category'=>'connected','message'=>'TCP connection succeeded. This does not prove that the application is healthy.']);
        $match=$spec['contains']===''||strpos($body,$spec['contains'])!==false;
        $pass=$status===$spec['expected_status']&&$match;
        return array_replace($result,['passed'=>$pass,'category'=>$pass?'criteria_met':(in_array($status,[401,403],true)?'access_denied':'criteria_mismatch'),
            'message'=>$pass?'HTTP response matched the configured status and text criteria.':'HTTP response did not match the configured criteria.',
            'expected_status'=>$spec['expected_status'],'text_matched'=>$match,'bytes_checked'=>strlen($body)]);
    }finally{curl_close($curl);}
}

/** Parse dig's bounded answer data. A successful DNS transaction alone does not meet the expected-address criterion. */
function nms_workspace_service_dns_result($output,$spec)
{
    if(!preg_match('/;; ->>HEADER<<-.*status: ([A-Z0-9]+),/',$output,$m))return ['passed'=>false,'category'=>'dns_transport','message'=>'No valid DNS response was received.'];
    $status=$m[1];$answers=[];$owners=[$spec['name']=>true];$records=[];
    foreach(explode("\n",$output) as $line) {
        if(!preg_match('/^([a-z0-9_.-]+)\s+\d+\s+IN\s+(A|AAAA|CNAME)\s+(\S+)\s*$/iD',trim($line),$r))continue;
        $records[]=[strtolower(rtrim($r[1],'.')),strtoupper($r[2]),strtolower(rtrim($r[3],'.'))];
    }
    // A bounded closure admits addresses only for the queried owner or its returned CNAME chain.
    for($i=0;$i<16;$i++){ $changed=false;foreach($records as [$owner,$type,$value])if(isset($owners[$owner])&&$type==='CNAME'&&!isset($owners[$value])){$owners[$value]=true;$changed=true;}if(!$changed)break; }
    foreach($records as [$owner,$type,$value])if(isset($owners[$owner])&&$type===$spec['record_type']&&filter_var($value,FILTER_VALIDATE_IP))$answers[]=inet_ntop(inet_pton($value));
    $answers=array_values(array_unique($answers));$pass=$status==='NOERROR'&&in_array($spec['expected_address'],$answers,true);
    return ['passed'=>$pass,'category'=>$pass?'criteria_met':($status==='NOERROR'?'criteria_mismatch':'dns_response_error'),
        'message'=>$pass?'DNS answer contains the expected address.':'DNS response did not satisfy the expected-address criterion.','rcode'=>$status,'answers'=>array_slice($answers,0,64)];
}

/** Invoked only by collector jobs; the outer worker also bounds process lifetime. */
function nms_workspace_service_probe($host,$input)
{
    if(PHP_SAPI!=='cli')throw new RuntimeException('Service checks run only on the collector.');
    $spec=nms_workspace_service_spec($input);$target=nms_workspace_service_target($host);$started=microtime(true);
    if($spec['kind']!=='dns')$result=nms_workspace_service_http($target,$spec);
    else {
        $binary=null;foreach(['/usr/bin/dig','/bin/dig'] as $path)if(is_executable($path)){$binary=$path;break;}
        if(!$binary)$result=['passed'=>false,'category'=>'unsupported','message'=>'Install the DNS dig tool on this collector.'];
        else {
            $run=nms_diag_run_command([$binary,'@'.$target,'-p',(string)$spec['port'],$spec['name'],$spec['record_type'],'+time='.$spec['timeout'],'+tries=1','+noall','+comments','+answer'],min(12,$spec['timeout']+1));
            $result=$run['timed_out']?['passed'=>false,'category'=>'timeout','message'=>'DNS check exceeded its timeout.']:nms_workspace_service_dns_result($run['stdout'],$spec);
        }
    }
    return $result+['kind'=>$spec['kind'],'target'=>$target,'port'=>$spec['port'],'checked_at'=>date('Y-m-d H:i:s'),'elapsed_ms'=>round((microtime(true)-$started)*1000,3)];
}
