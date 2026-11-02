#!/usr/bin/env php
<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require __DIR__.'/../../../../include/cli_check.php';
require_once __DIR__.'/../../shared/services/bootstrap.php';
try{icct_nms_backend();icct_nms_collect_alarms();echo "Device alarm journal synchronized.\n";}
catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
