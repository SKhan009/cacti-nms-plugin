<?php
/** Execute actual configuration controllers without a Cacti database or equipment. */
$routes = ['device'=>'page.php', 'node'=>'page.php', 'connection'=>'connection_page.php'];
if (($argv[1] ?? '') === '--request') {
    $route = $argv[2];
    $case = $argv[3];
    $GLOBALS['allow_management'] = $case === 'invalid-node';
    function is_realm_allowed($realm) { return $GLOBALS['allow_management']; }
    function html_escape($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
    // Any attempt to continue into storage makes the response fail this test.
    function db_execute_prepared(...$args) { throw new LogicException('Unexpected storage write'); }
    function db_fetch_assoc(...$args) { throw new LogicException('Unexpected storage read'); }
    function db_fetch_row_prepared(...$args) { throw new LogicException('Unexpected storage read'); }
    $_SESSION = [];
    $_GET = $route === 'node' ? ['view'=>'node', 'node_id'=>'1'] : ['id'=>'1'];
    if ($case === 'invalid-node') $_GET['node_id'] = '-1';
    $_SERVER['REQUEST_METHOD'] = $case === 'get' || $case === 'invalid-node' ? 'GET' : 'POST';
    $_POST = ['action'=>$case, 'members'=>[1], 'field_key'=>'limit', 'requested'=>'23'];
    set_error_handler(function($severity, $message, $file, $line) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    ob_start();
    register_shutdown_function(function() {
        $body = ob_get_clean();
        echo json_encode(['status'=>http_response_code(), 'body'=>$body, 'fatal'=>error_get_last()]);
    });
    require __DIR__.'/../../plugins/nms/includes/functions.php';
    require __DIR__.'/../../plugins/nms/includes/configuration/'.$routes[$route];
    throw new LogicException('Denied request returned to caller');
}

$cases = [
    'device'=>['get','assign_equipment','create_graphs','read','preview_write','apply_write'],
    'node'=>['get','read','preview','apply','invalid-node'],
    'connection'=>['get','create_device','preview_connection','create_connection','preview_refresh','apply_refresh','assign'],
];
$count = 0;
foreach ($cases as $route=>$actions) foreach ($actions as $action) {
    $process = proc_open([PHP_BINARY, __FILE__, '--request', $route, $action],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start controller request');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($process);
    $response = json_decode($output, true);
    $message = $action === 'invalid-node' ? 'Invalid node or device identifier.'
        : 'Your Cacti account does not have permission to change this configuration.';
    if ($exit !== 0 || $errors !== '' || !is_array($response)
        || $response['status'] !== 403 || $response['body'] !== $message || $response['fatal'] !== null) {
        throw new RuntimeException("$route/$action failed: ".$output.$errors);
    }
    $count++;
}
echo "PASS: $count actual controller GET/POST admission cases return handled 403 responses; permission evaluator is a test double, no database or equipment I/O\n";
