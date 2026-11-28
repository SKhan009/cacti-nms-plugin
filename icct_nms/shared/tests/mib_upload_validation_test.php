<?php
/** Offline upload validation: no Cacti installation, network or device requests. */
require_once __DIR__ . '/../../protocols/snmp/mibs/services/mib_repository_service.php';
require_once __DIR__ . '/../../protocols/snmp/mibs/services/mib_upload_session_service.php';
$repository = []; $chunks = []; $checks = 0;
function db_fetch_assoc($sql) { return array_map(fn($b)=>['meta_value'=>json_encode($b)], $GLOBALS['repository']); }
function db_fetch_cell_prepared($sql, $args) { return $GLOBALS['chunks'][$args[0]] ?? false; }
function db_execute_prepared($sql, $args = []) { throw new RuntimeException('Unexpected database write during validation.'); }
function icct_backend_current_user_id() { return 7; }
function icct_backend_require_management($realm) {}
function check($condition, $message) { $GLOBALS['checks']++; if (!$condition) throw new RuntimeException($message); }
function rejected(callable $action, string $message) {
    try { $action(); } catch (RuntimeException | InvalidArgumentException $e) {
        check(stripos($e->getMessage(), $message) !== false, 'Unexpected rejection: ' . $e->getMessage() . ' (wanted ' . $message . ')'); return;
    }
    throw new RuntimeException('Accepted invalid input: ' . $message);
}
function upload(array $texts) { return ['name'=>array_keys($texts), 'stored_content'=>array_values($texts)]; }
function moduleText($body, $name = 'TEST-MIB') { return "$name DEFINITIONS ::= BEGIN\n$body\nEND\n"; }
$assets = __DIR__ . '/../assets/mibs/';
$ups = file_get_contents($assets . 'UPS-MIB.txt');
$batch = [];
foreach (['UPS-MIB','SNMPv2-CONF','SNMPv2-TC','SNMPv2-SMI'] as $name) $batch[$name . '.txt'] = file_get_contents($assets . $name . '.txt');
$result = icct_mib_parse_upload(upload($batch));
check(count($result['records']) === 71, 'UPS should have 71 objects.');
check(count($result['files']) === 4 && count($result['validation']) === 4, 'All files validated together.');
check(array_unique(array_column($result['validation'], 'status')) === ['valid'], 'Valid status per file.');
$reverse = icct_mib_parse_upload(upload(array_reverse($batch, true)));
check($reverse['records'] === $result['records'], 'Dependency order must not change UPS records.');
$single = icct_mib_parse_upload(upload(['UPS-MIB.txt'=>$ups]));
check(count($single['records']) === 71 && count($single['dependencies']) === 3, 'Bundled dependencies resolve offline.');
foreach (glob($assets . '*.txt') as $path) {
    $parsed = icct_mib_parse_upload(upload([basename($path)=>file_get_contents($path)]));
    check($parsed['parse_error'] === '', 'Bundled module must remain supported: ' . basename($path));
}
$base = moduleText('root OBJECT IDENTIFIER ::= { iso 3 }');
rejected(fn()=>icct_mib_parse_upload(upload(['a.txt'=>$base, 'b.txt'=>$base])), 'also supplied');
rejected(fn()=>icct_mib_parse_upload(upload(['a.exe'=>$base])), '.mib');
rejected(fn()=>icct_mib_parse_upload(upload(['../a.txt'=>$base])), '.mib');
rejected(fn()=>icct_mib_parse_upload(upload(['a.txt'=>''])), 'empty');
rejected(fn()=>icct_mib_parse_upload(upload(['a.txt'=>str_repeat(' ', 1048577)])), '1 MiB');
rejected(fn()=>icct_mib_validate_upload_selection(['name'=>array_fill(0,21,'a.txt')]), '1–20');
rejected(fn()=>icct_mib_validate_upload_selection(upload($batch), 1), '1–1');
$large = [];
for ($i=0;$i<9;$i++) $large["module$i.txt"] = str_pad(moduleText('root OBJECT IDENTIFIER ::= { iso 3 }', "MODULE-$i"),1048576,' ');
rejected(fn()=>icct_mib_parse_upload(upload($large)), '8 MiB');
foreach ([
    ['<html>not a mib</html>', 'DEFINITIONS'],
    [$base . $base, 'after END'],
    [str_replace('END', '', $base), 'missing END'],
    [moduleText('root OBJECT IDENTIFIER ::= { iso 3 )'), 'Unbalanced'],
    [moduleText('x OBJECT-TYPE DESCRIPTION "not closed'), 'quoted'],
    [moduleText('unexpected garbage'), 'malformed'],
    [moduleText('IMPORTS x FROM MISSING-DEPENDENCY; root OBJECT IDENTIFIER ::= { iso 3 }'), 'MISSING-DEPENDENCY'],
    [moduleText('IMPORTS nonexistent FROM SNMPv2-SMI; root OBJECT IDENTIFIER ::= { iso 3 }'), 'imported symbol nonexistent'],
    [moduleText('IMPORTS x; root OBJECT IDENTIFIER ::= { iso 3 }'), 'IMPORTS'],
    [moduleText('root OBJECT IDENTIFIER ::= { missing 1 }'), 'Unresolved symbol'],
    [moduleText('root OBJECT IDENTIFIER ::= { next 1 } next OBJECT IDENTIFIER ::= { root 1 }'), 'Circular OID'],
    [moduleText('root OBJECT IDENTIFIER ::= { iso 3 } root OBJECT IDENTIFIER ::= { iso 4 }'), 'Duplicate definition'],
    [moduleText("root OBJECT IDENTIFIER ::= { iso 3 }\0"), 'binary'],
] as [$text,$error]) rejected(fn()=>icct_mib_parse_upload(upload(['test.txt'=>$text])), $error);
$object = 'IMPORTS OBJECT-TYPE FROM SNMPv2-SMI; value OBJECT-TYPE SYNTAX INTEGER MAX-ACCESS read-only STATUS current DESCRIPTION "The words IMPORTS bad FROM NO-SUCH-MIB; are documentation." ::= { iso 3 6 1 4 1 99999 1 }';
check(count(icct_mib_parse_upload(upload(['test.txt'=>moduleText($object)]))['records']) === 1, 'Quoted imports ignored.');
foreach (['MAX-ACCESS read-only'=>'MAX-ACCESS invalid', 'STATUS current'=>'STATUS broken', 'SYNTAX INTEGER'=>'SYNTAX NoSuchType'] as $from=>$to) {
    rejected(fn()=>icct_mib_parse_upload(upload(['test.txt'=>moduleText(str_replace($from,$to,$object))])), $from === 'SYNTAX INTEGER' ? 'Unresolved symbol' : 'requires valid');
}
// Identical stored dependency is reused, not duplicated; source bytes remain integrity checked.
$dependency = $batch['SNMPv2-SMI.txt'];
$repository = [['id'=>'fixture','files'=>[['name'=>'SNMPv2-SMI.txt','module'=>'SNMPv2-SMI','sha256'=>hash('sha256',$dependency),'parts'=>1]]]];
$chunks['mib_file_fixture_0_0'] = base64_encode($dependency);
$reused = icct_mib_parse_upload(upload($batch));
check(count($reused['files']) === 3 && count($reused['validation']) === 4, 'Reused dependency retains its validation row.');
check(str_contains($reused['validation'][3]['message'], 'reusing'), 'Reuse explained.');
$repository[] = ['id'=>'other','files'=>[['name'=>'SMI.txt','module'=>'SNMPv2-SMI','sha256'=>hash('sha256',$dependency . "\n"),'parts'=>1]]];
rejected(fn()=>icct_mib_parse_upload(upload(['UPS-MIB.txt'=>$ups])), 'Multiple repository versions');
check(count(icct_mib_parse_upload(upload($batch))['records']) === 71, 'Explicit dependency disambiguates versions.');
check(str_contains(icct_mib_parse_upload(upload(['UPS-MIB.txt'=>$ups]), true)['parse_error'], 'Multiple repository versions'), 'Legacy comparison reports ambiguous imports without silently choosing a version.');
$repository = array_reverse($repository);
check(count(icct_mib_parse_upload(upload($batch))['files']) === 3, 'Exact older dependency is reused regardless of repository order.');
$repository = array_reverse($repository);
array_pop($repository);
// Re-review uses the original dependency bytes, even if the catalog later changes.
$saved = ['id'=>'saved-upload','files'=>[], 'dependency_sources'=>$reused['dependency_sources']];
foreach ($reused['files'] as $i=>$file) {
    $chunks['mib_file_saved-upload_' . $i . '_0'] = base64_encode($file['content']);
    unset($file['content']); $file['parts'] = 1; $saved['files'][] = $file;
}
$repository = [];
$review = icct_mib_parse_upload(icct_mib_stored_upload($saved));
check(count($review['records']) === 71, 'Pinned repository dependency survives catalog removal.');
check(count($review['files']) === 3, 'Re-review preserves every file in the saved bundle.');
$pinnedUpload = upload(['UPS-MIB.txt'=>$ups]) + ['dependency_sources'=>$single['dependency_sources']];
$pinnedUpload['dependency_sources']['SNMPv2-SMI']['sha256'] = str_repeat('0',64);
rejected(fn()=>icct_mib_parse_upload($pinnedUpload), 'changed since validation');
$repository = [['id'=>'fixture','files'=>[['name'=>'SNMPv2-SMI.txt','module'=>'SNMPv2-SMI','sha256'=>hash('sha256',$dependency),'parts'=>1]]]];
$internal = upload($batch) + ['reuse_repository_dependencies'=>false];
check(count(icct_mib_parse_upload($internal)['files']) === 4, 'Re-review does not remove dependencies originally saved in the batch.');
$chunks['mib_file_fixture_0_0'] = base64_encode('corrupt');
rejected(fn()=>icct_mib_parse_upload(upload(['UPS-MIB.txt'=>$ups])), 'integrity');
$repository = [];
// Missing dependencies can never pass the explicit upload commit guard.
$token = str_repeat('a',32);
foreach ([['parse_error'=>'missing dependency','validation'=>[['status'=>'valid']]], ['validation'=>[]], ['validation'=>[['status'=>'unresolved']]]] as $draft) {
    $_SESSION['icct_mib_uploads'][$token] = ['owner'=>7,'created'=>time(),'draft'=>$draft];
    rejected(fn()=>icct_mib_upload_session_commit($token,false), 'Validate the complete');
}
echo "MIB batch validation: $checks checks passed.\n";
