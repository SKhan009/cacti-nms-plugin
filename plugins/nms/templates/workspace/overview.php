<?php /** One summary and one navigation bar for every readings workflow. */ ?>
<?php if(!$workspace_host) {
    $serial_status=null;$serial_samples=[];$device_readings=[];$device_discovery_readings=[];
    $interfaces=$traffic=$unknown=$stale=$serial_problems=0;$failed=[];
    require __DIR__.'/reading_tabs.php';
    if($workspace_section==='overview') { ?>
<section class="nms-panel"><h2>Select a device to see its readings</h2><p>Network discovery is available without selecting a device.</p></section>
<?php } else require __DIR__.'/'.$workspace_section.'.php';
} else {
    $nms_workspace_embedded=true;
    require __DIR__.'/../devices/readings.php';
} ?>
