<?php
/** Read-only rendering and scope regression for the guided setup. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$o=getopt('',array('cacti-root:'));if(empty($o['cacti-root']))exit(2);
require($o['cacti-root'].'/include/cli_check.php');require_once(dirname(__DIR__, 2).'/plugins/topology/includes/ui.php');require_once(dirname(__DIR__, 2).'/plugins/topology/includes/flow.php');
try {
    $_SESSION=array('sess_user_id'=>(int)read_config_option('admin_user'));
    $_SERVER['SCRIPT_NAME']='/cacti/plugins/topology/topo_start.php';$_SERVER['REQUEST_METHOD']='GET';$_POST=array();
    $unit=tp_units()[0]??null;if(!$unit)throw new RuntimeException('A configured test node is required.');
    foreach(tp_flow_steps() as $step=>$label){
        $_GET=array('step'=>$step,'flow_unit'=>$unit['id'],'flow_site'=>$unit['site_id']);
        ob_start();tp_flow_page();$html=ob_get_clean();
        if(!str_contains($html,'Step '.$step.' of '.count(tp_flow_steps()))||substr_count($html,'aria-current="step"')!==1)throw new RuntimeException('Step rendering failed: '.$label);
        if(stripos($html,'vehicle')!==false)throw new RuntimeException('Legacy terminology remains.');
        print 'PASS: '.$step.'. '.$label." renders with one current step\n";
    }
    if(tp_flow_steps()!==array(1=>'Device Categories',2=>'Port Profiles',3=>'Assign Devices'))throw new RuntimeException('Unexpected steps.');
    $_GET=array('step'=>3);ob_start();tp_flow_page();$html=ob_get_clean();
    foreach(array('Select a node','Reference Cacti Device','Equipment Categories','flow_site') as $old)if(str_contains($html,$old))throw new RuntimeException('Legacy input remains: '.$old);
    if(!str_contains($html,'host_ids[]')||!str_contains($html,'Apply to Selected Devices'))throw new RuntimeException('Missing bulk assignment.');
    print "PASS: assignment is visible without Site or Node prerequisite\nFLOW CHECKS COMPLETE\n";
}catch(Throwable $e){if(ob_get_level())ob_end_clean();fwrite(STDERR,$e->getMessage()."\n");exit(1);}
