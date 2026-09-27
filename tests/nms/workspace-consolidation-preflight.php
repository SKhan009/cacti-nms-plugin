<?php
require __DIR__.'/../../plugins/nms/includes/workspace/consolidation_preflight.php';
$count=0;function check($ok,$message){global $count;if(!$ok)throw new RuntimeException($message);$count++;echo "PASS: $message\n";}
$keep=['id'=>1,'poller_id'=>1,'disabled'=>'on'];$source=['id'=>2,'poller_id'=>1,'disabled'=>'on'];
$graphs=[['id'=>10,'snmp_query_id'=>0]];$data=[['id'=>20,'snmp_query_id'=>0,'data_source_path'=>'<path_rra>/fixture.rrd']];$links=[['graph_id'=>10,'graph_host_id'=>2,'data_id'=>20,'data_host_id'=>2]];
function plan($k=[],$s=[],$g=null,$d=null,$l=null){global $keep,$source,$graphs,$data,$links;return nms_workspace_consolidation_preflight(array_replace($keep,$k),array_replace($source,$s),$g??$graphs,$d??$data,$l??$links);}
$p=plan();check($p['status']==='structurally_eligible'&&count($p['remaining_checks'])===3,'Regular local assets require further RRD/permission/reference checks');
check(isset(plan([],['id'=>1])['blockers']['invalid_pair']),'Same-record transfer blocked');
check(isset(plan([],['poller_id'=>2])['blockers']['different_collectors']),'Cross-collector transfer blocked');
check(isset(plan(['poller_id'=>2],['poller_id'=>2])['blockers']['remote_collector']),'Unverified remote transfer blocked');
check(isset(plan(['disabled'=>''])['blockers']['polling_enabled']),'Active destination polling blocks transfer');
check(isset(plan([],['disabled'=>''])['blockers']['polling_enabled']),'Active source polling blocks transfer');
check(isset(plan([],[],[['id'=>10,'snmp_query_id'=>1]])['blockers']['query_graph']),'Native unsupported query graph blocked');
check(isset(plan([],[],null,[array_replace($data[0],['snmp_query_id'=>1])])['blockers']['query_data_source']),'Query data source requires index mapping');
check(isset(plan([],[],null,[array_replace($data[0],['data_source_path'=>''])])['blockers']['missing_rrd_path']),'Unknown RRD storage blocks transfer');
check(isset(plan([],[],null,null,[array_replace($links[0],['data_host_id'=>3])])['blockers']['foreign_data_source']),'Source graph cannot silently move foreign data');
check(isset(plan([],[],null,null,[array_replace($links[0],['graph_host_id'=>1])])['blockers']['shared_data_source']),'Data used by destination graph is still a shared dependency');
check(isset(plan([],[],null,null,[array_replace($links[0],['graph_host_id'=>3])])['blockers']['shared_data_source']),'External graph reference blocks transfer without exposing identity');
check(isset(plan([],[],null,null,[array_replace($links[0],['graph_id'=>11])])['blockers']['changed_graph_inventory']),'Changed graph inventory blocked');
check(isset(plan([],[],null,null,[array_replace($links[0],['data_id'=>21])])['blockers']['changed_data_inventory']),'Changed data inventory blocked');
check(isset(plan([],[],[],[],[])['blockers']['no_assets']),'Empty transfer blocked');
check(plan([],[],[],$data,[])['data_ids']===[20],'Standalone regular data source remains inventoried');
echo "$count structural preflight assertions passed.\n";
