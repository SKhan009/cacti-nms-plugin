<?php
/** Reuse native retry settings within the short verification budget, without changing polling. */
function nms_workspace_bounded_snmp($host)
{
    $retries=$host['nms_snmp_retries']??read_config_option('snmp_retries');
    if((int)($host['snmp_version']??0)===0 || (int)($host['snmp_timeout']??0)<1 || (int)$host['snmp_timeout']>2000 ||
        !is_scalar($retries) || !preg_match('/^\d+$/D',(string)$retries)) {
        throw new RuntimeException('Verification requires enabled SNMP with timeout from 1 to 2000 ms and a valid nonnegative retry count.');
    }
    $host['nms_snmp_retries']=min(2,(int)$retries);
    return $host;
}
