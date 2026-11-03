<?php
$nfFields=['version'=>['Version','select',[5=>'NetFlow v5',7=>'NetFlow v7',9=>'NetFlow v9',10=>'IPFIX']],'collector'=>['Collector Address','text','Receiver hostname or IP address'],'port'=>['Collector Port','number','1–65535'],'active_timeout'=>['Active Timeout (Sec)','number','1–86400'],'inactive_timeout'=>['Inactive Timeout (Sec)','number','1–86400'],'sampling_rate'=>['Sampling Rate','number','Packets per sample'],'lan_cidrs'=>['LAN Prefixes','text','Comma-separated CIDRs'],'wan_interfaces'=>['WAN Interface ifIndex Values','text','Optional: 2,3'],'bandwidth_limit_mbps'=>['Bandwidth Alert Limit (Mbps)','number','5-minute average'],'scan_threshold'=>['Scan Alert Destinations / Ports','number','Distinct targets per 5 minutes'],'syn_pps_threshold'=>['SYN Alert Packets / Sec','number','5-minute average']];
$nfHelp=[
    'version'=>'Choose the format exported by your router or switch: NetFlow v5, v7, v9 or IPFIX. Configure the same format on the exporter. Saving a preset does not configure the router.',
    'collector'=>'IP address or hostname of the NMS receiver, reachable from the exporting router or switch. Capture files are processed every 60 seconds; flow data is retained for 30 days.',
    'port'=>'UDP port receiving flow records. Configure the same destination port on the exporter. Common defaults: NetFlow 2055, IPFIX 4739. Range: 1–65535.',
    'active_timeout'=>'Seconds between exports for an ongoing flow. Configure this timeout on the exporter itself. For example, 60 exports an update about every minute. Range: 1–86400 seconds.',
    'inactive_timeout'=>'Seconds without traffic before the exporter finishes and exports a flow record. Configure this timeout on the exporter itself. Range: 1–86400 seconds.',
    'sampling_rate'=>'Packets per sample: 1 observes every packet; 100 samples approximately one in 100. Match the exporter configuration. Traffic totals use advertised sampling; this saved value applies only when the exporter supplies no sampling information. Range: 1–1000000.',
    'lan_cidrs'=>'Comma-separated IPv4 or IPv6 CIDR ranges treated as internal networks. Traffic within these ranges is LAN; traffic crossing an internal range is WAN; other traffic is unclassified. Explicit WAN interfaces take priority.',
    'wan_interfaces'=>'Optional comma-separated SNMP ifIndex IDs for WAN links, such as 2,3. These are interface identifiers, not necessarily physical port numbers. Matching input or output interfaces classify traffic as WAN before LAN prefix rules.',
    'bandwidth_limit_mbps'=>'Alert when collected device traffic reaches this five-minute average in Mbps. This detects high usage; it does not limit traffic speed. Range: 1–1000000 Mbps.',
    'scan_threshold'=>'Flag a possible scan when one source reaches this many distinct destination IPs or destination ports within five minutes. Range: 2–1000000.',
    'syn_pps_threshold'=>'Flag a possible SYN flood when one source reaches this rate of SYN packets without ACK, averaged over five minutes. Range: 1–100000000 packets per second.',
];
$nfRanges=['port'=>[1,65535],'active_timeout'=>[1,86400],'inactive_timeout'=>[1,86400],'sampling_rate'=>[1,1000000],'bandwidth_limit_mbps'=>[1,1000000],'scan_threshold'=>[2,1000000],'syn_pps_threshold'=>[1,100000000]];
?>
<div class="protocol-grid cols-4">
<?php foreach($nfFields as $name=>[$label,$type,$help]): ?><label class="field"><span class="field-label"><?= $label ?> <span class="field-info" tabindex="0" role="img" aria-label="Information about <?= icct_nms_h($label) ?>" data-tooltip="<?= icct_nms_h($nfHelp[$name]) ?>"><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8"/><path d="M10 9v5M10 6v.5"/></svg></span></span>
<?php if($type==='select'): ?><select name="<?= $name ?>"><?php foreach($help as $v=>$caption): ?><option value="<?= $v ?>" <?= (int)$netflow[$name]===$v?'selected':'' ?>><?= $caption ?></option><?php endforeach; ?></select>
<?php else: ?><input name="<?= $name ?>" type="<?= $type ?>" value="<?= icct_nms_h($netflow[$name]) ?>" placeholder="<?= icct_nms_h($help) ?>" <?= in_array($name,['wan_interfaces','lan_cidrs'],true)?'':'required' ?> <?= $type==='number'?'min="'.$nfRanges[$name][0].'" max="'.$nfRanges[$name][1].'"':'' ?>><?php endif; ?></label><?php endforeach; ?>
<?php if(!$presetMode): ?><label class="field"><span class="field-label">Exporter Source IP</span><input name="exporter_address" required value="<?= icct_nms_h($netflow['exporter_address']??$host['hostname']??'') ?>" placeholder="Source IP observed by the collector"></label><?php endif; ?>
</div>
<div class="protocol-actions"><button class="button primary" type="submit">Save</button></div>
