<?php
/** Local process test; simulated controller, no Cacti mutation or network probes. */
require __DIR__.'/../../plugins/nms/includes/workspace/scan_worker.php';
if(!function_exists('pcntl_signal'))throw new RuntimeException('This process test requires pcntl');
$fixture=tempnam(sys_get_temp_dir(),'nms-stop-');
$marker=$fixture.'.cleaned';
file_put_contents($fixture, <<<'PHP'
<?php
pcntl_async_signals(true);
pcntl_signal(SIGTERM,function()use($argv){usleep(1200000);file_put_contents($argv[1],'cleaned');exit(0);});
echo "ready\n";flush();
while(true)usleep(100000);
PHP
);
$process=null;$pipes=[];
try {
    $process=proc_open([PHP_BINARY,$fixture,$marker],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Cannot start cleanup fixture');
    fclose($pipes[0]);stream_set_timeout($pipes[1],3);
    if(fgets($pipes[1])!=="ready\n")throw new RuntimeException('Fixture did not become ready');
    stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
    $start=microtime(true);nms_scan_stop_process($process,$pipes);
    $elapsed=microtime(true)-$start;
    if(!is_file($marker) || file_get_contents($marker)!=='cleaned')throw new RuntimeException('Controller cleanup was interrupted');
    if(proc_get_status($process)['running'] || $elapsed>8)throw new RuntimeException('Controller failed to stop within its bound');
    echo "PASS: owned controller completes delayed SIGTERM cleanup before termination\n";
    nms_scan_stop_process($process,$pipes);
    echo "PASS: stopping an already-exited controller is harmless\n";
} finally {
    if(is_resource($process)){if(proc_get_status($process)['running'])proc_terminate($process,9);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}
    unlink($fixture);if(is_file($marker))unlink($marker);
}
