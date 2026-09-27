<?php
/** Readings workspace routes and evidence interpretation; no network I/O or mutations. */

/** Whitelist workspace sections before template selection. */
function nms_workspace_tabs()
{
    return ['overview'=>'Overview','identity'=>'IP addresses & identity','networks'=>'Network discovery','duplicates'=>'Duplicate review','neighbours'=>'Discovery','history'=>'History'];
}

/** Build internal links without allowing arbitrary routes or reflected query parameters. */
function nms_workspace_url($section = 'overview', $id = 0, $extra = [])
{
    if ($section === 'diagnosis') return 'diagnostics.php?' . http_build_query(array_merge(['section'=>'diagnosis','host_id'=>max(0,(int)$id)],array_intersect_key($extra,array_flip(['job_id']))), '', '&', PHP_QUERY_RFC3986);
    if (!isset(nms_workspace_tabs()[$section])) $section = 'overview';
    return 'devices.php?' . http_build_query(array_merge(['tab'=>'readings','section'=>$section,'id'=>max(0,(int)$id)], array_intersect_key($extra,array_flip(['view','job_id','history_page','network_id','saved','run_id','scan_page','result_page','audit_page','management_review','consolidation_keep','consolidation_other']))), '', '&', PHP_QUERY_RFC3986);
}

/** Interpret only current, same-target, same-collector diagnostic evidence for this host. */
function nms_workspace_probe_evidence($host, $job, $now, $fresh_seconds = 900)
{
    if (!$job || (int)$job['host_id'] !== (int)$host['id'] || (int)$job['poller_id'] !== (int)$host['poller_id']) return null;
    $at = strtotime((string)($job['finished_at'] ?? ''));
    if (!$at || $at > $now + 30 || $now - $at > $fresh_seconds || !in_array($job['status'],['complete','failed'],true)) return null;
    $result = json_decode((string)$job['result_json'],true);
    if (!is_array($result) || !empty($result['self_test']) || !empty($result['truncated']) || !empty($result['protocol_error'])) return null;
    if (($result['target'] ?? '') !== $host['hostname'] || ($result['tool'] ?? '') !== $job['tool']) return null;
    if (!isset($result['exit']) || !in_array((int)$result['exit'],[0,1],true)) return null;
    $loss = null; $latency = null;
    if ($job['tool'] === 'ping') {
        $text=(string)($result['output'] ?? '');
        if (preg_match('/(\d+) packets transmitted,\s*(\d+) (?:packets )?received.*?([\d.]+)% packet loss/', $text, $m) && (int)$m[1]>0 && (int)$m[2]<=(int)$m[1]) {
            $calculated = 100 * ((int)$m[1]-(int)$m[2]) / (int)$m[1];
            if (is_numeric($m[3]) && abs($calculated-(float)$m[3])<1) $loss=(float)$m[3];
        }
        if ($loss !== null && $loss < 100 && preg_match('/(?:rtt|round-trip)[^=]*=\s*[\d.]+\/([\d.]+)\//', $text, $m)) $latency=(float)$m[1];
    }
    if ($loss === null) return null;
    return ['packet_loss'=>$loss,'latency_ms'=>$latency,'method'=>$job['tool'],'target'=>$result['target'],'collected_at'=>$job['finished_at'],'job_id'=>(int)$job['id']];
}
