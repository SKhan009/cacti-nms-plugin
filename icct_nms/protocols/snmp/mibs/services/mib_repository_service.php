<?php
/** Offline MIB repository and reviewed native Cacti templates. Parser adapted from the NMS reference. */
function icct_mib_upload_path($upload, $index)
{
    $error = $upload['error'][$index] ?? UPLOAD_ERR_NO_FILE;
    $errors = [
        UPLOAD_ERR_INI_SIZE => 'The MIB exceeds the RHEL PHP upload_max_filesize limit (' . ini_get('upload_max_filesize') . ').',
        UPLOAD_ERR_FORM_SIZE => 'The MIB exceeds the form upload size limit.',
        UPLOAD_ERR_PARTIAL => 'The MIB upload was interrupted. Select the file and retry.',
        UPLOAD_ERR_NO_FILE => 'Select a MIB file to upload.',
        UPLOAD_ERR_NO_TMP_DIR => 'PHP has no upload temporary directory. Ask the RHEL administrator to configure upload_tmp_dir.',
        UPLOAD_ERR_CANT_WRITE => 'PHP cannot write the uploaded MIB. Check temporary-directory permissions, free space and SELinux labels on RHEL.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the MIB upload. Check the RHEL PHP-FPM log.',
    ];
    if ($error !== UPLOAD_ERR_OK) throw new InvalidArgumentException($errors[$error] ?? 'The MIB upload failed. Select the file and retry.');
    $name = $upload['name'][$index] ?? '';
    if (!is_string($name) || !in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['mib', 'my', 'txt'], true)) {
        throw new InvalidArgumentException('Select .mib, .my or .txt MIB definition files.');
    }
    $path = $upload['tmp_name'][$index] ?? '';
    if (!is_string($path) || !is_file($path) || !is_readable($path)) {
        throw new RuntimeException('The uploaded MIB temporary file is unavailable. Select the file and retry.');
    }
    return $path;
}
function icct_mib_command($args)
{
	$pipes = [];
	$p = proc_open($args, [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes, null, null, [
		"bypass_shell" => true,
	]);
	if (!is_resource($p)) {
		throw new RuntimeException("Net-SNMP snmptranslate is required locally. On offline RHEL, install net-snmp-utils and its dependencies from matching RHEL installation media or an offline RPM repository.");
	}
	fclose($pipes[0]);
	stream_set_blocking($pipes[1], false);
	stream_set_blocking($pipes[2], false);
	$out = "";
	$err = "";
	$deadline = microtime(true) + 10;
	$exit = -1;
	try {
		while (true) {
			$out .= stream_get_contents($pipes[1]);
			$err .= stream_get_contents($pipes[2]);
			$state = proc_get_status($p);
			if (strlen($out) + strlen($err) > 2097152 || microtime(true) > $deadline) {
				proc_terminate($p);
				throw new RuntimeException("MIB parsing exceeded its time or output limit.");
			}
			if (!$state["running"]) {
				$exit = $state["exitcode"];
				break;
			}
			usleep(10000);
		}
		$out .= stream_get_contents($pipes[1]);
		$err .= stream_get_contents($pipes[2]);
	} finally {
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($p);
	}
    if ($exit === 127) {
        throw new RuntimeException('Cannot run snmptranslate. Install net-snmp-utils locally on RHEL and check the configured executable path. Offline installation can use matching RHEL media or an offline RPM repository.');
    }
    if (preg_match_all('/Cannot find module \(([^)]+)\)/', $err, $missing)) {
        throw new RuntimeException('Missing MIB dependencies: ' . implode(', ', array_unique($missing[1])) . '. Upload these modules to the MIB Repository. Saved modules and bundled dependencies are loaded from the plugin folder. Internet access is not required.');
    }
	if (
		$exit !== 0 ||
		preg_match(
			"/Cannot find module|Unlinked OID|Undefined identifier|Bad operator|Cannot adopt OID|Error in parsing/i",
			$err,
		)
	) {
		throw new RuntimeException(
			"MIB parsing failed locally. Check the MIB syntax and its imported dependencies. " .
				substr($err, 0, 500),
		);
	}
	return $out;
}
function icct_mib_name($value,$max=150){
    if(!is_string($value)||trim($value)===''||strlen($value)>$max||preg_match('/[\x00-\x1f<>]/',$value))throw new InvalidArgumentException('Enter a plain name of at most '.$max.' characters.');
    return trim($value);
}
/** Private runtime files stay in the plugin; only this directory is writable. */
function icct_mib_private_directory(){
    $root=__DIR__ . '/../storage';
    if(!is_dir($root)||!is_writable($root))throw new RuntimeException('The plugin protocols/snmp/mibs/storage directory must be writable by the web-server account.');
    $dir=$root.'/review-'.bin2hex(random_bytes(16));
    if(!mkdir($dir,0700))throw new RuntimeException('Cannot create private MIB parsing directory.');
    return $dir;
}
/** Resolve only imported repository modules, newest saved version first. */
function icct_mib_repository_dependencies($dir,$files){
    $catalog=[];foreach(icct_mib_list() as $bundle)foreach($bundle['files'] as $i=>$file){
        $module=$file['module']??'';
        if(preg_match('/^[A-Za-z][A-Za-z0-9-]*$/D',$module)&&!isset($catalog[$module]))$catalog[$module]=[$bundle,$i];
    }
    $seen=array_fill_keys(array_keys($files),true);$queue=array_column($files,'content');$bytes=0;$used=[];
    while($queue){
        $content=array_shift($queue);
        if(!preg_match('/\bIMPORTS\b(.*?);/s',preg_replace('/--[^\r\n]*/','',$content),$imports))continue;
        preg_match_all('/\bFROM\s+([A-Za-z][A-Za-z0-9-]*)/',$imports[1],$matches);
        foreach($matches[1] as $module){
            if(isset($seen[$module]))continue;$seen[$module]=true;
            if(!isset($catalog[$module]))continue;
            [$bundle,$index]=$catalog[$module];$text=icct_mib_file($bundle,$index);$bytes+=strlen($text);
            if(count($used)>=128||$bytes>8388608)throw new RuntimeException('Repository dependency limit exceeded.');
            if(!preg_match('/\b'.preg_quote($module,'/').'\s+DEFINITIONS\s*::=\s*BEGIN\b/',$text))throw new RuntimeException('Stored MIB module header does not match.');
            if(file_put_contents($dir.'/'.$module.'.txt',$text)!==strlen($text))throw new RuntimeException('Cannot write repository dependency.');
            $used[]=$module;$queue[]=$text;
        }
    }
    return $used;
}
/** Bounds declared by the MIB, not thresholds inferred from descriptions. */
function icct_mib_bounds($syntax){
    if(preg_match('/\((-?\d+)\s*\.\.\s*(-?\d+)\)/',$syntax,$m))return ['min'=>$m[1],'max'=>$m[2]];
    if(preg_match('/^(?:Counter32|Counter64|Unsigned32|Gauge32|TimeTicks)\b/',$syntax))return ['min'=>'0','max'=>'U'];
    return ['min'=>'U','max'=>'U'];
}
function icct_mib_default_name($preview){
    return substr(implode(' / ',array_column($preview['files'],'module')),0,150);
}
/** Recoverable removal of an upload; native templates and file chunks are retained. */
function icct_mib_set_deleted($id,$deleted){
    icct_backend_require_management(3);
    if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Invalid MIB upload.');
    $lock='icct_mib_templates';
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,10)',[$lock])!==1)throw new RuntimeException('Repository is busy. Retry shortly.');
    try{
        $from=($deleted?'mib_repository_':'mib_deleted_').$id;
        $to=($deleted?'mib_deleted_':'mib_repository_').$id;
        if(!db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',[$from]))throw new InvalidArgumentException('MIB upload not found.');
        icct_mib_write('UPDATE plugin_icct_nms_meta SET meta_key=?,updated_at=NOW() WHERE meta_key=?',[$to,$from]);
    }finally{db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}
/** Read native device inputs and graph settings; never guess an instance index. */
function icct_mib_device_inputs($preview,$hostId){
    icct_backend_require_management(3);$hostId=icct_backend_device_require($hostId);
    if(!is_device_allowed($hostId,icct_backend_current_user_id()))throw new InvalidArgumentException('Device access denied.');
    $host=db_fetch_row_prepared("SELECT * FROM host WHERE id=? AND deleted=''",[$hostId]);
    if(!$host||$host['disabled']!==''||(int)$host['snmp_version']<1||!icct_backend_protocol_enabled($hostId,'snmp'))throw new InvalidArgumentException('Choose an enabled device with SNMP selected in Protocol Config.');
    $inputs=db_fetch_assoc_prepared("SELECT DISTINCT dl.id AS local_data_id,dl.data_template_id,dtd.id AS input_id,did.value AS oid,dt.name AS data_name,r.data_source_name,r.data_source_type_id AS ds_type,r.rrd_minimum AS min,r.rrd_maximum AS max,r.id AS rrd_id
        FROM data_local dl JOIN data_template_data dtd ON dtd.local_data_id=dl.id
        JOIN data_template dt ON dt.id=dl.data_template_id
        JOIN data_input_data did ON did.data_template_data_id=dtd.id
        JOIN data_input_fields f ON f.id=did.data_input_field_id AND f.data_name='oid'
        JOIN data_template_rrd r ON r.local_data_id=dl.id WHERE dl.host_id=? ORDER BY dl.id",[$hostId]);
    // Indexed data queries keep their resolved OIDs in the device poller cache.
    $indexed=db_fetch_assoc_prepared("SELECT DISTINCT dl.id AS local_data_id,dl.data_template_id,dtd.id AS input_id,pi.oid,dt.name AS data_name,r.data_source_name,r.data_source_type_id AS ds_type,r.rrd_minimum AS min,r.rrd_maximum AS max,r.id AS rrd_id
        FROM poller_item pi JOIN data_local dl ON dl.id=pi.local_data_id
        JOIN data_template_data dtd ON dtd.local_data_id=dl.id JOIN data_template dt ON dt.id=dl.data_template_id
        JOIN data_template_rrd r ON r.local_data_id=dl.id AND r.data_source_name=pi.rrd_name
        WHERE dl.host_id=? AND pi.action=0 ORDER BY dl.id",[$hostId]);
    $inputs=array_merge($inputs?:[],$indexed?:[]);
    $result=[];$seen=[];
    foreach($preview['records'] as $i=>$record){
        if(!$record['numeric'])continue;
        foreach($inputs as $input){
            $oid=ltrim(trim($input['oid']),'.');$prefix=$record['base_oid'].'.';
            if(!str_starts_with($oid,$prefix)||!preg_match('/^\d+(?:\.\d+)*$/D',substr($oid,strlen($prefix))))continue;
            if(!$record['table']&&$oid!==$prefix.'0')continue;
            $graph=db_fetch_row_prepared("SELECT gt.name AS graph_name,gg.vertical_label AS units,i.graph_type_id AS graph_type,i.consolidation_function_id AS cf,c.hex AS color,i.text_format AS legend,i.graph_template_id,i.local_graph_id
                FROM graph_templates_item i JOIN graph_local gl ON gl.id=i.local_graph_id
                JOIN graph_templates gt ON gt.id=i.graph_template_id JOIN graph_templates_graph gg ON gg.local_graph_id=gl.id
                LEFT JOIN colors c ON c.id=i.color_id WHERE gl.host_id=? AND i.task_item_id=? AND i.graph_type_id IN (4,5,6,7) ORDER BY i.sequence LIMIT 1",[$hostId,$input['rrd_id']]);
            if(isset($seen[$i][$oid]))continue;$seen[$i][$oid]=true;
            $values=array_intersect_key($input,array_flip(['ds_type','min','max']));$values['oid']=$oid;$values['instance_index']=$record['table']?substr($oid,strlen($prefix)):'';
            if($graph){
                foreach(['units','graph_type','cf','color','legend'] as $key)$values[$key]=$graph[$key];
                $values['statistics']=db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_templates_item WHERE local_graph_id=? AND graph_type_id=9',[$graph['local_graph_id']])?'1':'0';
                $lines=db_fetch_assoc_prepared('SELECT text_format,value FROM graph_templates_item WHERE local_graph_id=? AND graph_type_id=2 ORDER BY sequence',[$graph['local_graph_id']]);
                foreach($lines as $line){if($line['text_format']==='Lower threshold')$values['threshold_low']=$line['value'];if($line['text_format']==='Upper threshold')$values['threshold_high']=$line['value'];}
            }
            $result[$i][]=$values;
        }
    }
    return $result;
}
function icct_mib_preview($upload,$typeId,$allowUnresolved=false){
    $types=icct_nms_device_types();if(!is_string($typeId)||!isset($types[$typeId]))throw new InvalidArgumentException('Select a device type.');
    $names=$upload['name']??[];if(!is_array($names)||!count($names)||count($names)>8)throw new InvalidArgumentException('Upload 1–8 MIB files including dependencies.');
    $dir=icct_mib_private_directory();
    $files=[];$symbols=[];$total=0;
    try{
        foreach($names as $i=>$name){
            $path=icct_mib_upload_path($upload,$i);$size=filesize($path);$total+=$size;
            if($size<1||$size>1048576||$total>4194304)throw new InvalidArgumentException('Limit: 1 MiB per file, 4 MiB total.');
            $text=file_get_contents($path);
            if(str_contains($text,"\0")||!preg_match('/\b([A-Za-z][A-Za-z0-9-]*)\s+DEFINITIONS\s*::=\s*BEGIN\b/',$text,$m))throw new InvalidArgumentException('Invalid MIB module header.');
            $module=$m[1];if(isset($files[$module]))throw new InvalidArgumentException('Duplicate MIB module.');
            $files[$module]=['name'=>basename($name),'module'=>$module,'bytes'=>$size,'sha256'=>hash('sha256',$text),'content'=>$text];
            if(file_put_contents($dir.'/'.$module.'.txt',$text)!==strlen($text))throw new RuntimeException('Cannot write private parsing file.');
            preg_match_all('/^\s*([A-Za-z][A-Za-z0-9-]*)\s+(OBJECT-TYPE|NOTIFICATION-TYPE|TRAP-TYPE|TEXTUAL-CONVENTION)\b/m',preg_replace('/\bIMPORTS\b.*?;/s','',$text),$matches,PREG_SET_ORDER);
            foreach($matches as $match)$symbols[$module.'::'.$match[1]]=$match[2];
        }
        if(count($symbols)>512)throw new InvalidArgumentException('Limit: 512 object definitions per upload. Split larger modules.');
        $dependencies=icct_mib_repository_dependencies($dir,$files);$parseError='';
        $args=[read_config_option('path_snmptranslate')?:'snmptranslate','-M',$dir.':'.__DIR__ . '/../../../../shared/assets/mibs','-m',implode(':',array_keys($files))];
        try{icct_mib_command(array_merge($args,['-Tz']));}
        catch(RuntimeException $e){
            if(!$allowUnresolved||!str_starts_with($e->getMessage(),'Missing MIB dependencies:'))throw $e;
            $parseError=$e->getMessage();
        }
        $records=[];$deadline=microtime(true)+60;
        foreach($parseError?[]:$symbols as $symbol=>$kind){
            if(microtime(true)>$deadline)throw new RuntimeException('MIB review exceeded its time limit. Upload fewer modules.');
            if($kind==='TEXTUAL-CONVENTION')continue;
            $definition=icct_mib_command(array_merge($args,['-Td','-On',$symbol]));
            preg_match('/^\.?([0-9]+(?:\.[0-9]+)+)\s*$/m',$definition,$oid);
            preg_match('/(?:MAX-ACCESS|ACCESS)\s+(\S+)/',$definition,$access);
            preg_match('/SYNTAX\s+([^\n]+)/',$definition,$syntax);
            preg_match('/DESCRIPTION\s+"(.*?)"/s',$definition,$description);
            preg_match('/UNITS\s+"([^"]*)"/',$definition,$units);
            $base=$oid[1]??'';$table=false;
            if($base && $kind==='OBJECT-TYPE'){
                $parent=icct_mib_command(array_merge($args,['-Td','-On',preg_replace('/\.[0-9]+$/','',$base)]));
                $table=(bool)preg_match('/\b(?:INDEX|AUGMENTS)\s*\{|SYNTAX\s+SEQUENCE\b/',$parent);
            }
            $numeric=$kind==='OBJECT-TYPE' && in_array($access[1]??'',['read-only','read-write','read-create']) && preg_match('/^(Integer32|INTEGER|Unsigned32|Gauge32|Counter32|Counter64|TimeTicks)\b/',$syntax[1]??'');
            $counter=(bool)preg_match('/^Counter(?:32|64)\b/',$syntax[1]??'');$enum=str_contains($syntax[1]??'','{');
            $records[]=['symbol'=>$symbol,'kind'=>$kind,'base_oid'=>$base,'oid'=>$base?($table?$base:$base.'.0'):'','syntax'=>$syntax[1]??'','access'=>$access[1]??'','description'=>trim(preg_replace('/\s+/',' ',$description[1]??'')),'units'=>$units[1]??'','table'=>$table,'numeric'=>(bool)$numeric,'enum'=>$enum,'ds_type'=>$counter?2:1,'label'=>explode('::',$symbol)[1],'min'=>icct_mib_bounds($syntax[1]??'')['min'],'max'=>icct_mib_bounds($syntax[1]??'')['max'],'reason'=>!$numeric?'Metadata / nonnumeric object':($table?'Table object':($enum?'Numeric enumeration: review state mapping':'Readable numeric scalar'))];
        }
        return ['id'=>bin2hex(random_bytes(16)),'type_id'=>$typeId,'type_name'=>$types[$typeId]['name'],'category_id'=>$types[$typeId]['category_id'],'files'=>array_values($files),'records'=>$records,'parse_error'=>$parseError,'dependencies'=>$dependencies,'created'=>time(),'owner'=>icct_backend_current_user_id()];
    }finally{foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
}
function icct_mib_list(){
    $rows=db_fetch_assoc("SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key LIKE 'mib_repository_%' ORDER BY updated_at DESC");
    $bundles=array_values(array_filter(array_map(fn($r)=>json_decode($r['meta_value'],true),$rows?:[]),'is_array'));
    foreach($bundles as &$bundle)if(isset($bundle['row_parts'])){
        $encoded='';for($i=0;$i<$bundle['row_parts'];$i++)$encoded.=(string)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['mib_rows_'.$bundle['id'].'_'.$i]);
        $bundle['rows']=json_decode(base64_decode($encoded,true),true,32,JSON_THROW_ON_ERROR);
    }unset($bundle);return $bundles;
}
/** Pack large reviews into one POST field to avoid PHP max_input_vars truncation. */
function icct_mib_review_input($input){
    if(!isset($input['review_payload']))return $input;
    if(!is_string($input['review_payload'])||strlen($input['review_payload'])>2097152)throw new InvalidArgumentException('Review payload is too large.');
    $values=json_decode($input['review_payload'],true,32,JSON_THROW_ON_ERROR);
    if(!is_array($values)||!is_array($values['selected']??null)||!is_array($values['records']??null)||count($values['records'])>512)throw new InvalidArgumentException('Invalid review payload.');
    return array_replace($input,['selected'=>$values['selected'],'records'=>$values['records']]);
}
function icct_mib_plan($preview,$input){
    $types=icct_nms_device_types();$type=$input['type_id']??'';
    if(!is_string($type)||!isset($types[$type]))throw new InvalidArgumentException('Select an existing device type.');
    $options=[];foreach(['data','graph','device'] as $key)$options[$key]=!empty($input['create_'.$key]);
    if(!empty($preview['parse_error'])&&array_filter($options))throw new InvalidArgumentException('Resolve the missing dependencies before creating templates. Save the files only, upload the dependencies, then review again.');
    if($options['graph']&&!$options['data'])throw new InvalidArgumentException('Graph templates require data source templates.');
    $name=icct_mib_name($input['template_name']??'');$records=[];
    foreach($preview['records'] as $i=>$record){
        if(empty($input['selected'][$i]))continue;
        if(!$record['numeric'])throw new InvalidArgumentException('Only readable numeric objects can create polling templates.');
        $r=$input['records'][$i]??[];$oid=ltrim(trim($r['oid']??$record['oid']),'.');
        $index=trim($r['instance_index']??'');
        if($record['table']&&$index!==''){
            if(!preg_match('/^(?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*))*$/D',$index))throw new InvalidArgumentException($record['symbol'].': enter a numeric instance index, such as 1 or 1001.2.');
            $indexed=$record['base_oid'].'.'.$index;
            if($oid!==''&&$oid!==$record['base_oid']&&$oid!==$indexed)throw new InvalidArgumentException($record['symbol'].': full OID and instance index disagree.');
            $oid=$indexed;
        }
        if(!$record['table']&&$oid==='')$oid=$record['base_oid'].'.0';
        $record['deferred_instance']=$record['table']&&$index===''&&($oid===''||$oid===$record['base_oid']);
        if($record['deferred_instance'])$oid=$record['base_oid'];
        if(!$record['deferred_instance']&&(!preg_match('/^'.preg_quote($record['base_oid'],'/').'\.(?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*))*$/D',$oid)||strlen($oid)>255||(!$record['table']&&$oid!==$record['base_oid'].'.0')))throw new InvalidArgumentException($record['symbol'].': enter a valid instance OID.');
        $record['oid']=$oid;$record['label']=icct_mib_name($r['label']??$record['label']);
        $record['data_name']=icct_mib_name($r['data_name']??($name.' - '.$record['label']),190);$record['graph_name']=icct_mib_name($r['graph_name']??($name.' - '.$record['label']),190);
        $record['units']=trim($r['units']??$record['units']);if(strlen($record['units'])>20)throw new InvalidArgumentException('Units must be at most 20 characters.');
        $record['ds_type']=(int)($r['ds_type']??$record['ds_type']);if(!in_array($record['ds_type'],[1,2,3,4],true))throw new InvalidArgumentException('Choose a data source type.');
        foreach(['min','max'] as $limit){$v=trim($r[$limit]??$record[$limit]??'U');if($v!==''&&$v!=='U'&&(!is_numeric($v)||!is_finite((float)$v)))throw new InvalidArgumentException('Use a number or U for RRD bounds.');$record[$limit]=$v===''?'U':$v;}
        if($record['min']!=='U'&&$record['max']!=='U'&&(float)$record['min']>(float)$record['max'])throw new InvalidArgumentException('Minimum cannot exceed maximum.');
        $record['graph_type']=(int)($r['graph_type']??4);$record['cf']=(int)($r['cf']??1);
        if(!in_array($record['graph_type'],[4,5,6,7],true)||!in_array($record['cf'],[1,2,3,4],true))throw new InvalidArgumentException('Choose a valid graph item and consolidation function.');
        $record['color']=strtoupper(trim($r['color']??'00CF00'));
        if(!preg_match('/^[A-F0-9]{6}$/D',$record['color']))throw new InvalidArgumentException('Graph color must be six hexadecimal digits.');
        $record['legend']=icct_mib_name($r['legend']??$record['label'],150);
        $record['statistics']=isset($r['statistics'])?($r['statistics']==='1'):true;
        foreach(['threshold_low','threshold_high'] as $key){
            $v=trim($r[$key]??'');if($v!==''&&(!is_numeric($v)||!is_finite((float)$v)))throw new InvalidArgumentException($record['symbol'].': threshold lines must be finite numbers or blank.');$record[$key]=$v;
        }
        if($record['threshold_low']!==''&&$record['threshold_high']!==''&&(float)$record['threshold_low']>(float)$record['threshold_high'])throw new InvalidArgumentException('Lower threshold cannot exceed upper threshold.');
        $records[]=$record;
    }
    if(count($records)>512)throw new InvalidArgumentException('Select at most 512 metrics per creation.');
    if(($options['data']||$options['graph'])&&!$records)throw new InvalidArgumentException('Select at least one numeric metric.');
    if(count(array_unique(array_column($records,'oid')))!==count($records))throw new InvalidArgumentException('Each instance OID must be unique.');
    return ['name'=>$name,'type_id'=>$type,'type_name'=>$types[$type]['name'],'category_id'=>$types[$type]['category_id'],'options'=>$options,'records'=>$records];
}
function icct_mib_write($sql,$args=[]){if(!db_execute_prepared($sql,$args))throw new RuntimeException('Could not save MIB templates.');}
function icct_mib_native_metric($record,$options){
    global $config;
    require_once $config['base_path'].'/lib/api_data_source.php';require_once $config['base_path'].'/lib/api_graph.php';require_once $config['base_path'].'/lib/template.php';
    $pair=['data_template_id'=>0,'graph_template_id'=>0];
    if(!$options['data'])return $pair;
    $base=(int)db_fetch_cell("SELECT id FROM data_template WHERE name='SNMP - Generic OID Template'");
    if(!$base)throw new RuntimeException('Cacti Generic OID data template is not installed.');
    $pair['data_template_id']=(int)api_duplicate_data_source(0,$base,$record['data_name']);
    if(!$pair['data_template_id'])throw new RuntimeException('Could not create data source template.');
    $data=(int)db_fetch_cell_prepared('SELECT id FROM data_template_data WHERE data_template_id=? AND local_data_id=0',[$pair['data_template_id']]);
    $rrd=(int)db_fetch_cell_prepared('SELECT id FROM data_template_rrd WHERE data_template_id=? AND local_data_id=0',[$pair['data_template_id']]);
    $field=(int)db_fetch_cell("SELECT id FROM data_input_fields WHERE data_input_id=1 AND data_name='oid'");
    if(!$data||!$rrd||!$field)throw new RuntimeException('The duplicated data template is incomplete.');
    $ds=substr(trim(preg_replace('/[^a-z0-9]+/','_',strtolower($record['label'])),'_'),0,19)?:'reading';
    icct_mib_write('UPDATE data_template_data SET name=? WHERE id=?',['|host_description| - '.$record['label'],$data]);
    icct_mib_write("UPDATE data_template_rrd SET data_source_name=?,data_source_type_id=?,rrd_minimum=?,rrd_maximum=?,t_rrd_minimum='',t_rrd_maximum='' WHERE id=?",[$ds,$record['ds_type'],$record['min'],$record['max'],$rrd]);
    icct_mib_write('UPDATE data_input_data SET t_value=?,value=? WHERE data_template_data_id=? AND data_input_field_id=?',[!empty($record['deferred_instance'])?'on':'',!empty($record['deferred_instance'])?'':$record['oid'],$data,$field]);
    if(!$options['graph'])return $pair;
    $base=(int)db_fetch_cell("SELECT id FROM graph_templates WHERE name='SNMP - Generic OID Template'");
    if(!$base)throw new RuntimeException('Cacti Generic OID graph template is not installed.');
    $pair['graph_template_id']=(int)api_duplicate_graph(0,$base,$record['graph_name'],false);
    if(!$pair['graph_template_id'])throw new RuntimeException('Could not create graph template.');
    icct_mib_write('UPDATE graph_templates_graph SET title=?,vertical_label=? WHERE graph_template_id=? AND local_graph_id=0',['|host_description| - '.$record['label'],$record['units'],$pair['graph_template_id']]);
    icct_mib_write('UPDATE graph_templates_item SET task_item_id=? WHERE graph_template_id=? AND local_graph_id=0',[$rrd,$pair['graph_template_id']]);
    icct_mib_write("UPDATE graph_template_input SET name=? WHERE graph_template_id=? AND column_name='task_item_id'",['Data Source ['.$record['label'].']',$pair['graph_template_id']]);
    $graphId=$pair['graph_template_id'];
    $colorId=(int)db_fetch_cell_prepared('SELECT id FROM colors WHERE hex=?',[$record['color']]);
    if(!$colorId){$colorId=(int)sql_save(['id'=>0,'hex'=>$record['color']],'colors');if(!$colorId)throw new RuntimeException('Cannot save graph color.');}
    icct_mib_write('UPDATE graph_templates_item SET graph_type_id=?,consolidation_function_id=?,color_id=?,text_format=? WHERE graph_template_id=? AND local_graph_id=0 AND graph_type_id IN (4,5,6,7)',[$record['graph_type'],$record['cf'],$colorId,$record['legend'],$graphId]);
    if(!$record['statistics']){
        icct_mib_write('DELETE d FROM graph_template_input_defs d JOIN graph_templates_item i ON i.id=d.graph_template_item_id WHERE i.graph_template_id=? AND i.local_graph_id=0 AND i.graph_type_id=9',[$graphId]);
        icct_mib_write('DELETE FROM graph_templates_item WHERE graph_template_id=? AND local_graph_id=0 AND graph_type_id=9',[$graphId]);
    }
    $sequence=(int)db_fetch_cell_prepared('SELECT MAX(sequence) FROM graph_templates_item WHERE graph_template_id=?',[$graphId]);
    foreach(['threshold_low'=>'Lower threshold','threshold_high'=>'Upper threshold'] as $key=>$label){
        if($record[$key]==='')continue;
        $red=(int)db_fetch_cell("SELECT id FROM colors WHERE hex='FF0000'")?:$colorId;
        icct_mib_write('INSERT INTO graph_templates_item (hash,graph_template_id,graph_type_id,color_id,alpha,text_format,value,sequence) VALUES (?,?,?,?,?,?,?,?)',[bin2hex(random_bytes(16)),$graphId,2,$red,'FF',$label,$record[$key],++$sequence]);
    }
    if(!(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_templates_item WHERE graph_template_id=? AND task_item_id=?',[$pair['graph_template_id'],$rrd]))throw new RuntimeException('Graph template has no linked data source.');
    return $pair;
}
function icct_mib_save($preview,$plan){
    global $config;
    icct_backend_require_management(3);
    require_once $config['base_path'].'/lib/template.php';
    if(($preview['owner']??0)!==icct_backend_current_user_id()||time()-($preview['created']??0)>3600)throw new InvalidArgumentException('This upload review expired. Upload again.');
    $types=icct_nms_device_types();if(!isset($types[$plan['type_id']])||$types[$plan['type_id']]['name']!==$plan['type_name']||(int)$types[$plan['type_id']]['category_id']!==(int)$plan['category_id'])throw new InvalidArgumentException('Device type changed. Review again.');
    $id=$preview['id'];$lock='icct_mib_templates';
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,10)',[$lock])!==1)throw new RuntimeException('Template creation is busy. Retry shortly.');
    try{
        if(db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['mib_repository_'.$id]))throw new InvalidArgumentException('This upload was already saved.');
        $names=['data_template'=>[],'graph_templates'=>[],'host_template'=>[]];
        if($plan['options']['device'])$names['host_template'][]=$plan['name'];
        foreach($plan['records'] as $record){if($plan['options']['data'])$names['data_template'][]=$record['data_name'];if($plan['options']['graph'])$names['graph_templates'][]=$record['graph_name'];}
        foreach($names as $table=>$values){if(count($values)!==count(array_unique($values)))throw new InvalidArgumentException('Template names must be unique within each template type.');foreach($values as $name)if(db_fetch_cell_prepared('SELECT id FROM '.$table.' WHERE name=?',[$name]))throw new InvalidArgumentException('Template already exists: '.$name.'. Edit the name in review.');}
        if(!db_execute('START TRANSACTION'))throw new RuntimeException('Cannot begin creation.');
        try{
            $hostId=0;if($plan['options']['device']){$hostId=(int)sql_save(['id'=>0,'hash'=>get_hash_host_template(0),'name'=>$plan['name']],'host_template');if(!$hostId)throw new RuntimeException('Could not create device template.');}
            $rows=[];foreach($plan['records'] as $record){$pair=icct_mib_native_metric($record,$plan['options']);if($hostId&&$pair['graph_template_id'])icct_mib_write('INSERT INTO host_template_graph(host_template_id,graph_template_id) VALUES(?,?)',[$hostId,$pair['graph_template_id']]);$rows[]=array_merge($pair,['oid'=>$record['oid'],'name'=>$record['label'],'deferred_instance'=>!empty($record['deferred_instance'])]);}
            $bundle=['id'=>$id,'name'=>$plan['name'],'type_id'=>$plan['type_id'],'type_name'=>$plan['type_name'],'category_id'=>$plan['category_id'],'host_template_id'=>$hostId,'rows'=>$rows,'options'=>$plan['options'],'created_at'=>date('Y-m-d H:i:s'),'files'=>[],'records'=>$preview['records'],'parse_error'=>$preview['parse_error']??'','dependencies'=>$preview['dependencies']??[]];
            foreach($preview['files'] as $i=>$file){$parts=str_split(base64_encode($file['content']),45000);foreach($parts as $j=>$part)icct_mib_write('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',['mib_file_'.$id.'_'.$i.'_'.$j,$part]);unset($file['content']);$file['parts']=count($parts);$bundle['files'][]=$file;}
            // Chunk the metadata as well: large descriptions can exceed a TEXT column.
            $objectParts=str_split(base64_encode(json_encode($bundle['records'],JSON_THROW_ON_ERROR)),45000);foreach($objectParts as $j=>$part)icct_mib_write('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',['mib_objects_'.$id.'_'.$j,$part]);unset($bundle['records']);$bundle['object_parts']=count($objectParts);$bundle['object_count']=count($preview['records']);
            $storedBundle=$bundle;
            $rowParts=str_split(base64_encode(json_encode($bundle['rows'],JSON_THROW_ON_ERROR)),45000);
            foreach($rowParts as $j=>$part)icct_mib_write('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',['mib_rows_'.$id.'_'.$j,$part]);
            unset($storedBundle['rows']);$storedBundle['row_parts']=count($rowParts);
            icct_mib_write('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',['mib_repository_'.$id,json_encode($storedBundle,JSON_THROW_ON_ERROR)]);
            if($hostId)icct_mib_write('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',['mib_bundle_'.$hostId,json_encode($storedBundle,JSON_THROW_ON_ERROR)]);
            if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit templates.');
        }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
    }finally{db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
    if($plan['options']['data']||$plan['options']['graph']){set_config_option('time_last_change_graph',time());set_config_option('time_last_change_data_source',time());}
    return $bundle;
}
function icct_mib_file($bundle,$index){
    if(!isset($bundle['files'][$index]))throw new InvalidArgumentException('MIB file not found.');
    $file=$bundle['files'][$index];$encoded='';for($i=0;$i<$file['parts'];$i++)$encoded.=(string)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['mib_file_'.$bundle['id'].'_'.$index.'_'.$i]);
    $content=base64_decode($encoded,true);if($content===false||!hash_equals($file['sha256'],hash('sha256',$content)))throw new RuntimeException('Stored MIB failed integrity verification.');return $content;
}

function icct_mib_objects($bundle){
    $encoded='';for($i=0;$i<(int)$bundle['object_parts'];$i++)$encoded.=(string)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['mib_objects_'.$bundle['id'].'_'.$i]);
    $json=base64_decode($encoded,true);if($json===false)throw new RuntimeException('Stored MIB metadata is unavailable.');
    $records=json_decode($json,true,512,JSON_THROW_ON_ERROR);if(!is_array($records)||count($records)!==(int)$bundle['object_count'])throw new RuntimeException('Stored MIB metadata is incomplete.');return $records;
}
