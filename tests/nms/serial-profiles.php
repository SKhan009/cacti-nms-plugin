<?php
/** No equipment I/O: validate malformed input and independent preset snapshots. */
require __DIR__ . '/../../plugins/nms/includes/configuration/validation.php';
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$input = ['name'=>'Lab fixture', 'description'=>'Simulator only', 'manufacturer'=>'Test',
    'model'=>'Fixture', 'protocol'=>'modbus_rtu', 'baud_rate'=>'9600', 'data_bits'=>'8',
    'parity'=>'even', 'stop_bits'=>'1', 'flow_control'=>'none', 'timeout_ms'=>'1000', 'retries'=>'2'];
$profile = nms_serial_profile_validate($input);
check($profile['settings']['baud_rate'] === 9600, 'Numeric form input not normalised');
foreach (['name'=>['', [], str_repeat('a',151)], 'protocol'=>['shell','modbus_tcp',null],
    'baud_rate'=>['9600;id', '1e4', -1, true, 4000001], 'data_bits'=>[7,9],
    'parity'=>['EVEN','invalid'], 'stop_bits'=>[0,3], 'flow_control'=>['xonxoff'],
    'timeout_ms'=>[0,10001,'1.5'], 'retries'=>[-1,4], 'model'=>['',"abc\0def"]] as $field=>$values) {
    foreach ($values as $bad) {
        try { nms_serial_profile_validate(array_replace($input,[$field=>$bad])); }
        catch (InvalidArgumentException $e) { continue; }
        throw new RuntimeException("Invalid $field accepted");
    }
}
$snapshot = nms_serial_profile_snapshot($profile, 4, 1);
$profile['settings']['baud_rate'] = 19200;
check($snapshot['settings']['baud_rate'] === 9600, 'Preset edit changed existing connection snapshot');
foreach ([0,248,-1,'1.2',[],true] as $bad) {
    try { nms_serial_device_address($bad); }
    catch (InvalidArgumentException $e) { continue; }
    throw new RuntimeException('Invalid/broadcast address accepted');
}
check(nms_serial_device_address('247') === 247, 'Valid address rejected');
echo "PASS: serial profile validation, bounded timing, independent snapshots and unicast addresses (no hardware I/O)\n";

$direct=['transport'=>'direct','poller_id'=>1,'endpoint'=>'/dev/ttyUSB0'];
$a=nms_serial_endpoint($direct);
$b=nms_serial_endpoint(array_replace($direct,['poller_id'=>2]));
check($a['endpoint_key']!==$b['endpoint_key'],'Direct ports on different collectors collide');
$gateway=['transport'=>'rtu_tcp','poller_id'=>1,'endpoint'=>'2001:db8::1','port'=>4001];
$a=nms_serial_endpoint($gateway);
$b=nms_serial_endpoint(array_replace($gateway,['poller_id'=>2,'endpoint'=>'2001:0db8:0:0:0:0:0:1']));
check($a['endpoint_key']===$b['endpoint_key'],'Gateway aliases evade ownership');
foreach(['/etc/passwd','/dev/ttyUSB0;id','/dev/serial/by-id/../ttyUSB0'] as $bad) {
    try { nms_serial_endpoint(array_replace($direct,['endpoint'=>$bad])); }
    catch(InvalidArgumentException $e) { continue; }
    throw new RuntimeException('Invalid direct path accepted');
}
echo "PASS: collector-scoped ports, canonical gateway ownership and rejected unsafe endpoints\n";

foreach ([50,75,110,134,150,200,9600,115200,4000000] as $baud) {
    check(nms_serial_profile_validate(array_replace($input,['baud_rate'=>$baud]))['settings']['baud_rate']===$baud,'Supported baud rejected');
}
check(nms_serial_profile_validate(array_replace($input,['baud_rate'=>'custom','custom_baud_rate'=>'14400']))['settings']['baud_rate']===14400,'Custom rate not normalised');
foreach (['mark','space'] as $parity) check(nms_serial_profile_validate(array_replace($input,['parity'=>$parity]))['settings']['parity']===$parity,'Parity option rejected');
foreach ([['baud_rate'=>49],['baud_rate'=>'custom','custom_baud_rate'=>''],['baud_rate'=>'custom','custom_baud_rate'=>'1e4'],['flow_control'=>'dsrdtr']] as $change) {
    try { nms_serial_profile_validate(array_replace($input,$change)); }
    catch (InvalidArgumentException $e) { continue; }
    throw new RuntimeException('Unsupported option accepted');
}
echo "PASS: low and custom baud rates, Mark/Space parity and unsupported flow rejection\n";

$stored=nms_serial_profile_validate($input);
$stored['settings_json']=json_encode($stored['settings']);
$override=json_decode(nms_serial_connection_settings($stored,['custom_serial_settings'=>1,'baud_rate'=>'19200','parity'=>'none','stop_bits'=>'2']),true);
check($override['baud_rate']===19200 && $override['stop_bits']===2,'Connection overrides were not applied');
check($stored['settings']['baud_rate']===9600,'Custom connection mutated the preset');
check(nms_serial_connection_settings($stored,['baud_rate'=>'19200'])===$stored['settings_json'],'Unrequested overrides changed the connection');
try {
    nms_serial_connection_settings($stored,['custom_serial_settings'=>1,'baud_rate'=>'19200;id']);
    throw new RuntimeException('Invalid override accepted');
} catch(InvalidArgumentException $e) {}
echo "PASS: custom connection settings, preset preservation and invalid override rejection\n";

foreach (['rs232','rs485'] as $interface) {
    $profile=nms_serial_profile_validate(array_replace($input,['interface'=>$interface]));
    check($profile['settings']['interface']===$interface,'Interface was not retained');
    check(nms_serial_profile_snapshot($profile,4,1)['settings']['interface']===$interface,'Interface snapshot was lost');
}
check(nms_serial_profile_validate($input)['settings']['interface']==='unspecified','Legacy settings were relabelled');
foreach ([['interface'=>'invalid'],['interface'=>'rs485','flow_control'=>'rtscts']] as $change) {
    try { nms_serial_profile_validate(array_replace($input,$change)); throw new LogicException('Unsupported interface combination accepted'); }
    catch(InvalidArgumentException $e) {}
}
echo "PASS: RS-232/RS-485 snapshots, legacy interface preservation and invalid RS-485 flow rejection\n";
