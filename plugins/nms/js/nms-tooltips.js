/**
 * @file nms-tooltips.js
 * Provide shared contextual help with keyboard/pointer positioning and enhancement of dynamically inserted controls.
 */
/** Initialize shared contextual help, tooltip positioning, and dynamic-content enhancement. */ (function () {
	"use strict";

	var diagnosticHelp = {
        ping: "Checks whether the device responds. Shows response time and packet loss; blocked ICMP can prevent replies.",
        traceroute: "Shows the network hops to the device. Helps locate delays; some routers may not reply.",
        arp: "Shows IP and MAC addresses learned by the collector. It does not scan the network or read the device’s ARP table.",
        iperf3: "Measures TCP speed by sending test traffic. Requires an iperf3 server on the target, using TCP port 5201.",
        netperf: "Measures TCP speed by sending test traffic. Requires netperf on the collector and netserver on the target.",
        pathchar: "Estimates bandwidth along the network path. Requires Pathchar on the collector; blocked probes may give incomplete results."
    };

    var fieldHelp = {
        diagnostic_profile_name: "Name this set of tests and limits, for example Branch office checks.",
        diagnostic_profile_id: "Choose the tests and limits allowed for this device. Assigning a profile does not run tests.",
        ping_count: "Number of ping probes per test (1–10). More probes take longer.",
        trace_hops: "Maximum network hops to check (1–30). For example, 20 checks up to twenty hops.",
        bandwidth_seconds: "How long iPerf3 or Netperf sends traffic (1–30 seconds). Longer tests use more bandwidth.",
        tool: "Choose a test to run. Only tools allowed by the device’s profile are listed.",
		rule_name:
			"Enter a recognisable rule name, for example New network switches. This helps identify the rule when reviewing automatic assignments.",
		preset_id:
			"Choose the shared preset to assign, for example the five-minute LLDP preset. Its timing and methods are shared by assigned devices.",
		"host_ids[]":
			"Select the existing devices that should receive this preset, for example all lab switches. Only the selected devices are updated.",
		refresh_seconds:
			"Enter how often the topology display refreshes, for example 30 seconds. Allowed range: 10–300. Display refresh does not start collection.",
		stale_seconds:
			"Enter how long evidence remains current, for example 900 for fifteen minutes. Use at least twice the collection interval; allowed range is 600–604800 seconds.",
		interval_seconds:
			"Enter the collection interval in seconds, for example 300 for five minutes. It must match a multiple of the Cacti poller interval, between 300 and 86400.",
		device_type:
			"Enter the equipment type, for example Switch, Router, Server or UPS. Use consistent spelling across devices. Leave blank if the type is unknown.",
		manual_chassis_id:
			"Enter the chassis identifier advertised by LLDP, for example 02:11:22:33:44:55, or the manufacturer chassis identifier. Review any suggestion; leave blank if unknown. This is not necessarily the serial number.",
		manual_mac_address:
			"Enter the device MAC in six hexadecimal pairs, for example 02:11:22:33:44:55. Review any discovered suggestion. Leave blank if unknown; do not enter an IP address.",
		manual_port_count:
			"Enter the total physical network ports, for example 24 for a 24-port switch. Use a whole number from 0 to 65535; leave blank if unknown. This does not create ports or connections.",
		manual_serial_number:
			"Enter the serial printed on the device label, for example SW-2026-001. Review any SNMP suggestion before saving. Leave blank if unknown; your manual entry stays separate from SNMP observations.",
		description:
			"Enter a unique, recognisable device name, for example BLR-Core-Switch-01. This name appears in Cacti inventory and the topology.",
		hostname:
			"Enter the management IP or DNS name reachable from the assigned collector, for example 192.0.2.10 or switch01.example.net. Do not include https:// or a port; enter the SNMP port separately.",
		host_template_id:
			"Choose an installed Cacti template matching the device, for example Generic SNMP Device for a basic SNMP target. Choose None if you will add monitoring items separately.",
		site_id:
			"Choose the device location from existing Cacti sites, for example Edge. Choose No site if no location group applies. A site does not configure network connections.",
		discovery_preset_id:
			"Select a shared collection preset, for example NMS Lab LLDP — 5 minutes. It supplies methods and timing; this device keeps its own Cacti SNMP credentials. Select Not assigned to leave connection discovery unconfigured.",
		discovery_enabled:
			"Select Enabled to collect the assigned connection methods on schedule, or Paused to stop NMS connection collection. Example: pause a device during maintenance; Cacti monitoring remains separate.",
		discovery_method_mode:
			"Choose Follow all shared-preset methods to inherit its selections. Choose Use selected subset below to use fewer methods, for example LLDP only from an LLDP/CDP preset. Save the device to apply.",
		poller_id:
			"Choose the Cacti collector that can reach the management address. Example: Main Poller for devices reachable from this server.",
		location:
			"Optionally enter the physical location, for example Building A / Floor 2 / Rack 03. Leave blank if unknown.",
		snmp_version:
			"Choose the version enabled on the device. Version 1 and Version 2 use a community; Version 3 uses an SNMPv3 user. Not In Use disables SNMP. Connection discovery supports SNMPv1, SNMPv2c, and SNMPv3; unavailable MIBs are skipped safely.",
		snmp_community:
			"Enter the exact read-only community configured on the target. Example: use public only if that is actually configured. This value is case-sensitive; an arbitrary name will not authenticate.",
		snmp_port:
			"Enter the target SNMP UDP port. Example: 161 for a network device, or 1161 for this NMS simulator. Valid ports are 1–65535.",
		snmp_timeout:
			"Enter the reply timeout in milliseconds, for example 1000 for one second. Use a positive number; increase it for a slow link.",
		snmp_username:
			"Enter the SNMPv3 security user configured on the target, for example nmsreader. This is not necessarily the operating-system login.",
		snmp_auth_protocol:
			"Choose the same authentication algorithm as the target SNMPv3 user, for example SHA if configured there. Choose None only when that user uses no authentication.",
		snmp_password:
			"Enter the target SNMPv3 authentication passphrase, at least eight characters. Use the actual configured secret, not the username. It must match the chosen authentication algorithm.",
		snmp_priv_protocol:
			"Choose the target SNMPv3 encryption algorithm, for example AES if configured there. Use None if privacy is not configured. Encryption requires authentication.",
		snmp_priv_passphrase:
			"Enter the configured SNMPv3 privacy passphrase, at least eight characters. It may differ from the authentication passphrase. Leave empty when privacy is None.",
		snmp_context:
			"Leave blank for the default SNMPv3 context. If your device requires a context, enter its exact configured name, for example vlan-10.",
		snmp_engine_id:
			"Normally leave blank so SNMP can discover the engine ID. Only enter the vendor-provided value when required. NMS connection discovery does not support an explicitly configured engine ID.",
		availability_method:
			"Choose how Cacti checks whether the device is up. Example: SNMP Uptime for an SNMP-managed switch; Ping for a device without SNMP. None disables this availability check.",
		ping_method:
			"Choose the probe used when availability includes ping. Example: ICMP Ping if allowed, or TCP Ping to a known listening service. This does not configure the remote service.",
		ping_port:
			"For TCP or UDP probes, enter the service port to test, for example TCP 443 for HTTPS. ICMP ignores this field. Use a port appropriate to the selected probe.",
		ping_timeout:
			"Enter the availability probe timeout in milliseconds, for example 1000 for one second. Increase it for slower links.",
		ping_retries:
			"Enter extra attempts after the first probe, for example 2 for two retries. Use 0 for no retries.",
		max_oids:
			"Choose the maximum OIDs per SNMP request. Example: start with 10; reduce to 5 if the device rejects larger requests.",
		device_threads:
			"Choose parallel polling workers for this device. Example: keep 1 thread initially; increase only if the device needs more polling capacity.",
		external_id:
			"Optionally enter an ID from your asset system, for example ASSET-1042. Leave blank if there is no external inventory ID.",
		notes: "Optionally enter operator information, for example Installed in rack 3; uplink to core port 12. Do not place passwords here.",
		proxy: "Check only when devices share a management address through a proxy or simulator. Example: several SNMPSim communities on one collector address. Leave unchecked for a normal unique device address.",
		disabled:
			"Check to stop Cacti monitoring during maintenance while keeping the device. Leave unchecked for normal polling.",
		data_template_rrd_id:
			"A reusable item from a Cacti data template. It defines the reading used by this global graph template; no device is changed.",
		graph_name:
			"Optional display name for the graph template. Leave empty to use a clear name derived from the data template.",
		vertical_label:
			"Short unit label printed beside the graph, such as %, bytes, ms, or °C.",
		graph_style:
			"All graph item types from Cacti core. The form shows the settings for the selected type.",
		stack_source_id:
			"Reading drawn first as the base for an AREA:STACK or LINE:STACK item.",
		item_value:
			"Horizontal rule value, vertical rule Unix timestamp, or tick fraction, depending on the item type.",
		line_width:
			"Line thickness in pixels. Leave blank to use the selected LINE type default.",
		dashes: "Optional comma-separated lengths for alternating drawn and empty line segments, such as 5,3.",
		dash_offset: "Number of pixels by which to offset the dash pattern.",
		vdef_id: "A reusable Cacti VDEF calculation for the selected reading.",
		text_format: "Text displayed in the graph legend or as a COMMENT.",
		textalign:
			"Alignment for subsequent legend text; TEXTALIGN itself does not draw a line.",
		hard_return: "Start a new legend line after this item.",
		shift: "Shift the plotted reading forward by the configured number of seconds.",
		shift_seconds: "Non-negative time shift in seconds.",
		consolidation:
			"RRDtool consolidation function used for the main graph item: average, last, minimum, or maximum.",
		color_id: "Cacti color applied to the main graph item.",
		alpha_percent:
			"Visibility of the graph item. A lower percentage makes the item more transparent.",
		cdef_id:
			"Optional Cacti CDEF that transforms the value before it is drawn, for example dividing or converting units.",
		gprint_id:
			"Cacti GPRINT preset that formats numeric legend values such as Current, Minimum, Average, and Maximum.",
		show_current: "Adds the latest collected value to the graph legend.",
		show_minimum:
			"Adds the lowest value in the selected time range to the graph legend.",
		show_average:
			"Adds the average value in the selected time range to the graph legend.",
		show_maximum:
			"Adds the highest value in the selected time range to the graph legend.",
		width: "Rendered graph width in pixels.",
		height: "Rendered graph height in pixels.",
		base_value:
			"Use 1000 for decimal/network units or 1024 for binary/memory units.",
		image_format_id:
			"Image type generated by Cacti. SVG scales cleanly; PNG is a raster image.",
		auto_scale_opts:
			"Controls which configured limits Cacti keeps when automatic scaling is enabled.",
		lower_limit:
			"Minimum Y-axis value used according to the selected scaling method.",
		upper_limit:
			"Maximum Y-axis value used according to the selected scaling method.",
		slope_mode: "Uses smoother RRDtool line rendering.",
		auto_scale:
			"Lets Cacti calculate a useful Y-axis range from the collected values.",
		auto_padding:
			"Adds space around labels and graph edges to reduce clipping.",
		auto_scale_log:
			"Uses a logarithmic Y-axis. This is useful when values span several orders of magnitude.",
		auto_scale_rigid:
			"Prevents Cacti from extending the configured Y-axis limits.",
		snmprec_file:
			"Choose a .snmprec recording, for example lab-switch.snmprec. Each line contains OID|type|value. Upload a recording, not a MIB definition or a ZIP archive.",
		community:
			"Enter a unique simulator record name, for example lab-switch-01. Devices use this as their SNMP community; avoid spaces and path separators.",
		template_name:
			"Enter a descriptive Cacti device template name, for example Lab Switch Monitoring. Imported numeric OIDs supply its monitoring templates.",
		category_id:
			"Choose the segment appropriate to the imported equipment, for example Network for a switch.",
		equipment_category_id:
			"Choose the broad device segment. Example: select Network for a switch, then enter Switch as its device type. Choose Unclassified if you are unsure.",
		graph_template_id:
			"Choose an existing graph template matching the device metric, for example Device - Uptime. Adding the association makes it available for graph creation.",
		snmp_query_id:
			"Choose a supported indexed query, for example SNMP - Interface Statistics for interface monitoring. The device must expose the matching SNMP table.",
		reindex_method:
			"Choose when Cacti should refresh indexed rows. Example: Uptime refreshes after a reboot; Index Count refreshes when the row count changes.",
		parameter_key: "The live Cacti parameter evaluated by this fault rule.",
		name: "Enter a short descriptive name, for example Switch LLDP — 5 minutes. Use a name that helps operators choose the correct configuration.",
		comparison: "The comparison applied to the current device value.",
		threshold_value:
			"The value that makes this rule a fault, such as 80, down, or full.",
		unit: "Optional unit shown with the configured threshold.",
		severity:
			"Operational importance assigned when this rule creates a fault.",
		enabled:
			"Enable the preset or rule when it should run. Example: turn it off temporarily during maintenance; existing configuration is retained.",
		host_id:
			"Choose the existing Cacti device to configure, for example BLR-Core-Switch-01. It must be reachable using its saved connection settings.",
	};

	var sectionHelp = {
        "Enabled tools": "Choose which tests this profile allows. Checking a box does not run or install the tool.",
        "On-demand diagnostics": "Allow manual tests from the device’s collector. Assigning a profile does not schedule tests.",
        "Collector tool requirements": "Tools must be installed on the assigned collector. Bandwidth tests also need a server on the target.",
        "Run a diagnostic": "Choose a device and tool, then run the test. Results appear automatically.",
		"Device identity":
			"Core Cacti fields that identify the device and connect it to a host template, site, and collector.",
		"SNMP connection":
			"Credentials and transport settings Cacti uses to read the device over SNMP.",
		"Availability and polling":
			"Reachability checks and poller workload settings used by Cacti.",
		"Legend values":
			"Choose which GPRINT values are added after the main graph item.",
		"Rendering and scale":
			"RRDtool presentation and Y-axis behavior saved on the native Cacti graph template.",
		"Cacti device inventory":
			"Live device records, status, availability, data-source, graph, and poller totals read from Cacti core.",
		"Upload an SNMP record":
			"Validates a .snmprec file, deploys it to SNMPSim, and creates native Cacti templates for numeric readings.",
		"Imported SNMP records":
			"Import history and generated Cacti objects recorded by the NMS plugin.",
		"Create graph template":
			"Build a reusable native Cacti graph template from a global data-template item without selecting or changing a device.",
		"Create a graph template from a data template":
			"Choose a reusable Cacti data-template item and configure the graph appearance, legend, and scale.",
		"Current graph templates":
			"All global graph templates and their device-graph usage counts read directly from Cacti.",
		"Associated Graph Templates":
			"Templates currently associated with this Cacti device and their graph status.",
		"Associated Data Queries":
			"Indexed Cacti data queries, re-index rules, and cached row counts for this device.",
		"Topology root":
			"The selected core device remains fixed while other devices are positioned and connected around it.",
		"Cacti devices":
			"Live Cacti inventory for the selected site. Drag a device onto the map to place it.",
		"Network topology":
			"Interactive site map. Positions and parent links are stored by NMS; device health stays live from Cacti.",
		"Fault values and severity":
			"Rules compare live device parameters with configured values and assign operational severity.",
		"Cacti template mapping":
			"Maps each Cacti host template to a device segment fetched from Cacti Graph Trees.",
	};

	var tooltip;
	var activeTarget;
	var pointerX = 0;
	var pointerY = 0;
	var pointerMode = false;
	var counter = 0;
	var pointerTargets =
		".nms-sidebar-link,.nms-sidebar-status,.nms-sidebar-toggle,.nms-brand,.nms-backend-button";

	/** Normalize whitespace and remove the optional marker from display text. */
	function cleanText(value) {
		return (value || "")
			.replace(/\s+/g, " ")
			.replace(/optional/gi, "")
			.trim();
	}

	/** Read a label's text without embedded controls or existing help icons. */
	function labelText(label) {
		var clone = label.cloneNode(true);
		Array.prototype.forEach.call(
			clone.querySelectorAll(
				"input,select,textarea,button,small,.nms-help-icon,.nms-search-select-control",
			),
			/** Remove an embedded control from the temporary label clone. */ function (
				node,
			) {
				node.remove();
			},
		);
		return cleanText(clone.textContent);
	}

	/** Identify search/filter controls and explicit opt-outs that should not receive tooltips. */
	function isSearchOrFilter(target) {
		if (!target || !target.matches) return false;
		if (target.closest("[data-nms-no-tooltip]")) return true;
		if (
			target.matches(
				'input[type="search"],.nms-search,.nms-topology-search',
			)
		)
			return true;
		if (
			target.closest(
				".nms-search,.nms-topology-search,.nms-toolbar,.nms-site-picker",
			)
		)
			return true;
		if (target.closest('form[method="get"]')) return true;
		return false;
	}

	/** Create the shared accessible tooltip once; a native popover keeps it above modal dialogs. */
	function ensureTooltip() {
		if (tooltip) return tooltip;
		tooltip = document.createElement("div");
		tooltip.id = "nmsGlobalTooltip";
		tooltip.className = "nms-tooltip";
		tooltip.setAttribute("role", "tooltip");
		if (typeof tooltip.showPopover === "function") {
			tooltip.setAttribute("popover", "manual");
		}
		document.body.appendChild(tooltip);
		return tooltip;
	}

	/** Open the tooltip in the browser top layer when available. */
	function openTooltip() {
		if (!tooltip || typeof tooltip.showPopover !== "function") return;
		try {
			if (!tooltip.matches(":popover-open")) tooltip.showPopover();
		} catch (error) {
			// The normal fixed-position fallback remains available in older browsers.
		}
	}

	/** Close the native popover without affecting the fallback tooltip presentation. */
	function closeTooltip() {
		if (!tooltip || typeof tooltip.hidePopover !== "function") return;
		try {
			if (tooltip.matches(":popover-open")) tooltip.hidePopover();
		} catch (error) {
			// A tooltip that was not opened as a popover needs no further cleanup.
		}
	}

	/** Place the tooltip above or below its target while keeping it within the viewport. */
	function position(target) {
		if (!tooltip || !target) return;
		var rect = target.getBoundingClientRect();
		var tipRect = tooltip.getBoundingClientRect();
		var gap = 9;
		var placement = rect.top > tipRect.height + 20 ? "top" : "bottom";
		var top =
			placement === "top"
				? rect.top - tipRect.height - gap
				: rect.bottom + gap;
		var left = Math.max(
			12,
			Math.min(
				rect.left + rect.width / 2 - tipRect.width / 2,
				window.innerWidth - tipRect.width - 12,
			),
		);
		var arrow = Math.max(
			12,
			Math.min(rect.left + rect.width / 2 - left - 6, tipRect.width - 24),
		);
		tooltip.style.top = Math.round(top) + "px";
		tooltip.style.left = Math.round(left) + "px";
		tooltip.style.setProperty("--nms-tip-arrow", Math.round(arrow) + "px");
		tooltip.setAttribute("data-placement", placement);
	}

	/** Position a pointer-following tooltip with edge-aware offsets. */
	function positionAtPointer() {
		if (!tooltip) return;
		var tipRect = tooltip.getBoundingClientRect();
		var gap = 16;
		var left = pointerX + gap;
		var top = pointerY + gap;
		if (left + tipRect.width > window.innerWidth - 12)
			left = pointerX - tipRect.width - gap;
		if (top + tipRect.height > window.innerHeight - 12)
			top = pointerY - tipRect.height - gap;
		left = Math.max(
			12,
			Math.min(left, window.innerWidth - tipRect.width - 12),
		);
		top = Math.max(
			12,
			Math.min(top, window.innerHeight - tipRect.height - 12),
		);
		tooltip.style.top = Math.round(top) + "px";
		tooltip.style.left = Math.round(left) + "px";
		tooltip.setAttribute("data-placement", "pointer");
	}

	/** Display escaped help text for a target and select keyboard or pointer positioning. */
	function show(target, event) {
		var text = target.getAttribute("data-nms-tip");
		if (!text) return;
		ensureTooltip();
		activeTarget = target;
		pointerMode = !!(
			event &&
			event.type !== "focus" &&
			target.matches(pointerTargets)
		);
		if (pointerMode) {
			pointerX = event.clientX;
			pointerY = event.clientY;
		}
		tooltip.innerHTML =
			"<strong>More information</strong>" +
			text.replace(
				/[&<>"']/g,
				/** Replace a special HTML character with its safe entity. */ function (
					character,
				) {
					return {
						"&": "&amp;",
						"<": "&lt;",
						">": "&gt;",
						'"': "&quot;",
						"'": "&#39;",
					}[character];
				},
			);
		tooltip.classList.add("visible");
		openTooltip();
		if (pointerMode) positionAtPointer();
		else position(target);
	}

	/** Hide the active tooltip, ignoring a hide request from another target. */
	function hide(target) {
		if (target && activeTarget !== target) return;
		if (tooltip) {
			tooltip.classList.remove("visible");
			closeTooltip();
		}
		activeTarget = null;
		pointerMode = false;
	}

	/** Bind tooltip interactions once per eligible element. */
	function bind(target) {
		if (!target || target.getAttribute("data-nms-tip-bound") === "1")
			return;
		if (isSearchOrFilter(target)) return;
		if (
			target.tagName === "LABEL" &&
			target.querySelector(".nms-help-icon")
		)
			return;
		target.setAttribute("data-nms-tip-bound", "1");
		target.classList.add("nms-tooltip-source");
		if (target.matches(pointerTargets))
			target.classList.add("nms-pointer-tooltip");
		if (!target.matches("a,button,input,select,textarea,[tabindex]"))
			target.setAttribute("tabindex", "0");
		if (!target.id) target.id = "nmsHelpTarget" + ++counter;
		target.setAttribute("aria-describedby", "nmsGlobalTooltip");
		target.addEventListener(
			"mouseenter",
			/** Show the target's help when the pointer enters it. */ function (
				event,
			) {
				show(target, event);
			},
		);
		target.addEventListener(
			"mousemove",
			/** Follow pointer movement only for the active pointer-enabled tooltip target. */ function (
				event,
			) {
				if (activeTarget !== target || !target.matches(pointerTargets))
					return;
				pointerX = event.clientX;
				pointerY = event.clientY;
				positionAtPointer();
			},
		);
		target.addEventListener(
			"mouseleave",
			/** Hide this target's tooltip when the pointer leaves. */ function () {
				hide(target);
			},
		);
		target.addEventListener(
			"focus",
			/** Show contextual help when the target receives keyboard focus. */ function (
				event,
			) {
				show(target, event);
			},
		);
		target.addEventListener(
			"blur",
			/** Hide this target's help when keyboard focus leaves it. */ function () {
				hide(target);
			},
		);
		target.addEventListener(
			"click",
			/** Open help-icon content without triggering its surrounding control. */ function (
				event,
			) {
				if (!target.classList.contains("nms-help-icon")) return;
				event.preventDefault();
				event.stopPropagation();
				show(target);
			},
		);
	}

	/** Append and bind a focusable help icon unless the host already has one. */
	function addIcon(host, text, title) {
		if (!host || !text || host.querySelector(".nms-help-icon")) return;
		var icon = document.createElement("span");
		icon.className = "nms-help-icon";
		icon.setAttribute("role", "button");
		icon.setAttribute("tabindex", "0");
		icon.setAttribute(
			"aria-label",
			"More information about " + (title || "this setting"),
		);
		icon.setAttribute("data-nms-tip", text);
		icon.textContent = "?";
		host.appendChild(icon);
		bind(icon);
	}

	/** Resolve a form label's help text and enhance it once with a help icon. */
	function enhanceLabel(label) {
		if (label.getAttribute("data-nms-help-ready") === "1") return;
		if (isSearchOrFilter(label)) return;
		var control = label.querySelector(
			'select[name],textarea[name],input[name]:not([type="hidden"])',
		);
		if (!control)
			control = label.querySelector(
				'input:not([type="hidden"]),select,textarea',
			);
		if (!control) return;
		var name = control.getAttribute("name") || "";
		var title =
			labelText(label) ||
			control.getAttribute("aria-label") ||
			name.replace(/_/g, " ");
		var text = label.getAttribute("data-nms-tip") || fieldHelp[name];
        if (!label.getAttribute("data-nms-tip") && name === "diagnostic_tools[]") text = diagnosticHelp[control.value];
        if (!label.getAttribute("data-nms-tip") && control.id === "nmsDiagnosticHost")
            text = "Choose the device to test. Tests run from its assigned collector, not your browser.";
		if (!text && (name === "methods[]" || name === "discovery_methods[]")) {
			text = {
				lldp: "Read advertised LLDP neighbours and ports through SNMP. Example: enable this for an LLDP-capable switch to read its neighbour ports. LLDP must already be enabled on the equipment; selecting this does not configure it.",
				cdp: "Read Cisco CDP neighbours and ports through SNMP. Example: select this for a Cisco switch with CDP enabled and readable CDP tables.",
				arp: "Read IPv4 ARP and IPv6 neighbour IP-to-MAC observations through SNMP. The device must expose IP-MIB neighbour tables. These mappings do not establish a direct physical cable; IPv6 link-local addresses retain their reporting interface scope.",
				fdb: "Read learned MAC addresses, bridge ports and available VLAN context through SNMP. Example: locate a learned MAC on switch port Gi1/0/4. Uplinks may contain many devices; endpoint locations are inferred.",
				icmp: "Use native Cacti ICMP reachability on the selected Automation range.",
				tcp: "Test each selected TCP port independently. A responding port does not identify a physical network connection.",
				udp: "Use native Cacti UDP host reachability. A result does not prove the selected UDP service is open.",
				snmp: "Read sysDescr using only the explicitly selected native SNMP option. No alternate credentials are attempted.",
			}[control.value];
		}
		// Add the visible input format to existing help where it provides a useful example.
		var placeholder = control.getAttribute("placeholder");
		if (text && placeholder && !/example|for example|such as/i.test(text))
			text += " Example or format: " + placeholder + ".";
		if (!text && title) {
			var hint = control.getAttribute("placeholder");
			var note = label.querySelector("small");
			if (hint)
				text =
					"Enter " +
					title.toLowerCase() +
					". Example or format: " +
					hint +
					".";
			else if (note) text = note.textContent.trim();
			else if (control.tagName === "SELECT")
				text =
					"Choose " +
					title.toLowerCase() +
					" from the available options to match your device configuration.";
			else
				text =
					"Enter " +
					title.toLowerCase() +
					" using the value from your device configuration.";
			if (control.type === "number") {
				if (control.min !== "")
					text += " Minimum: " + control.min + ".";
				if (control.max !== "")
					text += " Maximum: " + control.max + ".";
			}
		}
		if (!text) return;
		var host = label.querySelector(
			":scope > span:not(.nms-inline-selects):not(.nms-sidebar-copy):not(.nms-search-select-control)",
		);
		if (!host) {
			host = document.createElement("span");
			host.className = "nms-generated-label";
			var firstControl = label.querySelector("input,select,textarea");
			while (firstControl.parentNode !== label)
				firstControl = firstControl.parentNode;
			while (label.firstChild && label.firstChild !== firstControl)
				host.appendChild(label.firstChild);
			label.insertBefore(host, firstControl);
		}
		addIcon(host, text, title);
		label.setAttribute("data-nms-help-ready", "1");
	}

	/** Add contextual help to recognized section headings outside the topology canvas. */
	function enhanceSection(heading) {
		if (heading.getAttribute("data-nms-help-ready") === "1") return;
		var title = cleanText(heading.textContent);
		var text = heading.getAttribute("data-nms-tip") || sectionHelp[title];
		if (!text || heading.closest(".nms-tooltip")) return;
		addIcon(heading, text, title);
		heading.setAttribute("data-nms-help-ready", "1");
	}

	/** Enhance eligible elements in a scope and replace native titles with shared tooltip content. */
	function refresh(scope) {
		scope = scope && scope.querySelectorAll ? scope : document;
		Array.prototype.forEach.call(
			scope.querySelectorAll("[data-nms-tip]"),
			/** Remove tooltip content from controls that are excluded from contextual help. */ function (
				target,
			) {
				if (isSearchOrFilter(target))
					target.removeAttribute("data-nms-tip");
			},
		);
		Array.prototype.forEach.call(
			scope.querySelectorAll("[title]:not([data-nms-tip])"),
			/** Transfer native title text into the shared tooltip attribute to avoid duplicate tooltips. */ function (
				target,
			) {
				target.setAttribute(
					"data-nms-tip",
					target.getAttribute("title"),
				);
				target.removeAttribute("title");
			},
		);
		Array.prototype.forEach.call(
			scope.querySelectorAll("label"),
			enhanceLabel,
		);
		Array.prototype.forEach.call(
			scope.querySelectorAll("h2,legend"),
			enhanceSection,
		);
		Array.prototype.forEach.call(
			scope.querySelectorAll("[data-nms-tip]"),
			bind,
		);
	}

	document.addEventListener(
		"keydown",
		/** Dismiss the active tooltip when Escape is pressed. */ function (
			event,
		) {
			if (event.key === "Escape") hide();
		},
	);
	document.addEventListener(
		"click",
		/** Dismiss help when clicking outside the active target. */ function (
			event,
		) {
			if (activeTarget && !activeTarget.contains(event.target)) hide();
		},
	);
	window.addEventListener(
		"resize",
		/** Reposition the active tooltip after a viewport resize. */ function () {
			if (activeTarget)
				pointerMode ? positionAtPointer() : position(activeTarget);
		},
	);
	window.addEventListener(
		"scroll",
		/** Hide pointer help or reposition anchored help while the page scrolls. */ function () {
			if (activeTarget)
				pointerMode ? hide(activeTarget) : position(activeTarget);
		},
		true,
	);
	document.addEventListener(
		"DOMContentLoaded",
		/** Enhance initial page content and observe newly inserted elements for help bindings. */ function () {
			ensureTooltip();
			refresh(document);
			new MutationObserver(
				/** Process added-node batches reported by the DOM observer. */ function (
					mutations,
				) {
					mutations.forEach(
						/** Visit the nodes inserted by this DOM mutation. */ function (
							mutation,
						) {
							Array.prototype.forEach.call(
								mutation.addedNodes,
								/** Enhance newly inserted element content, ignoring text nodes. */ function (
									node,
								) {
									if (node.nodeType === 1) refresh(node);
								},
							);
						},
					);
				},
			).observe(document.body, { childList: true, subtree: true });
		},
	);
	window.NMSTooltips = { refresh: refresh, hide: hide };
})();
