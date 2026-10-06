<?php
/** Local Cacti server readings; no external service or shell command is required. */
function icct_nms_server_cpu_sample()
{
    $stat = @file_get_contents('/proc/stat');
    if ($stat === false || !preg_match('/^cpu\s+(.+)$/m', $stat, $match)) return null;
    $ticks = array_map('intval', preg_split('/\s+/', trim($match[1])));
    if (count($ticks) < 4) return null;
    return ['total' => array_sum(array_slice($ticks, 0, 8)), 'idle' => $ticks[3] + ($ticks[4] ?? 0)];
}
function icct_nms_server_status($path)
{
    $cpu = null;
    $before = icct_nms_server_cpu_sample();
    if ($before !== null) {
        usleep(50000);
        $after = icct_nms_server_cpu_sample();
        $total = $after !== null ? $after['total'] - $before['total'] : 0;
        if ($total > 0) $cpu = max(0, min(100, round(100 * (1 - ($after['idle'] - $before['idle']) / $total))));
    }
    $ram = null;
    $memory = @file_get_contents('/proc/meminfo');
    if ($memory !== false && preg_match('/^MemTotal:\s+(\d+)/m', $memory, $totalMemory) && preg_match('/^MemAvailable:\s+(\d+)/m', $memory, $availableMemory) && (int)$totalMemory[1] > 0) {
        $ram = max(0, min(100, round(100 * (1 - (int)$availableMemory[1] / (int)$totalMemory[1]))));
    }
    $totalDisk = @disk_total_space($path);
    $freeDisk = @disk_free_space($path);
    $disk = $totalDisk > 0 && $freeDisk !== false ? round(100 * (1 - $freeDisk / $totalDisk)) : null;
    return ['hostname' => gethostname() ?: 'Cacti Server', 'cpu' => $cpu, 'ram' => $ram, 'disk' => $disk];
}
