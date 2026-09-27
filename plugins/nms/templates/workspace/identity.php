<section class="nms-panel"><h2 data-nms-tip="Reported addresses belong to the SNMP agent. Shared or virtual addresses may appear on more than one device. Matching evidence does not merge records or change the polling address.">IP addresses</h2>
<?php if(!$workspace_host) { ?><p>Select a device.</p><?php } else {
$address_hosts=$workspace_discovery['hosts'];
$address_identity=$workspace_identities[$workspace_id] ?? ['addresses'=>[],'matches'=>[],'message'=>'No current identity evidence. Assign an enabled discovery preset and collect again.','checked_at'=>''];
$address_identity_compact=true;
require __DIR__.'/../devices/address_identity.php';
unset($address_identity_compact);
} ?></section>
<?php require __DIR__.'/management_ip.php'; ?>
