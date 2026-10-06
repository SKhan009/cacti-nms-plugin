<?php
require __DIR__ . '/../../dashboard/map/services/map_service.php';
$site=['id'=>7,'name'=>'Test siteSummary','coordinates'=>[12.9716,77.5946],'devices'=>[
 ['status'=>'Up','fault_counts'=>['Critical'=>2,'Warning'=>3]],
 ['status'=>'Down','fault_counts'=>['Critical'=>1,'Major'=>4]],
 ['status'=>'Disabled'],['status'=>'Unavailable'],
]];
$siteSummary=icct_nms_map_site_summary($site);
if($siteSummary['counts']!==['total'=>4,'online'=>1,'offline'=>1,'disabled'=>1,'other'=>1])throw new LogicException('Incorrect device counts');
if($siteSummary['fault_counts']!==['Critical'=>3,'Major'=>4,'Minor'=>0,'Warning'=>3,'Information'=>0])throw new LogicException('Incorrect alarm counts');
if($siteSummary['status']!=='Offline')throw new LogicException('Failed device hidden by online state');
$site['devices']=[['status'=>'Up']];if(icct_nms_map_site_summary($site)['status']!=='Online')throw new LogicException('Online state');
$site['devices']=[['status'=>'Disabled']];if(icct_nms_map_site_summary($site)['status']!=='Disabled')throw new LogicException('Disabled state');
$site['devices']=[];if(icct_nms_map_site_summary($site)['status']!=='Other')throw new LogicException('Empty siteSummary reported online');
echo "Site device totals, alarm aggregation and mixed/empty status passed.\n";
