<?php

const ICCT_NMS_NTP_EPOCH = 2208988800;
const ICCT_NMS_NTP_ERA = 4294967296;

function icct_nms_ntp_timestamp($epoch)
{
    $seconds = floor($epoch);
    return pack(
        'NN',
        ((int) $seconds + ICCT_NMS_NTP_EPOCH) % ICCT_NMS_NTP_ERA,
        (int) (($epoch - $seconds) * ICCT_NMS_NTP_ERA),
    );
}

function icct_nms_ntp_timestamp_read($bytes, $near)
{
    ['seconds' => $seconds, 'fraction' => $fraction] = unpack('Nseconds/Nfraction', $bytes);
    $epoch = $seconds - ICCT_NMS_NTP_EPOCH;
    $era = round(($near - $epoch) / ICCT_NMS_NTP_ERA);
    return $epoch + $era * ICCT_NMS_NTP_ERA + $fraction / ICCT_NMS_NTP_ERA;
}

function icct_nms_ntp_udp_sample($packet, $origin, $sent, $received, $host)
{
    if (strlen($packet) < 48) {
        throw new RuntimeException('Incomplete direct NTP response.');
    }
    $header = ord($packet[0]);
    $version = ($header >> 3) & 7;
    if (($header & 7) !== 4 || !in_array($version, [3, 4], true) ||
        !hash_equals($origin, substr($packet, 24, 8))) {
        throw new RuntimeException('The direct NTP response did not match the request.');
    }
    $stratum = ord($packet[1]);
    $leap = $header >> 6;
    if ($stratum === 0 && $leap !== 3) {
        throw new RuntimeException('The NTP server declined the request (stratum zero).');
    }
    $synchronized = $leap !== 3 && $stratum >= 1 && $stratum <= 15;
    $offset = $delay = $reference = null;
    if ($synchronized) {
        $timestamps = str_split(substr($packet, 32, 16), 8);
        if (in_array(str_repeat("\0", 8), $timestamps, true)) {
            throw new RuntimeException('The direct NTP response omitted a measurement timestamp.');
        }
        [$serverReceived, $serverSent] = array_map(
            static fn($timestamp) => icct_nms_ntp_timestamp_read($timestamp, $sent),
            $timestamps,
        );
        if ($serverSent < $serverReceived || $received < $sent) {
            throw new RuntimeException('Invalid timestamp order in the direct NTP measurement.');
        }
        $offset = (($serverReceived - $sent) + ($serverSent - $received)) * 500;
        $delay = max(0, ($received - $sent) - ($serverSent - $serverReceived)) * 1000;
        $referenceBytes = substr($packet, 16, 8);
        if ($referenceBytes !== str_repeat("\0", 8)) {
            $reference = icct_nms_ntp_timestamp_read($referenceBytes, $sent);
        }
    }
    return [
        'synchronized' => $synchronized,
        'local_reference' => false,
        'source_id' => strtoupper(bin2hex(substr($packet, 12, 4))),
        'source_address' => null,
        'server_address' => $host,
        'stratum' => $stratum,
        'reference_epoch' => $reference,
        'leap_status' => ['Normal', 'Insert second', 'Delete second', 'Not synchronised'][$leap],
        'offset_ms' => $offset,
        'rms_offset_ms' => null,
        'jitter_ms' => null,
        'peer_delay_ms' => $delay,
        'root_delay_ms' => null,
    ];
}

function icct_nms_ntp_udp_read($host, $timeout)
{
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $endpoint = '[' . $host . ']';
    } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ||
        filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        $endpoint = $host;
    } else {
        throw new RuntimeException('Direct NTP requires an inventory IP address or hostname.');
    }
    $deadline = hrtime(true) + $timeout * 1000000000;
    $socket = @stream_socket_client('udp://' . $endpoint . ':123', $errno, $error, $timeout);
    if ($socket === false) {
        throw new RuntimeException('Could not open a direct NTP connection to the inventory address.');
    }
    try {
        stream_set_blocking($socket, false);
        $sent = microtime(true);
        $origin = icct_nms_ntp_timestamp($sent);
        if (@fwrite($socket, "\x23" . str_repeat("\0", 39) . $origin) !== 48) {
            throw new RuntimeException('Could not send the direct NTP request.');
        }
        $remaining = (int) (($deadline - hrtime(true)) / 1000);
        $read = [$socket];
        $write = $except = null;
        if ($remaining <= 0 ||
            @stream_select($read, $write, $except, intdiv($remaining, 1000000), $remaining % 1000000) !== 1) {
            throw new RuntimeException('No direct NTP response. Check that the inventory address serves NTP on UDP 123.');
        }
        $packet = @stream_socket_recvfrom($socket, 2048);
        $received = microtime(true);
        if ($packet === false || $packet === '' || hrtime(true) > $deadline) {
            throw new RuntimeException('No direct NTP response. Check that the inventory address serves NTP on UDP 123.');
        }
        return icct_nms_ntp_udp_sample($packet, $origin, $sent, $received, $host);
    } finally {
        fclose($socket);
    }
}
