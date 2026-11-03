<?php
/** Native database lifecycle tests; all preset writes use a connection-local table. */
if(PHP_SAPI!=='cli')exit(1);
try {
    require '/var/www/html/cacti/include/cli_check.php';
    require_once __DIR__.'/../services/bootstrap.php';icct_nms_backend();
    require_once __DIR__.'/../services/forms.php';
    require_once __DIR__.'/../../inventory/services/device_service.php';
    require_once __DIR__.'/../../protocols/serial/services/serial_service.php';
    require_once __DIR__.'/../../protocols/shared/services/protocol_seed.php';
    if(!db_execute('CREATE TEMPORARY TABLE qa_preset_meta LIKE plugin_icct_nms_meta') || !db_execute('CREATE TEMPORARY TABLE plugin_icct_nms_meta LIKE qa_preset_meta'))throw new RuntimeException('Cannot isolate metadata.');
    icct_nms_seed_protocol_presets();
    $presets=icct_nms_protocol_presets();
    if(count($presets)!==7||count(icct_nms_netflow_profiles())!==4)throw new RuntimeException('Fresh install incomplete.');
    foreach($presets as $protocol=>$values)icct_nms_protocol_preset_validate($protocol,$values);
    foreach([5=>2056,7=>2057,9=>2059,10=>4740] as $version=>$port){
        $input=array_replace(icct_nms_netflow_profiles()[$version],['preset_protocol'=>'netflow','port'=>$port]);
        icct_nms_save_protocol_preset($input);
    }
    icct_nms_seed_protocol_presets();
    foreach([5=>2056,7=>2057,9=>2059,10=>4740] as $version=>$port)if(icct_nms_netflow_profiles()[$version]['port']!==$port)throw new RuntimeException('Upgrade lost a saved format.');
    echo "PASS: native database install, native validation of seven protocols, four format saves and upgrade preservation; live presets unchanged.\n";
} catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
