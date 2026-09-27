<main class="nms-shell nms-topology-config"><h1><?php print $topology_tab === "appearance"
	? "Device appearance"
	: "Discovery inventory"; ?></h1>
<?php if ($error) { ?><p class="nms-action-feedback error" role="alert"><?php print nms_h($error); ?></p><?php } elseif (
	isset($_GET["saved"])
) { ?><p class="nms-action-feedback" role="status">Settings saved.</p><?php } ?>
<?php if ($topology_tab === "appearance") {

	$catalog = nms_appearance_read();
	$categories = nms_categories();
	$names = [0 => "Unclassified"];
	foreach ($categories as $c) {
		$names[$c["id"]] = $c["name"];
	}
	?>

<?php
$section =
	($_GET["section"] ?? "") === "types" || isset($_GET["type"]) || strpos($_POST["nms_action"] ?? "", "type_") === 0
		? "types"
		: "segments";
$editing =
	isset($_GET["segment"]) ||
	isset($_GET["type"]) ||
	isset($_GET["new"]) ||
	($error && in_array($_POST["nms_action"] ?? "", ["type_save", "segment_save"]));
$segment = ["id" => 0, "name" => "", "description" => ""];
foreach ($categories as $c) {
	if ((string) $c["id"] === ($_GET["segment"] ?? "")) {
		$segment = $c;
	}
}
$id = is_string($_GET["type"] ?? null) ? $_GET["type"] : "";
$type = $catalog["types"][$id] ?? ["name" => "", "category_id" => 0, "icon" => "switch", "color" => "#334155"];
if (!isset($catalog["types"][$id])) {
	$id = "";
}
if ($error && ($_POST["nms_action"] ?? "") === "segment_save") {
	$segment = array_merge($segment, array_intersect_key($_POST, $segment));
}
if ($error && ($_POST["nms_action"] ?? "") === "type_save") {
	$type = array_merge($type, array_intersect_key($_POST, $type));
	$id = $_POST["id"] ?? "";
    $type["connection_type"] = is_string($_POST["connection_type"] ?? null) ? $_POST["connection_type"] : "";
}
$base = "topology.php?tab=appearance";
?>
<?php $appearance_section = $section; require __DIR__ . '/appearance_nav.php'; ?>
<section class="nms-panel nms-appearance-list"><div class="nms-panel-head"><h2><?php print $section === "types"
	? "Device types and icons"
	: "Device segments"; ?></h2><a class="nms-catalog-button" href="<?php print $base .
	"&amp;section=" .
	$section; ?>&amp;new=1">Add <?php print $section === "types" ? "device type" : "segment"; ?></a></div>
<div class="nms-catalog-scroll"><table class="nms-table"><thead><tr><?php if (
	$section === "types"
) { ?><th>Segment</th><th>Device type</th><th>Icon</th><th>Colour</th><?php } else { ?><th>Name</th><th>Description</th><?php } ?><th>Actions</th></tr></thead><tbody>
<?php foreach ($section === "types" ? $catalog["types"] : $categories as $key => $item) {
	$item_id = $section === "types" ? $key : $item["id"]; ?>
<tr><?php if ($section === "types") { ?><td><?php print nms_h(
	$names[$item["category_id"]] ?? "Unclassified",
); ?></td><td><?php print nms_h(
	$item["name"],
); ?></td><td><?php if ($item_image = nms_appearance_image($item)) { ?><img class="nms-type-image-preview" src="<?php print nms_h($item_image); ?>" alt="<?php print nms_h($item['name']); ?>"><?php } else { ?><span class="nms-icon-preview"><?php print nms_appearance_icon_svg(
	$item["icon"],
); ?></span><?php } ?><?php print nms_h(
	nms_appearance_icons()[$item["icon"]][0] ?? $item["icon"],
); ?></td><td><span class="nms-color-swatch" style="background:<?php print nms_h(
	$item["color"],
); ?>"></span><?php print nms_h($item["color"]); ?></td><?php } else { ?><td><?php print nms_h(
	$item["name"],
); ?></td><td><?php print nms_h($item["description"]); ?></td><?php } ?>
<td><div class="nms-catalog-actions"><a class="nms-catalog-button" href="<?php print $base .
	"&amp;section=" .
	$section .
	"&amp;" .
	($section === "types" ? "type" : "segment") .
	"=" .
	urlencode($item_id); ?>">Edit</a><form method="post"><?php nms_catalog_fields(
	$section === "types" ? "type_delete" : "segment_delete",
	$item_id,
); ?><button class="nms-catalog-button">Delete unused</button></form></div></td></tr><?php
} ?></tbody></table></div></section>
<?php if (
	$editing
) { ?><dialog id="nmsAppearanceDialog" data-nms-auto-open class="nms-appearance-dialog" aria-labelledby="nmsAppearanceTitle"><div class="nms-panel-head"><h2 id="nmsAppearanceTitle"><?php print $section ===
"types"
	? ($id ? "Edit" : "Add") . " device type"
	: ($segment["id"] ? "Edit" : "Add") .
		" segment"; ?></h2><button type="button" data-nms-dialog-close aria-label="Close form" class="nms-popup-close nms-catalog-button"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
<?php if ($error) { ?><p class="nms-catalog-error" role="alert"><?php print nms_h($error); ?></p><?php } ?>
<form method="post" enctype="multipart/form-data" class="nms-catalog-form">
<?php if ($section === "segments") {
	nms_catalog_fields(
		"segment_save",
		$segment["id"],
	); ?><label>Name<input name="name" required maxlength="150" placeholder="Network equipment" value="<?php print nms_h(
	$segment["name"],
); ?>"></label><label>Description<input name="description" maxlength="512" placeholder="Switches and routers" value="<?php print nms_h(
	$segment["description"],
); ?>"></label>
<?php
} else {
	nms_catalog_fields("type_save", $id); ?><label>Device segment<select name="category_id"><?php foreach (
	$names
	as $key => $name
) { ?><option value="<?php print (int) $key; ?>" <?php if ((int) $key === (int) $type["category_id"]) {
	print "selected";
} ?>><?php print nms_h(
	$name,
); ?></option><?php } ?></select></label><label>Device type<input name="name" required maxlength="150" placeholder="IP phone" value="<?php print nms_h(
	$type["name"],
); ?>"></label>
<div class="nms-type-image-field">
<label>Topology image<input type="file" name="device_image" accept="image/png,image/jpeg,image/webp"><small>PNG, JPEG or WebP · up to 2 MB. Choose where to use it below.</small></label>
<?php if ($preview_image = nms_appearance_image($type)) { ?><img class="nms-type-image-preview" src="<?php print nms_h($preview_image); ?>" alt="Current device type image"><label><input type="checkbox" name="remove_image" value="1"> Remove uploaded image</label><?php } ?>
<div class="nms-display-mode-grid">
<?php foreach (['network'=>'Network','rack'=>'Rack-style devices','map'=>'Map device details'] as $view=>$label) { $mode = nms_appearance_display_mode($type, $view); if ($error && is_string($_POST['display_'.$view] ?? null)) $mode = $_POST['display_'.$view]; ?>
<label><?php print nms_h($label); ?><select name="display_<?php print $view; ?>">
<option value="icon"<?php if($mode==='icon') print ' selected'; ?>>Icon</option>
<option value="image"<?php if($mode==='image') print ' selected'; ?>>Image</option>
</select></label>
<?php } ?>
</div>
<label>Connection type<select name="connection_type">
<option value="">Not assigned</option>
<?php foreach (db_fetch_assoc('SELECT name FROM plugin_nms_connection_types ORDER BY name') as $connection_type) { ?>
<option value="<?php print nms_h($connection_type['name']); ?>"<?php if (($type['connection_type'] ?? '') === $connection_type['name']) print ' selected'; ?>><?php print nms_h($connection_type['name']); ?></option>
<?php } ?>
</select></label>
</div>
<fieldset class="nms-icon-picker"><legend>Choose an icon</legend><p>Select the picture that represents this device type.</p><div class="nms-icon-grid"><?php foreach (
	nms_appearance_icons()
	as $key => $icon
) { ?><label data-nms-help-ready="1"><input type="radio" required name="icon" value="<?php print $key; ?>" <?php if (
	$type["icon"] === $key
) {
	print "checked";
} ?>><span><?php print nms_appearance_icon_svg($key); ?><b><?php print nms_h(
	$icon[0],
); ?></b></span></label><?php } ?></div></fieldset>
<label>Icon colour<input type="color" name="color" value="<?php print nms_h(
	$type["color"],
); ?>"><small>Health indicators keep their status colour. The built-in icon is used when no image is uploaded.</small></label><?php
} ?>
<div class="nms-catalog-footer"><button type="button" data-nms-dialog-close class="nms-cancel-button nms-catalog-button">Cancel</button><button type="submit">Save <?php print $section ===
"types"
	? "device type"
	: "segment"; ?></button></div></form></dialog><?php } ?>
<?php
} else {
	require __DIR__ . "/../discovery/inventory.php";
} ?></main>
