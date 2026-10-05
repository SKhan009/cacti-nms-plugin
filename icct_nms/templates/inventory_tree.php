<?php
/** Segment navigation uses the same access-filtered inventory as the table. */
$treeSegments = [];
foreach ($devices as $treeDevice) {
    $treeSegment = trim((string)($treeDevice['segment'] ?? '')) ?: 'Unassigned';
    $treeSegments[$treeSegment][] = $treeDevice;
}
uksort($treeSegments, 'strnatcasecmp');
?>
<div class="inventory-heading">
 <div><p class="breadcrumb"><a href="topology.php">Dashboard</a> / <a href="inventory.php">Inventory</a> / Tree View</p><h1>Inventory</h1></div>
 <div class="inventory-tools"><div class="view-toggle"><a href="inventory.php">Table View</a><a class="active" href="inventory.php?view=tree" aria-current="page">Tree View</a></div>
 <?php if ($management): ?><button class="button" type="button" id="device-discovery">Device Discovery</button><a class="button primary" href="device.php">Add Device</a><?php endif; ?></div>
</div>
<?php if ($management): ?>
<dialog id="discovery-dialog"><div class="dialog-heading"><h2>Device Discovery</h2><button type="button" data-close-dialog aria-label="Close">×</button></div><form method="post" action="protocol.php"><?php icct_nms_token(); ?><input type="hidden" name="action" value="discover"><button class="button primary">Queue Discovery</button></form></dialog>
<?php endif; ?>
<div class="inventory-tree-layout">
 <aside class="inventory-segment-sidebar" aria-label="Devices by segment">
  <label class="inventory-tree-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/></svg><span class="sr-only">Search devices by name or IP address</span><input type="search" id="segment-tree-search" placeholder="Search With Device Name"></label>
  <nav class="inventory-segment-tree" aria-label="Segment tree">
  <?php foreach ($treeSegments as $treeSegment=>$segmentDevices):
      $selectedSegment = in_array((int)$id, array_map(static fn($d)=>(int)$d['id'], $segmentDevices), true);
  ?>
   <details class="inventory-segment" <?= $selectedSegment?'open':'' ?>><summary><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h7l2 3h9v11H3z"/></svg><span><?= icct_nms_h($treeSegment) ?></span></summary>
   <?php foreach ($segmentDevices as $treeDevice): $selectedDevice=(int)$treeDevice['id']===(int)$id; ?>
    <a class="inventory-tree-entry <?= $selectedDevice?'selected':'' ?>" href="device.php?id=<?= (int)$treeDevice['id'] ?>&amp;view=1&amp;tree=1" <?= $selectedDevice?'aria-current="page"':'' ?> data-tree-search="<?= icct_nms_h($treeDevice['description'].' '.$treeDevice['short_name'].' '.$treeDevice['hostname']) ?>"><span class="device-status-dot <?= icct_nms_status_class($treeDevice['status_label']) ?>" aria-label="<?= icct_nms_h($treeDevice['status_label']) ?>"></span><span><?= icct_nms_h($treeDevice['description']) ?></span></a>
   <?php endforeach; ?></details>
  <?php endforeach; ?>
  </nav><p id="segment-tree-empty" class="empty-state" hidden>No matching devices.</p>
 </aside>
 <div class="inventory-tree-content">
