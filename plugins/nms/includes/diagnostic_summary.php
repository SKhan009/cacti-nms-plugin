<?php
/** Loopback detection is address classification, not a configured test target. */
function nms_diag_summary_local(array $result): bool {
    if (!empty($result['self_test']) || preg_match('/Collector (?:loopback|local-address) self-test/i', $result['output'] ?? '')) return true;
    $ip = @inet_pton((string)($result['target'] ?? ''));
    if ($ip === false) return false;
    return (strlen($ip) === 4 && ord($ip[0]) === 127) ||
        (strlen($ip) === 16 && ($ip === str_repeat(chr(0),15).chr(1) ||
        (substr($ip,0,12) === str_repeat(chr(0),10).chr(255).chr(255) && ord($ip[12]) === 127)));
}
/** Explain saved results using only measurements actually present in their output. */
function nms_diag_plain_summary(array $result): array
{
    $tool = $result['tool'] ?? '';
    $output = (string) ($result['output'] ?? '');
    $ok = isset($result['exit']) && (int) $result['exit'] === 0;
    $purpose = [
        'ping' => 'Checks whether the device replies and how long a reply takes.',
        'traceroute' => 'Shows the network stops between the collector and the device.',
        'arp' => 'Lists nearby IP addresses already learned by the collector.',
        'iperf3' => 'Measures how much test data can be transferred per second.',
        'netperf' => 'Measures TCP data transfer speed during this test.',
        'pathchar' => 'Estimates capacity along the network route.'
    ];
    foreach (['traceroute_icmp', 'traceroute_tcp', 'mtr_icmp', 'mtr_tcp'] as $key) {
        $purpose[$key] = 'Shows the network path and responding hops using ' . (str_ends_with($key, '_tcp') ? 'TCP port 443.' : 'ICMP.');
    }
    foreach (['nping_icmp', 'nping_tcp', 'hping3_icmp', 'hping3_tcp'] as $key) {
        $purpose[$key] = 'Sends a bounded number of ' . (str_ends_with($key, '_tcp') ? 'TCP SYN probes to port 443.' : 'ICMP echo probes.');
    }
    $lines = [$purpose[$tool] ?? 'Checks the selected device from its collector.'];
    if (nms_diag_summary_local($result)) {
        $lines[] = 'This test stayed inside the collector. It does not measure your cable, switch, internet speed or a remote device.';
    }
    if (!$ok) {
        if (preg_match('/permission denied|operation not permitted/i', $output)) {
            $lines[] = 'The collector was not allowed to run this operation. Ask the administrator to check the collector’s existing permissions.';
        } elseif (preg_match('/not found|not installed|not executable/i', $output)) {
            $lines[] = 'A required tool is missing or cannot run on the collector. Install the matching package and try again.';
        } elseif (preg_match('/connection refused|control connection|Bad file descriptor/i', $output)) {
            $lines[] = 'The test could not connect to its server. Check that the correct test server is running at the destination and is reachable.';
        } elseif (($result['timed_out'] ?? false) || (int) ($result['exit'] ?? -1) === 124) {
            $lines[] = 'The test reached its time limit. Any partial readings below do not confirm a complete test.';
        } else {
            $lines[] = 'The test did not complete successfully. Check the technical output for the reported error; this alone does not prove the device is down.';
        }
    }
    switch ($tool) {
        case 'ping':
            if (preg_match('/(\d+) packets transmitted,\s*(\d+) (?:packets )?received.*?([\d.]+)% packet loss/s', $output, $m)) {
                $lines[] = "$m[2] of $m[1] probes received a reply. Packet loss: $m[3]%.";
                if ((float) $m[3] > 0) $lines[] = 'Missing replies can mean loss, filtering or an unreachable device. Check the path and repeat the test.';
            }
            if (preg_match('/(?:rtt|round-trip)[^=]*=\s*[\d.]+\/([\d.]+)\//', $output, $m)) $lines[] = "Average reply time: $m[1] milliseconds. Lower values mean faster replies.";
            break;
        case 'traceroute_icmp':
        case 'traceroute_tcp':
        case 'traceroute':
            $lines[] = 'Each numbered line is a network hop. A * means no reply arrived for that probe; it does not by itself mean a broken link.';
            $lines[] = 'A completed command does not guarantee the destination replied. Check the last responding address in Technical output.';
            break;
        case 'mtr_icmp':
        case 'mtr_tcp':
            $lines[] = 'Loss% and reply times describe each responding hop. Loss at an intermediate hop alone does not prove end-to-end packet loss; routers can limit probe replies.';
            $lines[] = 'Review the final hop in Technical output to check whether the destination replied.';
            break;
        case 'nping_icmp':
        case 'nping_tcp':
        case 'hping3_icmp':
        case 'hping3_tcp':
            $lines[] = 'Review replies in Technical output. Missing replies can mean filtering or loss; an ICMP error is not an echo reply.';
            if (str_ends_with($tool, '_tcp')) $lines[] = 'A TCP reset is a response, but it does not mean port 443 is open. These SYN probes do not test a complete HTTPS connection.';
            break;
        case 'arp':
            preg_match_all('/^\S+\s+dev\s+\S+.*$/m', $output, $rows);
            if ($ok) $lines[] = count($rows[0]) . ' neighbour entries are shown. This is the collector’s cache, not a full device inventory or the selected device’s ARP table.';
            $lines[] = 'STALE means an entry has not been confirmed recently. FAILED or INCOMPLETE means address resolution did not succeed.';
            break;
        case 'iperf3':
            if ($ok && preg_match('/Receiver throughput:\s*([\d,.]+) Mbit\/s/', $output, $m)) $lines[] = "Measured receive speed: $m[1] megabits per second (Mbps).";
            else $lines[] = 'No complete receive-speed measurement was identified.';
            $lines[] = 'This is a test reading, not a guaranteed link speed. Other traffic and endpoint performance can affect it.';
            break;
        case 'netperf':
            if ($ok && preg_match('/10\^6bits\/sec\s*\n\s*\n?\s*\d+\s+\d+\s+\d+\s+[\d.]+\s+([\d.]+)/', $output, $m)) $lines[] = "Measured transfer speed: $m[1] megabits per second (Mbps).";
            else $lines[] = 'No complete transfer-speed measurement was identified in the saved output.';
            $lines[] = 'Speed depends on the test duration, endpoint performance and other traffic.';
            break;
        case 'pathchar':
            $lines[] = 'Capacity values are estimates, not measured usable transfer speed. Missing replies can leave gaps; review the hop readings in Technical output.';
            break;
    }
    if ($ok) array_splice($lines, 1, 0, ['The tool finished without reporting an execution error.']);
    return $lines;
}

/** Structured readings from the same saved text shown in Technical output. */
function nms_diag_description(array $result): array
{
    $text = (string) ($result['output'] ?? '');
    $tool = $result['tool'] ?? '';
    $failed = !isset($result['exit']) || (int) $result['exit'] !== 0;
    $warning = !empty($result['truncated']);
    $metrics = ['Target' => $result['target'] ?? 'Not reported'];
    if (preg_match('/_(icmp|tcp)$/', $tool, $protocol)) {
        $metrics['Probe protocol'] = strtoupper($protocol[1]);
        if ($protocol[1] === 'tcp') $metrics['Destination port'] = '443';
    }
    $missing = 'Not reported';
    switch ($tool) {
        case 'netperf':
            $metrics += ['Test type'=>'TCP_STREAM', 'Duration'=>$missing, 'Transfer speed'=>$missing,
                'Data sent'=>$missing, 'Data received'=>$missing, 'Receive socket buffer'=>$missing,
                'Send socket buffer'=>$missing, 'Send message size'=>$missing];
            if (preg_match('/10\^6bits\/sec\s*\n\s*(\d+)\s+(\d+)\s+(\d+)\s+([\d.]+)\s+([\d.]+)/', $text, $m)) {
                $metrics['Receive socket buffer'] = $m[1] . ' bytes';
                $metrics['Send socket buffer'] = $m[2] . ' bytes';
                $metrics['Send message size'] = $m[3] . ' bytes';
                $metrics['Duration'] = $m[4] . ' seconds';
                $metrics['Transfer speed'] = $m[5] . ' Mbps';
            } else $warning = true;
            // Socket sizes are buffers, never totals of bytes transferred.
            break;
        case 'iperf3':
            $metrics += ['Test type'=>$missing, 'Duration'=>$missing, 'Data sent'=>$missing, 'Data received'=>$missing, 'Send speed'=>$missing, 'Receive speed'=>$missing];
            $begin = strpos($text, '{'); $end = strrpos($text, '}');
            $json = $begin !== false && $end !== false ? json_decode(substr($text, $begin, $end - $begin + 1), true) : null;
            if (!empty($json['error'])) $failed = true;
            $metrics['Test type'] = $json['start']['test_start']['protocol'] ?? $missing;
            foreach (['sum_sent'=>['Data sent','Send speed'], 'sum_received'=>['Data received','Receive speed']] as $key=>$labels) {
                $value = $json['end'][$key] ?? [];
                if (isset($value['bytes'])) $metrics[$labels[0]] = number_format((float)$value['bytes'], 0) . ' bytes';
                if (isset($value['bits_per_second'])) $metrics[$labels[1]] = number_format((float)$value['bits_per_second']/1000000, 2) . ' Mbps';
                if (isset($value['seconds'])) $metrics[$key === 'sum_sent' ? 'Send duration' : 'Duration'] = (string)$value['seconds'] . ' seconds';
            }
            if ($metrics['Receive speed'] === $missing) $warning = true;
            break;
        case 'ping':
            $metrics += ['Packets sent'=>$missing,'Packets received'=>$missing,'Packet loss'=>$missing,'Duration'=>$missing,'Average reply time'=>$missing];
            if (preg_match('/(\d+) packets transmitted,\s*(\d+) (?:packets )?received.*?([\d.]+)% packet loss/', $text, $m)) {
                $metrics['Packets sent']=$m[1]; $metrics['Packets received']=$m[2]; $metrics['Packet loss']=$m[3].'%';
                if ((float)$m[3] > 0) $warning=true;
                if ((float)$m[3] >= 100) $failed=true;
            } else $warning=true;
            if (preg_match('/packet loss,\s*time\s+(\d+)ms/', $text, $m)) $metrics['Duration']=$m[1].' ms';
            if (preg_match('/(?:rtt|round-trip)[^=]*=\s*[\d.]+\/([\d.]+)\//', $text, $m)) $metrics['Average reply time']=$m[1].' ms';
            break;
        case 'arp':
            preg_match_all('/^\S+\s+dev\s+\S+.*$/m', $text, $rows);
            $metrics['Neighbour entries']=(string)count($rows[0]);
            $issues=0;
            foreach ($rows[0] as $row) if (preg_match('/\b(FAILED|INCOMPLETE)\b/', $row)) $issues++;
            $metrics['Unresolved entries']=(string)$issues;
            if ($issues) $warning=true;
            break;
        case 'traceroute_icmp':
        case 'traceroute_tcp':
        case 'traceroute':
            preg_match_all('/^\s*\d+[ :]+.*$/m', $text, $rows);
            $metrics['Reported hop lines']=(string)count($rows[0]);
            $metrics['Probes without a reply']=(string)substr_count(implode('\n',$rows[0]), '*');
            $metrics['Duration']=$missing;
            if (!$rows[0] || strpos(implode(' ',$rows[0]), '*') !== false) $warning=true;
            break;
        case 'pathchar':
            $metrics['Test type']='Route capacity estimate';
            $metrics['Duration']=$missing;
            $local = nms_diag_summary_local($result);
            if (!empty($result['timed_out'])) $failed=true;
            $metrics['Scope']=$local ? 'Collector self-test' : 'Network route';
            $metrics['Hop estimates']='No usable capacity estimate reported';
            preg_match_all('/Hop char:\s*rtt\s*=\s*([-+\d.eE]+) ms,\s*bw\s*=\s*([-+\d.eE]+) (\S+)/', $text, $hops, PREG_SET_ORDER);
            $usable=0;
            foreach ($hops as $i=>$hop) {
                $capacity=(float)$hop[2];
                $metrics['Hop '.($i+1).' estimate']='Reply time '.$hop[1].' ms · '.($capacity>0 ? $hop[2].' '.$hop[3] : 'Capacity unavailable');
                if ($capacity>0 && is_finite($capacity)) $usable++;
            }
            if ($hops) $metrics['Hop estimates']=$usable.' of '.count($hops).' with positive capacity estimates';
            preg_match_all('/Partial loss:\s*(\d+)\s*\/\s*(\d+)/', $text, $losses, PREG_SET_ORDER);
            $lost=0; $sent=0;
            foreach ($losses as $loss) { $lost+=(int)$loss[1]; $sent+=(int)$loss[2]; }
            if ($sent) { $metrics['Probes sent']=(string)$sent; $metrics['Replies received']=(string)max(0,$sent-$lost); }
            $complete=preg_match('/Path length:\s*\d+ hops/', $text) && preg_match('/End time:/', $text);
            $warning = $warning || !$complete || $lost>0 || (!$local && (!$hops || $usable<count($hops))) || (bool)preg_match('/timed? out|unreachable|no repl(?:y|ies)|insufficient|unreliable/i',$text);
            if (!$local && preg_match('/\bb\s*=\s*-/', $text)) $warning=true;
            if (!$local && preg_match_all('/r2\s*=\s*([-+\d.eE]+)/', $text, $fits)) {
                foreach ($fits[1] as $fit) if ((float)$fit < 0.5) $warning=true;
            }
            if ($local) $metrics['Network capacity']='Not applicable to a local self-test';
            if (preg_match('/Path length:\s*(\d+) hops/', $text, $m)) $metrics['Path length']=$m[1].((int)$m[1]===1?' hop':' hops');
            if (preg_match('/Path char:\s*rtt = ([\d.]+) ms/', $text, $m)) $metrics['Path reply time']=$m[1].' ms';
            if (preg_match('/Start time:\s*([^\r\n]+)/', $text, $start) && preg_match('/End time:\s*([^\r\n]+)/', $text, $end)) {
                $a=strtotime(trim($start[1])); $b=strtotime(trim($end[1]));
                if ($a !== false && $b !== false && $b >= $a) $metrics['Duration']=($b-$a).' seconds';
            }

            break;
    }
    if (preg_match('/^(mtr|nping|hping3)_/', $tool)) {
        $probe = nms_diag_probe_metrics($result);
        $metrics += $probe['metrics'];
        $warning = $warning || !$probe['confirmed'];
    }
    $lines=nms_diag_plain_summary($result);
    if ($tool === 'pathchar') {
        $lines = array_values(array_filter($lines, static fn($line)=>!str_starts_with($line,'Capacity values are estimates')));
        $lines[] = nms_diag_summary_local($result)
            ? 'Choose a different network device to estimate route capacity. This local test does not measure a physical network link.'
            : ($warning ? 'Some hop estimates are missing or unreliable. Check replies and repeat the test; this alone does not prove a network fault.' : 'Hop capacities are estimates from probe timings, not guaranteed transfer speeds.');
    }
    if ($failed || $warning) $lines=array_values(array_filter($lines, static function($line) { return $line !== 'The tool finished without reporting an execution error.'; }));
    if ($tool === 'netperf') $lines[]='Socket buffers and message size are not totals sent or received. This output does not report those totals.';
    return ['tone'=>$failed?'error':($warning?'warning':'success'),
        'status'=>$failed?'Test failed':($warning?'Review required':($tool==='pathchar' && nms_diag_summary_local($result)?'Local self-test completed':'Test completed')),
        'metrics'=>$metrics, 'lines'=>$lines];
}

/** Read probe statistics while requiring actual endpoint replies for a successful summary. */
function nms_diag_probe_metrics(array $result): array
{
    $tool = $result['tool'] ?? '';
    $text = (string) ($result['output'] ?? '');
    $metrics = ['Destination reachability' => 'No confirmed endpoint reply'];
    $confirmed = false;
    if (str_starts_with($tool, 'mtr_')) {
        preg_match_all('/^\s*\d+\.\|--\s+(\S+)\s+([\d.]+)%\s+(\d+)\s+([\d.]+)\s+([\d.]+)/m', $text, $rows, PREG_SET_ORDER);
        $metrics['Reported hops'] = (string) count($rows);
        if ($rows) {
            $last = end($rows);
            $metrics['Last responding address'] = $last[1];
            $metrics['Final hop packet loss'] = $last[2] . '%';
            $metrics['Final hop probes sent'] = $last[3];
            $metrics['Final hop average reply time'] = $last[5] . ' ms';
            $expected = @inet_pton((string) ($result['target'] ?? ''));
            $confirmed = $expected !== false && $expected === @inet_pton($last[1]) && (int)$last[3] > 0 && (float)$last[2] === 0.0;
        }
    } else {
        $nping = str_starts_with($tool, 'nping_');
        $pattern = $nping
            ? '/Raw packets sent:\s*(\d+)\s*\([^)]*\)\s*\|\s*Rcvd:\s*(\d+)\s*\([^)]*\)\s*\|\s*Lost:\s*\d+\s*\(([\d.]+)%\)/'
            : '/(\d+) packets transmitted,\s*(\d+) packets received,\s*([\d.]+)% packet loss/';
        if (preg_match($pattern, $text, $stats)) {
            $metrics['Packets sent'] = $stats[1];
            $metrics['Packets received'] = $stats[2];
            $metrics['Packet loss'] = $stats[3] . '%';
            $tcp = str_ends_with($tool, '_tcp');
            if ($nping) {
                $replyPattern = $tcp ? '/^RCVD .* TCP .* SA(?: |$)/m' : '/^RCVD .* Echo reply \(type=0\/code=0\)/m';
            } else {
                $replyPattern = $tcp ? '/^len=.* flags=SA(?: |$)/m' : '/^len=.* icmp_seq=\d+ /m';
            }
            $replies = preg_match_all($replyPattern, $text);
            $metrics[$tcp ? 'TCP SYN-ACK replies' : 'ICMP echo replies'] = (string) $replies;
            $confirmed = (int)$stats[1] > 0 && (int)$stats[1] === (int)$stats[2] && (float)$stats[3] === 0.0 && $replies === (int)$stats[1];
        }
        if (preg_match('/Avg rtt:\s*([\d.]+)ms/', $text, $rtt) || preg_match('/round-trip min\/avg\/max = [\d.]+\/([\d.]+)\//', $text, $rtt)) {
            $metrics['Average reply time'] = $rtt[1] . ' ms';
        }
    }
    if ($confirmed) $metrics['Destination reachability'] = str_ends_with($tool, '_tcp') ? 'TCP probe replies received (not an application test)' : 'ICMP probe replies received';
    return ['metrics' => $metrics, 'confirmed' => $confirmed];
}
