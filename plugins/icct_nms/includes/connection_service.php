<?php
/** Connection styling follows the existing NMS topology line and endpoint choices. */
function icct_nms_connection_styles() { return ['solid'=>'','dashed'=>'9 5','dotted'=>'2 5','dash-dot'=>'10 4 2 4','fine-dotted'=>'1 3','short-dashed'=>'4 4']; }
function icct_nms_connections() {
    $json=db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['connection_profiles']);
    if ($json!==false && $json!==null && $json!=='') {
        $profiles=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($profiles)) throw new RuntimeException('Invalid connection catalogue.');
        return $profiles;
    }
    $profiles=[];
    foreach ([['Ethernet','#00bfae','dotted','circle'],['Fiber','#7050ff','dotted','circle'],['Line-of-Sight (LOS) Link','#00b7d8','dash-dot','circle'],['Logical','#c830dd','dash-dot','circle'],['Optical Fiber Link','#ff7e99','dash-dot','arrow'],['VSAT, Leased Line Link','#ba9b80','fine-dotted','arrow']] as $row) {
        $profiles[substr(sha1($row[0]),0,16)]=array_combine(['name','color','line_style','symbol'],$row);
    }
    return $profiles;
}
function icct_nms_connection_preview($profile) {
    $color=preg_match('/^#[a-f0-9]{6}$/iD',$profile['color'] ?? '') ? $profile['color'] : '#64748b';
    $dash=icct_nms_connection_styles()[$profile['line_style'] ?? ''] ?? '';
    $symbol=$profile['symbol'] ?? 'none';
    $svg='<svg class="connection-preview" viewBox="0 0 120 16" role="img" aria-label="'.icct_nms_h(($profile['name'] ?? 'Connection').' line preview').'" style="color:'.$color.'"><path d="M8 8h104" fill="none" stroke="currentColor" stroke-width="1.5" stroke-dasharray="'.$dash.'"/>';
    if ($symbol==='circle') $svg.='<circle cx="8" cy="8" r="4" fill="currentColor"/><circle cx="112" cy="8" r="4" fill="currentColor"/>';
    elseif ($symbol==='square') $svg.='<path d="M4 4h8v8H4zM108 4h8v8h-8z" fill="currentColor"/>';
    elseif ($symbol==='arrow') $svg.='<path d="m12 5-4 3 4 3M108 5l4 3-4 3" fill="none" stroke="currentColor" stroke-width="1.5"/>';
    return $svg.'</svg>';
}
function icct_nms_save_connection($input) {
    $lock='icct_connections_'.substr(sha1((string)db_fetch_cell('SELECT DATABASE()')),0,20);
    if ((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,5)',[$lock])!==1) throw new RuntimeException('Network connections are busy. Please retry.');
    try {
        $profiles=icct_nms_connections(); $id=$input['connection_id'] ?? '';
        if (!is_string($id) || ($id!=='' && !isset($profiles[$id]))) throw new InvalidArgumentException('Choose a saved network connection.');
        $old=$profiles[$id] ?? null;
        if (($input['action'] ?? '')==='delete_connection') {
            if (!$old) throw new InvalidArgumentException('Choose a saved network connection.');
            unset($profiles[$id]);
        } else {
            $name=icct_backend_classification_text($input['connection_name'] ?? '',150);
            if ($name==='') throw new InvalidArgumentException('Enter a network connection name.');
            foreach ($profiles as $key=>$profile) if ($key!==$id && strcasecmp($profile['name'],$name)===0) throw new InvalidArgumentException('A network connection with this name already exists.');
            $color=$input['color'] ?? ''; $style=$input['line_style'] ?? ''; $symbol=$input['symbol'] ?? '';
            if (!is_string($color) || !preg_match('/^#[a-f0-9]{6}$/iD',$color)) throw new InvalidArgumentException('Choose a valid six-digit color.');
            if (!is_string($style) || !array_key_exists($style,icct_nms_connection_styles())) throw new InvalidArgumentException('Choose a valid line style.');
            if (!in_array($symbol,['none','circle','square','arrow'],true)) throw new InvalidArgumentException('Choose a valid endpoint symbol.');
            $id=$id ?: bin2hex(random_bytes(8));
            $profiles[$id]=['name'=>$name,'color'=>strtolower($color),'line_style'=>$style,'symbol'=>$symbol];
        }
        $json=json_encode($profiles,JSON_THROW_ON_ERROR);
        if (strlen($json)>60000) throw new InvalidArgumentException('The network connection catalogue is full.');
        icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['connection_profiles',$json]);
        return ($input['action'] ?? '')==='delete_connection' ? 'Network connection deleted.' : ($old ? 'Network connection updated.' : 'Network connection added.');
    } finally { db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]); }
}
