<?php
require __DIR__.'/../../plugins/nms/includes/topology/connections.php';
function check($ok,$message) { if (!$ok) throw new RuntimeException($message); }
check(nms_connection_fingerprint('3:1:2','4:1:3')===nms_connection_fingerprint('4:1:3','3:1:2'),'Reverse duplicate');
check(nms_connection_fingerprint('3:1:2','4:1:3')!==nms_connection_fingerprint('3:1:4','4:1:3'),'Parallel ports');
foreach (['solid','dashed','dotted'] as $line) foreach (['none','circle','square','arrow'] as $symbol) {
 check(nms_connection_style(['color'=>'#AAbb12','line_style'=>$line,'symbol'=>$symbol])===['#AAbb12',$line,$symbol],'Valid style');
}
foreach ([['color'=>'url(x)','line_style'=>'solid','symbol'=>'none'],['color'=>'#abcdef','line_style'=>'bogus','symbol'=>'none'],['color'=>'#abcdef','line_style'=>'solid','symbol'=>'<script>']] as $bad) {
 try { nms_connection_style($bad); throw new RuntimeException('Accepted invalid style'); } catch (InvalidArgumentException $e) {}
}
echo "17 manual connection validation checks passed\n";
foreach (['VSAT / Leased-line'=>'dash-dot','Optical fiber'=>'fine-dotted','Line-of-sight (LOS)'=>'short-dashed'] as $name=>$style) {
 check(isset(nms_connection_types()[$name]),'BLR type exists');
 check(strlen($name)<=24,'Fits existing schema');
 check(nms_connection_style(['color'=>'#334155','line_style'=>$style,'symbol'=>'none'])[1]===$style,'BLR style accepted');
 check(nms_connection_patterns()[$style] !== '', 'Pattern defined');
}
echo "12 BLR type and pattern checks passed\n";

$discovered=nms_connection_discovered_rows(['nodes'=>[['id'=>1,'name'=>'Switch A'],['id'=>2,'name'=>'Router B']], 'links'=>[
 ['id'=>'d1','a'=>1,'b'=>2,'a_port'=>'Gi1','b_port'=>'Gi2','protocols'=>['LLDP'=>true],'state'=>'Confirmed','speed'=>1000000000,'manual'=>false],
 ['id'=>'m1','a'=>1,'b'=>2,'manual'=>true],
 ['id'=>'d2','a'=>1,'b'=>3,'manual'=>false]
]]);
if (count($discovered)!==1 || $discovered[0]['id']!==null || $discovered[0]['source']!=='Auto-detected' || $discovered[0]['a_display']!=='Switch A / Gi1' || $discovered[0]['speed_mbps']!=1000 || $discovered[0]['protocol']!=='LLDP' || $discovered[0]['type']!=='Unclassified') throw new RuntimeException('Discovered list provenance or endpoint filtering failed');
echo "Discovered list provenance and endpoint checks passed\n";
