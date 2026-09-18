<?php
/** Native Cacti template routes. No copied option catalogs or template records. */
function nms_template_sections()
{
	return [
		"input" => [
			"label" => "Data Input Methods",
			"page" => "data_input.php",
			"help" => "Define how a script collects data and its input and output fields.",
		],
		"query" => [
			"label" => "Data Queries",
			"page" => "data_queries.php",
			"help" =>
				"For indexed tables, configure the query and associate its graph templates. Simple scalar readings do not need a data query.",
		],
		"source" => [
			"label" => "Data Source Templates",
			"page" => "data_templates.php",
			"help" => "Choose a data input method, define data-source items, and configure collection and retention.",
		],
		"graph" => [
			"label" => "Graph Templates",
			"page" => "graph_templates.php",
			"help" => "Use data-source items to define graph items, inputs, legends, and graph options.",
		],
		"device" => [
			"label" => "Device Templates",
			"page" => "host_templates.php",
			"help" =>
				"Group graph templates and data queries into a reusable device template, then assign it to a device.",
		],
	];
}

/** Only local native editor entry points may be loaded by the workspace. */
function nms_template_core_route($section, $requested = "")
{
	$sections = nms_template_sections();
	$default = $sections[$section]["page"];
	$allowed = [
		"data_input.php",
		"data_queries.php",
		"data_templates.php",
		"graph_templates.php",
		"graph_templates_items.php",
		"graph_templates_inputs.php",
		"host_templates.php",
	];
	if ($requested === "") {
		return $default;
	}
	$parts = parse_url($requested);
	if (
		!$parts ||
		isset($parts["host"]) ||
		isset($parts["scheme"]) ||
		!in_array($parts["path"] ?? "", $allowed, true)
	) {
		throw new InvalidArgumentException("Unsupported Cacti template page.");
	}
	parse_str($parts["query"] ?? "", $query);
	// Workspace entry links are read-only; writes remain native CSRF-protected forms.
	if (
		isset($query["action"]) &&
		!in_array(
			$query["action"],
			["", "edit", "template_edit", "item_edit", "input_edit", "field_edit", "query_edit", "query_graph_edit"],
			true,
		)
	) {
		throw new InvalidArgumentException("Open this operation from the Cacti editor.");
	}
	unset($query["header"], $query["headercontent"], $query["pagecontent"]);
	return $parts["path"] . ($query ? "?" . http_build_query($query) : "");
}
