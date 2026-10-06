<?php
require_once __DIR__ . '/../../ports/services/port_monitoring_service.php';
/** Device-owned thresholds. Graph rendering limits are not changed by fault rules. */
function icct_nms_fault_catalogue() {
    return db_fetch_assoc("SELECT DISTINCT gt.id AS template_id,gt.name AS template_name,dtr.id AS metric_id,dtr.data_template_id,dtr.data_source_name,dtr.rrd_minimum,dtr.rrd_maximum FROM graph_templates gt JOIN graph_templates_item gti ON gti.graph_template_id=gt.id AND gti.local_graph_id=0 JOIN data_template_rrd dtr ON dtr.id=gti.task_item_id WHERE dtr.local_data_id=0 ORDER BY gt.name,dtr.data_source_name");
}
/** Convert only explicitly declared unit factors; never infer scaling from an alarm bound. */
function icct_nms_fault_unit_scale($units) {
    $units=trim((string)$units);$scale=1.0;$label=$units;
    $factors=['tenths'=>0.1,'tenth'=>0.1,'hundredths'=>0.01,'hundredth'=>0.01,'thousandths'=>0.001,'thousandth'=>0.001];
    if(preg_match('/^(tenths?|hundredths?|thousandths?)\s+(?:of\s+)?(.+)$/i',$units,$m)){$scale=$factors[strtolower($m[1])];$label=$m[2];}
    elseif(preg_match('/^(0\.0*1)\s*(?:x\s*)?([a-z°%].*)$/i',$units,$m)){$scale=(float)$m[1];$label=$m[2];}
    return ['scale'=>$scale,'units'=>$label];
}
/** Numeric parameters from this device's resolved SNMP inputs and uploaded MIBs. */
function icct_nms_fault_parameters($id) {
    require_once __DIR__ . '/../../protocols/snmp/mibs/services/mib_repository_service.php';
    $parameters=[];$definitions=[];
    foreach(icct_mib_list() as $bundle) {
        if(!empty($bundle['deleted_at'])||!empty($bundle['deleted']))continue;
        foreach(icct_mib_objects($bundle) as $record) {
            if(empty($record['numeric'])||empty($record['base_oid']))continue;
            $conversion=icct_nms_fault_unit_scale($record['units']??'');$record['units']=$conversion['units'];$record['scale']=$conversion['scale'];$definitions[]=$record;
            if(empty($record['table']))$parameters[]=['oid'=>$record['oid'],'parameter'=>$record['label'],'units'=>$record['units']??'','scale'=>$record['scale'],'type_id'=>$bundle['type_id'],'label'=>$record['symbol'],'template_id'=>0,'metric_id'=>0];
        }
    }
    if($id) {
        $rows=db_fetch_assoc_prepared("SELECT DISTINCT pi.arg1 AS oid,r.data_source_name,dt.name,origin.id AS metric_id,gti.graph_template_id AS template_id
            FROM poller_item pi JOIN data_local dl ON dl.id=pi.local_data_id
            JOIN data_template dt ON dt.id=dl.data_template_id
            JOIN data_template_rrd r ON r.local_data_id=dl.id AND r.data_source_name=pi.rrd_name
            LEFT JOIN data_template_rrd origin ON origin.local_data_id=0 AND origin.data_template_id=r.data_template_id AND origin.data_source_name=r.data_source_name
            LEFT JOIN graph_templates_item gti ON gti.task_item_id=origin.id AND gti.local_graph_id=0
            WHERE dl.host_id=? AND pi.action=0 ORDER BY dt.name,r.data_source_name",[$id]);
        // Native single-OID inputs may exist before Cacti rebuilds its poller cache.
        $inputs=db_fetch_assoc_prepared("SELECT DISTINCT did.value AS oid,r.data_source_name,dt.name,origin.id AS metric_id,gti.graph_template_id AS template_id
            FROM data_local dl JOIN data_template_data dtd ON dtd.local_data_id=dl.id
            JOIN data_input_data did ON did.data_template_data_id=dtd.id
            JOIN data_input_fields f ON f.id=did.data_input_field_id AND f.data_name='oid'
            JOIN data_template dt ON dt.id=dl.data_template_id JOIN data_template_rrd r ON r.local_data_id=dl.id
            LEFT JOIN data_template_rrd origin ON origin.local_data_id=0 AND origin.data_template_id=r.data_template_id AND origin.data_source_name=r.data_source_name
            LEFT JOIN graph_templates_item gti ON gti.task_item_id=origin.id AND gti.local_graph_id=0
            WHERE dl.host_id=? ORDER BY dt.name,r.data_source_name",[$id]);
        foreach(array_merge($rows?:[],$inputs?:[]) as $row) {
            try{$oid=icct_nms_fault_oid($row['oid']);}catch(InvalidArgumentException $e){continue;}
            $parameter=$row['data_source_name'];$units=in_array($oid,['1.3.6.1.4.1.2021.11.9.0','1.3.6.1.4.1.2021.11.10.0','1.3.6.1.4.1.2021.11.11.0'],true)?'%':'';$scale=1;
            foreach($definitions as $record)if($oid===($record['oid']??'')||(!empty($record['table'])&&str_starts_with($oid,$record['base_oid'].'.'))){$parameter=$record['label'];$units=$record['units']??'';$scale=$record['scale'];break;}
            $parameters[]=['oid'=>$oid,'parameter'=>$parameter,'units'=>$units,'scale'=>$scale,'type_id'=>'','label'=>$row['name'].' / '.$parameter.' — '.$oid,'template_id'=>(int)$row['template_id'],'metric_id'=>(int)$row['metric_id']];
        }
    }
    $out=[];$seen=[];
    foreach($parameters as $row){$key=$row['oid'].'|'.$row['type_id'].'|'.$row['metric_id'].'|'.$row['template_id'];if(isset($seen[$key]))continue;$seen[$key]=true;$out[]=$row;if(count($out)>=1500)break;}
    return $out;
}
function icct_nms_fault_rules($id) {
    $rules=json_decode((string)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['fault_rules_'.$id]),true);
    return is_array($rules)?$rules:[];
}
function icct_nms_fault_validate($rules,$catalogue,$associated) {
    if (!is_array($rules) || count($rules)>100) throw new InvalidArgumentException('Use no more than 100 fault rules.');
    $out=[];
    foreach ($rules as $rule) {
        if (!is_array($rule)) throw new InvalidArgumentException('Invalid fault rule.');
        $source=$rule['source'] ?? 'rrd';
        if(!in_array($source,['rrd','snmp'],true))throw new InvalidArgumentException('Choose a supported measurement source.');
        $template=$source==='rrd'?icct_nms_id($rule['template_id'] ?? 0):0;
        $metric=$source==='rrd'?icct_nms_id($rule['metric_id'] ?? 0):0;
        if($source==='rrd') {
            $valid=false;foreach($catalogue as $item) if((int)$item['template_id']===$template && (int)$item['metric_id']===$metric)$valid=true;
            if (!$valid || !in_array($template,$associated,true)) throw new InvalidArgumentException('Choose a data source from a graph template associated with this device.');
        }
        $parameter=$rule['parameter'] ?? ''; $units=$rule['units'] ?? '';
        foreach(['parameter'=>$parameter,'units'=>$units] as $label=>$textValue)if(!is_string($textValue)||strlen($textValue)>($label==='units'?32:120)||strpos($textValue,"\0")!==false)throw new InvalidArgumentException('Invalid '.$label.'.');
        $oid=$source==='snmp'?icct_nms_fault_oid($rule['oid'] ?? ''):'';
        if($source==='snmp'&&trim($parameter)==='')throw new InvalidArgumentException('Enter the parameter name.');
        $scale=$source==='snmp'?($rule['scale'] ?? 1):1;
        if(!is_scalar($scale)||!is_numeric($scale)||!is_finite((float)$scale)||(float)$scale<=0)throw new InvalidArgumentException('Scale must be a positive finite number.');
        $bounds=[];
        foreach(['minimum','maximum'] as $key) {
            $value=$rule[$key] ?? '';if($value===null || $value===''){$bounds[$key]=null;continue;}
            if(!is_scalar($value) || !is_numeric($value) || !is_finite((float)$value))throw new InvalidArgumentException('Thresholds must be finite numbers.');
            $bounds[$key]=(float)$value;
        }
        if($bounds['minimum']===null && $bounds['maximum']===null)throw new InvalidArgumentException('Enter at least one minimum or maximum threshold.');
        if($bounds['minimum']!==null && $bounds['maximum']!==null && $bounds['minimum']>=$bounds['maximum'])throw new InvalidArgumentException('Minimum must be less than maximum.');
        $condition=$rule['condition'] ?? ($bounds['minimum']!==null&&$bounds['maximum']!==null?'outside':($bounds['minimum']!==null?'below':'above'));
        if(!in_array($condition,['above','below','outside','equals','not_equals'],true))throw new InvalidArgumentException('Choose a supported condition.');
        if($condition==='below'&&$bounds['minimum']===null)throw new InvalidArgumentException('Enter the lower threshold.');
        if(in_array($condition,['above','equals','not_equals'],true)&&$bounds['maximum']===null)throw new InvalidArgumentException('Enter the threshold or numeric status code.');
        if($condition==='outside'&&($bounds['minimum']===null||$bounds['maximum']===null))throw new InvalidArgumentException('Enter both range limits.');
        $severity=$rule['severity'] ?? '';if(!in_array($severity,['Information','Minor','Warning','Major','Critical'],true))throw new InvalidArgumentException('Select a fault severity.');
        $text=[];
        foreach(['name'=>120,'corrective_action'=>1000,'email_recipients'=>1000] as $key=>$limit) {
            $value=$rule[$key] ?? '';
            if(!is_string($value) || strlen($value)>$limit || strpos($value,"\0")!==false)throw new InvalidArgumentException('Invalid fault '.str_replace('_',' ',$key).'.');
            $text[$key]=trim($value);
        }
        $recipients=preg_split('/[;,\s]+/',$text['email_recipients'],-1,PREG_SPLIT_NO_EMPTY);
        if(count($recipients)>20)throw new InvalidArgumentException('Use no more than 20 email recipients per rule.');
        foreach($recipients as $email)if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter valid email recipients separated by commas.');
        if(!empty($rule['email']) && !$recipients)throw new InvalidArgumentException('Enter a recipient when Email is selected.');
        $text['email_recipients']=implode(', ',array_unique($recipients));
        $out[]=['template_id'=>$template,'metric_id'=>$metric,'source'=>$source,'parameter'=>trim($parameter),'units'=>trim($units),'oid'=>$oid,'scale'=>(float)$scale,'condition'=>$condition]+$bounds+$text+['severity'=>$severity,'enabled'=>!empty($rule['enabled']),'email'=>!empty($rule['email']),'audio'=>!empty($rule['audio'])];
    }
    return $out;
}
function icct_nms_save_faults($id,$input) {
    icct_backend_require_management(3);icct_backend_require_device_access($id);
    $rules=json_decode((string)($input['fault_rules'] ?? ''),true,512,JSON_THROW_ON_ERROR);
    $associated=array_map('intval',array_column(db_fetch_assoc_prepared('SELECT graph_template_id FROM host_graph WHERE host_id=?',[$id]),'graph_template_id'));
    $rules=icct_nms_fault_validate($rules,icct_nms_fault_catalogue(),$associated);
    icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['fault_rules_'.$id,json_encode($rules,JSON_THROW_ON_ERROR)]);
}
function icct_nms_fault_state($rule,$value) {
    if (!$rule['enabled']) return 'Disabled';
    if ($value===null || !is_numeric($value) || !is_finite((float)$value))return 'Unknown';
    $condition=$rule['condition'] ?? 'outside';
    switch($condition) {
        case 'above':$alarm=$value>$rule['maximum'];break;
        case 'below':$alarm=$value<$rule['minimum'];break;
        case 'equals':$alarm=(float)$value===(float)$rule['maximum'];break;
        case 'not_equals':$alarm=(float)$value!==(float)$rule['maximum'];break;
        default:$alarm=($rule['minimum']!==null&&$value<$rule['minimum'])||($rule['maximum']!==null&&$value>$rule['maximum']);
    }
    return $alarm?$rule['severity']:'Normal';
}
function icct_backend_collect_faults() {
    global $config;
    require_once $config['base_path'].'/lib/rrd.php';
    require_once $config['base_path'].'/lib/snmp.php';
    $collector=icct_backend_inventory_collector_id();$now=time();$interval=max(60,(int)read_config_option('poller_interval'));
    $hosts=db_fetch_assoc_prepared("SELECT h.* FROM host h JOIN plugin_icct_nms_meta m ON m.meta_key=CONCAT('fault_rules_',h.id) WHERE h.deleted='' AND h.disabled='' AND h.poller_id=?",[$collector]);
    foreach($hosts as $host) {
        $id=(int)$host['id'];$rules=icct_nms_fault_rules($id);$results=[];$cache=[];$snmpCache=[];$snmpDeadline=microtime(true)+min(60,max(10,$interval/3));
        foreach($rules as $number=>$rule) {
            if(($rule['source'] ?? 'rrd')==='snmp') {
                $value=null;
                if($rule['enabled']&&(int)$host['snmp_version']>0&&microtime(true)<$snmpDeadline&&icct_backend_protocol_enabled($id,'snmp')) {
                    try {
                        $oid=icct_nms_fault_oid($rule['oid']);
                        if(!array_key_exists($oid,$snmpCache))$snmpCache[$oid]=icct_nms_fault_snmp_read($host,$oid);
                        $raw=$snmpCache[$oid];
                        if($raw!==null&&is_finite($raw*(float)$rule['scale']))$value=$raw*(float)$rule['scale'];
                    } catch(Throwable $failure){$value=null;}
                }
                $results[]=['rule'=>$number,'graph_id'=>0,'graph'=>$rule['parameter'],'value'=>$value,'sample_time'=>$value!==null?$now:0,'state'=>icct_nms_fault_state($rule,$value)];
                continue;
            }
            // Bind the chosen template source to actual graphs on this host; never poll another host's data.
            $sources=db_fetch_assoc_prepared('SELECT DISTINCT gl.id AS graph_id,gtg.title_cache,dtr.local_data_id,dtr.data_source_name FROM graph_local gl JOIN host_graph hg ON hg.host_id=gl.host_id AND hg.graph_template_id=gl.graph_template_id JOIN graph_templates_graph gtg ON gtg.local_graph_id=gl.id JOIN graph_templates_item gti ON gti.local_graph_id=gl.id JOIN data_template_rrd dtr ON dtr.id=gti.task_item_id JOIN data_template_rrd origin ON origin.id=? AND origin.data_template_id=dtr.data_template_id AND origin.data_source_name=dtr.data_source_name WHERE gl.host_id=? AND gl.graph_template_id=?',[$rule['metric_id'],$id,$rule['template_id']]);
            if(!$sources)$results[]=['rule'=>$number,'graph_id'=>0,'graph'=>'No graph instance yet','value'=>null,'sample_time'=>0,'state'=>$rule['enabled']?'Unknown':'Disabled'];
            foreach($sources as $source) {
                $key=(int)$source['local_data_id'];$value=null;$sample=0;
                try {
                    if(!isset($cache[$key]))$cache[$key]=rrdtool_function_fetch($key,$now-3*$interval,$now,$interval,true,null,'AVERAGE');
                    $fetch=$cache[$key];$column=array_search($source['data_source_name'],$fetch['data_source_names'] ?? [],true);
                    $points=$column===false?[]:($fetch['values'][$column] ?? []);krsort($points,SORT_NUMERIC);
                    foreach($points as $timestamp=>$point) {
                        if((int)$timestamp>$now)continue;
                        $sample=(int)$timestamp;
                        if($now-$sample<=2*$interval && is_numeric($point) && is_finite((float)$point))$value=(float)$point;
                        break; // Never substitute an older good sample for an unknown latest sample.
                    }
                } catch(Throwable $failure) { $value=null; }
                $results[]=['rule'=>$number,'graph_id'=>(int)$source['graph_id'],'graph'=>$source['title_cache'],'value'=>$value,'sample_time'=>$sample,'state'=>icct_nms_fault_state($rule,$value)];
            }
        }
        $snapshot=['time'=>$now,'rules_hash'=>hash('sha256',json_encode($rules,JSON_THROW_ON_ERROR)),'results'=>$results];
        icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['fault_status_'.$id,json_encode($snapshot,JSON_THROW_ON_ERROR)]);
    }
}
function icct_nms_fault_observations($host) {
    $id=(int)$host['id'];$rules=icct_nms_fault_rules($id);
    $data=json_decode((string)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['fault_status_'.$id]),true);
    $age=time()-(int)($data['time'] ?? 0);
    if($host['disabled']!=='' || $age<0 || $age>2*max(60,(int)read_config_option('poller_interval')) || ($data['rules_hash'] ?? '')!==hash('sha256',json_encode($rules,JSON_THROW_ON_ERROR)))return icct_nms_port_faults($host);
    $allowed=array_map('intval',array_column(icct_nms_device_graphs($id),'local_graph_id'));
    return array_merge(array_values(array_filter($data['results'] ?? [],static fn($row)=>!(int)$row['graph_id'] || in_array((int)$row['graph_id'],$allowed,true))),icct_nms_port_faults($host));
}

/** Require a numeric full instance OID; scalar objects include their .0 instance. */
function icct_nms_fault_oid($value) {
    if(!is_string($value)||strlen($value)>512||!preg_match('/^\.?([0-2])\.([0-9]+)(?:\.[0-9]+)+$/D',$value,$match))throw new InvalidArgumentException('Enter a numeric full instance OID, including .0 or the table index.');
    $parts=explode('.',ltrim($value,'.'));
    if(count($parts)>128||((int)$match[1]<2&&(float)$match[2]>39))throw new InvalidArgumentException('Invalid SNMP OID.');
    foreach($parts as $part)if(strlen($part)>10||(float)$part>4294967295)throw new InvalidArgumentException('Invalid SNMP OID component.');
    return implode('.',$parts);
}
function icct_nms_fault_numeric($value) {
    if(!is_scalar($value))return null;
    $text=trim((string)$value);
    $text=preg_replace('/^(INTEGER|Gauge32|Unsigned32|Counter32|Counter64|Timeticks):\s*/i','',$text);
    if(preg_match('/^[A-Za-z][A-Za-z0-9_-]*\((-?[0-9]+)\)$/D',$text,$match))$text=$match[1];
    return is_numeric($text)&&is_finite((float)$text)?(float)$text:null;
}
function icct_nms_fault_snmp_read($host,$oid) {
    return icct_nms_fault_numeric(cacti_snmp_get($host['hostname'],$host['snmp_community'],$oid,$host['snmp_version'],$host['snmp_username'],$host['snmp_password'],$host['snmp_auth_protocol'],$host['snmp_priv_passphrase'],$host['snmp_priv_protocol'],$host['snmp_context'],$host['snmp_port'],min(10000,max(1,(int)$host['snmp_timeout'])),min(1,max(0,(int)read_config_option('snmp_retries'))),'ICCT Fault',$host['snmp_engine_id'],SNMP_STRING_OUTPUT_ASCII));
}
