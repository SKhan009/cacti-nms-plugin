<?php
/** Build native Cacti graph-item rows without writing to the database. */
function nms_graph_item_rows($types, $source_id, $source_name, $options)
{
	global $consolidation_functions, $struct_graph_item;
	$aliases = ["line1" => "LINE1", "line2" => "LINE2", "line3" => "LINE3", "area" => "AREA"];
	$style = (string) ($options["graph_style"] ?? $types[$struct_graph_item["graph_type_id"]["default"]]);
	$style = $aliases[$style] ?? $style;
	$type_id = array_search($style, $types, true);
	if ($type_id === false) {
		throw new InvalidArgumentException("Select a graph item type from Cacti.");
	}
	$cf = [];
	// Semantic names describe the generated legend; numeric IDs belong to Cacti.
	foreach (["average" => "AVERAGE", "minimum" => "MIN", "maximum" => "MAX", "last" => "LAST"] as $key => $name) {
		$cf[$key] = array_search($name, $consolidation_functions, true);
		if ($cf[$key] === false) {
			throw new RuntimeException("Missing Cacti consolidation function: " . $name);
		}
	}
	$plot = in_array($style, ["LINE1", "LINE2", "LINE3", "LINE:STACK", "AREA", "AREA:STACK"], true);
	$line = strpos($style, "LINE") === 0;
	$stack = strpos($style, ":STACK") !== false;
	$legend = in_array($style, ["LEGEND", "LEGEND_CAMM"], true);
	$gprint = strpos($style, "GPRINT") === 0;
	$rule = in_array($style, ["HRULE", "VRULE"], true);
	$numeric = $plot || $gprint || $legend || $style === "TICK";
	$row = [
		"graph_type_id" => (int) $type_id,
		"task_item_id" => $numeric ? (int) $source_id : 0,
		"consolidation_function_id" =>
			(int) ($options["consolidation_function_id"] ?? $cf[$options["consolidation"] ?? "average"]),
		"color_id" =>
			$plot || $rule || $style === "TICK"
				? (int) ($options["color_id"] ?? $struct_graph_item["color_id"]["default"])
				: 0,
		// Preserve Cacti's exact hex alpha value; percentage rounding changes native values.
		"alpha" => $options["alpha"] ?? $struct_graph_item["alpha"]["default"],
		"cdef_id" => $numeric ? (int) ($options["cdef_id"] ?? 0) : 0,
		"vdef_id" => $numeric ? (int) ($options["vdef_id"] ?? 0) : 0,
		"gprint_id" => (int) ($options["gprint_id"] ?? $struct_graph_item["gprint_id"]["default"]),
		"line_width" => 0,
		"dashes" => "",
		"dash_offset" => 0,
		"shift" => "",
		"value" => "",
		"textalign" => "",
		"text_format" => trim((string) ($options["text_format"] ?? "")),
		"hard_return" => !empty($options["hard_return"]) ? "on" : "",
	];
	if (mb_strlen($row["text_format"]) > (int) $struct_graph_item["text_format"]["max_length"]) {
		throw new InvalidArgumentException("Text format exceeds the Cacti field length.");
	}
	if ($plot && $row["text_format"] === "") {
		$row["text_format"] = $source_name . ":";
	}
	if ($line) {
		$width = $options["line_width"] ?? "";
		if ($width === "") {
			$width = ["LINE2" => 2, "LINE3" => 3][$style] ?? 1;
		}
		if (
			!is_numeric($width) ||
			!is_finite((float) $width) ||
			$width <= 0 ||
			strlen((string) $width) > (int) $struct_graph_item["line_width"]["max_length"]
		) {
			throw new InvalidArgumentException("Line width must be positive and fit the Cacti field length.");
		}
		$row["line_width"] = (float) $width;
	}
	if ($line || $rule) {
		foreach (["dashes" => '/^([0-9]+(,[0-9]+)*)?$/D', "dash_offset" => '/^[0-9]*$/D'] as $key => $pattern) {
			$value = trim((string) ($options[$key] ?? ""));
			if (strlen($value) > (int) $struct_graph_item[$key]["max_length"] || !preg_match($pattern, $value)) {
				throw new InvalidArgumentException("Invalid " . $key . " or value exceeds the Cacti field length.");
			}
			$row[$key] = $key === "dash_offset" ? (int) $value : $value;
		}
	}
	if ($plot && !empty($options["shift"])) {
		$value = (string) ($options["shift_seconds"] ?? "");
		if (!preg_match('/^-?[0-9]+$/D', $value) || strlen($value) > (int) $struct_graph_item["value"]["max_length"]) {
			throw new InvalidArgumentException("Enter a shift in seconds within the Cacti field length.");
		}
		$row["shift"] = "on";
		$row["value"] = $value;
	}
	if ($rule || $style === "TICK") {
		$value = trim((string) ($options["item_value"] ?? ""));
		if (!is_numeric($value) || !is_finite((float) $value)) {
			throw new InvalidArgumentException("Enter a numeric rule value or tick fraction.");
		}
		if ($style === "VRULE" && !preg_match('/^[0-9]+$/D', $value)) {
			throw new InvalidArgumentException("VRULE requires a Unix timestamp in seconds.");
		}
		if ($style === "TICK" && abs((float) $value) > 1) {
			throw new InvalidArgumentException("Tick fraction must be between -1 and 1.");
		}
		$row["value"] = $value;
	}
	if ($style === "TEXTALIGN") {
		$alignment = (string) ($options["textalign"] ?? $struct_graph_item["textalign"]["default"]);
		if (!array_key_exists($alignment, $struct_graph_item["textalign"]["array"])) {
			throw new InvalidArgumentException("Choose a Cacti text alignment.");
		}
		$row["textalign"] = $alignment;
		$row["text_format"] = "";
		$row["hard_return"] = "";
	}
	$forced_cf = [
		"GPRINT:LAST" => $cf["last"],
		"GPRINT:AVERAGE" => $cf["average"],
		"GPRINT:MIN" => $cf["minimum"],
		"GPRINT:MAX" => $cf["maximum"],
	];
	if (isset($forced_cf[$style])) {
		$row["consolidation_function_id"] = $forced_cf[$style];
	}
	$rows = $legend ? [] : [$row];
	if ($stack) {
		$base_id = (int) ($options["stack_source_id"] ?? 0);
		if ($base_id < 1) {
			throw new InvalidArgumentException("Select a data-template item for the stack base.");
		}
		$base = $row;
		$base["graph_type_id"] = (int) array_search($line ? "LINE1" : "AREA", $types, true);
		$base["task_item_id"] = $base_id;
		$base["text_format"] = "Base:";
		array_unshift($rows, $base);
	}
	if ($legend || $plot) {
		$labels = [
			$cf["last"] => "Current:",
			$cf["average"] => "Average:",
			$cf["minimum"] => "Minimum:",
			$cf["maximum"] => "Maximum:",
		];
		$flags = [
			$cf["last"] => "show_current",
			$cf["average"] => "show_average",
			$cf["minimum"] => "show_minimum",
			$cf["maximum"] => "show_maximum",
		];
		foreach ($labels as $cf_id => $label) {
			if ($style === "LEGEND" && $cf_id === $cf["minimum"]) {
				continue;
			}
			if (!$legend && array_key_exists($flags[$cf_id], $options) && empty($options[$flags[$cf_id]])) {
				continue;
			}
			$print = $row;
			$print["graph_type_id"] = (int) array_search("GPRINT", $types, true);
			$print["color_id"] = 0;
			$print["line_width"] = 0;
			$print["dashes"] = $print["shift"] = $print["value"] = "";
			$print["dash_offset"] = 0;
			$print["consolidation_function_id"] = $cf_id;
			$print["text_format"] = $label;
			$print["hard_return"] = "";
			$rows[] = $print;
		}
		if (count($rows) > ($stack ? 2 : ($plot ? 1 : 0))) {
			$rows[count($rows) - 1]["hard_return"] = "on";
		}
	}
	return $rows;
}
