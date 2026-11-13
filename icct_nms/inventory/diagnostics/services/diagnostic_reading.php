<?php

/** Extract displayable measurements from the terminal text saved with a diagnostic run. */
function icct_nms_diagnostic_reading($tool, $output)
{
    if (in_array($tool, ['mtr_icmp', 'mtr_tcp'], true)) {
        $hops = [];
        foreach (preg_split('/\R/', $output) as $line) {
            if (
                preg_match(
                    '/^\s*(\d+)\.\|--\s+(\S+)\s+([\d.]+%)\s+(\d+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*$/',
                    $line,
                    $match,
                )
            ) {
                $hops[] = array_slice($match, 1);
            }
        }
        return ['hops' => $hops];
    }
    if ($tool === 'ping') {
        $metrics = [];
        if (
            preg_match(
                '/(\d+) packets transmitted,\s*(\d+) (?:packets )?received,.*?([\d.]+)% packet loss/',
                $output,
                $counts,
            )
        ) {
            $metrics = [
                ['Probes sent', $counts[1], 'Test packets sent'],
                ['Replies received', $counts[2], 'Test packets that came back'],
                ['Packet loss', $counts[3] . '%', 'Probes that went unanswered'],
            ];
        }
        if (
            preg_match(
                '/(?:rtt|round-trip) [^=\r\n]+=\s*[\d.]+\/([\d.]+)\/[\d.]+\/[\d.]+ ms/',
                $output,
                $timing,
            )
        ) {
            $metrics[] = ['Average response', $timing[1] . ' ms', 'Average round-trip time'];
        }
        return ['metrics' => $metrics];
    }
    if (in_array($tool, ['traceroute', 'traceroute_icmp', 'traceroute_tcp', 'arp'], true)) {
        $arp = $tool === 'arp';
        $rows = [];
        foreach (preg_split('/\R/', $output) as $line) {
            if ($arp && preg_match('/^(\S+)\s+dev\s+(\S+)\s+(.*\S)\s*$/', $line, $match)) {
                $rows[] = array_slice($match, 1);
            } elseif (
                !$arp &&
                preg_match('/^\s*(\d+)(?:\s+|:\s+)(\S+)(?:\s+(.*\S))?\s*$/', $line, $match)
            ) {
                $rows[] = [$match[1], $match[2], $match[3] ?? ''];
            }
        }
        return [
            'table_title' => $arp ? 'Neighbours in the collector cache' : 'Route measurements',
            'table_headers' => $arp
                ? ['Address', 'Interface', 'MAC and state']
                : ['Hop', 'Address or reply', 'Response times'],
            'table_rows' => $rows,
        ];
    }
    if ($tool === 'netperf') {
        return icct_nms_netperf_reading($output);
    }
    if ($tool === 'pathchar') {
        return icct_nms_pathchar_reading($output);
    }
    if (!in_array($tool, ['iperf3', 'iperf3_udp'], true)) {
        return [];
    }

    $reading = ['intervals' => [], 'totals' => []];
    $udp = $tool === 'iperf3_udp';
    foreach (preg_split('/\R/', $output) as $line) {
        if (preg_match('/^Connecting to host (.+), port (\d+)\s*$/', $line, $endpoint)) {
            $reading['endpoint'] = $endpoint[1];
            $reading['port'] = $endpoint[2];
        }
        if (
            preg_match(
                '/^\[\s*\d+\]\s+local (\S+) port \d+ connected to (\S+) port \d+/',
                $line,
                $connection,
            )
        ) {
            $reading['source_ip'] = $connection[1];
            $reading['destination_ip'] = $connection[2];
        }
        if (preg_match("/^\\$ .*? '-b' '([\\d.]+)([KMGT]?)'/", $line, $limit)) {
            $reading['target_rate'] = $limit[1] . ' ' . $limit[2] . 'bit/s';
        }
        if (
            !preg_match(
                '/^\[\s*\d+\]\s+([\d.]+)\s*-\s*([\d.]+)\s+sec\s+([\d.]+ [KMGT]?Bytes)\s+([\d.]+)\s+([KMGT]?)bits\/sec(.*)$/',
                $line,
                $match,
            )
        ) {
            continue;
        }
        [, $start, $end, $transfer, $rate, $unit, $extra] = $match;
        $scale = ['' => 0.000001, 'K' => 0.001, 'M' => 1, 'G' => 1000, 'T' => 1000000];
        $row = [
            'interval' => $start . '–' . $end . ' s',
            'seconds' => (float) $end - (float) $start,
            'transfer' => $transfer,
            'speed' => $rate . ' ' . $unit . 'bit/s',
            'mbps' => (float) $rate * $scale[$unit],
        ];
        if (preg_match('/\b(sender|receiver)\s*$/', $extra, $role)) {
            if (
                $udp &&
                preg_match('/^\s+([\d.]+)\s+ms\s+(\d+)\/(\d+)\s+\(([\d.]+)%\)/', $extra, $stats)
            ) {
                $row['jitter'] = $stats[1] . ' ms';
                $row['loss'] = $stats[4] . '% (' . $stats[2] . '/' . $stats[3] . ')';
            } elseif (!$udp && preg_match('/^\s+(\d+)\s+sender\s*$/', $extra, $stats)) {
                $row['retries'] = $stats[1];
            }
            $reading['totals'][$role[1]] = $row;
        } else {
            if (!$udp && preg_match('/^\s+(\d+)\s+([\d.]+ [KMGT]?Bytes)\s*$/', $extra, $stats)) {
                $row['retries'] = $stats[1];
                $row['window'] = $stats[2];
            } elseif ($udp && preg_match('/^\s+(\d+)\s*$/', $extra, $stats)) {
                $row['datagrams'] = $stats[1];
            }
            $reading['intervals'][] = $row;
        }
    }
    return $reading;
}

function icct_nms_netperf_reading($output)
{
    $command = strtok($output, "\n") ?: '';
    $mode = '';
    if (preg_match("/(?:^|[ \t])'?\\-t'?[ \t]+'?([A-Z_]+)\\b/", $command, $test)) {
        $mode = $test[1];
    } elseif (
        preg_match('/^(?:MIGRATED\s+)?(TCP STREAM|TCP MAERTS|UDP STREAM) TEST\b/m', $output, $test)
    ) {
        $mode = str_replace(' ', '_', $test[1]);
    }
    preg_match("/(?:^|[ \t])'?\\-p'?[ \t]+'?(\\d+)\\b/", $command, $port);
    $reading = [
        'details' => [
            'Test' => $mode,
            'Control port' => $port[1] ?? '',
        ],
    ];
    if (preg_match('/\bto [^\r\n]+?\b(AF_INET6|AF_INET)\b/', $output, $family)) {
        $reading['details']['IP version'] = $family[1] === 'AF_INET6' ? 'IPv6' : 'IPv4';
    }

    $decimal = '\d+(?:\.\d+)?';
    $header = '10\^6bits\/sec[ \t]*\R(?:[ \t]*\R)*[ \t]*';
    if (
        $mode === 'UDP_STREAM' &&
        preg_match(
            "/$header(\d+)[ \t]+(\d+)[ \t]+($decimal)[ \t]+(\d+)[ \t]+(\d+)[ \t]+($decimal)[ \t]*\R" .
                "[ \t]*(\d+)[ \t]+($decimal)[ \t]+(\d+)[ \t]+($decimal)[ \t]*(?:\R|$)/",
            $output,
            $rows,
        )
    ) {
        [
            ,
            $sendBuffer,
            $message,
            $time,
            $sent,
            $errors,
            $sendRate,
            $receiveBuffer,
            $receiveTime,
            $received,
            $receiveRate,
        ] = $rows;
        $shortfall = 'Not available';
        $shortfallNote = 'Requires a positive sender count and a receiver count no larger than it.';
        if ((int) $sent > 0 && (int) $received <= (int) $sent) {
            $missing = (int) $sent - (int) $received;
            $shortfall = number_format((100 * $missing) / (int) $sent, 2) . '%';
            $shortfallNote = number_format($missing) . ' fewer messages received';
        }
        $reading['details'] += [
            'Measured duration' => $time . ' s',
            'Message size' => number_format((int) $message) . ' bytes',
            'Local send errors' => number_format((int) $errors),
        ];
        $reading['metrics'] = [
            ['Received speed', $receiveRate . ' Mbit/s', 'Average speed at the receiver'],
            ['Sent messages', number_format((int) $sent), 'Messages accepted by the sender'],
            [
                'Received messages',
                number_format((int) $received),
                'Messages counted at the receiver',
            ],
            ['Delivery shortfall', $shortfall, $shortfallNote],
        ];
        $reading['tables'] = [
            [
                'Sender and receiver results',
                [
                    'Endpoint',
                    'Messages',
                    'Average speed',
                    'Socket buffer',
                    'Elapsed time',
                    'Send errors',
                ],
                [
                    [
                        'Sender',
                        number_format((int) $sent),
                        $sendRate . ' Mbit/s',
                        number_format((int) $sendBuffer) . ' bytes',
                        $time . ' s',
                        $errors,
                    ],
                    [
                        'Receiver',
                        number_format((int) $received),
                        $receiveRate . ' Mbit/s',
                        number_format((int) $receiveBuffer) . ' bytes',
                        $receiveTime . ' s',
                        '',
                    ],
                ],
            ],
            [
                'Disturbance and loss',
                ['Metric', 'Result', 'Meaning'],
                [
                    [
                        'Delivery shortfall',
                        $shortfall,
                        'Compares sent and received counts. The cause and location are unknown.',
                    ],
                    ['Local send errors', $errors, 'Send failures reported by the client.'],
                    ['Jitter', 'Not reported', 'This output has no jitter measurement.'],
                ],
            ],
        ];
    } elseif (
        in_array($mode, ['TCP_STREAM', 'TCP_MAERTS'], true) &&
        preg_match(
            "/$header(\d+)[ \t]+(\d+)[ \t]+(\d+)[ \t]+($decimal)[ \t]+($decimal)[ \t]*(?:\R|$)/",
            $output,
            $row,
        )
    ) {
        [, $receiveBuffer, $sendBuffer, $message, $time, $rate] = $row;
        $reverse = $mode === 'TCP_MAERTS';
        $reading['details'] += [
            'Direction' => $reverse ? 'Server to client' : 'Client to server',
            'Measured duration' => $time . ' s',
            'Message size' => number_format((int) $message) . ' bytes',
        ];
        $reading['metrics'] = [
            [
                'Transfer speed',
                number_format((float) $rate, strlen(substr(strrchr($rate, '.') ?: '', 1))) .
                ' Mbit/s',
                'Reported TCP stream throughput',
            ],
            ['Sent messages', 'Not reported', 'Sender message count'],
            ['Received messages', 'Not reported', 'Receiver message count'],
            ['Delivery shortfall', 'Not available', 'Requires both message counts'],
        ];
        $reading['tables'] = [
            [
                'Sender and receiver results',
                ['Endpoint', 'Role', 'Messages', 'Average speed', 'Socket buffer', 'Send errors'],
                [
                    [
                        $reverse ? 'Server' : 'Client',
                        'Sender',
                        'Not reported',
                        'Not reported separately',
                        number_format((int) $sendBuffer) . ' bytes',
                        'Not reported',
                    ],
                    [
                        $reverse ? 'Client' : 'Server',
                        'Receiver',
                        'Not reported',
                        'Not reported separately',
                        number_format((int) $receiveBuffer) . ' bytes',
                        '',
                    ],
                ],
            ],
            [
                'Disturbance and loss',
                ['Metric', 'Result', 'Meaning'],
                [
                    [
                        'Delivery shortfall',
                        'Not available',
                        'Requires sender and receiver message counts.',
                    ],
                    [
                        'Local send errors',
                        'Not reported',
                        'This result provides no send error count.',
                    ],
                    ['Jitter', 'Not reported', 'This result provides no packet timing variation.'],
                    [
                        'TCP retransmissions',
                        'Not reported',
                        'This result provides no count of retransmitted segments.',
                    ],
                ],
            ],
        ];
    }
    return $reading;
}

function icct_nms_pathchar_reading($output)
{
    $number = '[+-]?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?';
    $extract = static function ($pattern, $text, $group = 1) {
        return preg_match($pattern, $text, $match) ? $match[$group] ?? '' : '';
    };
    $details = [
        'Tool' => $extract('/^(pchar|pathchar) to /mi', $output),
        'Probe type' => $extract('/^(?:pchar|pathchar) to [^\r\n]+ using ([^\r\n]+)/mi', $output),
        'Repetitions per hop' => $extract('/^(\d+) repetition\(s\) per hop/m', $output),
        'Maximum packet size' => $extract(
            '/^Packet size increments from \d+ to (\d+) by \d+/m',
            $output,
        ),
        'Packet-size step' => $extract(
            '/^Packet size increments from \d+ to \d+ by (\d+)/m',
            $output,
        ),
    ];
    foreach (['Maximum packet size', 'Packet-size step'] as $label) {
        if ($details[$label] !== '') {
            $details[$label] .= ' bytes';
        }
    }
    $length = $extract('/Path length:\s+(\d+) hops?/', $output);
    $rtt = $extract("/Path char:\s+rtt = ($number ms)/", $output);
    $bottleneck = $extract("/Path bottleneck:\s+($number Kbps)/", $output);
    $queue = $extract("/Path queueing:\s+average = ($number ms)/", $output);
    $destinationLoss = '';
    $intermediate = [];
    $rows = [];
    $estimates = [];
    preg_match_all(
        '/^[ \t]*(\d+):[ \t]*([^\r\n]*)\R((?:(?![ \t]*\d+:)[ \t]+[^\r\n]*\R?)*)/m',
        $output,
        $hops,
        PREG_SET_ORDER,
    );
    foreach ($hops as [, $hop, $address, $block]) {
        $loss = $extract("/Partial loss:\s+(\d+\s*\/\s*\d+\s*\($number%\))/", $block);
        $capacity = $extract("/Hop char:[^\r\n]*\bbw = ($number Kbps)/", $block);
        $rows[] = [
            $hop,
            trim($address),
            $capacity,
            $extract("/Hop char:\s+rtt = ($number ms)/", $block),
            $extract("/Partial char:\s+rtt = ($number ms)/", $block),
            $loss,
        ];
        if ($length !== '' && (int) $hop === (int) $length) {
            $destinationLoss = $loss;
        } elseif ((int) $hop > 0 && $loss !== '' && (int) $loss > 0) {
            $intermediate[] = "Hop $hop: $loss";
        }
        $rttDeviation = $extract("/stddev rtt = ($number)(?=[ \\t]*(?:ms|,|$))/m", $block);
        $estimate = [
            $extract("/Partial char:[^\r\n]*r2 = ($number)/", $block),
            $extract("/Hop queueing:\s+avg = ($number ms)/", $block),
            $extract('/Hop queueing:[^\r\n]*\((\d+ bytes)\)/', $block),
            $rttDeviation !== '' ? $rttDeviation . ' ms' : '',
            $extract("/Partial char:[^\r\n]*\bb = ($number ms\/B)/", $block),
        ];
        if (array_filter($estimate, static fn($value) => $value !== '')) {
            $estimates[] = array_merge([$hop], $estimate);
        }
    }

    return [
        'details' => $details,
        'metrics' => [
            [
                'Estimated bottleneck',
                $bottleneck ?: 'Not reported',
                'Lowest estimated link capacity',
            ],
            [
                'Estimated round-trip delay',
                $rtt ?: 'Not reported',
                'Round-trip estimate for the path',
            ],
            [
                'Destination probe loss',
                $destinationLoss ?: 'Not reported',
                'Missing replies at the destination',
            ],
            ['Hops', $length !== '' ? $length : 'Not reported', 'Reported path length'],
        ],
        'tables' => [
            [
                'Hop readings',
                [
                    'Hop',
                    'Responding address',
                    'Estimated link capacity',
                    'Added RTT',
                    'Cumulative RTT',
                    'Missing replies',
                ],
                $rows,
            ],
            [
                'Detailed estimates by hop',
                [
                    'Hop',
                    'Model fit (R²)',
                    'Hop queueing',
                    'Queue estimate',
                    'Cumulative RTT standard deviation',
                    'Cumulative slope',
                ],
                $estimates,
            ],
            [
                'Disturbance and loss',
                ['Metric', 'Result', 'Meaning'],
                [
                    [
                        'Intermediate probe loss',
                        implode('; ', $intermediate) ?:
                        'No missing replies listed for intermediate hops',
                        'Missing replies can reflect filtering or rate limiting.',
                    ],
                    [
                        'Destination probe loss',
                        $destinationLoss ?: 'Not reported',
                        'Missing replies at the destination.',
                    ],
                    [
                        'Path queueing estimate',
                        $queue ?: 'Not reported',
                        'Estimated delay from queueing.',
                    ],
                ],
            ],
            [
                'Path summary',
                ['Path pipe', 'Path fit (R²)'],
                [
                    [
                        $extract('/Path pipe:\s+(\d+ bytes)/', $output),
                        $extract("/Path char:[^\r\n]*r2 = ($number)/", $output),
                    ],
                ],
            ],
        ],
    ];
}
