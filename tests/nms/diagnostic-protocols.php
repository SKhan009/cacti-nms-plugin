<?php
/** Protocol checks are pure fixtures; never send packets from this test. */
require __DIR__ . '/../../plugins/nms/includes/diagnostics.php';
require __DIR__ . '/../../plugins/nms/includes/diagnostic_summary.php';
function verify($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function rejects($call, $message) {
    try { $call(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException($message);
}
$row = ['name'=>'Protocol test', 'hostname'=>'192.0.2.10', 'ping_count'=>4, 'trace_hops'=>20, 'bandwidth_seconds'=>10];
$lookup = static fn($name) => in_array($name, ['tracepath', 'pchar', 'mtr', 'nping', 'hping3'], true) ? '/fixture/' . $name : '';
verify(nms_diag_executable('traceroute', $lookup) === ['tracepath', '/fixture/tracepath'], 'Legacy UDP fallback');
verify(nms_diag_executable('pathchar', $lookup) === ['pchar', '/fixture/pchar'], 'Pathchar fallback');
foreach (['traceroute_icmp', 'traceroute_tcp'] as $tool) {
    verify(nms_diag_executable($tool, $lookup) === ['traceroute', ''], 'Must not substitute UDP for ' . $tool);
}
$all = array_keys(nms_diag_labels());
$profile = nms_diag_profile_validate(['diagnostic_profile_name'=>'All checks', 'diagnostic_tools'=>$all]);
verify(explode(',', $profile['tools']) === $all && strlen($profile['tools']) <= 255, 'All choices fit saved profile');
foreach (['traceroute', 'mtr', 'nping', 'hping3'] as $program) {
    foreach (['icmp', 'tcp'] as $protocol) {
        $tool = $program . '_' . $protocol;
        [$args, $timeout] = nms_diag_arguments($row, $tool, $program);
        verify(end($args) === $row['hostname'] && $timeout > 0 && $timeout <= 65, 'Bounded single-target command');
        verify(!in_array('sudo', $args, true), 'No sudo');
        if ($program === 'nping') verify(!in_array('-n', $args, true) && in_array('--privileged', $args, true), 'Nping supports capabilities without invalid -n flag');
        $flag = ['traceroute'=>['icmp'=>'-I','tcp'=>'-T'], 'mtr'=>['icmp'=>'--report','tcp'=>'--tcp'],
            'nping'=>['icmp'=>'--icmp','tcp'=>'--tcp'], 'hping3'=>['icmp'=>'-1','tcp'=>'-S']][$program][$protocol];
        verify(in_array($flag, $args, true), 'Correct protocol: ' . $tool);
        verify(in_array('443', $args, true) === ($protocol === 'tcp'), 'Explicit TCP port only');
        $countFlag = $program === 'mtr' ? '--report-cycles' : ($program === 'traceroute' ? '-q' : '-c');
        verify($args[array_search($countFlag, $args, true) + 1] === ($program === 'traceroute' ? '1' : '4'), 'Bounded count');
        if ($program === 'mtr' || $program === 'traceroute') {
            $hopFlag = $program === 'mtr' ? '--max-ttl' : '-m';
            verify($args[array_search($hopFlag, $args, true) + 1] === '20', 'Profile hop bound');
        }
        if ($program !== 'traceroute') verify(nms_diag_executable($tool, $lookup)[1] === '/fixture/' . $program, 'Shared readiness resolver');
        foreach (['--help', 'host;id', 'host name', '192.0.2.0/24'] as $bad) {
            rejects(fn()=>nms_diag_arguments(array_replace($row, ['hostname'=>$bad]), $tool, $program), 'Unsafe target accepted');
        }
        foreach (['ping_count'=>11, 'trace_hops'=>31, 'bandwidth_seconds'=>31] as $key=>$bad) {
            rejects(fn()=>nms_diag_arguments(array_replace($row, [$key=>$bad]), $tool, $program), 'Unbounded profile accepted');
        }
        $v6 = array_replace($row, ['hostname'=>'2001:db8::10']);
        if ($program === 'hping3') rejects(fn()=>nms_diag_arguments($v6, $tool, $program), 'hping3 IPv6 accepted');
        else verify(in_array('-6', nms_diag_arguments($v6, $tool, $program)[0], true), 'IPv6 mode missing');
        $d = nms_diag_description(['tool'=>$tool, 'exit'=>0, 'output'=>'No responses']);
        verify($d['tone'] === 'warning', 'No response must not report success');
        verify($d['metrics']['Probe protocol'] === strtoupper($protocol), 'Saved protocol shown');
        $d = nms_diag_description(['tool'=>$tool, 'exit'=>1, 'output'=>'Operation not permitted']);
        verify($d['tone'] === 'error', 'Permission failure shown');
    }
}
rejects(fn()=>nms_diag_arguments(array_replace($row, ['hostname'=>'192.0.2.1-3']), 'nping_tcp', 'nping'), 'Nping range expansion accepted');
rejects(fn()=>nms_diag_executable('arbitrary_command', $lookup), 'Unknown executable accepted');
[$args] = nms_diag_arguments($row, 'traceroute', 'tracepath');
verify($args === ['-n', '-m', '20', '192.0.2.10'], 'Existing tracepath arguments changed');
[$args] = nms_diag_arguments($row, 'ping', 'ping');
verify($args === ['-n','-c','4','-W','2','-w','14','192.0.2.10'], 'Existing ping arguments changed');
echo "Protocol fixtures passed: modes, availability, bounds, targets, IPv6 and result semantics.\n";

// Changing the selected device must change every targeted command, never fall back to a lab IP.
foreach (['198.51.100.27', '203.0.113.82', 'router.example.net', '2001:db8::27'] as $target) {
    foreach ($all as $tool) {
        if (strpos($tool, 'hping3_') === 0 && strpos($target, ':') !== false) continue;
        $program = explode('_', $tool)[0];
        [$args] = nms_diag_arguments(array_replace($row, ['hostname'=>$target]), $tool, $program);
        if ($tool === 'arp') {
            verify($args === ['neigh', 'show'], 'ARP remains the collector cache');
        } else {
            verify(count(array_keys($args, $target, true)) === 1, 'Selected target preserved: ' . $tool);
            verify(!in_array('127.0.0.1', $args, true) && !in_array('localhost', $args, true), 'No local fallback: ' . $tool);
        }
    }
}
echo "PASS: all diagnostic commands preserve selected IPv4, IPv6 and DNS targets.\n";
