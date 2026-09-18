<?php
/** NMS view of current discovered endpoints; independent of manually configured layout. */
$positions = [];
$i = 0;
foreach ($discovery["hosts"] as $id => $h) {
	$positions[$id] = [170 + ($i % 3) * 310, 70 + intdiv($i, 3) * 160];
	$i++;
}
$height = max(180, (int) ceil(count($positions) / 3) * 160);
?>
<div class="nms-table-wrap"><svg viewBox="0 0 1000 <?php print $height; ?>" style="width:100%;min-width:650px" role="img" aria-label="NMS discovered topology"><title>Discovered LLDP / CDP connections</title>
<?php
foreach ($discovery["links"] as $index => $edge) {

	[$x1, $y1] = $positions[$edge["a"][0]];
	[$x2, $y2] = $positions[$edge["b"][0]];
	?>
<line x1="<?php print $x1; ?>" y1="<?php print $y1; ?>" x2="<?php print $x2; ?>" y2="<?php print $y2; ?>" stroke="<?php print $edge[
	"current"
]
	? "#326f4b"
	: "#888"; ?>" stroke-width="3" <?php if (!$edge["current"]) {
	print 'stroke-dasharray="6 4"';
} ?>><title><?php print nms_h(
	"ifIndex " . $edge["a"][1] . " ↔ " . $edge["b"][1] . " · " . $edge["state"],
); ?></title></line>
<text x="<?php print ($x1 + $x2) / 2; ?>" y="<?php print ($y1 + $y2) / 2 -
	20; ?>" text-anchor="middle" font-size="12"><?php print "Link " . ($index + 1); ?></text>
<?php
}
foreach ($discovery["hosts"] as $id => $h) {
	[$x, $y] = $positions[$id]; ?>
<rect x="<?php print $x - 125; ?>" y="<?php print $y -
	27; ?>" width="250" height="54" rx="6" fill="#fff" stroke="#333"/><text x="<?php print $x; ?>" y="<?php print $y; ?>" text-anchor="middle" font-size="12"><?php print nms_h(
	mb_strimwidth($h["description"], 0, 35, "…"),
); ?></text><text x="<?php print $x; ?>" y="<?php print $y +
	17; ?>" text-anchor="middle" font-size="11"><?php print nms_h($h["hostname"]); ?></text>
<?php
}
?></svg></div><p>Solid links have current neighbour evidence; dashed links are historical. Connection evidence does not prove cable health.</p>
