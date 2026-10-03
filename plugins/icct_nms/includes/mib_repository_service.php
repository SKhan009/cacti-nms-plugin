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
        throw new RuntimeException('Missing MIB dependencies: ' . implode(', ', array_unique($missing[1])) . '. Upload these modules together with the main MIB, or have the administrator install them in /usr/share/snmp/mibs. Internet access is not required.');
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
function icct_mib_preview($upload,$typeId){
    $types=icct_nms_device_types();if(!is_string($typeId)||!isset($types[$typeId]))throw new InvalidArgumentException('Select a device type.');
    $names=$upload['name']??[];if(!is_array($names)||!count($names)||count($names)>8)throw new InvalidArgumentException('Upload 1–8 MIB files including dependencies.');
    $dir=sys_get_temp_dir().'/icct-mib-'.bin2hex(random_bytes(16));if(!mkdir($dir,0700))throw new RuntimeException('Cannot create private parsing directory.');
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
        $args=[read_config_option('path_snmptranslate')?:'snmptranslate','-M','+'.$dir,'-m',implode(':',array_keys($files))];
        icct_mib_command(array_merge($args,['-Tz']));$records=[];$deadline=microtime(true)+60;
        foreach($symbols as $symbol=>$kind){
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
            $records[]=['symbol'=>$symbol,'kind'=>$kind,'base_oid'=>$base,'oid'=>$base?($table?$base:$base.'.0'):'','syntax'=>$syntax[1]??'','access'=>$access[1]??'','description'=>trim(preg_replace('/\s+/',' ',$description[1]??'')),'units'=>$units[1]??'','table'=>$table,'numeric'=>(bool)$numeric,'enum'=>$enum,'ds_type'=>$counter?2:1,'label'=>explode('::',$symbol)[1],'reason'=>!$numeric?'Metadata / nonnumeric object':($table?'Enter the device-specific instance index':($enum?'Numeric enumeration: review state mapping':'Readable numeric scalar'))];
        }
        return ['id'=>bin2hex(random_bytes(16)),'type_id'=>$typeId,'type_name'=>$types[$typeId]['name'],'category_id'=>$types[$typeId]['category_id'],'files'=>array_values($files),'records'=>$records,'created'=>time(),'owner'=>icct_backend_current_user_id()];
    }finally{foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
}
function icct_mib_list(){
    $rows=db_fetch_assoc("SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key LIKE 'mib_repository_%' ORDER BY updated_at DESC");
    return array_values(array_filter(array_map(fn($r)=>json_decode($r['meta_value'],true),$rows?:[]),'is_array'));
}
function icct_mib_plan($preview,$input){
    $types=icct_nms_device_types();$type=$input['type_id']??'';
    if(!is_string($type)||!isset($types[$type]))throw new InvalidArgumentException('Select an existing device type.');
    $options=[];foreach(['data','graph','device'] as $key)$options[$key]=!empty($input['create_'.$key]);
    if($options['graph']&&!$options['data'])throw new InvalidArgumentException('Graph templates require data source templates.');
    $name=icct_mib_name($input['template_name']??'');$records=[];
    foreach($preview['records'] as $i=>$record){
        if(empty($input['selected'][$i]))continue;
        if(!$record['numeric'])throw new InvalidArgumentException('Only readable numeric objects can create polling templates.');
        $r=$input['records'][$i]??[];$oid=ltrim(trim($r['oid']??''),'.');
        if(!preg_match('/^'.preg_quote($record['base_oid'],'/').'\.(?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*))*$/D',$oid)||strlen($oid)>255||(!$record['table']&&$oid!==$record['base_oid'].'.0'))throw new InvalidArgumentException($record['symbol'].': enter a valid full instance OID. Scalars end in .0; table columns need their instance index.');
        $record['oid']=$oid;$record['label']=icct_mib_name($r['label']??'');
        $record['data_name']=icct_mib_name($r['data_name']??'',190);$record['graph_name']=icct_mib_name($r['graph_name']??'',190);
        $record['units']=trim($r['units']??'');if(strlen($record['units'])>20)throw new InvalidArgumentException('Units must be at most 20 characters.');
        $record['ds_type']=(int)($r['ds_type']??0);if(!in_array($record['ds_type'],[1,2,3,4],true))throw new InvalidArgumentException('Choose a data source type.');
        foreach(['min','max'] as $limit){$v=trim($r[$limit]??'U');if($v!==''&&$v!=='U'&&(!is_numeric($v)||!is_finite((float)$v)))throw new InvalidArgumentException('Use a number or U for RRD bounds.');$record[$limit]=$v===''?'U':$v;}
        if($record['min']!=='U'&&$record['max']!=='U'&&(float)$record['min']>(float)$record['max'])throw new InvalidArgumentException('Minimum cannot exceed maximum.');
        $records[]=$record;
    }
    if(count($records)>64)throw new InvalidArgumentException('Select at most 64 metrics per creation.');
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
    icct_mib_write("UPDATE data_input_data SET t_value='',value=? WHERE data_template_data_id=? AND data_input_field_id=?",[$record['oid'],$data,$field]);
    if(!$options['graph'])return $pair;
    $base=(int)db_fetch_cell("SELECT id FROM graph_templates WHERE name='SNMP - Generic OID Template'");
    if(!$base)throw new RuntimeException('Cacti Generic OID graph template is not installed.');
    $pair['graph_template_id']=(int)api_duplicate_graph(0,$base,$record['graph_name'],false);
    if(!$pair['graph_template_id'])throw new RuntimeException('Could not create graph template.');
    icct_mib_write('UPDATE graph_templates_graph SET title=?,vertical_label=? WHERE graph_template_id=? AND local_graph_id=0',['|host_description| - '.$record['label'],$record['units'],$pair['graph_template_id']]);
    icct_mib_write('UPDATE graph_templates_item SET task_item_id=? WHERE graph_template_id=? AND local_graph_id=0',[$rrd,$pair['graph_template_id']]);
    icct_mib_write("UPDATE graph_template_input SET name=? WHERE graph_template_id=? AND column_name='task_item_id'",['Data Source ['.$record['label'].']',$pair['graph_template_id']]);
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
            $rows=[];foreach($plan['records'] as $record){$pair=icct_mib_native_metric($record,$plan['options']);if($hostId&&$pair['graph_template_id'])icct_mib_write('INSERT INTO host_template_graph(host_template_id,graph_template_id) VALUES(?,?)',[$hostId,$pair['graph_template_id']]);$rows[]=array_merge($pair,['oid'=>$record['oid'],'name'=>$record['label']]);}
            $bundle=['id'=>$id,'name'=>$plan['name'],'type_id'=>$plan['type_id'],'type_name'=>$plan['type_name'],'category_id'=>$plan['category_id'],'host_template_id'=>$hostId,'rows'=>$rows,'options'=>$plan['options'],'created_at'=>date('Y-m-d H:i:s'),'files'=>[],'records'=>$preview['records']];
            foreach($preview['files'] as $i=>$file){$parts=str_split(base64_encode($file['content']),45000);foreach($parts as $j=>$part)icct_mib_write('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',['mib_file_'.$id.'_'.$i.'_'.$j,$part]);unset($file['content']);$file['parts']=count($parts);$bundle['files'][]=$file;}
            // Chunk the metadata as well: large descriptions can exceed a TEXT column.
            $objectParts=str_split(base64_encode(json_encode($bundle['records'],JSON_THROW_ON_ERROR)),45000);foreach($objectParts as $j=>$part)icct_mib_write('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',['mib_objects_'.$id.'_'.$j,$part]);unset($bundle['records']);$bundle['object_parts']=count($objectParts);$bundle['object_count']=count($preview['records']);
            icct_mib_write('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',['mib_repository_'.$id,json_encode($bundle,JSON_THROW_ON_ERROR)]);
            if($hostId)icct_mib_write('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',['mib_bundle_'.$hostId,json_encode($bundle,JSON_THROW_ON_ERROR)]);
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
