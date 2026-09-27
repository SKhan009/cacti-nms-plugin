<?php
/** Choose storage schedules that Cacti's poller can actually execute. */
function nms_config_graph_profile(array $profiles, $reading_interval, $poller_interval)
{
    $base=(int)$poller_interval;
    if($base<1) throw new RuntimeException('Cacti poller interval is not configured.');
    $ceiling=max($base,(int)$reading_interval);
    $eligible=array_values(array_filter($profiles,function($profile) use($base,$ceiling) {
        $step=(int)$profile['step'];
        return $step >= $base && $step <= $ceiling && $step % $base === 0
            && (int)$profile['heartbeat'] >= $step;
    }));
    usort($eligible,function($a,$b) {
        return (int)$b['step'] <=> (int)$a['step']
            ?: (($b['default'] ?? '')==='on') <=> (($a['default'] ?? '')==='on')
            ?: (int)$a['id'] <=> (int)$b['id'];
    });
    if(!$eligible) throw new RuntimeException('No compatible Cacti Data Source Profile. Add a profile with a step of '.$base.' seconds and a heartbeat at least as long as its step.');
    return $eligible[0];
}
