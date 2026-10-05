<?php
$presetMode=true; $wizard=true; $id=0;
$protocolPresets=icct_nms_protocol_presets();
$failedProtocol='';
if ($_SERVER['REQUEST_METHOD']==='POST' && $error) {
    $key=$_POST['preset_protocol'] ?? '';
    if (is_string($key) && in_array($key,['cdp','lldp','snmp','ssh','serial','syslog'],true)) {
        $protocolPresets[$key]=array_intersect_key(array_filter($_POST,'is_scalar'),array_flip(icct_nms_protocol_preset_fields($key)));
        $failedProtocol=$key;
    }
}
$protocolDraft=['cdp','lldp','snmp','ssh','serial','syslog','netflow','ntp','tacacs'];
$host=array_replace(icct_nms_defaults(),$protocolPresets['snmp'] ?? []);
$host['snmp_community']=''; $host['description']='Protocol defaults';
$discovery=[]; $ssh=$protocolPresets['ssh'] ?? []; $serial=[]; $connections=[];
?>
<section id="protocol-defaults" class="protocol-defaults">
<?php require __DIR__.'/protocol.php'; ?>
</section>
<?php $wizard=false; ?>
