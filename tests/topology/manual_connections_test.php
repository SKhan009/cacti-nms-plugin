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
