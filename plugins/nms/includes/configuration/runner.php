<?php
/** Collector-only transport bridge; the web process never opens equipment ports. */
require_once __DIR__.'/monitoring.php';

function nms_config_serial_execute(array $target,array $field,$operation,$before=null,$requested=null)
{
    global $config;
    $serial=$target['serial'];
    $job=['connection'=>['transport'=>$serial['transport'],'endpoint'=>$serial['endpoint'],'settings'=>$serial['settings']],
        'unit'=>(int)$serial['device_address'],'offset'=>$field['offset'],'function'=>$field['function'],'operation'=>$operation,'count'=>1];
    if($operation==='write') { $job['expected']=((int)$before)&65535; $job['value']=((int)$requested)&65535; }
    $input=json_encode($job,JSON_THROW_ON_ERROR);
    if(strlen($input)>4096) throw new RuntimeException('Serial request is too large.');
    $python=$config['nms_serial_python'] ?? '/usr/bin/python3';
    $locks=$config['nms_serial_lock_dir'] ?? '/run/cacti-nms-serial';
    if(!is_dir($locks) || !is_writable($locks)) throw new RuntimeException('Collector serial lock directory is not provisioned.');
    $process=proc_open([$python,dirname(__DIR__,2).'/collector/serial_transport.py',$locks],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
    if(!is_resource($process)) throw new RuntimeException('Cannot start serial adapter.');
    $stdout=''; $stderr=''; $timeout=false;
    $deadline=microtime(true)+60;
    try {
        fwrite($pipes[0],$input); fclose($pipes[0]);
        stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false);
        do {
            $stdout.=stream_get_contents($pipes[1]); $stderr.=stream_get_contents($pipes[2]);
            $state=proc_get_status($process);
            if(strlen($stdout)>16384 || strlen($stderr)>16384) throw new RuntimeException('Serial adapter output exceeded limit.');
            if(!$state['running']) break;
            if(microtime(true)>$deadline) { $timeout=true; proc_terminate($process,9); break; }
            usleep(20000);
        } while(true);
        $stdout.=stream_get_contents($pipes[1]);
    } finally {
        if(proc_get_status($process)['running']) proc_terminate($process,9);
        foreach($pipes as $pipe) if(is_resource($pipe)) fclose($pipe);
        proc_close($process);
    }
    if($timeout) return ['status'=>$operation==='write'?'unverified':'failed','error'=>'Collector execution deadline exceeded. Writes are not replayed.'];
    try { $result=json_decode($stdout,true,32,JSON_THROW_ON_ERROR); }
    catch(Throwable $e) { return ['status'=>$operation==='write'?'unverified':'failed','error'=>'Serial adapter failed to return a valid result. Check collector installation.']; }
    if(!is_array($result) || !isset($result['status'])) throw new RuntimeException('Invalid adapter response.');
    if($operation==='read' && $result['status']==='read') $result['value']=$result['values'][0];
    foreach(['value','before','observed'] as $key) if(isset($result[$key]) && $field['type']==='int16' && $result[$key]>32767) $result[$key]-=65536;
    if(isset($result['value'])) $result['value']=nms_equipment_value($field,$result['value']);
    return $result;
}

/** SNMP credentials are read at execution, never stored in job payloads or arguments. */
function nms_config_snmp_session(array $target, $write=false)
{
    global $config;
    if(!class_exists('SNMP')) throw new RuntimeException('Install the collector PHP SNMP extension.');
    $host=$target['host'];
    $credentials=$host;
    if($write) {
        $ref=$target['assignment']['credential_ref'];
        $credentials=$config['nms_configuration_credentials'][$ref] ?? null;
        if(!is_array($credentials)) throw new RuntimeException('Write credential reference is not configured on this collector.');
        // Restrict credential use to explicit devices, not every user who guesses its name.
        if(!in_array((int)$host['id'],array_map('intval',$credentials['host_ids'] ?? []),true)) throw new RuntimeException('Write credential is not authorised for this device.');
    }
    $version=(int)($credentials['snmp_version'] ?? $host['snmp_version']);
    if(!in_array($version,[1,2,3],true)) throw new RuntimeException('Unsupported SNMP version.');
    $endpoint=filter_var($host['hostname'],FILTER_VALIDATE_IP,FILTER_FLAG_IPV6) ? '['.$host['hostname'].']' : $host['hostname'];
    $endpoint.=':'.(int)$host['snmp_port'];
    $session=new SNMP([1=>SNMP::VERSION_1,2=>SNMP::VERSION_2c,3=>SNMP::VERSION_3][$version],$endpoint,
        $version===3?($credentials['snmp_username'] ?? ''):($credentials['snmp_community'] ?? ''),min(10000000,max(100000,(int)$host['snmp_timeout']*1000)),0);
    $session->exceptions_enabled=SNMP::ERRNO_ANY;
    $session->valueretrieval=SNMP_VALUE_PLAIN;
    if($version===3) {
        $auth=$credentials['snmp_auth_protocol'] ?? '[None]'; $priv=$credentials['snmp_priv_protocol'] ?? '[None]';
        $session->setSecurity($auth==='[None]'?'noAuthNoPriv':($priv==='[None]'?'authNoPriv':'authPriv'),
            $auth==='[None]'?'':$auth,$credentials['snmp_password'] ?? '',$priv==='[None]'?'':$priv,$credentials['snmp_priv_passphrase'] ?? '',$host['snmp_context']);
    }
    return $session;
}

function nms_config_snmp_execute(array $target,array $field,$operation,$before=null,$requested=null)
{
    $session=null;
    try {
        $session=nms_config_snmp_session($target,$operation==='write');
        $current=nms_equipment_value($field,$session->get($field['oid']));
        if($operation==='read') return ['status'=>'read','value'=>$current,'device_responded'=>true];
        if($current!==$before) return ['status'=>'failed','before'=>$current,'error'=>'Value changed since preview; no write sent.'];
        $ack=false;
        try { $ack=$session->set($field['oid'],['integer'=>'i','unsigned'=>'u','string'=>'s'][$field['type']],$requested); }
        catch(Throwable $e) { /* Ambiguous result: read back without retrying SET. */ }
        try { $observed=nms_equipment_value($field,$session->get($field['oid'])); }
        catch(Throwable $e) { return ['status'=>'unverified','before'=>$before,'acknowledged'=>$ack,'error'=>'SNMP read-back failed. Do not assume the setting is unchanged.']; }
        return ['status'=>$observed===$requested?'verified':'unverified','before'=>$before,'observed'=>$observed,'acknowledged'=>$ack];
    } catch(Throwable $e) {
        // SNMP library exceptions may contain connection details; do not persist them.
        return ['status'=>'failed','error'=>'SNMP access failed. Check collector credentials, device permissions and the field definition.'];
    } finally { if($session) $session->close(); }
}

/** Process one job. Running writes survive crashes as Unverified, never requeued. */
function nms_config_worker_once($collector)
{
    if(PHP_SAPI!=='cli') throw new RuntimeException('Collector execution is CLI-only.');
    $lock='nms_config_worker_'.(int)$collector;
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,0)',[$lock])!==1) return;
    try {
        nms_category_execute("UPDATE plugin_nms_config_jobs SET status=IF(operation='write','unverified','failed'),finished_at=NOW(),result_json=? WHERE poller_id=? AND status='running'",[json_encode(['error'=>'Previous worker stopped; request was not replayed.']),$collector]);
        nms_category_execute("UPDATE plugin_nms_config_jobs SET status='failed',finished_at=NOW(),result_json=? WHERE poller_id=? AND status='queued' AND requested_at < DATE_SUB(NOW(),INTERVAL 5 MINUTE)",[json_encode(['error'=>'Request expired before execution.']),$collector]);
        $job=db_fetch_row_prepared("SELECT * FROM plugin_nms_config_jobs WHERE poller_id=? AND status='queued' ORDER BY id LIMIT 1",[$collector]);
        if(!$job) { nms_config_monitor_once($collector); return; }
        nms_category_execute("UPDATE plugin_nms_config_jobs SET status='running',started_at=NOW() WHERE id=?",[$job['id']]);
        $previous_session=$_SESSION ?? [];
        $dispatched=false;
        try {
            nms_config_job_authorize($job);
            $_SESSION=['sess_user_id'=>(int)$job['user_id']];
            $result=nms_serial_mutation(function() use($job,$collector,&$dispatched) {
                // Hold the configuration lock through execution so connection/profile edits cannot race a write.
                $target=nms_config_target($job['host_id'],false);
                if((int)$target['host']['poller_id']!==(int)$collector || !hash_equals($target['signature'],$job['signature'])) throw new RuntimeException('Device, connection or equipment profile changed after submission.');
                $field=$target['fields'][$job['field_key']] ?? null;
                if(!$field) throw new RuntimeException('Field no longer exists.');
                $before=json_decode($job['before_json'],true,32,JSON_THROW_ON_ERROR);
                $requested=json_decode($job['requested_json'],true,32,JSON_THROW_ON_ERROR);
                if($job['operation']==='write') {
                    if(!$field['writable']) throw new RuntimeException('Field is no longer writable.');
                    $before=nms_equipment_value($field,$before); $requested=nms_equipment_value($field,$requested);
                }
                $dispatched=true;
                return $target['profile']['protocol']==='modbus_rtu' ? nms_config_serial_execute($target,$field,$job['operation'],$before,$requested) : nms_config_snmp_execute($target,$field,$job['operation'],$before,$requested);
            });
        } catch(Throwable $e) {
            // Admission failures cannot have changed equipment; only dispatched writes are uncertain.
            $result=['status'=>$job['operation']==='write' && $dispatched?'unverified':'failed','error'=>$e->getMessage()];
        }
        finally { $_SESSION=$previous_session; }
        $status=['read'=>'complete','verified'=>'verified','unverified'=>'unverified','failed'=>'failed'][$result['status'] ?? ''] ?? 'failed';
        nms_category_execute('UPDATE plugin_nms_config_jobs SET status=?,result_json=?,finished_at=NOW() WHERE id=?',[$status,json_encode($result,JSON_THROW_ON_ERROR),$job['id']]);
        if($job['operation']==='write') nms_category_execute('DELETE FROM plugin_nms_serial_readings WHERE host_id=? AND field_key=?',[$job['host_id'],$job['field_key']]);
        if($job['operation']==='read') nms_category_execute('INSERT INTO plugin_nms_serial_readings(host_id,field_key,signature,value_json,status,error_text,observed_at) VALUES (?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE signature=VALUES(signature),value_json=VALUES(value_json),status=VALUES(status),error_text=VALUES(error_text),observed_at=NOW()',[$job['host_id'],$job['field_key'],$job['signature'],json_encode($result['value'] ?? null),$status,substr($result['error'] ?? '',0,512)]);
    } finally { db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]); }
}
