<?php
require __DIR__.'/../../plugins/nms/includes/workspace/consolidation_rrd.php';
$count=0;function check($ok,$message){global $count;if(!$ok)throw new RuntimeException($message);$count++;echo "PASS: $message\n";}
function rejects($fn,$message){try{$fn();}catch(RuntimeException $e){check(true,$message);return;}throw new RuntimeException('Unexpected acceptance: '.$message);}
$root=sys_get_temp_dir().'/nms-rrd-proof-'.bin2hex(random_bytes(8));mkdir($root,0700);mkdir($root.'/rra',0700);
try {
 file_put_contents($root.'/rra/sample.rrd',"RRD\0fixture-history");$data=[['id'=>1,'data_source_path'=>'<path_rra>/sample.rrd']];$rra=$root.'/rra';
 $proof=nms_workspace_consolidation_rrd_manifest($data,$rra);
 check($proof[1]['sha256']===hash_file('sha256',$rra.'/sample.rrd'),'Exact fixture content hashed');
 check(nms_workspace_consolidation_rrd_verify($proof,$data,$rra)===$proof,'Unchanged files and metadata verify');
 check(count(nms_workspace_consolidation_rrd_manifest(array_merge($data,$data),$rra))===1,'Repeated metadata for same data source deduplicates');
 rejects(fn()=>nms_workspace_consolidation_rrd_manifest([['id'=>1,'data_source_path'=>'missing.rrd']],$rra),'Missing file rejects');
 rejects(fn()=>nms_workspace_consolidation_rrd_manifest($data,$rra,4),'Aggregate size bound enforced');
 file_put_contents($root.'/outside.rrd',"RRD\0outside");symlink($root.'/outside.rrd',$rra.'/escape.rrd');
 rejects(fn()=>nms_workspace_consolidation_rrd_manifest([['id'=>1,'data_source_path'=>'<path_rra>/escape.rrd']],$rra),'Symlink outside collector RRD directory rejects');
 rejects(fn()=>nms_workspace_consolidation_rrd_manifest([['id'=>1,'data_source_path'=>'<path_rra>/../outside.rrd']],$rra),'Traversal outside configured directory rejects');
 rejects(fn()=>nms_workspace_consolidation_rrd_manifest(array_merge($data,[['id'=>2,'data_source_path'=>'sample.rrd']]),$rra),'Shared RRD across different data sources rejects');
 rejects(fn()=>nms_workspace_consolidation_rrd_manifest(array_merge($data,[['id'=>1,'data_source_path'=>'different.rrd']]),$rra),'Conflicting path metadata rejects');
 file_put_contents($rra.'/sample.rrd',"RRD\0changed-history");
 rejects(fn()=>nms_workspace_consolidation_rrd_verify($proof,$data,$rra),'Changed historical bytes reject');
 if(function_exists('posix_mkfifo')) {posix_mkfifo($rra.'/pipe.rrd',0600);rejects(fn()=>nms_workspace_consolidation_rrd_manifest([['id'=>1,'data_source_path'=>'pipe.rrd']],$rra),'Named pipe rejected before blocking open');}
 file_put_contents($rra.'/sample.rrd','not an RRD');
 rejects(fn()=>nms_workspace_consolidation_rrd_manifest($data,$rra),'Non-RRD header rejects');
 rejects(fn()=>nms_workspace_consolidation_rrd_manifest($data,$rra,1,16),'Unbounded deadline rejects');
}finally{foreach(glob($root.'/rra/*') as $file)unlink($file);rmdir($root.'/rra');if(is_file($root.'/outside.rrd'))unlink($root.'/outside.rrd');rmdir($root);}
echo "$count filesystem assertions passed; synthetic file contents, not RRD semantic validation.\n";
