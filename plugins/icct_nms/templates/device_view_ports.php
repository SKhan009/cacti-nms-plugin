<?php
$portView=icct_backend_ports_view($old);
$portConnections=icct_nms_port_connections($id,$portView['ports']);
$states=[1=>'UP',2=>'DOWN',3=>'TESTING',4=>'UNKNOWN',5=>'DORMANT',6=>'NOT PRESENT',7=>'LOWER LAYER DOWN'];
$slots=icct_nms_port_slots($portView['ports'],$profile['physical_ports']??null);
$physicalPorts=$slots['chassis'];$portView['ports']=$slots['ports'];
$portTone=static fn($port)=>!$portView['fresh']?'unknown':(($port['admin']??null)===2 || ($port['oper']??null)===2?'down':(($port['oper']??null)===1?'up':'unknown'));
?>
<section id="view-ports" data-device-view-panel="ports" hidden>
<h2>Interfaces/Ports</h2>
<?php if($physicalPorts): ?><div class="device-port-chassis" role="group" aria-label="Device ports">
<?php foreach(array_chunk($physicalPorts,16) as $bank): ?><div class="device-port-bank"><?php foreach(array_chunk($bank,2) as $pair): ?><div class="device-port-pair"><?php foreach($pair as $position=>$port): ?><button type="button" class="device-port-jack <?= $portTone($port) ?>" data-port-index="<?= (int)$port['index'] ?>" aria-label="<?= icct_nms_h($port['name'].' · '.$port['status']) ?>" title="<?= icct_nms_h($port['name'].' · '.$port['status']) ?>"><span><?= icct_nms_h($port['name']) ?></span><svg viewBox="0 0 40 30" aria-hidden="true"><path d="M3 27V9h7V5h7V2h6v3h7v4h7v18Z"/></svg></button><?php endforeach; ?></div><?php endforeach; ?></div><?php endforeach; ?>
</div><?php endif; ?>
<p class="device-port-observation"><?= icct_nms_h($portView['message']) ?> Green: link up · Red: link or admin down · Grey: unknown or stale.</p>
<?php if(isset($profile['physical_ports'])): ?><p class="device-port-observation"><?= (int)$profile['physical_ports'] ?> physical ports configured for <?= icct_nms_h($profile['name']) ?>. Unreported slots are grey; additional discovered interfaces are also shown.</p><?php endif; ?>
<div class="device-port-toolbar"><div class="field"><label class="sr-only" for="device-port-search">Search interfaces</label><input id="device-port-search" type="search" placeholder="Search with port name or IP address" aria-controls="device-port-rows"></div><button type="button" class="device-port-export" id="device-port-export" aria-label="Export interfaces CSV" title="Export interfaces CSV">⇧</button></div>
<div class="device-port-table-wrap"><table class="device-port-table" id="device-port-table"><thead><tr><?php foreach(['ifIndex','ifName','Port No','Availability','Status','Connected Device','Connected IP','VLAN ID','Admin Status','ifSpeed (Gb/s)','Last change Time'] as $heading): ?><th><?= icct_nms_h($heading) ?></th><?php endforeach; ?></tr></thead><tbody id="device-port-rows">
<?php foreach($portView['ports'] as $port):
$fresh=$portView['fresh']&&empty($port['placeholder']);$names=array_values(array_unique(array_filter($portConnections[$port['index']]['names']??[])));$addresses=array_values(array_unique(array_filter($portConnections[$port['index']]['addresses']??[])));
$speed=($port['high_speed_mbps']??0)*1000000?:($port['speed_bps']??0);
$lastChange='Not reported';$ticks=$port['last_change_ticks']??null;$uptime=$portView['uptime_ticks'];
if($fresh&&$ticks!==null){if($ticks===0)$lastChange='Before last agent restart';elseif($uptime!==null&&$uptime>=$ticks)$lastChange=date('d/m/Y H:i:s',(int)($portView['collected']-($uptime-$ticks)/100));}
$availability=match($port['status']){'In use'=>'Used','Available (link down)'=>'Available (link down)','Disabled'=>'Disabled',default=>'Unknown'};
$cells=[empty($port['placeholder'])?(int)$port['index']:'Not reported',$port['name'],$port['panel_port']??'Not reported',$availability,$fresh?($states[$port['oper']??0]??'UNKNOWN'):'UNKNOWN',implode(', ',$names)?:'Not reported',implode(', ',$addresses)?:'Not reported',$fresh&& !empty($port['vlan_id'])?'['.$port['vlan_id'].']':'Not reported',$fresh?($states[$port['admin']??0]??'UNKNOWN'):'UNKNOWN',$fresh&&$speed>0?rtrim(rtrim(number_format($speed/1e9,6,'.',''),'0'),'.'):'Not reported',$lastChange];
?><tr data-port-row="<?= (int)$port['index'] ?>"><?php foreach($cells as $column=>$cell): ?><td<?php if(in_array($column,[4,8],true)): ?> class="port-state <?= $fresh?($cell==='UP'?'up':($cell==='DOWN'?'down':'unknown')):'unknown' ?>"<?php endif; ?>><?= icct_nms_h($cell) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div><p id="device-port-empty" <?= $portView['ports']?'hidden':'' ?>>No interfaces reported for the current configuration.</p><p id="device-port-count" role="status" aria-live="polite"></p>
</section>
