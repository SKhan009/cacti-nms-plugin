<?php
/** Read-only device presentation. All data is loaded after device access checks. */
$deviceViewPage = true;
$status = icct_backend_device_status_name($old);
$segment = db_fetch_cell_prepared('SELECT name FROM plugin_icct_nms_categories WHERE id=?', [$values['category_id']]);
$rackName = db_fetch_cell_prepared('SELECT name FROM plugin_icct_nms_racks WHERE id=?', [$values['rack_id']]);
$profile = [];
foreach (icct_nms_device_types() as $type) {
    if ((int)$type['category_id'] === (int)$values['category_id'] && $type['name'] === $values['device_type']) { $profile = $type; break; }
}
$discovery = icct_nms_discovery_assignment($id);
$ssh = db_fetch_row_prepared('SELECT p.username FROM plugin_icct_nms_ssh_devices d JOIN plugin_icct_nms_ssh_presets p ON p.id=d.preset_id WHERE d.host_id=?', [$id]);
$protocols = [];
if ((int)$old['snmp_version'] > 0 && icct_backend_protocol_enabled($id, 'snmp')) $protocols[] = 'SNMP';
foreach (['lldp'=>'LLDP', 'cdp'=>'CDP'] as $key=>$label) {
    if (stripos((string)($discovery['protocol'] ?? ''), $key) !== false && icct_backend_protocol_enabled($id, $key)) $protocols[] = $label;
}
if ($ssh && icct_backend_protocol_enabled($id, 'ssh')) $protocols[] = 'SSH';
if (icct_backend_serial_assignment($id) && icct_backend_protocol_enabled($id, 'serial')) $protocols[] = 'Serial';
$diagnostics = is_realm_allowed(3) ? icct_backend_diag_selected_labels($id) : [];
$paths = [
 'ping'=>'M3 12h4l3-7 4 14 3-7h4',
 'traceroute'=>'M3 5h5v7h8v7h5 M18 16l3 3-3 3',
 'traceroute_icmp'=>'M3 5h6v7h6v7h6 M3 3v4 M7 3v4',
 'traceroute_tcp'=>'M3 5h6v7h6v7h6 M17 3h4v4 M17 7l4-4',
 'mtr_icmp'=>'M3 18V9h4v9 M10 18V5h4v13 M17 18V2h4v16',
 'mtr_tcp'=>'M3 18V9h4v9 M10 18V5h4v13 M17 18V2h4v16 M2 22h20',
 'arp'=>'M3 3h6v6H3z M15 3h6v6h-6z M9 15h6v6H9z M6 9v3h12V9 M12 12v3',
 'iperf3'=>'M4 18a9 9 0 1 1 16 0 M12 12l5-5 M4 18h16',
 'netperf'=>'M3 17l5-5 4 3 8-10 M15 5h5v5',
 'pathchar'=>'M3 20h18 M5 17V8 M10 17V4 M15 17V11 M20 17V6',
];
// Collapse transport variants into one control per diagnostic family.
$diagnosticGroups = [];
foreach ($diagnostics as $tool=>$label) {
    $family = in_array($tool, ['traceroute','traceroute_icmp','traceroute_tcp'], true) ? 'traceroute' : (in_array($tool, ['mtr_icmp','mtr_tcp'], true) ? 'mtr' : $tool);
    $diagnosticGroups[$family][$tool] = $label;
}
$display = static function($value) { return icct_nms_h(trim((string)$value) !== '' ? $value : '—'); };
?>
<div class="device-view">
<header class="device-view-header">
 <a class="device-back" href="inventory.php" aria-label="Back to inventory">←</a>
 <div class="device-view-identity"><h1><span class="device-status-dot <?= $status==='Up'?'online':($status==='Down'?'offline':'other') ?>" aria-label="<?= icct_nms_h($status) ?>"></span><?= icct_nms_h($values['description']) ?></h1><span><?= icct_nms_h($values['hostname']) ?></span></div>
 <dl class="device-view-metrics"><div><dt>Packet Loss</dt><dd><?= isset($old['cur_loss']) && is_numeric($old['cur_loss']) ? $display($old['cur_loss']).'%' : '—' ?></dd></div><div><dt>Response Time</dt><dd><?= isset($old['cur_time']) && is_numeric($old['cur_time']) ? number_format((float)$old['cur_time'], 2).' ms' : '—' ?></dd></div><div><dt>System Uptime</dt><dd><?= icct_nms_h(icct_nms_uptime($old['snmp_sysUpTimeInstance'] ?? 0)) ?></dd></div></dl>
 <div class="device-view-actions" aria-label="Device diagnostics and actions">
 <?php foreach ($diagnosticGroups as $family=>$methods): ?>
 <?php if (in_array($family, ['traceroute','mtr'], true)): $familyLabel=$family==='traceroute'?'Traceroute':'MTR'; ?>
 <details class="row-menu device-diagnostic-menu"><summary class="device-tool" aria-label="<?= $familyLabel ?> protocols" data-tooltip="<?= $familyLabel ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= $paths[$family==='traceroute'?'traceroute':'mtr_icmp'] ?>"/></svg><span class="device-tool-caret" aria-hidden="true">⌄</span></summary><div class="row-menu-panel">
 <?php foreach ($methods as $tool=>$label): ?><a href="diagnostics.php?host_id=<?= $id ?>&amp;tool=<?= icct_nms_h($tool) ?>#diagnostic-run" data-host-id="<?= $id ?>" data-tool="<?= icct_nms_h($tool) ?>" data-device-name="<?= icct_nms_h($values['description']) ?>"><?= icct_nms_h($label) ?></a><?php endforeach; ?>
 </div></details>
 <?php else: $tool=array_key_first($methods);$label=$methods[$tool]; ?>
 <a class="device-tool" href="diagnostics.php?host_id=<?= $id ?>&amp;tool=<?= icct_nms_h($tool) ?>#diagnostic-run" data-host-id="<?= $id ?>" data-tool="<?= icct_nms_h($tool) ?>" data-device-name="<?= icct_nms_h($values['description']) ?>" aria-label="<?= icct_nms_h($label) ?>" data-tooltip="<?= icct_nms_h($label) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= $paths[$tool] ?? 'M4 5h16v14H4z M8 9l3 3-3 3 M13 15h4' ?>"/></svg></a>
 <?php endif; ?><?php endforeach; ?>
 <?php if (is_realm_allowed(3)): ?><details class="row-menu device-view-menu"><summary class="device-tool" aria-label="More device actions">⋮</summary><div class="row-menu-panel">
 <button type="button" disabled title="Alarm suppression unavailable.">Alarm Suppression</button>
 <form method="post" action="protocol.php?id=<?= $id ?>"><?php icct_nms_token(); ?><input type="hidden" name="action" value="reindex"><button type="submit">Re-Index Device</button></form>
 <a href="device.php?id=<?= $id ?>">Modify Device</a>
 <a href="device.php?clone_id=<?= $id ?>" data-clone-device data-device-name="<?= icct_nms_h($values['description']) ?>">Clone Device</a>
 <?php foreach ([($old['disabled']==='on'?2:3)=>($old['disabled']==='on'?'Enable Device':'Disable Device'),1=>'Delete Device'] as $action=>$label): ?><form method="post" action="<?= icct_nms_h($config['url_path']) ?>host.php" <?= $action===1?'class="danger-action"':'' ?>><?php icct_nms_token(); ?><input type="hidden" name="action" value="actions"><input type="hidden" name="drp_action" value="<?= $action ?>"><input type="hidden" name="chk_<?= $id ?>" value="on"><button type="submit"><?= $label ?></button></form><?php endforeach; ?>
 </div></details><?php endif; ?>
 </div>
</header>
<nav class="device-view-tabs" aria-label="Device view sections"><?php foreach (['details'=>'Device Details','graphs'=>'Graphs','ports'=>'Interfaces/Ports','fcaps'=>'FCAPS','syslog'=>'Syslog','records'=>'Records'] as $key=>$label): ?><a href="#view-<?= $key ?>" data-device-view-tab="<?= $key ?>"><?= $label ?></a><?php endforeach; ?></nav>
<section id="view-details" data-device-view-panel="details">
<dl class="device-detail-grid">
<?php foreach ([
 'Short Name'=>$values['short_name'], 'Segment'=>$segment, 'Device Type/Model'=>$values['device_type'], 'Serial Number'=>$values['serial_number'],
 'MAC Address'=>$values['mac_address'], 'Polling Interval (Sec)'=>read_config_option('poller_interval'), 'Rack Placement'=>$rackName ? ($rack ? 'U'.$rack['start_unit'].'–U'.($rack['start_unit']+$rack['unit_height']-1).' ('.$rackName.')' : $rackName.' (Peripheral)') : '', 'Firmware Version'=>$meta['firmware_version'] ?? '',
 'Configured Protocols'=>implode(', ', $protocols), 'Discovery Protocol'=>implode(', ', array_map(static fn($key)=>['lldp'=>'LLDP','cdp'=>'CDP','arp'=>'IP neighbours (IPv4/IPv6)','fdb'=>'MAC/FDB'][$key] ?? strtoupper($key), array_filter(explode(',', (string)($discovery['protocol'] ?? ''))))), 'Default SNMP Version'=>[0=>'None',1=>'SNMP v1',2=>'SNMP v2c',3=>'SNMP v3'][(int)$old['snmp_version']] ?? '', 'Last Maintenance Date'=>$meta['last_maintenance_date'] ?? '',
] as $label=>$value): ?><div><dt><?= icct_nms_h($label) ?></dt><dd><?= $display($value) ?></dd></div><?php endforeach; ?>
</dl>
<?php if (is_realm_allowed(3)): ?><section class="device-view-section"><h2>Device Login Credentials</h2><dl class="device-detail-grid"><div><dt>Username</dt><dd><?= $display($ssh['username'] ?? '') ?></dd></div><div><dt>Password / Private Key</dt><dd>Hidden</dd></div></dl></section><?php endif; ?>
<section class="device-view-section"><h2>Device Image</h2><?php $image=icct_nms_type_asset(array_replace($profile,['display_modes'=>['network'=>'image']])); if (!empty($profile['image']) && str_contains($image,'/uploads/')): ?><img class="device-view-image" src="<?= icct_nms_h($image) ?>" alt="<?= icct_nms_h($values['device_type']) ?>"><?php else: ?><p>No image saved for this device type.</p><?php endif; ?></section>
<?php if (trim($values['notes'])!==''): ?><section class="device-view-section"><h2>Notes</h2><p><?= nl2br(icct_nms_h($values['notes'])) ?></p></section><?php endif; ?>
</section>
<section id="view-graphs" data-device-view-panel="graphs" hidden><h2>Graphs</h2><?php require __DIR__ . '/../../graphs/templates/device_graph_filters.php'; ?><p id="device-graph-count" role="status" aria-live="polite"></p><div class="device-view-graphs" id="device-view-graphs" data-columns="2"><?php $viewGraphs=icct_nms_device_graphs($id); foreach ($viewGraphs as $graph): $graphId=(int)$graph['local_graph_id']; ?><figure><figcaption><?= icct_nms_h($graph['title_cache']) ?></figcaption><div class="device-graph-body"><a class="device-graph-image" href="<?= icct_nms_h($config['url_path']) ?>graph.php?action=view&amp;local_graph_id=<?= $graphId ?>"><img loading="lazy" src="<?= icct_nms_h($config['url_path']) ?>graph_image.php?local_graph_id=<?= $graphId ?>&amp;rra_id=0" alt="<?= icct_nms_h($graph['title_cache']) ?>"></a><?php require __DIR__ . '/../../graphs/templates/device_graph_actions.php'; ?></div></figure><?php endforeach; ?><?php if (!$viewGraphs): ?><p>No accessible graphs are available for this device.</p><?php endif; ?></div><p id="device-graph-empty" hidden>No graphs match your search.</p><div class="type-pagination"><button type="button" id="device-graph-prev">Previous</button><span id="device-graph-page"></span><button type="button" id="device-graph-next">Next</button></div></section>
<?php require __DIR__ . '/../../ports/templates/device_view_ports.php'; ?>
<section id="view-fcaps" data-device-view-panel="fcaps" hidden><h2>FCAPS</h2><p>Saved fault rules and current device observations.</p><div class="site-table-wrap"><table class="site-table"><thead><tr><th>Rule</th><th>Severity</th><th>Minimum</th><th>Maximum</th><th>Enabled</th></tr></thead><tbody><?php $viewRules=icct_nms_fault_rules($id); foreach ($viewRules as $rule): ?><tr><td><?= $display($rule['name']) ?></td><td><?= $display($rule['severity']) ?></td><td><?= $display($rule['minimum']) ?></td><td><?= $display($rule['maximum']) ?></td><td><?= $rule['enabled']?'Yes':'No' ?></td></tr><?php endforeach; ?><?php if (!$viewRules): ?><tr><td colspan="5">No fault rules configured.</td></tr><?php endif; ?></tbody></table></div><?php $faults=icct_nms_fault_observations($old); if($faults): ?><h3>Current observations</h3><div class="site-table-wrap"><table class="site-table"><thead><tr><th>Graph</th><th>Value</th><th>State</th></tr></thead><tbody><?php foreach($faults as $fault): ?><tr><td><?= $display($fault['graph']) ?></td><td><?= $display($fault['value']) ?></td><td><?= $display($fault['state']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php require __DIR__ . '/../../protocols/syslog/templates/device_view_syslog.php'; ?>
<?php require __DIR__ . '/device_records.php'; ?>
</div>
