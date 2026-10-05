<?php
require __DIR__.'/../includes/site_service.php';
$valid=['name'=>'Demo site','latitude'=>'19.0760000000','longitude'=>'72.8777000000','zoom'=>'7','timezone'=>'Asia/Kolkata'];
$save=icct_nms_site_values($valid);
if ($save['latitude']!==$valid['latitude'] || $save['timezone']!==$valid['timezone']) throw new Exception('Site values must be retained.');
foreach ([['latitude'=>'91'],['longitude'=>'-181'],['zoom'=>'24'],['timezone'=>'Unknown/Zone'],['name'=>''],['state'=>str_repeat('x',21)],['latitude'=>['bad']]] as $invalid) {
    try { icct_nms_site_values(array_replace($valid,$invalid)); } catch (InvalidArgumentException $e) { continue; }
    throw new Exception('Invalid site input was accepted.');
}
foreach ([['latitude'=>'-90','longitude'=>'180','zoom'=>'23'],['latitude'=>'','longitude'=>'','zoom'=>'','timezone'=>'']] as $boundary) icct_nms_site_values(array_replace($valid,$boundary));
echo "Site value retention, bounds and timezone validation passed.\n";
