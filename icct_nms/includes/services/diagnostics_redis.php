<?php
/** Private local Redis transport; no extension or downloaded PHP dependency required. */
function icct_backend_diag_redis(array $arguments) {
    global $config;
    $socket=$config['icct_nms_redis_socket']??'/run/redis/redis.sock';
    if(!is_string($socket)||!str_starts_with($socket,'/')||str_contains($socket,"\0"))return null;
    $stream=@stream_socket_client('unix://'.$socket,$errno,$error,.15);
    if(!$stream)return null;
    try {
        stream_set_timeout($stream,0,200000);
        $packet='*'.count($arguments)."\r\n";
        foreach($arguments as $argument){$argument=(string)$argument;$packet.='$'.strlen($argument)."\r\n".$argument."\r\n";}
        for($offset=0;$offset<strlen($packet);$offset+=$written){$written=@fwrite($stream,substr($packet,$offset));if(!$written)return null;}
        $line=fgets($stream,1024);if($line===false)return null;
        if($line[0]==='+')return substr($line,1,-2);
        if($line[0]===':')return (int)substr($line,1);
        if($line[0]!=='$')return null;
        $length=(int)substr($line,1);if($length<0||$length>1048576)return null;
        $value='';while(strlen($value)<$length+2){$part=fread($stream,$length+2-strlen($value));if($part===false||$part==='')return null;$value.=$part;}
        return substr($value,0,$length);
    } finally {fclose($stream);}
}
function icct_backend_diag_redis_key($suffix) {
    global $config;
    return 'icct_nms:'.substr(hash('sha256',$config['base_path']??ICCT_NMS_ROOT),0,16).':diagnostics:'.$suffix;
}
function icct_backend_diag_redis_enqueue($collector,$job) {
    $key=icct_backend_diag_redis_key('queue:'.(int)$collector);
    icct_backend_diag_redis(['RPUSH',$key,(int)$job]);
    icct_backend_diag_redis(['EXPIRE',$key,120]);
}
function icct_backend_diag_redis_wake($collector) {
    return icct_backend_diag_redis(['LPOP',icct_backend_diag_redis_key('queue:'.(int)$collector)]);
}
function icct_backend_diag_redis_publish($id) {
    $job=db_fetch_row_prepared('SELECT * FROM plugin_icct_nms_diagnostic_jobs WHERE id=?',[(int)$id]);
    if($job)icct_backend_diag_redis(['SET',icct_backend_diag_redis_key('result:'.(int)$id),json_encode($job,JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR),'EX',300]);
}
function icct_backend_diag_redis_result($job) {
    $cached=icct_backend_diag_redis(['GET',icct_backend_diag_redis_key('result:'.(int)$job['id'])]);
    $cached=is_string($cached)?json_decode($cached,true):null;
    // DB ownership/status/configuration is authoritative; Redis cannot grant access or replay another result.
    foreach(['id','host_id','user_id','poller_id','tool','config_hash','status','finished_at'] as $field){
        if(!is_array($cached)||!array_key_exists($field,$cached)||!array_key_exists($field,$job)||(string)$cached[$field] !== (string)$job[$field])return null;
    }
    return is_string($cached['result_json']??null)?$cached['result_json']:null;
}
