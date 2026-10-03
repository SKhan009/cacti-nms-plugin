<?php
$networkField=function($key,$label,$kind='text',$tip='',$extra='')use($networkEdit,$networkOptions){$value=$networkEdit[$key]??''; ?>
<div class="field" <?= $extra ?>><label for="network_<?= $key ?>" <?= $tip!==''?'title="'.icct_nms_h($tip).'"':'' ?>><?= icct_nms_h($label) ?></label>
<?php if(isset($networkOptions[$key])): $selected=is_array($value)?$value:explode(',',(string)$value); ?><select id="network_<?= $key ?>" name="<?= $key ?><?= $kind==='multi'?'[]':'' ?>" <?= $kind==='multi'?'multiple size="5"':'' ?>><?php foreach($networkOptions[$key] as $n=>$text): ?><option value="<?= icct_nms_h($n) ?>" <?= in_array((string)$n,array_map('strval',$selected),true)?'selected':'' ?>><?= icct_nms_h($text) ?></option><?php endforeach; ?></select>
<?php elseif($kind==='checkbox'): ?><label class="network-check"><input id="network_<?= $key ?>" type="checkbox" name="<?= $key ?>" value="1" <?= !empty($value)?'checked':'' ?>> Enable</label>
<?php elseif($kind==='textarea'): ?><textarea id="network_<?= $key ?>" name="<?= $key ?>" rows="4" maxlength="1024" required><?= icct_nms_h($value) ?></textarea>
<?php elseif($kind==='read'): ?><span id="network_<?= $key ?>" class="network-readonly"><?= icct_nms_h($value!==''?$value:'—') ?></span>
<?php else: ?><input id="network_<?= $key ?>" name="<?= $key ?>" type="<?= $kind==='number'?'number':'text' ?>" value="<?= icct_nms_h($value) ?>" <?= in_array($key,['name','start_at','ping_timeout','ping_retries','ping_port'],true)?'required':'' ?> <?= $kind==='number'?'min="0" max="'.($key==='ping_port'?'65535':'99999').'"':'maxlength="'.(['notification_fromname'=>32,'notification_fromemail'=>128,'start_at'=>30][$key]??250).'"' ?> <?= $key==='start_at'?'placeholder="YYYY-MM-DD HH:MM:SS"':'' ?>><?php endif; ?>
</div><?php };
?>
<form method="post" class="topology-config-form network-editor" id="networkEditor">
<?php icct_nms_token(); ?><input type="hidden" name="action" value="save_network"><input type="hidden" name="network_id" value="<?= (int)$networkEdit['id'] ?>">
<div class="titlebar"><h2><?= $networkEdit['id']?'Edit':'Add' ?> Network</h2><div class="type-editor-actions"><a class="button" href="?tab=networks">Cancel</a><button class="button primary">Save</button></div></div>
<h3>General Settings</h3><div class="form-grid">
<?php
$networkField('name','Network Name *');$networkField('poller_id','Data Collector');$networkField('site_id','Associated Site');$networkField('sched_type','Schedule Type');
$networkField('subnet_range','Subnet Range *','textarea','Comma-separated IPs, CIDR ranges, netmask ranges or wildcards.');$networkField('total_ips','Total IP Addresses','read','Calculated when the network is saved.');$networkField('dns_servers','Alternate DNS Servers','text','Space-separated DNS IP addresses or names. Blank uses the collector’s DNS.');$networkField('threads','Discovery Threads');$networkField('run_limit','Run Limit');
foreach(['enabled'=>'Network Enabled','enable_netbios'=>'Enable NetBIOS','add_to_cacti'=>'Automatically Add to Cacti','same_sysname'=>'Allow Same sysName on Different Hosts','rerun_data_queries'=>'Rerun Data Queries'] as $key=>$label)$networkField($key,$label,'checkbox');
?></div>
<h3>Notification Settings</h3><div class="form-grid">
<?php $networkField('notification_enabled','Notifications','checkbox');$networkField('notification_email','Notification Email','text','Separate recipients with commas or semicolons.','data-network-notification');$networkField('notification_fromname','Sender Name','text','Blank uses Cacti defaults.','data-network-notification');$networkField('notification_fromemail','Sender Email','text','Blank uses Cacti defaults.','data-network-notification'); ?></div>
<h3 data-network-schedules="2,3,4,5">Discovery Timing</h3><div class="form-grid">
<?php $networkField('start_at','Starting Date/Time *','text','Uses the Cacti server timezone.','data-network-schedules="2,3,4,5"');$networkField('recur_every','Rerun Every','text','','data-network-schedules="2,3"');$networkField('day_of_week','Days of Week','multi','','data-network-schedules="3"');$networkField('month','Months of Year','multi','','data-network-schedules="4,5"');$networkField('day_of_month','Days of Month','multi','','data-network-schedules="4"');$networkField('monthly_week','Weeks of Month','multi','','data-network-schedules="5"');$networkField('monthly_day','Days of Week','multi','','data-network-schedules="5"'); ?></div>
<h3>Reachability Settings</h3><div class="form-grid">
<?php $networkField('snmp_id','SNMP Options');$networkField('ping_method','Ping Method');$networkField('ping_port','Ping Port','number','','data-network-ping="2,3,5"');$networkField('ping_timeout','Ping Timeout (ms)','number','','data-network-ping="1,2,3,5"');$networkField('ping_retries','Ping Retry Count','number','','data-network-ping="1,2,3,5"'); ?></div>
<?php if($networkEdit['id']): ?><h3>Discovery Status</h3><div class="form-grid"><?php foreach(['total_ips'=>'Total Addresses','up_hosts'=>'Up Hosts','snmp_hosts'=>'SNMP Hosts','next_start'=>'Next Start','last_started'=>'Last Started','last_runtime'=>'Last Runtime (seconds)','last_status'=>'Last Result'] as $key=>$label)$networkField($key,$label,'read'); ?></div><?php endif; ?>
<div class="type-editor-actions"><a class="button" href="?tab=networks">Cancel</a><button class="button primary">Save</button></div>
</form>
