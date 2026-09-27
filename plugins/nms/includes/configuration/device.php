<?php
/** Create a native serial device with a separate reader endpoint. */
require_once __DIR__.'/equipment.php';
require_once $config['base_path'].'/plugins/nms/includes/device_manager.php';
require_once $config['base_path'].'/plugins/nms/includes/nodes/service.php';

function nms_serial_device_create(array $input)
{
    global $fields_host_edit;
    nms_require_management();
    foreach(['short_name'=>'nms_short_name_validate','manual_serial_number'=>'nms_manual_serial_validate'] as $key=>$validator) if(array_key_exists($key,$input)) $input[$key]=$validator($input[$key]);
    if(array_key_exists('hostname',$input)) $input['hostname']=nms_config_text($input['hostname'],255,'Hostname / identifier',false);
    $name=nms_config_text($input['description'] ?? '',150,'Device name');
    $site=nms_config_integer($input['site_id'] ?? 0,0,2147483647,'Site');
    $address=nms_serial_device_address($input['device_address'] ?? '');
    $node_lock=nms_nodes_lock();
    try {
        $node=nms_node_assignment_validate($input['node_id'] ?? 0,$site);
        return nms_serial_mutation(function() use($input,$name,$site,$address,$node,$fields_host_edit) {
            $connection_id=nms_config_integer($input['connection_id'] ?? 0,0,2147483647,'Connection');
            if(!$connection_id) {
                $profile=nms_serial_profile_get($input['profile_id'] ?? 0);
                $connection_id=nms_serial_connection_insert(array_replace($input,[
                    'name'=>nms_config_text($input['connection_name'] ?? '',150,'Connection name',false) ?: $name,'profile_revision'=>$input['profile_revision'] ?? $profile['revision']
                ]));
            }
            $connection=nms_serial_connection_get($connection_id);
            if(!$connection['enabled']) throw new InvalidArgumentException('Select an enabled connection.');
            if(!db_fetch_cell_prepared("SELECT id FROM poller WHERE id=? AND disabled=''",[$connection['poller_id']])) throw new InvalidArgumentException('Connection collector is disabled.');
            if(db_fetch_cell_prepared('SELECT host_id FROM plugin_nms_serial_devices WHERE connection_id=? AND device_address=?',[$connection['id'],$address])) throw new InvalidArgumentException('This address is already assigned on the serial bus.');
            $defaults=[];
            foreach($fields_host_edit as $key=>$field) if(array_key_exists('default',$field)) $defaults[$key]=$field['default'];
            $values=array_replace($defaults,['description'=>$name,'hostname'=>($input['hostname'] ?? '') ?: $connection['endpoint'],'host_template_id'=>$input['host_template_id'] ?? 0,'site_id'=>$site,'poller_id'=>(int)$connection['poller_id'],'snmp_version'=>0,'snmp_community'=>'','snmp_username'=>'','snmp_password'=>'','snmp_auth_protocol'=>'[None]','snmp_priv_protocol'=>'[None]','snmp_priv_passphrase'=>'','snmp_context'=>'','snmp_engine_id'=>'','availability_method'=>0,'proxy'=>true,'disabled'=>!empty($input['disabled']),'notes'=>nms_config_text($input['notes'] ?? '',512,'Notes',false),'location'=>$input['location'] ?? '','external_id'=>$input['external_id'] ?? '', 'device_threads'=>$input['device_threads'] ?? 1]);
            // Core owns the record; the collector always reads the separate connection endpoint.
            $id=nms_device_save(0,$values);
            nms_category_execute('INSERT INTO plugin_nms_serial_devices(host_id,connection_id,device_address,revision,updated_by,updated_at) VALUES (?,?,?,1,?,NOW())',[$id,$connection['id'],$address,nms_current_user_id()]);
            if(array_key_exists('short_name',$input)) nms_short_name_save($id,$input['short_name']);
            if(array_key_exists('manual_serial_number',$input)) nms_manual_serial_save($id,$input['manual_serial_number']);
            nms_node_assign_device($id,$node);
            nms_equipment_assign_locked($id,[
                'profile_id'=>$input['equipment_profile_id'] ?? 0,'revision'=>0,
                'interval_seconds'=>$input['interval_seconds'] ?? 300
            ]);
            if(array_key_exists('device_type',$input)) nms_device_classification_save($id,$input['equipment_category_id'] ?? 0,$input['device_type'],'');
            return $id;
        });
    } finally { nms_nodes_unlock($node_lock); }
}

/** Save the serial editor as one transaction, preserving unrelated core settings. */
function nms_serial_device_update($id,array $input)
{
    nms_require_management();
    nms_require_device_access($id);
    foreach(['short_name'=>'nms_short_name_validate','manual_serial_number'=>'nms_manual_serial_validate'] as $key=>$validator) if(array_key_exists($key,$input)) $input[$key]=$validator($input[$key]);
    if(array_key_exists('hostname',$input)) $input['hostname']=nms_config_text($input['hostname'],255,'Hostname / identifier',false);
    $lock=nms_nodes_lock();
    try {
        return nms_serial_mutation(function() use($id,$input) {
            $host=db_fetch_row_prepared("SELECT * FROM host WHERE id=? AND deleted='' FOR UPDATE",[$id]);
            if(!$host || !nms_serial_assignment($id)) throw new InvalidArgumentException('Serial device not found.');
            $connection_id=nms_config_integer($input['connection_id'] ?? 0,0,2147483647,'Connection');
            if(!$connection_id) {
                $profile=nms_serial_profile_get($input['profile_id'] ?? 0);
                $connection_id=nms_serial_connection_insert(array_replace($input,['name'=>nms_config_text($input['connection_name'] ?? '',150,'Connection name',false) ?: ($input['description'] ?? ''), 'poller_id'=>$host['poller_id'],'profile_revision'=>$input['profile_revision'] ?? $profile['revision']]));
            }
            $connection=nms_serial_connection_get($connection_id);
            $site=nms_config_integer($input['site_id'] ?? 0,0,2147483647,'Site');
            $current_node=(int)db_fetch_cell_prepared('SELECT node_id FROM plugin_nms_node_devices WHERE host_id=?',[$id]);
            $node=nms_node_assignment_validate($input['node_id'] ?? $current_node,$site);
            nms_serial_assign_locked($id,array_replace($input,['connection_id'=>$connection_id]));
            nms_equipment_assign_locked($id,['profile_id'=>$input['equipment_profile_id'] ?? 0,'revision'=>$input['equipment_revision'] ?? 0,'interval_seconds'=>$input['interval_seconds'] ?? 300]);
            nms_device_save($id,array_replace($host,[
                'description'=>nms_config_text($input['description'] ?? '',150,'Device name'),
                'hostname'=>array_key_exists('hostname',$input)?($input['hostname'] ?: $connection['endpoint']):$host['hostname'],'site_id'=>$site,
                'notes'=>nms_config_text($input['notes'] ?? '',512,'Notes',false),
                'host_template_id'=>$input['host_template_id'] ?? $host['host_template_id'],
                'device_threads'=>$input['device_threads'] ?? $host['device_threads'],
                'location'=>$input['location'] ?? $host['location'],'external_id'=>$input['external_id'] ?? $host['external_id'],
                'proxy'=>true,'disabled'=>array_key_exists('core_fields',$input)?!empty($input['disabled']):$host['disabled']!==''
            ]));
            if(array_key_exists('short_name',$input)) nms_short_name_save($id,$input['short_name']);
            if(array_key_exists('manual_serial_number',$input)) nms_manual_serial_save($id,$input['manual_serial_number']);
            nms_node_assign_device($id,$node);
            if(array_key_exists('device_type',$input)) nms_device_classification_save($id,$input['equipment_category_id'] ?? 0,$input['device_type'],'');
            return $id;
        });
    } finally { nms_nodes_unlock($lock); }
}
