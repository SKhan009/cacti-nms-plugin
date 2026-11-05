<?php

/** Parse measured seconds without accepting NaN, negative durations or overflow as clock data. */
function icct_nms_ntp_milliseconds($value)
{
    if (
        !is_string($value) ||
        !is_numeric($value) ||
        !is_finite((float) $value) ||
        (float) $value < 0 ||
        !is_finite((float) $value * 1000)
    ) {
        throw new RuntimeException('Invalid measured NTP duration.');
    }
    return (float) $value * 1000;
}

/** Chrony reports residual offset standard deviation per source, distinct from tracking RMS offset. */
function icct_nms_ntp_jitter($output, $source)
{
    $found = null;
    foreach (explode("\n", trim($output)) as $line) {
        $fields = str_getcsv(trim($line), ',', '"', '');
        if (count($fields) !== 8) {
            throw new RuntimeException('Unsupported chrony source statistics response.');
        }
        // Unselected reference clocks can be named IDs (e.g. PPS0), not network addresses.
        if (
            !filter_var($fields[0], FILTER_VALIDATE_IP) ||
            inet_pton($fields[0]) !== inet_pton($source)
        ) {
            continue;
        }
        if (
            $found !== null ||
            !preg_match('/^[0-9]+$/D', $fields[1]) ||
            (int) $fields[1] < 3 ||
            !preg_match('/^[0-9]+$/D', $fields[3]) ||
            (int) $fields[3] < 1
        ) {
            throw new RuntimeException(
                'Selected NTP source has insufficient or ambiguous jitter statistics.',
            );
        }
        $found = [
            'jitter_ms' => icct_nms_ntp_milliseconds($fields[7]),
            'jitter_samples' => (int) $fields[1],
            'jitter_span_seconds' => (int) $fields[3],
        ];
    }
    if ($found === null) {
        throw new RuntimeException('Selected NTP source is missing from chrony source statistics.');
    }
    return $found;
}

/** Parse named peer fields so appended chrony diagnostics cannot shift the measured delay column. */
function icct_nms_ntp_peer_delay($output, $source)
{
    $selected = null;
    $current = null;
    foreach (explode("\n", trim($output)) as $line) {
        if (
            preg_match(
                '/^Remote address\s*:\s*(\S+)\s+\([A-Fa-f0-9]{8}\)\s*$/D',
                trim($line),
                $match,
            )
        ) {
            if (!filter_var($match[1], FILTER_VALIDATE_IP)) {
                throw new RuntimeException('Invalid chrony peer address.');
            }
            $current = inet_pton($match[1]) === inet_pton($source);
            if ($current) {
                if ($selected !== null) {
                    throw new RuntimeException('Ambiguous selected NTP peer.');
                }
                $selected = [];
            }
        } elseif (
            $current &&
            preg_match(
                '/^(Peer delay|Total valid RX|Total good RX)\s*:\s*(.*?)\s*$/D',
                trim($line),
                $match,
            )
        ) {
            if (isset($selected[$match[1]])) {
                throw new RuntimeException('Duplicated chrony peer measurement.');
            }
            $selected[$match[1]] = $match[2];
        }
    }
    foreach (['Total valid RX', 'Total good RX'] as $counter) {
        if (
            $selected === null ||
            !isset($selected[$counter]) ||
            !preg_match('/^[0-9]+$/D', $selected[$counter]) ||
            (int) $selected[$counter] < 1
        ) {
            throw new RuntimeException('Selected NTP peer has no valid measured response.');
        }
    }
    if (
        !isset($selected['Peer delay']) ||
        !preg_match('/^(\S+) seconds$/D', $selected['Peer delay'], $match)
    ) {
        throw new RuntimeException('Selected NTP peer delay is unavailable.');
    }
    return icct_nms_ntp_milliseconds($match[1]);
}

/** Bracket the detail reads with tracking so measurements from different selected sources never mix. */
function icct_nms_ntp_observe($read)
{
    $sample = icct_nms_ntp_parse($read('tracking'));
    if (!$sample['synchronized']) {
        return $sample;
    }
    $source = $sample['source_address'];
    if ($source === null) {
        throw new RuntimeException('A selected NTP peer address is required for jitter and delay.');
    }
    $details = icct_nms_ntp_jitter($read('jitter'), $source);
    $details['peer_delay_ms'] = icct_nms_ntp_peer_delay($read('delay'), $source);
    $after = icct_nms_ntp_parse($read('tracking'));
    if (
        !$after['synchronized'] ||
        $after['source_id'] !== $sample['source_id'] ||
        $after['source_address'] !== $source ||
        $after['reference_epoch'] !== $sample['reference_epoch']
    ) {
        throw new RuntimeException(
            'NTP source or reference measurement changed during collection.',
        );
    }
    return array_replace($sample, $details);
}
