<?php
/** Shared evidence view. Inputs: $address_identity and permitted $address_hosts. */
?>
<?php if(empty($address_identity_compact)) { ?>
<p><?php print nms_h($address_identity['message']); ?><?php if ($address_identity['checked_at']) { ?> Last collected: <?php print nms_h($address_identity['checked_at']); ?>.<?php } ?></p>
<?php } else { ?>
<p><span><?php print $address_identity['addresses'] ? count($address_identity['addresses']).' reported addresses' : 'No reported addresses'; ?></span> <span class="nms-help-icon" role="button" tabindex="0" aria-label="Address collection details" data-nms-tip="<?php print nms_h($address_identity['message'].($address_identity['checked_at']?' Last collected: '.$address_identity['checked_at'].'.':'')); ?>">?</span></p>
<?php } ?>
<?php if ($address_identity['addresses']) { ?>
<div class="nms-table-wrap"><table class="nms-table"><thead><tr><th>Reported IP address</th><th>Interface</th><th>Source</th><th>Address state</th></tr></thead><tbody>
<?php foreach ($address_identity['addresses'] as $address) { ?>
<tr><td><?php print nms_h($address['address'] . ($address['zone'] ? '%' . $address['zone'] : '')); ?></td><td>ifIndex <?php print (int) $address['ifindex']; ?></td><td><?php print nms_h($address['source']); ?></td><td><?php print nms_h(([1 => 'Preferred', 2 => 'Deprecated', 3 => 'Invalid', 4 => 'Inaccessible', 5 => 'Unknown', 6 => 'Tentative', 7 => 'Duplicate', 8 => 'Optimistic'][$address['status']] ?? 'Not reported') . ' / ' . ([1 => 'Unicast', 2 => 'Anycast', 3 => 'Broadcast'][$address['type']] ?? 'Type not reported')); ?></td></tr>
<?php } ?></tbody></table></div>
<?php } ?>
<?php if (!$address_identity['matches'] && $address_identity['addresses']) { ?><p data-nms-tip="Matching compares visible devices in the same site, collector and SNMP context.">No matching devices found.</p><?php } ?>
<?php foreach ($address_identity['matches'] as $match) {
    $peer = $address_hosts[$match['host_id']] ?? null;
    if (!$peer) continue;
?>
<p><strong><?php print nms_h($match['state']); ?>:</strong> <a href="devices.php?tab=readings&amp;id=<?php print (int) $match['host_id']; ?>"><?php print nms_h($peer['description'] . ' · ' . $peer['hostname']); ?></a>. <?php print nms_h($match['reason']); ?></p>
<?php } ?>
<?php if(empty($address_identity_compact)) { ?><p>One Cacti device keeps its configured polling address and graph history. Matching evidence does not merge records or switch polling addresses.</p><?php } ?>
