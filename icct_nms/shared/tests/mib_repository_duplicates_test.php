<?php
require __DIR__.'/../../protocols/snmp/mibs/services/mib_repository_duplicates_service.php';
function icct_mib_list(){return $GLOBALS['saved'];}
function icct_mib_objects($bundle){return $bundle['objects'];}
function icct_mib_file($bundle,$index){return 'saved bytes';}
function icct_mib_preview($upload,$type,$allow){foreach($GLOBALS['saved'] as $bundle)if($bundle['files'][0]['name']===$upload['name'][0])return ['records'=>$bundle['objects'],'parse_error'=>$bundle['parse_error']];throw new RuntimeException('Unknown saved fixture');}
function check($value,$message){if(!$value)throw new RuntimeException($message);echo 'PASS: '.$message."\n";}
function object($module,$oid,$description='Reading'){return ['symbol'=>$module.'::reading','base_oid'=>$oid,'kind'=>'OBJECT-TYPE','syntax'=>'INTEGER','access'=>'read-only','description'=>$description];}
function preview($module,$content,$records,$filename='different-name.txt'){return ['files'=>[['module'=>$module,'name'=>$filename,'content'=>$content,'sha256'=>hash('sha256',$content)]],'records'=>$records,'parse_error'=>''];}
$original=preview('QA-MIB','original bytes',[object('QA-MIB','1.3.6.1.4.1.999.1')]);
$saved=[['id'=>'fixture','files'=>[['module'=>'QA-MIB','name'=>'original.mib','sha256'=>hash('sha256','original bytes')]],'objects'=>$original['records'],'parse_error'=>'']];
check(icct_mib_repository_comparison($original)['blocked'],'Identical content under a renamed file is blocked');
$variant=preview('QA-MIB','changed bytes',[object('QA-MIB','1.3.6.1.4.1.999.1','Changed description'),object('QA-MIB','1.3.6.1.4.1.999.2')]);
$comparison=icct_mib_repository_comparison($variant);$match=$comparison['conflicts'][0];
check($comparison['blocked']&&$match['same_module']&&$match['shared_oids']===1&&$match['added_oids']===1&&$match['changed_oids']===1,'Same-module revisions are blocked and retain their OID comparison details');
try{icct_mib_repository_require_acknowledgment($comparison,'');throw new RuntimeException('Unacknowledged variant accepted');}catch(IcctMibRepositoryConflict $e){check(true,'Same-module revision is rejected before saving');}
try{icct_mib_repository_require_acknowledgment($comparison,$comparison['fingerprint']);throw new RuntimeException('Acknowledgment bypassed module uniqueness');}catch(IcctMibRepositoryConflict $e){check(true,'Acknowledgment cannot bypass the one-entry module rule');}
$other=preview('OTHER-MIB','unrelated bytes',[object('OTHER-MIB','1.3.6.1.4.1.888.1')],'original.mib');
check(icct_mib_repository_comparison($other)['blocked'],'A reused filename is blocked even if its declared module differs');
$other['files'][0]['name']='unique.mib';check(!icct_mib_repository_comparison($other)['blocked'],'Unrelated modules, content, filenames and OIDs are accepted');
$overlap=preview('OTHER-MIB','overlap bytes',[object('OTHER-MIB','1.3.6.1.4.1.999.1')]);
check(icct_mib_repository_comparison($overlap)['warnings'][0]['type']==='oid_overlap','Different modules with overlapping OIDs are reported');
$overlapComparison=icct_mib_repository_comparison($overlap);icct_mib_repository_require_acknowledgment($overlapComparison,$overlapComparison['fingerprint']);
$saved[0]['objects'][]=object('QA-MIB','1.3.6.1.4.1.999.3');
$newComparison=icct_mib_repository_comparison($variant);
check($newComparison['conflicts'][0]['removed_oids']===1,'Removed OIDs are counted');
try{icct_mib_repository_require_acknowledgment(icct_mib_repository_comparison($overlap),$overlapComparison['fingerprint']);throw new RuntimeException('Stale acknowledgment accepted');}catch(IcctMibRepositoryConflict $e){check(true,'Changed repository comparison invalidates an earlier acknowledgment');}
$variant['parse_error']='Missing dependencies';$variant['records']=[];$unknown=icct_mib_repository_comparison($variant)['conflicts'][0];
check(!$unknown['oid_comparison_complete']&&$unknown['shared_oids']===null,'Unresolved OID comparisons are marked unavailable rather than guessed');
$saved=[];check(!icct_mib_repository_comparison($original)['blocked'],'Removed entries are excluded from the active repository policy');
