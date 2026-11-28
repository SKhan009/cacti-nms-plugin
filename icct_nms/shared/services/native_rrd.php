<?php

/** Exact-request memoization only: a new request must see Boost/rrdcached updates. */
function icct_nms_rrd_fetch($id, $start, $end, $resolution, $showUnknown = true, $rra = null, $cf = 'AVERAGE')
{
    global $config;
    $key = hash('sha256', serialize([$config['poller_id'] ?? 0, $_SESSION['sess_user_id'] ?? 0,
        $id, $start, $end, $resolution, $showUnknown, $rra, $cf]));
    $cache = &$GLOBALS['icct_nms_rrd_fetches'];
    if (!is_array($cache)) $cache = [];
    if (!array_key_exists($key, $cache)) {
        if (count($cache) >= 128) $cache = [];
        $cache[$key] = rrdtool_function_fetch($id, $start, $end, $resolution, $showUnknown, $rra, $cf);
    }
    return $cache[$key];
}
