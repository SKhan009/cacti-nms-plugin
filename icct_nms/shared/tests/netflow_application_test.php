<?php
require __DIR__ . '/../../protocols/netflow/services/netflow_service.php';
$cases = [
    [['application_name'=>'Actual App','proto'=>6,'dst_port'=>80], 'Actual App', 'exporter'],
    [['application_id'=>'13..42','proto'=>6,'dst_port'=>80], 'Application 13..42', 'exporter'],
    [['proto'=>6,'src_port'=>80,'dst_port'=>60534], 'HTTP', 'port-based service'],
    [['proto'=>6,'src_port'=>60534,'dst_port'=>80], 'HTTP', 'port-based service'],
    [['proto'=>6,'src_port'=>22,'dst_port'=>60534], 'SSH', 'port-based service'],
    [['proto'=>17,'src_port'=>67,'dst_port'=>68], 'DHCP', 'port-based service'],
    [['proto'=>6,'src_port'=>22,'dst_port'=>80], 'Not reported', 'unreported'],
    [['proto'=>6,'src_port'=>50001,'dst_port'=>50002], 'Not reported', 'unreported'],
];
foreach ($cases as [$record,$name,$basis]) {
    if (icct_nms_netflow_application($record) !== ['name'=>$name,'basis'=>$basis]) throw new Exception('Application classification failed');
}
echo "Application precedence, bidirectional services, DHCP and unknown flows passed.\n";
