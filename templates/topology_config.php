<?php /** Dynamic physical planning, independent of discovered network interfaces. */ ?>
<main class="nms-shell nms-topology-config">
 <div class="nms-heading"><div><p class="nms-eyebrow">NMS / Topology</p><h1><?php print $topology_tab === 'racks' ? 'Rack topology' : 'Topology configuration'; ?></h1><p>Configure physical capacity and rack placement. Device identity and current status come from Cacti.</p></div></div>
 <?php if ($notice) { ?><div class="nms-config-notice" role="status"><?php print nms_h($notice); ?></div><?php } ?>
 <?php if ($page_error) { ?><div class="nms-config-error" role="alert"><?php print nms_h($page_error); ?></div><?php } ?>
 <form class="nms-config-picker" method="get" action="topology.php">
  <input type="hidden" name="tab" value="<?php print nms_h($topology_tab); ?>">
  <label>Cacti site<select name="site_id" onchange="this.form.node_id.value='0'; this.form.submit()"><option value="">Select site</option><?php foreach ($sites as $site) { ?><option value="<?php print (int) $site['id']; ?>" <?php if ((int) $site['id'] === $site_id) print 'selected'; ?>><?php print nms_h($site['name']); ?></option><?php } ?></select></label>
  <label>Node / vehicle<select name="node_id" onchange="this.form.submit()"><option value="0"><?php print $topology_tab === 'configuration' ? 'Create a node or vehicle' : 'Select node or vehicle'; ?></option><?php foreach ($nodes as $item) { ?><option value="<?php print (int) $item['id']; ?>" <?php if ((int) $item['id'] === $node_id) print 'selected'; ?>><?php print nms_h($item['name'] . ' · ' . ucfirst($item['node_kind'])); ?></option><?php } ?></select></label>
  <button type="submit">Show</button>
 </form>
 <?php if (!$selected_site) { ?><section class="nms-panel"><p>Assign devices to a Cacti site before configuring topology.</p></section><?php } else { ?>
 <?php if ($topology_tab === 'configuration') { ?>
 <section class="nms-panel"><div class="nms-panel-head"><div><h2>Physical port capacity</h2><p>Category defaults with optional device-type profiles such as Switch, Router or UPS. These are configured counts, not discovered interfaces or live port status.</p></div></div>
  <form method="post" action="<?php print nms_h($base_url); ?>" class="nms-config-form nms-port-profile-form" data-confirm="Save this physical-port profile? It will apply to matching classified devices.">
   <?php nms_topology_form_fields('save_ports'); ?>
   <label>Device category<select name="category_id" required><option value="">Select category</option><?php foreach ($categories as $category) { ?><option value="<?php print (int) $category['id']; ?>"><?php print nms_h($category['name']); ?></option><?php } ?></select></label>
   <label>Device type (optional)<input type="text" name="device_type" maxlength="150" placeholder="Blank = category default"><small>Matches the existing device classification's type exactly.</small></label>
   <label>Physical port count<input type="number" name="physical_ports" min="0" max="4096" required><small>Use 0 for equipment with no physical ports. An absent profile means not configured.</small></label>
   <div class="nms-config-actions"><button type="submit">Save port profile</button></div>
  </form>
  <div class="nms-table-wrap"><table class="nms-table nms-port-profile-table"><thead><tr><th>Category</th><th>Device type</th><th>Physical ports</th><th>Action</th></tr></thead><tbody>
   <?php foreach ($profiles as $index => $profile) { $form_id = 'profile-' . $index; ?><tr><td><?php print nms_h($profile['category_name']); ?></td><td><?php print nms_h($profile['device_type'] ?: 'Category default'); ?></td><td><form id="<?php print $form_id; ?>" method="post" action="<?php print nms_h($base_url); ?>" data-confirm="Update this physical-port capacity?"><?php nms_topology_form_fields('save_ports'); ?><input type="hidden" name="category_id" value="<?php print (int) $profile['category_id']; ?>"><input type="hidden" name="device_type" value="<?php print nms_h($profile['device_type']); ?>"><input aria-label="Physical ports for <?php print nms_h($profile['category_name'] . ' ' . $profile['device_type']); ?>" type="number" name="physical_ports" min="0" max="4096" value="<?php print (int) $profile['physical_ports']; ?>" required></form></td><td><button type="submit" form="<?php print $form_id; ?>">Save</button></td></tr><?php } ?>
   <?php if (!$profiles) { ?><tr><td class="nms-empty" colspan="4">No physical-port profiles configured.</td></tr><?php } ?>
  </tbody></table></div>
 </section>
 <section class="nms-panel"><div class="nms-panel-head"><div><h2><?php print $node ? 'Edit node / vehicle' : 'Create node / vehicle'; ?></h2><p>Each node or vehicle has its own racks. Units are numbered from U1 at the bottom. Counts are editable, not fixed to 4, 5 or 8.</p></div></div>
  <form method="post" action="<?php print nms_h($base_url); ?>" class="nms-config-form nms-node-form" data-confirm="Save this node and rack count? Reducing the count removes only empty racks at the end; occupied racks are protected.">
   <?php nms_topology_form_fields('save_node'); ?><input type="hidden" name="node_id" value="<?php print $node_id; ?>">
   <label>Name<input type="text" name="name" maxlength="150" required value="<?php print nms_h($node['name'] ?? ''); ?>" placeholder="Node or vehicle identifier"></label>
   <label>Kind<select name="node_kind"><option value="node">Node</option><option value="vehicle" <?php if (($node['node_kind'] ?? '') === 'vehicle') print 'selected'; ?>>Vehicle</option></select></label>
   <label>Number of racks<input type="number" name="rack_count" min="1" max="100" required value="<?php print (int) ($node['rack_count'] ?? 4); ?>"></label>
   <label>Units per new rack<input type="number" name="unit_count" min="1" max="100" required value="42"><small>Only used when adding racks. Existing rack capacities are edited separately below.</small></label>
   <div class="nms-config-actions"><button type="submit"><?php print $node ? 'Save node and racks' : 'Create node and racks'; ?></button></div>
  </form>
 </section>
 <?php if ($node) { ?>
 <section class="nms-panel"><div class="nms-panel-head"><h2>Rack capacities · <?php print nms_h($node['name']); ?></h2><a class="nms-button" href="topology.php?tab=racks&amp;site_id=<?php print $site_id; ?>&amp;node_id=<?php print $node_id; ?>">View racks</a></div>
  <div class="nms-table-wrap"><table class="nms-table nms-rack-capacity-table"><thead><tr><th>Rack</th><th>Name</th><th>Units</th><th>Action</th></tr></thead><tbody><?php foreach ($racks as $rack) { $form_id = 'rack-' . (int) $rack['id']; ?><tr><td><?php print (int) $rack['rack_number']; ?></td><td><form id="<?php print $form_id; ?>" method="post" action="<?php print nms_h($base_url); ?>" data-confirm="Save this rack name and capacity?"><?php nms_topology_form_fields('save_rack'); ?><input type="hidden" name="rack_id" value="<?php print (int) $rack['id']; ?>"><input aria-label="Rack name" type="text" name="name" maxlength="150" value="<?php print nms_h($rack['name']); ?>" required></form></td><td><input aria-label="Rack units" form="<?php print $form_id; ?>" type="number" name="unit_count" min="1" max="100" required value="<?php print (int) $rack['unit_count']; ?>"></td><td><button type="submit" form="<?php print $form_id; ?>">Save</button></td></tr><?php } ?></tbody></table></div>
 </section>
 <?php } ?>
 <?php } ?>
 <?php if ($node) { ?>
 <section class="nms-panel"><div class="nms-panel-head"><div><h2>Device rack placement</h2><p>One physical placement per Cacti device. Saving an existing device moves it; overlapping units are rejected.</p></div></div>
  <form class="nms-config-form nms-placement-form" method="post" action="<?php print nms_h($base_url); ?>" data-confirm="Save this device placement? An existing placement for this device will move to the selected rack and units."><?php nms_topology_form_fields('place_device'); ?>
   <label>Cacti device<select name="host_id" required><option value="">Select a device at this site</option><?php foreach ($devices as $device) { ?><option value="<?php print (int) $device['id']; ?>"><?php print nms_h($device['description'] . ' · ' . $device['hostname']); ?></option><?php } ?></select></label>
   <label>Rack<select name="rack_id" required><?php foreach ($racks as $rack) { ?><option value="<?php print (int) $rack['id']; ?>"><?php print nms_h($rack['name'] . ' · ' . $rack['unit_count'] . 'U'); ?></option><?php } ?></select></label>
   <label>Start unit (bottom)<input type="number" name="start_unit" min="1" max="100" value="1" required></label><label>Device height in units<input type="number" name="unit_height" min="1" max="100" value="1" required></label>
   <div class="nms-config-actions"><button type="submit">Save placement</button></div>
  </form>
 </section>
 <?php } elseif ($topology_tab === 'racks') { ?><section class="nms-panel"><p>No node/vehicle racks configured at this site. Open <a href="topology.php?tab=configuration&amp;site_id=<?php print $site_id; ?>">Topology configuration</a> to create them.</p></section><?php } ?>
 <?php if ($topology_tab === 'racks' && $node) { ?>
 <section class="nms-panel"><div class="nms-panel-head"><div><h2><?php print nms_h($node['name']); ?> · <?php print count($racks); ?> racks</h2><p>Configured placement with Cacti's last reported device state. Refresh to retrieve updated status; physical ports are capacity only.</p></div><a class="nms-button" href="<?php print nms_h($base_url); ?>">Refresh from Cacti</a></div>
 <div class="nms-rack-grid"><?php foreach ($racks as $rack) { $rack_placements = array_filter($placements, function($p) use ($rack) { return (int) $p['rack_id'] === (int) $rack['id']; }); ?>
  <article class="nms-rack"><h3><?php print nms_h($rack['name']); ?> <small><?php print (int) $rack['unit_count']; ?>U</small></h3><div class="nms-rack-units" style="grid-template-rows:repeat(<?php print (int) $rack['unit_count']; ?>, 30px)">
  <?php for ($unit = (int) $rack['unit_count']; $unit >= 1; $unit--) { $row = (int) $rack['unit_count'] - $unit + 1; ?><span class="nms-unit-number" style="grid-row:<?php print $row; ?>">U<?php print $unit; ?></span><span class="nms-unit-empty" style="grid-row:<?php print $row; ?>"></span><?php } ?>
  <?php foreach ($rack_placements as $placement) { $device = $device_by_id[(int) $placement['host_id']] ?? null;
   $state = !$device ? 'Reserved' : ($device['disabled'] !== '' ? 'Disabled' : (!nms_parameter_is_fresh($device['last_updated']) ? 'Stale' : nms_device_status_name($device)));
   $tone = $state === 'Up' ? 'up' : ($state === 'Down' ? 'down' : 'unknown');
   $row = (int) $rack['unit_count'] - (int) $placement['start_unit'] - (int) $placement['unit_height'] + 2; ?>
   <div class="nms-rack-device <?php print $tone; ?>" style="grid-row:<?php print $row; ?> / span <?php print (int) $placement['unit_height']; ?>" title="<?php print nms_h(($device['description'] ?? 'Reserved units') . ' · ' . $state); ?>"><span><?php print nms_h($device['description'] ?? 'Reserved units'); ?></span><strong><?php print nms_h($state); ?></strong></div>
  <?php } ?></div></article>
 <?php } ?></div></section>
 <?php } ?>
 <section class="nms-panel"><div class="nms-panel-head"><h2>Devices and configured physical capacity</h2></div><div class="nms-table-wrap"><table class="nms-table nms-device-capacity-table"><thead><tr><th>Device</th><th>Category / type</th><th>Configured physical ports</th><th>Rack / units</th><th>Action</th></tr></thead><tbody>
 <?php foreach ($devices as $device) { $ports = nms_topology_physical_ports($profiles, $device['category_id'], $device['device_type'] ?? ''); $position = null; foreach ($placements as $p) if ((int) $p['host_id'] === (int) $device['id']) $position = $p; ?><tr><td><a href="devices.php?tab=edit&amp;id=<?php print (int) $device['id']; ?>"><?php print nms_h($device['description']); ?></a></td><td><?php print nms_h(($device['category_name'] ?: 'Unclassified') . ' · ' . ($device['device_type'] ?: 'No type')); ?></td><td><?php print $ports === null ? 'Not configured' : (int) $ports; ?></td><td><?php if ($position) { foreach ($racks as $rack) if ((int) $rack['id'] === (int) $position['rack_id']) print nms_h($rack['name']); print ' · U' . (int) $position['start_unit'] . '–U' . ((int) $position['start_unit'] + (int) $position['unit_height'] - 1); } else print 'Not placed in selected node'; ?></td><td><?php if ($position) { ?><form method="post" action="<?php print nms_h($base_url); ?>" data-confirm="Remove this rack assignment? The Cacti device will be kept."><?php nms_topology_form_fields('unplace_device'); ?><input type="hidden" name="host_id" value="<?php print (int) $device['id']; ?>"><input type="hidden" name="rack_id" value="<?php print (int) $position['rack_id']; ?>"><button type="submit">Unassign</button></form><?php } ?></td></tr><?php } ?>
 <?php if (!$devices) { ?><tr><td class="nms-empty" colspan="5">No accessible devices at this site.</td></tr><?php } ?></tbody></table></div></section>
 <?php } ?>
</main>
