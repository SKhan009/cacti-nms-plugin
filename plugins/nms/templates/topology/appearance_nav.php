<?php
/** Shared tabs for the Device appearance sidebar section. */
$appearance_node = max(0, (int) ($node_id ?? ($_GET['node_id'] ?? 0)));
$appearance_tabs = [
    'segments' => ['Device segments', 'topology.php?tab=appearance&section=segments'],
    'types' => ['Device types and icons', 'topology.php?tab=appearance&section=types'],
    'connections' => ['Connections', 'topology.php?tab=connections'],
    'connection_types' => ['Connection types', 'topology.php?tab=connections&section=types'],
];
?>
<p>Manage device segments, icons and topology connections.</p>
<nav class="nms-preset-tabs" aria-label="Appearance sections">
<?php foreach ($appearance_tabs as $key => [$label, $url]) { ?>
<a href="<?php print nms_h($url . '&node_id=' . $appearance_node); ?>"<?php if ($appearance_section === $key) { ?> class="active" aria-current="page"<?php } ?>><?php print nms_h($label); ?></a>
<?php } ?>
</nav>
