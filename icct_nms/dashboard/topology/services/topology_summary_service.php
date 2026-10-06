<?php
/** Read existing, authorized graph samples only; never poll devices from a web request. */
function icct_nms_topology_capacity_spec($name, $units) {
    $name=strtolower(preg_replace('/[^a-z0-9]+/i',' ', $name));
    $units=strtolower(trim($units));
    if (preg_match('/switching capacity|switch capacity|switch fabric capacity/', $name)) {
        $factor=['tbps'=>1,'gbps'=>0.001,'mbps'=>0.000001,'bps'=>1e-12,'bits/sec'=>1e-12][$units] ?? null;
        return $factor===null?null:['Switching Capacity (Tbps)',$factor];
    }
    if (preg_match('/forwarding rate/', $name)) {
        $factor=['bpps'=>1,'gpps'=>1,'mpps'=>0.001,'kpps'=>0.000001,'pps'=>1e-9,'packets/sec'=>1e-9][$units] ?? null;
        return $factor===null?null:['Forwarding Rate (Bpps)',$factor];
    }
    if (str_contains($name,'hardware redundancy') && in_array($units,['w','watts'],true)) return ['Hardware Redundancy (W)',1];
    if (preg_match('/table scale|tcam capacity|fib capacity/', $name) && in_array($units,['entries','count',''],true)) return ['Table Scale (TCAM / FIB)',1];
    return null;
}
function icct_nms_topology_capacity($host) {
    global $config;
    $result=array_fill_keys(['Switching Capacity (Tbps)','Forwarding Rate (Bpps)','Hardware Redundancy (W)','Table Scale (TCAM / FIB)'],null);
    if (($host['disabled']??'')==='on') return $result;
    $graphs=icct_nms_device_graphs((int)$host['id']);
    $allowed=array_map('intval',array_column($graphs,'local_graph_id'));
    if (!$allowed) return $result;
    $rows=db_fetch_assoc_prepared('SELECT DISTINCT gl.id,gtg.title_cache,gtg.vertical_label,dtr.local_data_id,dtr.data_source_name FROM graph_local gl JOIN graph_templates_graph gtg ON gtg.local_graph_id=gl.id JOIN graph_templates_item gti ON gti.local_graph_id=gl.id JOIN data_template_rrd dtr ON dtr.id=gti.task_item_id JOIN data_local dl ON dl.id=dtr.local_data_id AND dl.host_id=gl.host_id WHERE gl.host_id=? AND gl.id IN ('.implode(',',$allowed).') AND gti.graph_type_id IN (4,5,6,7)',[(int)$host['id']]);
    $candidates=[];
    foreach ($rows as $row) {
        $spec=icct_nms_topology_capacity_spec($row['title_cache'], $row['vertical_label']);
        if ($spec) $candidates[$spec[0]][$row['local_data_id'].':'.$row['data_source_name']]=[$row,$spec[1]];
    }
    require_once $config['base_path'].'/lib/rrd.php';
    $now=time();$interval=max(60,(int)read_config_option('poller_interval'));$cache=[];
    foreach ($candidates as $label=>$sources) {
        // Several sources cannot safely be represented as one capacity value.
        if (count($sources)!==1) continue;
        [$row,$factor]=array_values($sources)[0];
        try {
            $key=(int)$row['local_data_id'];
            if (!isset($cache[$key])) $cache[$key]=rrdtool_function_fetch($key,$now-3*$interval,$now,$interval,true,null,'AVERAGE');
            $fetch=$cache[$key];$column=array_search($row['data_source_name'],$fetch['data_source_names']??[],true);
            $points=$column===false?[]:($fetch['values'][$column]??[]);krsort($points,SORT_NUMERIC);
            foreach ($points as $timestamp=>$value) {
                if ((int)$timestamp>$now) continue;
                if ($now-(int)$timestamp<=2*$interval && is_numeric($value) && is_finite((float)$value) && $value>=0) $result[$label]=(float)$value*$factor;
                break;
            }
        } catch (Throwable $error) { /* Missing or unreadable RRD stays unavailable. */ }
    }
    return $result;
}
