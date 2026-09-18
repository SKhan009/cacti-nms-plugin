/**
 * @file nms-devices.js
 * Enhance device and graph forms with protocol-dependent fields, searchable selectors, color previews, and scale help.
 */
/** Initialize protocol-dependent fields on the device form when its SNMP selector exists. */ (function () {
	"use strict";

	var version = document.getElementById("nmsSnmpVersion");
	if (!version) return;

	var community = document.getElementById("nmsSnmpCommunity");
	var username = document.getElementById("nmsSnmpUsername");
	var authProtocol = document.getElementById("nmsSnmpAuthProtocol");
	var authPassword = document.getElementById("nmsSnmpPassword");
	var privacyProtocol = document.getElementById("nmsSnmpPrivProtocol");
	var privacyPassphrase = document.getElementById("nmsSnmpPrivPassphrase");
	var context = document.getElementById("nmsSnmpContext");
	var engineId = document.getElementById("nmsSnmpEngineId");
	var modeNote = document.getElementById("nmsSnmpModeNote");

	/** Synchronize a conditional field's visibility, enabled state, and required flag. */
	function setFieldState(input, visible, required) {
		if (!input) return;
		var field = input.closest(".nms-conditional-field");
		if (field) field.hidden = !visible;
		input.disabled = !visible;
		input.required = visible && Boolean(required);
	}

	/** Show and require credentials appropriate to the selected SNMP version and security protocols. */
	function updateSnmpFields() {
		var isV3 = version.value === "3";
		var authenticationEnabled = isV3 && authProtocol.value !== "[None]";

		if (!authenticationEnabled && privacyProtocol)
			privacyProtocol.value = "[None]";
		var privacyEnabled =
			authenticationEnabled && privacyProtocol.value !== "[None]";

		setFieldState(
			community,
			version.value === "1" || version.value === "2",
			version.value === "1" || version.value === "2",
		);
		setFieldState(username, isV3, isV3);
		setFieldState(authProtocol, isV3, false);
		setFieldState(
			authPassword,
			authenticationEnabled,
			authenticationEnabled,
		);
		setFieldState(privacyProtocol, authenticationEnabled, false);
		setFieldState(privacyPassphrase, privacyEnabled, privacyEnabled);
		setFieldState(context, isV3, false);
		setFieldState(engineId, isV3, false);

		if (!modeNote) return;
		if (version.value === "0") {
			modeNote.textContent = "SNMP is not in use for this device.";
		} else if (!isV3) {
			modeNote.textContent =
				"Version " + version.value + " uses a community string.";
		} else if (!authenticationEnabled) {
			modeNote.textContent =
				"SNMP v3 username only (no authentication or encryption).";
		} else if (!privacyEnabled) {
			modeNote.textContent =
				"SNMP v3 authentication enabled without encryption.";
		} else {
			modeNote.textContent =
				"SNMP v3 authentication and encryption enabled.";
		}
	}

	version.addEventListener("change", updateSnmpFields);
	authProtocol.addEventListener("change", updateSnmpFields);
	privacyProtocol.addEventListener("change", updateSnmpFields);
	updateSnmpFields();
})();

/** Initialize searchable selectors and graph-style form controls on the current page. */ (function () {
	"use strict";

	/** Enhance a native select with searchable choices while preserving its submitted value and change events. */
	function upgradeSearchSelect(select) {
		if (!select || select.dataset.searchReady === "true") return;
		select.dataset.searchReady = "true";
		var wasRequired = select.required;
		select.required = false;
		select.classList.add("nms-search-select-source");

		var wrapper = document.createElement("span");
		wrapper.className = "nms-search-select-control";
		var trigger = document.createElement("button");
		trigger.type = "button";
		trigger.className = "nms-search-select-trigger";
		trigger.disabled = select.disabled;
		trigger.setAttribute("aria-haspopup", "listbox");
		trigger.setAttribute("aria-expanded", "false");
		var panel = document.createElement("span");
		panel.className = "nms-search-select-panel";
		panel.hidden = true;
		var search = document.createElement("input");
		search.type = "search";
		search.autocomplete = "off";
		search.placeholder =
			select.dataset.searchPlaceholder || "Search options";
		search.setAttribute("aria-label", search.placeholder);
		search.setAttribute("data-nms-no-tooltip", "");
		var list = document.createElement("span");
		list.className = "nms-search-select-options";
		list.setAttribute("role", "listbox");
		var empty = document.createElement("span");
		empty.className = "nms-search-select-empty";
		empty.textContent = "No matching options";
		empty.hidden = true;

		/** Show the core label and a validated color swatch for color options. */
		function renderOption(target, option) {
			target.textContent = option ? option.text : "Select an option";
			if (option && /^[0-9a-f]{6}$/i.test(option.dataset.hex || "")) {
				var chip = document.createElement("i");
				chip.className = "nms-option-color-swatch";
				chip.setAttribute("aria-hidden", "true");
				chip.style.backgroundColor = "#" + option.dataset.hex;
				target.prepend(chip);
			}
		}
		/** Copy the selected option's text into the searchable selector trigger. */
		function syncLabel() {
			var option =
				select.options[select.selectedIndex] || select.options[0];
			renderOption(trigger, option);
			list.querySelectorAll('[role="option"]').forEach(function (choice) {
				choice.setAttribute(
					"aria-selected",
					String(choice.dataset.value === select.value),
				);
			});
		}
		/** Close the options panel and update its accessibility state. */
		function close() {
			panel.hidden = true;
			trigger.setAttribute("aria-expanded", "false");
		}

		Array.prototype.slice.call(select.options).forEach(
			/** Create a selectable button for each native option. */ function (
				option,
			) {
				if (option.value === "") return;
				var choice = document.createElement("button");
				choice.type = "button";
				choice.className = "nms-search-select-option";
				renderOption(choice, option);
				choice.disabled = option.disabled;
				choice.dataset.value = option.value;
				choice.setAttribute("role", "option");
				choice.addEventListener(
					"click",
					/** Commit the chosen native value, notify listeners, and return focus to the trigger. */ function () {
						select.value = option.value;
						select.dispatchEvent(
							new Event("change", { bubbles: true }),
						);
						syncLabel();
						trigger.classList.remove("invalid");
						close();
						trigger.focus();
					},
				);
				list.appendChild(choice);
			},
		);

		trigger.addEventListener(
			"click",
			/** Toggle this options panel after closing other open searchable panels. */ function () {
				var opening = panel.hidden;
				document
					.querySelectorAll(".nms-search-select-panel:not([hidden])")
					.forEach(
						/** Hide another open options panel. */ function (
							other,
						) {
							other.hidden = true;
						},
					);
				panel.hidden = !opening;
				trigger.setAttribute(
					"aria-expanded",
					opening ? "true" : "false",
				);
				if (opening) {
					search.value = "";
					search.dispatchEvent(new Event("input"));
					search.focus();
				}
			},
		);
		search.addEventListener(
			"input",
			/** Filter searchable choices and update the no-results indicator. */ function () {
				var normalize =
					/** Normalize case, separators, and whitespace for forgiving option searches. */ function (
						value,
					) {
						return value
							.toLowerCase()
							.replace(/[^a-z0-9]+/g, " ")
							.trim();
					};
				var term = normalize(search.value);
				var shown = 0;
				list.querySelectorAll(".nms-search-select-option").forEach(
					/** Hide choices that do not match the normalized search and count visible results. */ function (
						choice,
					) {
						choice.hidden =
							term !== "" &&
							normalize(choice.textContent).indexOf(term) === -1;
						if (!choice.hidden) shown++;
					},
				);
				empty.hidden = shown !== 0;
			},
		);
		wrapper.addEventListener(
			"keydown",
			/** Close the options panel on Escape and restore trigger focus. */ function (
				event,
			) {
				if (event.key === "Escape") {
					close();
					trigger.focus();
				}
			},
		);
		document.addEventListener(
			"click",
			/** Close the selector when a click occurs outside its wrapper. */ function (
				event,
			) {
				if (!wrapper.contains(event.target)) close();
			},
		);
		if (wasRequired && select.form)
			select.form.addEventListener(
				"submit",
				/** Prevent submission of an empty required selector and focus its invalid trigger. */ function (
					event,
				) {
					if (!select.disabled && select.value === "") {
						event.preventDefault();
						trigger.classList.add("invalid");
						trigger.focus();
					}
				},
			);

		select.parentNode.insertBefore(wrapper, select);
		wrapper.appendChild(select);
		wrapper.appendChild(trigger);
		panel.appendChild(search);
		panel.appendChild(list);
		panel.appendChild(empty);
		wrapper.appendChild(panel);
		select.addEventListener("change", syncLabel);
		syncLabel();
	}

	document
		.querySelectorAll("select.nms-search-select")
		.forEach(upgradeSearchSelect);

	var itemStyle = document.getElementById("nmsGraphItemStyle");
	if (itemStyle) {
		var itemForm = itemStyle.form;
		/** Toggle one graph-item control without changing the native value it contains. */
		function showItemField(name, visible) {
			var input = itemForm.elements.namedItem(name);
			if (!input) return;
			var field =
				input.closest("[data-item-field]") ||
				input.closest(".nms-core-field") ||
				input.closest("label");
			field.hidden = !visible;
			input.disabled = !visible;
			var trigger = field.querySelector(".nms-search-select-trigger");
			if (trigger) trigger.disabled = !visible;
		}
		/** Mirror the fields relevant to each native Cacti graph item type. */
		function updateItemFields() {
			var type = itemStyle.value;
			var line = type.indexOf("LINE") === 0;
			var plot = line || type === "AREA" || type === "AREA:STACK";
			var print = type.indexOf("GPRINT") === 0;
			var legend = type === "LEGEND" || type === "LEGEND_CAMM";
			var rule = type === "HRULE" || type === "VRULE";
			var tick = type === "TICK";
			var numeric = plot || print || legend || tick;
			var fields = {
				color_id: plot || rule || tick,
				alpha: plot || tick,
				consolidation_function_id: plot || type === "GPRINT",
				cdef_id: numeric,
				vdef_id: numeric,
				gprint_id: plot || print || legend,
				text_format: !legend && type !== "TEXTALIGN",
				item_value: rule || tick,
				line_width: line,
				dashes: line || rule,
				dash_offset: line || rule,
				textalign: type === "TEXTALIGN",
				hard_return: !legend && type !== "TEXTALIGN",
				shift: plot,
				shift_seconds:
					plot && itemForm.elements.namedItem("shift").value === "on",
				stack_source_id: type.indexOf(":STACK") !== -1,
			};
			Object.keys(fields).forEach(function (name) {
				showItemField(name, fields[name]);
			});
			var legendGroup = itemForm.elements
				.namedItem("show_current")
				.closest("fieldset");
			legendGroup.hidden = !plot;
			legendGroup.querySelectorAll("input").forEach(function (input) {
				input.disabled = !plot;
			});
			var valueInput = itemForm.elements.namedItem("item_value");
			valueInput.required = rule || tick;
			document.getElementById("nmsGraphItemValueLabel").textContent = tick
				? "Tick fraction"
				: type === "VRULE"
					? "Unix timestamp"
					: "Rule value";
			document.getElementById("nmsGraphItemValueHelp").textContent = tick
				? "Graph-height fraction between -1 and 1, for example 0.1."
				: type === "VRULE"
					? "Time in Unix seconds at which to draw the vertical line."
					: "Numeric value at which to draw the horizontal line.";
			document.getElementById("nmsGraphItemHelp").textContent = legend
				? "Creates the native GPRINT legend items; this type does not draw a line."
				: print
					? "Prints the selected reading in the legend; no plotted line is added."
					: type.indexOf(":STACK") !== -1
						? "Choose a base reading below. Cacti draws the base before this stacked item."
						: type === "COMMENT" || type === "TEXTALIGN" || rule
							? "Creates this annotation item only. Other items can be added in Cacti."
							: "Creates this plotted item and the checked legend values.";
		}
		itemStyle.addEventListener("change", updateItemFields);
		itemForm.elements
			.namedItem("shift")
			.addEventListener("change", updateItemFields);
		itemForm.addEventListener("submit", function (event) {
			var stack = itemForm.elements.namedItem("stack_source_id");
			if (!stack.disabled && !stack.value) {
				event.preventDefault();
				var trigger = stack.parentElement.querySelector(
					".nms-search-select-trigger",
				);
				trigger.classList.add("invalid");
				trigger.focus();
			}
		});
		updateItemFields();
	}

	var color = document.getElementById("nmsGraphColor");
	var swatch = document.getElementById("nmsGraphColorSwatch");
	if (color && swatch) {
		/** Preview the selected graph color only when its value is valid six-digit hexadecimal. */
		function updateColorSwatch() {
			var option = color.options[color.selectedIndex];
			var hex = option ? option.getAttribute("data-hex") : "";
			if (hex && /^[0-9a-f]{6}$/i.test(hex))
				swatch.style.backgroundColor = "#" + hex;
		}
		color.addEventListener("change", updateColorSwatch);
		updateColorSwatch();
	}

	// Native scale descriptions remain visible; never hide values that still get saved.
	var logarithmic = document.getElementById("nmsCore_auto_scale_log");
	var logUnits = document.getElementById("nmsCore_scale_log_units");
	if (logarithmic && logUnits) {
		/** Match the native editor's dependency for SI units on logarithmic graphs. */
		function updateLogUnits() {
			logUnits.disabled = !logarithmic.checked;
			var override =
				logUnits.form.elements.namedItem("t_scale_log_units");
			if (override) override.disabled = !logarithmic.checked;
		}
		logarithmic.addEventListener("change", updateLogUnits);
		updateLogUnits();
	}
})();

/** Show inherited methods and seed a new preset selection without changing saved settings. */
(function () {
	"use strict";
	var section = document.getElementById("discovery-connections");
	if (!section) return;
	var preset = section.querySelector('[name="discovery_preset_id"]');
	var mode = section.querySelector('[name="discovery_method_mode"]');
	var boxes = section.querySelectorAll('[name="discovery_methods[]"]');
	/**
	 * Updates update Methods.
	 */
	function updateMethods(seed) {
		var option = preset.options[preset.selectedIndex];
		var methods = (
			(option && option.getAttribute("data-methods")) ||
			""
		).split(",");
		boxes.forEach(function (box) {
			var allowed = methods.indexOf(box.value) !== -1;
			if (seed || mode.value === "preset") box.checked = allowed;
			box.disabled = mode.value === "preset" || !allowed;
		});
	}
	preset.addEventListener("change", function () {
		updateMethods(true);
	});
	mode.addEventListener("change", function () {
		updateMethods(false);
	});
	updateMethods(false);
})();

(function () {
	const short = document.getElementById("nms-short-name"),
		name = document.querySelector('input[name="description"]');
	if (!short || !name) return;
	function update() {
		const kinds = {
			switch: "SW",
			router: "RTR",
			server: "SRV",
			sensor: "SNS",
			ups: "UPS",
			phone: "PH",
			laptop: "LAP",
			linux: "PC",
			workstation: "PC",
			rhel: "PC",
			camera: "CAM",
		};
		const key = Object.keys(kinds).find((k) =>
			name.value.toLowerCase().includes(k),
		);
		const id = Number(short.dataset.deviceId) || 0;
		const generated =
			(kinds[key] || "DEV") +
			"-" +
			(id ? String(id).padStart(2, "0") : "…");
		short.placeholder = generated;
		document.getElementById("nms-short-name-help").textContent = short.value
			? "Custom topology label. Clear to use the device name."
			: "Automatic short name: " +
				generated +
				(id ? "" : " (number assigned when saved)");
	}
	name.addEventListener("input", update);
	short.addEventListener("input", update);
	update();
})();
