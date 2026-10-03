<?php
$presetMode=true; $wizard=true; $id=0;
$protocolPresets=icct_nms_protocol_presets();
$failedProtocol='';
if ($_SERVER['REQUEST_METHOD']==='POST' && $error) {
    $key=$_POST['preset_protocol'] ?? '';
    if (is_string($key) && in_array($key,['cdp','lldp','snmp','ssh','serial'],true)) {
        $protocolPresets[$key]=array_intersect_key(array_filter($_POST,'is_scalar'),array_flip(icct_nms_protocol_preset_fields($key)));
        $failedProtocol=$key;
    }
}
$protocolDraft=['cdp','lldp','snmp','ssh','serial'];
$host=array_replace(icct_nms_defaults(),$protocolPresets['snmp'] ?? []);
$host['snmp_community']=''; $host['description']='Protocol defaults';
$discovery=[]; $ssh=$protocolPresets['ssh'] ?? []; $serial=[]; $connections=[];
?>
<section id="protocol-defaults" class="protocol-defaults">
<p class="empty-state">Defaults are copied when you add a protocol to a device. Device changes and existing saved settings remain independent. Enter credentials, serial port and bus address on each device.</p>
<?php require __DIR__.'/protocol.php'; ?>
</section>
<?php $wizard=false; ?>
