<?php
/** Explicit QA-only native graph provisioning; temporary equipment maps, native objects removed in finally. */
require __DIR__.'/serial-profiles-integration.php';
require __DIR__.'/../../plugins/nms/includes/configuration/graphs.php';
$host_id=(int)$hosts[0]['id'];
// Regression: the reader may run faster than Cacti without selecting an unpollable RRD.
nms_category_execute('UPDATE plugin_nms_config_devices SET interval_seconds=60 WHERE host_id=?',[$host_id]);
$profile=nms_config_graph_profile(db_fetch_assoc('SELECT id,step,heartbeat,`default` FROM data_source_profiles'),60,nms_poller_interval());
check((bool)$profile,'No suitable native collection profile');
$identity=$model_id.':1:limit:'.$profile['id'];
$input_hash=md5('nms:equipment:v1:input:'.$identity);
$template_hash=md5('nms:equipment:v1:template:'.$identity);
check(!db_fetch_cell_prepared('SELECT id FROM data_input WHERE hash=?',[$input_hash]),'Fixture identity already exists; refusing to alter it');
$graphs=[]; $rrd_path=null;
try {
    $graphs=nms_config_graphs_create($host_id);
    check(count($graphs)===1,'Expected one numeric graph');
    $graph_id=(int)reset($graphs);
    $line=db_fetch_row_prepared('SELECT id,color_id,graph_template_id FROM graph_templates_item WHERE local_graph_id=? AND graph_type_id=4',[$graph_id]);
    check((int)$line['color_id']>0,'Automatic equipment graph has no visible line color');
    nms_category_execute('UPDATE graph_templates_item SET color_id=0 WHERE id=?',[$line['id']]);
    check(nms_config_graphs_create($host_id)===$graphs,'Repeat provisioning duplicated graphs');
    check((int)db_fetch_cell_prepared('SELECT color_id FROM graph_templates_item WHERE id=?',[$line['id']])>0,'Legacy colorless line was not repaired');
    $rows=db_fetch_assoc_prepared('SELECT pi.local_data_id,pi.arg1,pi.host_id FROM poller_item pi JOIN data_template_data d ON d.local_data_id=pi.local_data_id JOIN data_input i ON i.id=d.data_input_id WHERE i.hash=?',[$input_hash]);
    check(count($rows)===1,'Expected a native poller item for the graph');
    check((int)$rows[0]['host_id']===$host_id,'Native poller item has wrong device');
    check(strpos($rows[0]['arg1'],'serial_value.php')!==false,'Native poller item does not call equipment input');
    check(strpos($rows[0]['arg1'],' '.escapeshellarg((string)$host_id).' limit '.$model_id.' 1')!==false,'Native input did not bind device and equipment revision: '.$rows[0]['arg1']);
    echo "PASS: native Cacti graph/data template, poller item, device and model binding, repeat provisioning without duplicates\n";
    // Exercise Cacti's actual RRD creation/update/render path using explicitly labelled fixture data.
    require_once $config['base_path'].'/lib/rrd.php';
    $data_id=(int)$rows[0]['local_data_id'];
    $candidate=get_data_source_path($data_id,true);
    check($candidate && !file_exists($candidate),'Refusing to replace an existing RRD');
    $rrd_path=$candidate;
    $target=nms_config_target($host_id);
    nms_config_store_reading($target,'limit',['status'=>'read','value'=>17]);
    $value=nms_config_graph_value($host_id,'limit',$hosts[0]['poller_id'],$model_id,1);
    check($value==='17','Bound input did not supply fixture sample');
    $step=(int)$profile['step']; $end=(int)(floor(time()/$step)*$step);
    rrdtool_function_update([$rrd_path=>['local_data_id'=>$data_id,'times'=>[
        $end-3*$step=>['value'=>$value],$end-2*$step=>['value'=>$value],$end-$step=>['value'=>$value]
    ]]]);
    check(file_exists($rrd_path),'Cacti did not create the native RRD');
    $fetch=rrdtool_execute('fetch '.escapeshellarg($rrd_path).' AVERAGE --start '.($end-3*$step).' --end '.($end-$step).' --resolution '.$step,false,RRDTOOL_OUTPUT_STDOUT);
    check((bool)preg_match('/1\.7000[0-9]*e\+0?1/i',$fetch),'RRD did not retain the fixture value: '.$fetch);
    nms_category_execute('UPDATE plugin_nms_serial_readings SET observed_at=DATE_SUB(NOW(),INTERVAL 20 MINUTE) WHERE host_id=?',[$host_id]);
    $unknown=nms_config_graph_value($host_id,'limit',$hosts[0]['poller_id'],$model_id,1);
    check($unknown==='U','Stale input was not unknown');
    rrdtool_function_update([$rrd_path=>['local_data_id'=>$data_id,'times'=>[$end=>['value'=>$unknown]]]]);
    $last=rrdtool_execute('lastupdate '.escapeshellarg($rrd_path),false,RRDTOOL_OUTPUT_STDOUT);
    check((bool)preg_match('/\bU\s*$/',$last),'Native RRD replaced unknown with a number: '.$last);
    $png=rrdtool_function_graph($graphs['limit'],0,['graph_start'=>$end-4*$step,'graph_end'=>$end]);
    $is_png=is_string($png) && substr($png,0,8)==="\x89PNG\r\n\x1a\n";
    $svg=is_string($png) && strpos($png,'<svg ')!==false ? simplexml_load_string($png) : false;
    check($is_png || ($svg && $svg->getName()==='svg' && isset($svg['viewBox'])),'Native graph did not render a valid image');
    echo "PASS: native Cacti RRD creation/update/fetch/render, model-bound fixture value 17 and stale U sample; no real equipment\n";

} finally {
    if($rrd_path && is_file($rrd_path)) unlink($rrd_path);
    $template=(int)db_fetch_cell_prepared('SELECT id FROM data_template WHERE hash=?',[$template_hash]);
    $input=(int)db_fetch_cell_prepared('SELECT id FROM data_input WHERE hash=?',[$input_hash]);
    if($template) {
        $sources=db_fetch_assoc_prepared('SELECT id FROM data_local WHERE data_template_id=?',[$template]);
        $templates=db_fetch_assoc_prepared('SELECT DISTINCT i.graph_template_id FROM graph_templates_item i JOIN data_template_rrd r ON r.id=i.task_item_id WHERE r.data_template_id=?',[$template]);
        foreach($templates as $gt) {
            foreach(db_fetch_assoc_prepared('SELECT id FROM graph_local WHERE graph_template_id=?',[$gt['graph_template_id']]) as $graph) { api_graph_remove($graph['id']); nms_managed_object_forget('graph',$graph['id']); }
            nms_graph_template_delete($gt['graph_template_id']);
        }
        foreach($sources as $source) { api_data_source_remove($source['id']); nms_managed_object_forget('data_source',$source['id']); }
        foreach(db_fetch_assoc_prepared('SELECT id FROM data_template_data WHERE data_template_id=?',[$template]) as $data) nms_category_execute('DELETE FROM data_input_data WHERE data_template_data_id=?',[$data['id']]);
        foreach(['data_template_data','data_template_rrd'] as $table) nms_category_execute("DELETE FROM $table WHERE data_template_id=?",[$template]);
        nms_category_execute('DELETE FROM data_template WHERE id=?',[$template]); nms_managed_object_forget('data_template',$template);
    }
    if($input) { api_data_input_remove($input); nms_managed_object_forget('data_input',$input); }
}
check(!db_fetch_cell_prepared('SELECT id FROM data_input WHERE hash=?',[$input_hash]),'Fixture input was not removed');
check(!db_fetch_cell_prepared('SELECT id FROM data_template WHERE hash=?',[$template_hash]),'Fixture template was not removed');
check(!$rrd_path || !file_exists($rrd_path),'Fixture RRD was not removed');
echo "PASS: native graph fixture input, template and RRD cleanup\n";
