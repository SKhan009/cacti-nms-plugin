<?php
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
    $lines = [$purpose[$tool] ?? 'Checks the selected device from its collector.'];
    if (!empty($result['self_test']) || stripos($output, 'Collector loopback self-test') !== false) {
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
        case 'traceroute':
            $lines[] = 'Each numbered line is a network hop. A * means no reply arrived for that probe; it does not by itself mean a broken link.';
            $lines[] = 'A completed command does not guarantee the destination replied. Check the last responding address in Technical output.';
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
            $metrics['Hop estimates']='See Technical output';
            if (preg_match('/Path length:\s*(\d+) hops/', $text, $m)) $metrics['Path length']=$m[1].' hops';
            if (preg_match('/Path char:\s*rtt = ([\d.]+) ms/', $text, $m)) $metrics['Path reply time']=$m[1].' ms';
            if (preg_match('/Start time:\s*([^\r\n]+)/', $text, $start) && preg_match('/End time:\s*([^\r\n]+)/', $text, $end)) {
                $a=strtotime(trim($start[1])); $b=strtotime(trim($end[1]));
                if ($a !== false && $b !== false && $b >= $a) $metrics['Duration']=($b-$a).' seconds';
            }

            $warning=true;
            break;
    }
    $lines=nms_diag_plain_summary($result);
    if ($failed || $warning) $lines=array_values(array_filter($lines, static function($line) { return $line !== 'The tool finished without reporting an execution error.'; }));
    if ($tool === 'netperf') $lines[]='Socket buffers and message size are not totals sent or received. This output does not report those totals.';
    return ['tone'=>$failed?'error':($warning?'warning':'success'),
        'status'=>$failed?'Test failed':($warning?'Review required':'Test completed'),
        'metrics'=>$metrics, 'lines'=>$lines];
}
