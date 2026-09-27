<?php
/** One serial setup entry point; native device creation remains in device.php. */
require_once __DIR__.'/device.php';
nms_require_management();
$tab='serial';
$host_id=nms_config_integer($_GET['id'] ?? 0,0,16777215,'Device ID');
$host=['id'=>0,'poller_id'=>1]; $assignment=null; $equipment=[];
if($host_id) {
    nms_require_device_access($host_id);
    $host=db_fetch_row_prepared("SELECT * FROM host WHERE id=? AND deleted=''",[$host_id]);
    $assignment=nms_serial_assignment($host_id);
    if(!$host || !$assignment) { http_response_code(404); exit('Serial device not found.'); }
    $equipment=db_fetch_row_prepared('SELECT * FROM plugin_nms_config_devices WHERE host_id=?',[$host_id]) ?: [];
}
$host_templates=db_fetch_assoc('SELECT id,name FROM host_template ORDER BY name');
$categories=nms_categories();
$graph_choices=db_fetch_assoc('SELECT id,name FROM graph_templates ORDER BY name');
$query_choices=db_fetch_assoc('SELECT sq.id,sq.name FROM snmp_query sq JOIN data_input di ON di.id=sq.data_input_id WHERE di.type_id NOT IN ('.(int)DATA_INPUT_TYPE_SNMP.','.(int)DATA_INPUT_TYPE_SNMP_QUERY.') ORDER BY sq.name');
$error='';
$association_error='';
if($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if(!csrf_check_tokens($_POST['__csrf_magic'] ?? '')) throw new RuntimeException('Invalid request token.');
        if(($_POST['action'] ?? '')==='test_serial') {
            if(!$host_id) throw new InvalidArgumentException('Save the device before testing.');
            require_once __DIR__.'/jobs.php';
            $target=nms_config_target($host_id);
            $field=array_key_first($target['fields']);
            nms_config_job_enqueue($host_id,$field,'read');
            header('Location: devices.php?tab=serial&id='.$host_id.'&test_requested=1#serial-test',true,303); exit;
        }
        if(($_POST['serial_protocol'] ?? 'modbus_rtu')!=='modbus_rtu') throw new InvalidArgumentException('Unsupported serial protocol.');
        if(!empty($_POST['create_serial_graphs']) && empty($_POST['equipment_profile_id'])) throw new InvalidArgumentException('Select a reading preset to create serial graphs.');
        foreach(['graph_template_ids'=>$graph_choices,'data_query_ids'=>$query_choices] as $field=>$choices) {
            $ids=$_POST[$field] ?? [];
            if(!is_array($ids) || count($ids)>100) throw new InvalidArgumentException('Invalid template selection.');
            foreach($ids as $selected) if(!is_scalar($selected) || !ctype_digit((string)$selected) || !in_array((int)$selected,array_map('intval',array_column($choices,'id')),true)) throw new InvalidArgumentException('Select a valid template or script query.');
        }
        if(!isset($reindex_types[(int)($_POST['reindex_method'] ?? 0)])) throw new InvalidArgumentException('Select a valid re-index method.');
        if(!empty($_POST['create_serial_graphs'])) {
            require_once __DIR__.'/graph_schedule.php';
            nms_config_graph_profile(db_fetch_assoc('SELECT id,step,heartbeat,`default` FROM data_source_profiles'),
                nms_config_integer($_POST['interval_seconds'] ?? 300,60,86400,'Reading interval'),nms_poller_interval());
        }
        $saved=$host_id?nms_serial_device_update($host_id,$_POST):nms_serial_device_create($_POST);
        // Native association APIs may invoke automation or scripts; run after the device commit.
        try {
            foreach($_POST['graph_template_ids'] ?? [] as $graph) if(!db_fetch_cell_prepared('SELECT host_id FROM host_graph WHERE host_id=? AND graph_template_id=?',[$saved,$graph])) nms_device_add_graph_template($saved,$graph);
            foreach($_POST['data_query_ids'] ?? [] as $query) if(!db_fetch_cell_prepared('SELECT host_id FROM host_snmp_query WHERE host_id=? AND snmp_query_id=?',[$saved,$query])) nms_device_add_data_query($saved,$query,(int)$_POST['reindex_method']);
            if(!empty($_POST['create_serial_graphs'])) { require_once __DIR__.'/graphs.php'; nms_config_graphs_create($saved); }
        } catch(Throwable $e) { $_SESSION['nms_serial_setup_warning'][$saved]='Device saved, but graph/query setup needs attention: '.$e->getMessage(); }
        header('Location: devices.php?tab=serial&id='.$saved.'&saved=1',true,303); exit;
    } catch(Throwable $e) { $error=$e->getMessage(); }
}
if($host_id && isset($_SESSION['nms_serial_setup_warning'][$host_id])) { $association_error=$_SESSION['nms_serial_setup_warning'][$host_id]; unset($_SESSION['nms_serial_setup_warning'][$host_id]); }
$classification=$host_id?(db_fetch_row_prepared('SELECT * FROM plugin_nms_device_classification WHERE host_id=?',[$host_id]) ?: []):[];
$values=array_replace(['host_template_id'=>0,'device_threads'=>1,'location'=>'','external_id'=>'','disabled'=>'','device_type'=>$classification['device_type'] ?? '','equipment_category_id'=>$classification['category_id'] ?? 0,'description'=>'','site_id'=>0,'node_id'=>0,'notes'=>'','connection_id'=>0,'device_address'=>1,'equipment_profile_id'=>0,'interval_seconds'=>300,'assignment_revision'=>0,'equipment_revision'=>0],$host);
if($assignment) $values=array_replace($values,['connection_id'=>$assignment['connection_id'],'device_address'=>$assignment['device_address'],'assignment_revision'=>$assignment['revision'],'equipment_revision'=>$equipment['revision'] ?? 0,'equipment_profile_id'=>$equipment['profile_id'] ?? 0,'interval_seconds'=>$equipment['interval_seconds'] ?? 300,'node_id'=>db_fetch_cell_prepared('SELECT node_id FROM plugin_nms_node_devices WHERE host_id=?',[$host_id]) ?: 0]);
if($_SERVER['REQUEST_METHOD']==='POST') $values=array_replace($values,$_POST);
$host['poller_id']=$values['poller_id'] ?? 1;
$values['short_name']=$values['short_name'] ?? ($host_id?nms_short_name_get($host_id):'');
$values['manual_serial_number']=$values['manual_serial_number'] ?? ($host_id?nms_manual_serial_get($host_id):'');
$profiles=nms_serial_profiles();
$equipment_choices=db_fetch_assoc("SELECT id,name,manufacturer,model,manual_reference,fields_json FROM plugin_nms_config_profiles WHERE protocol='modbus_rtu' ORDER BY name");
$source_profiles=db_fetch_assoc('SELECT id,name,step,heartbeat FROM data_source_profiles ORDER BY step,name');
$node_options=nms_nodes_list();
$pollers=db_fetch_assoc("SELECT id,name FROM poller WHERE disabled='' ORDER BY id");
if($host_id) $pollers=array_values(array_filter($pollers,function($poller) use($host){ return (int)$poller['id']===(int)$host['poller_id']; }));
$sites=db_fetch_assoc('SELECT id,name FROM sites ORDER BY name');
$associated_graphs=$host_id?db_fetch_assoc_prepared('SELECT gt.id,gt.name,(SELECT COUNT(*) FROM graph_local gl WHERE gl.host_id=hg.host_id AND gl.graph_template_id=gt.id) AS graph_count FROM host_graph hg JOIN graph_templates gt ON gt.id=hg.graph_template_id WHERE hg.host_id=?',[$host_id]):[];
$associated_queries=$host_id?db_fetch_assoc_prepared('SELECT sq.id,sq.name,hq.reindex_method FROM host_snmp_query hq JOIN snmp_query sq ON sq.id=hq.snmp_query_id WHERE hq.host_id=?',[$host_id]):[];
$last_test=$host_id?db_fetch_row_prepared("SELECT id,field_key,status,result_json,requested_at,finished_at FROM plugin_nms_config_jobs WHERE host_id=? AND operation='read' ORDER BY id DESC LIMIT 1",[$host_id]):[];
$wizard_connections=nms_serial_connections($host_id?(int)$host['poller_id']:null);
nms_prepare_page('devices','NMS · Serial device','css/nms-devices.css,css/nms-snmp-form.css,css/nms-nodes.css,css/nms-serial-wizard.css','js/nms-devices.js,js/nms-serial-wizard.js');
require __DIR__.'/../../templates/app_header.php';
require __DIR__.'/../../templates/devices/serial_wizard.php';
require __DIR__.'/../../templates/app_footer.php';
