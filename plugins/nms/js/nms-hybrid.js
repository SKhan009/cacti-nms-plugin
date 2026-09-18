(function () {
	"use strict";
	const root = document.getElementById("nms-hybrid");
	if (!root) return;
	const svg = document.getElementById("nms-canvas-svg"),
		notice = document.getElementById("nms-canvas-notice"),
		list = document.getElementById("nms-device-list"),
		details = document.getElementById("nms-port-details");
	let data = JSON.parse(
			document.getElementById("nms-canvas-initial").textContent,
		),
		zoom = 1,
		drag = null,
		source = null,
		selected = null,
		busy = false;
	notice.hidden = true;
	details.hidden = true;
	const editable = root.dataset.edit === "1",
		NS = "http://www.w3.org/2000/svg";
	const colors = { 3: "#228848", 1: "#cf3535", 2: "#d69c13", 0: "#7d8790" },
		states = { 3: "Up", 1: "Down", 2: "Recovering", 0: "Unknown" };
	/**
	 * Handles el.
	 */
	function el(tag, attrs, text) {
		const e = document.createElementNS(NS, tag);
		Object.entries(attrs || {}).forEach(([k, v]) => e.setAttribute(k, v));
		if (text !== undefined) e.textContent = text;
		return e;
	}
	/**
	 * Handles message.
	 */
	function message(text) {
		notice.textContent = text;
		notice.hidden = !/fail|error|unavailable/i.test(text);
	}
	let panX = 0,
		panY = 0,
		panning = null;
	/**
	 * Handles device Kind.
	 */
	function deviceKind(n) {
		if (n.icon) return n.icon;
		const type = (n.device_type || n.template || "").toLowerCase();
		if (/switch/.test(type)) return "switch";
		if (/router/.test(type)) return "router";
		if (/sensor|iot/.test(type)) return "sensor";
		if (/server|linux|windows/.test(type)) return "server";
		if (/workstation|desktop|computer/.test(type)) return "workstation";
		return "device";
	}
	/**
	 * Handles switch Nodes.
	 */
	function switchNodes() {
		return data.nodes
			.filter((n) => deviceKind(n) === "switch")
			.sort((a, b) =>
				isCore(a)
					? -1
					: isCore(b)
						? 1
						: (a.short_name || a.name).localeCompare(
								b.short_name || b.name,
							),
			);
	}
	/**
	 * Handles width.
	 */
	function width() {
		return switchNodes().length > 1
			? 2400
			: Math.max(1200, Math.ceil(Math.sqrt(data.nodes.length)) * 270);
	}
	function height() {
		return switchNodes().length > 1
			? 1000
			: Math.max(
					700,
					Math.ceil(
						data.nodes.length /
							Math.max(
								1,
								Math.ceil(Math.sqrt(data.nodes.length)),
							),
					) * 150,
				);
	}
	/**
	 * Handles ports.
	 */
	function ports(n) {
		const found = new Map();
		(n.ports || []).forEach((p) => {
			if (p.name) found.set(p.name, { ...p, edge: null });
		});
		data.links.forEach((l) => {
			const name =
				l.a === n.id ? l.a_port : l.b === n.id ? l.b_port : null;
			if (name && name !== "Unknown port")
				found.set(name, {
					...(found.get(name) || {}),
					name,
					edge: l.id,
				});
		});
		return [...found.values()];
	}
	/**
	 * Handles physical Ports.
	 */
	function physicalPorts(n) {
		const observed = ports(n),
			capacity = Math.max(
				Number(n.physical_port_count) || 0,
				observed.length,
			),
			visible = Math.min(48, capacity);
		if (deviceKind(n) !== "switch" || !visible) return observed;
		const slots = Array.from({ length: visible }, (_, i) => ({
			name: "Port " + (i + 1),
			position: i + 1,
			available: true,
		}));
		observed.forEach((p, index) => {
			let slot = Number(p.position) - 1;
			if (!Number.isInteger(slot) || slot < 0 || slot >= visible)
				slot = index < visible ? index : -1;
			if (slot >= 0)
				slots[slot] = {
					...slots[slot],
					...p,
					position: slot + 1,
					available: false,
				};
		});
		return slots;
	}
	/**
	 * Checks is Core.
	 */
	function isCore(n) {
		return n.id === data.core_id;
	}
	/**
	 * Checks is Fixed Switch.
	 */
	function isFixedSwitch(n) {
		return deviceKind(n) === "switch";
	}
	/**
	 * Handles place Switches.
	 */
	function placeSwitches() {
		const all = switchNodes();
		if (!all.length) return;
		all.forEach((node, index) => {
			node.x =
				all.length === 1 ? 50 : 18 + index * (64 / (all.length - 1));
			node.y = 52;
		});
	}
	/**
	 * Handles chassis Width.
	 */
	function chassisWidth(n) {
		return deviceKind(n) === "switch"
			? 720
			: Math.max(70, Math.min(12, ports(n).length) * 12 + 24);
	}
	/**
	 * Handles switch Port Offset.
	 */
	function switchPortOffset(i, count) {
		const capacity = Math.max(1, Math.min(48, count)),
			small = capacity <= 24;
		const pair = small ? Math.floor(i / 2) : 0,
			perRow = small ? Math.ceil(capacity / 2) : 24,
			col = small
				? perRow === 1
					? 0
					: Math.round((pair * 23) / (perRow - 1))
				: i % 24,
			isTop = small ? i % 2 === 0 : i < 24,
			gap = Math.floor(col / 4) * 16;
		return { x: -250 + col * 18 + gap, y: isTop ? -20 : 20 };
	}
	/**
	 * Handles port Point.
	 */
	function portPoint(n, name) {
		placeSwitches();
		const ps = deviceKind(n) === "switch" ? physicalPorts(n) : ports(n),
			i = ps.findIndex((p) => p.name === name);
		if (deviceKind(n) === "switch") {
			const p = switchPortOffset(i < 0 ? 0 : i, ps.length);
			return {
				x: (n.x * width()) / 100 + p.x,
				y: (n.y * height()) / 100 + p.y,
			};
		}
		return {
			x:
				(n.x * width()) / 100 +
				(i < 0
					? 0
					: ((i % 12) - (Math.min(ps.length, 12) - 1) / 2) * 12),
			y:
				(n.y * height()) / 100 +
				26 +
				Math.max(0, Math.floor(i / 12)) * 12,
		};
	}

	const tip = document.createElement("div");
	tip.className = "nms-topology-popup";
	tip.hidden = true;
	tip.setAttribute("role", "tooltip");
	document.body.append(tip);
	/**
	 * Handles hide Tip.
	 */
	function hideTip() {
		tip.hidden = true;
	}
	/**
	 * Handles hover.
	 */
	function hover(target, title, rows) {
		const show = (e) => {
			if (
				drag ||
				panning ||
				(target.hasAttribute("data-node") &&
					e.target.closest(".nms-observed-port"))
			)
				return;
			tip.replaceChildren();
			const h = document.createElement("strong");
			h.textContent = title;
			tip.append(h);
			rows.forEach(([key, value]) => {
				const row = document.createElement("div"),
					label = document.createElement("span"),
					v = document.createElement("b");
				label.textContent = key;
				v.textContent = String(value);
				row.append(label, v);
				tip.append(row);
			});
			tip.hidden = false;
			const r = target.getBoundingClientRect(),
				x = e.clientX || r.right,
				y = e.clientY || r.top;
			tip.style.left =
				Math.max(
					8,
					Math.min(x + 16, innerWidth - tip.offsetWidth - 12),
				) + "px";
			tip.style.top =
				Math.max(
					8,
					Math.min(y + 16, innerHeight - tip.offsetHeight - 12),
				) + "px";
		};
		target.addEventListener("pointerenter", show);
		target.addEventListener("pointermove", show);
		target.addEventListener("pointerleave", hideTip);
		target.addEventListener("focus", show);
		target.addEventListener("blur", hideTip);
		target.addEventListener("pointerdown", hideTip);
	}
	/**
	 * Handles format Bandwidth.
	 */
	function formatBandwidth(bps) {
		const n = Number(bps || 0);
		if (!n) return "Not reported by IF-MIB";
		if (n >= 1000000000)
			return (n / 1000000000).toFixed(n % 1000000000 ? 1 : 0) + " Gbps";
		if (n >= 1000000)
			return (n / 1000000).toFixed(n % 1000000 ? 1 : 0) + " Mbps";
		return Math.round(n / 1000) + " Kbps";
	}
	const diagnosticLabels = {
		ping: "Ping",
		traceroute: "Traceroute",
		arp: "Collector ARP lookup",
		iperf3: "iPerf3 bandwidth",
		netperf: "Netperf bandwidth",
		pathchar: "Pathchar capacity estimate",
	};
	/**
	 * Handles diagnostic Summary.
	 */
	function diagnosticSummary(n) {
		const tools = String(n.diagnostic_tools || "")
			.split(",")
			.map((v) => diagnosticLabels[v.trim()])
			.filter(Boolean);
		return n.diagnostic_profile
			? (n.short_name || n.name) +
					": " +
					n.diagnostic_profile +
					" (" +
					(tools.join(", ") || "No tools") +
					")"
			: (n.short_name || n.name) + ": no profile assigned";
	}
	/**
	 * Handles port Rows.
	 */
	function portRows(n, p) {
		const links = data.links.filter(
			(l) =>
				(l.a === n.id && l.a_port === p.name) ||
				(l.b === n.id && l.b_port === p.name),
		);
		return [
			["Port", p.name],
			["Position", p.position ?? "Not reported"],
			...(p.available
				? [
						[
							"Discovery",
							"Available socket — no SNMP interface reported",
						],
					]
				: [["Discovery", "Reported by SNMP"]]),
			...links.map((l) => {
				const other = data.nodes.find(
					(d) => d.id === (l.a === n.id ? l.b : l.a),
				);
				return [
					"Connected to",
					(other?.name || "Unknown") +
						" · " +
						(l.a === n.id ? l.b_port : l.a_port) +
						" · " +
						l.state,
				];
			}),
			...links.map((l) => ["Bandwidth", formatBandwidth(l.speed)]),
			...(!links.length
				? [["Connection", "No discovered neighbour"]]
				: []),
		];
	}
	/**
	 * Handles port Tone.
	 */
	function portTone(p) {
		const edge = p.edge && data.links.find((l) => l.id === p.edge);
		if (!edge) return "#334155";
		if (!edge.current) return "#ef4444";
		const speed = Number(
			edge.speed || edge.link_speed || edge.bandwidth || 0,
		);
		return speed >= 10000000000
			? "#10b981"
			: speed >= 1000000000
				? "#3b82f6"
				: speed > 0
					? "#f59e0b"
					: "#3b82f6";
	}
	/**
	 * Handles device Graphic.
	 */
	function deviceGraphic(n, g) {
		const color = colors[n.status] || colors[0],
			kind = deviceKind(n),
			w = chassisWidth(n),
			ps = kind === "switch" ? physicalPorts(n) : ports(n);
		const body = el("g", { class: "nms-device-chassis" });
		body.append(
			el("rect", {
				x: -w / 2 - 6,
				y: -36,
				width: w + 12,
				height: 72 + Math.ceil(ps.length / 12) * 12,
				rx: 9,
				fill: "#fff",
				stroke: source === n.id ? "#2563eb" : "transparent",
				"stroke-width": 2,
			}),
		);
		if (kind === "switch") {
			const rackHeight =
				140 + Math.max(0, Math.ceil(ps.length / 24) - 2) * 45;
			body.append(
				el("rect", {
					x: -360,
					y: -70,
					width: 720,
					height: rackHeight,
					rx: 6,
					fill: "url(#nms-rack-metal)",
					stroke: n.color || "#1e293b",
					"stroke-width": 3,
					class: "nms-reference-rack",
				}),
			);
			if (n.color)
				body.append(
					el("rect", {
						x: -356,
						y: -66,
						width: 712,
						height: rackHeight - 8,
						rx: 4,
						fill: n.color,
						"fill-opacity": 0.18,
						"pointer-events": "none",
					}),
				);
			[-1, 1].forEach((side) => {
				body.append(
					el("rect", {
						x: side < 0 ? -380 : 360,
						y: -62,
						width: 20,
						height: rackHeight - 16,
						rx: 2,
						fill: "url(#nms-rack-ears)",
					}),
				);
				[-25, 0, 25].forEach((y) =>
					body.append(
						el("circle", {
							cx: side * 370,
							cy: y,
							r: 4,
							fill: "#0f172a",
							stroke: "#475569",
						}),
					),
				);
			});
			body.append(
				el(
					"text",
					{
						x: -340,
						y: -20,
						fill: "#f8fafc",
						"font-size": 14,
						"font-weight": 800,
						"letter-spacing": 1,
					},
					n.short_name || "NEXUS-9000",
				),
				el(
					"text",
					{
						x: -340,
						y: -5,
						fill: "#94a3b8",
						"font-size": 9,
						"font-weight": 500,
					},
					(n.physical_port_count || ps.length) +
						" PHYSICAL PORTS" +
						((n.physical_port_count || 0) > 48
							? " · FIRST 48 SHOWN"
							: ""),
				),
				el("rect", {
					x: 260,
					y: -50,
					width: 75,
					height: 30,
					fill: "#020617",
					rx: 4,
					stroke: "#334155",
				}),
				el("circle", {
					cx: 275,
					cy: -35,
					r: 5,
					fill: color,
					class: n.status === 3 ? "nms-rack-led" : "",
				}),
				el(
					"text",
					{
						x: 290,
						y: -31,
						fill: "#cbd5e1",
						"font-size": 11,
						"font-weight": 600,
					},
					n.status === 3 ? "SYS OK" : states[n.status] || "Unknown",
				),
			);
		} else if (n.icon_path) {
			body.append(
				el("path", {
					d: n.icon_path,
					transform: "translate(-32 -38) scale(2)",
					fill: "none",
					stroke: n.color || color,
					"stroke-width": 1.8,
					"stroke-linecap": "round",
					"stroke-linejoin": "round",
				}),
			);
		} else if (
			[
				"laptop",
				"phone",
				"ipphone",
				"printer",
				"camera",
				"wireless",
				"firewall",
				"satellite",
			].includes(kind) &&
			n.icon_path
		) {
			body.append(
				el("path", {
					d: n.icon_path,
					transform: "translate(-28 -34) scale(1.75)",
					fill: "none",
					stroke: n.color || color,
					"stroke-width": 1.8,
					"stroke-linecap": "round",
					"stroke-linejoin": "round",
				}),
			);
		} else if (kind === "ups") {
			body.append(
				el("rect", {
					x: -25,
					y: -34,
					width: 50,
					height: 58,
					rx: 4,
					fill: n.color || color,
					stroke: "#334155",
				}),
				el("rect", {
					x: -15,
					y: -22,
					width: 30,
					height: 14,
					rx: 2,
					fill: "#0f172a",
				}),
				el("path", {
					d: "M 3 -4 L -7 9 H 0 L -3 20 L 9 6 H 2 Z",
					fill: "#fbbf24",
				}),
			);
		} else if (kind === "sensor") {
			body.append(
				el("circle", {
					cx: 0,
					cy: -5,
					r: 24,
					fill: n.color || color,
					stroke: "#334155",
					"stroke-width": 1.5,
				}),
				el("circle", {
					cx: 0,
					cy: -5,
					r: 13,
					fill: "#fff",
					"fill-opacity": 0.22,
				}),
				el("circle", { cx: 0, cy: -5, r: 4, fill: "#fff" }),
			);
		} else if (kind === "workstation") {
			body.append(
				el("rect", {
					x: -30,
					y: -31,
					width: 60,
					height: 40,
					rx: 4,
					fill: n.color || color,
					stroke: "#334155",
				}),
				el("rect", {
					x: -25,
					y: -26,
					width: 50,
					height: 29,
					rx: 1,
					fill: "#fff",
					"fill-opacity": 0.2,
				}),
				el("path", {
					d: "M 0 9 V 20 M -18 20 H 18",
					stroke: "#64748b",
					"stroke-width": 6,
				}),
			);
		} else {
			body.append(
				el("rect", {
					x: -w / 2,
					y: -29,
					width: w,
					height: 48,
					rx: 5,
					fill: n.color || color,
					stroke: "#334155",
					"stroke-width": 1.5,
				}),
				el("rect", {
					x: -w / 2 + 4,
					y: -25,
					width: w - 8,
					height: 15,
					rx: 2,
					fill: "#fff",
					"fill-opacity": 0.18,
				}),
			);
			if (kind === "router") {
				body.append(
					el("path", {
						d: "M -23 -4 H 23 M -23 -4 l 7 -6 M 23 -4 l -7 6 M 0 -20 V 12 M 0 -20 l -6 6 M 0 12 l 6 -6",
						stroke: "#fff",
						"stroke-width": 2,
						fill: "none",
					}),
				);
			} else {
				for (let i = 0; i < 3; i++)
					body.append(
						el("rect", {
							x: -w / 2 + 12,
							y: -5 + i * 6,
							width: w - 38,
							height: 2,
							fill: "#12352a",
							"fill-opacity": 0.45,
						}),
					);
				body.append(
					el("circle", { cx: w / 2 - 12, cy: 6, r: 3, fill: "#fff" }),
				);
			}
		}
		ps.forEach((p, i) => {
			const pt = portPoint(n, p.name),
				x = pt.x - (n.x * width()) / 100,
				y = pt.y - (n.y * height()) / 100,
				rack = kind === "switch",
				edge = data.links.find((l) => l.id === p.edge),
				active = !!edge?.current;
			const tone = portTone(p);
			const r = el("rect", {
				x: x - (rack ? 9 : 4),
				y: y - (rack ? 9 : 4),
				width: rack ? 18 : 8,
				height: rack ? 18 : 8,
				rx: rack ? 2 : 1,
				fill: rack ? tone : "#020617",
				stroke: rack ? "#0f172a" : "#fff",
				"stroke-width": rack ? 1.5 : 1,
				class: "nms-observed-port",
				tabindex: 0,
				role: "button",
				"aria-label":
					(p.available ? "Available " : "Observed ") +
					"port " +
					p.name,
			});
			hover(r, n.name, portRows(n, p));
			const inspect = (e) => {
				e.stopPropagation();
				source = n.id;
				selected = p.edge;
				showPorts(p.edge ? null : n.id, p.edge);
				message("Observed port: " + p.name);
				draw();
			};
			r.addEventListener("pointerdown", (e) => e.stopPropagation());
			r.addEventListener("click", inspect);
			r.addEventListener("keydown", (e) => {
				if (e.key === "Enter" || e.key === " ") {
					e.preventDefault();
					inspect(e);
				}
			});
			if (rack && p.edge)
				body.append(
					el("path", {
						d: `M ${x} ${y < 0 ? -70 : 70} V ${y}`,
						stroke: active ? "#3b82f6" : "#94a3b8",
						"stroke-width": 1.5,
						"pointer-events": "none",
					}),
				);
			body.append(r);
			if (rack) {
				body.append(
					el("rect", {
						x: x - 5,
						y: y - 5,
						width: 10,
						height: 10,
						fill: "url(#nms-port-bg)",
						rx: 1,
						"pointer-events": "none",
					}),
				);
				if (active)
					body.append(
						el("rect", {
							x: x - 3,
							y: y - 3,
							width: 6,
							height: 2,
							fill: "#fbbf24",
							"pointer-events": "none",
						}),
						el("rect", {
							x: x - 3,
							y: y + 1,
							width: 6,
							height: 2,
							fill: "#fbbf24",
							"pointer-events": "none",
						}),
					);
				if (ps.length <= 24 || i % 2 === 0)
					body.append(
						el(
							"text",
							{
								x,
								y: y + (y < 0 ? -14 : 18),
								"text-anchor": "middle",
								fill: "#64748b",
								"font-size": 9,
								"font-weight": 600,
								"pointer-events": "none",
							},
							p.position ?? p.name,
						),
					);
			}
		});
		g.append(
			body,
			el(
				"text",
				{
					x: 0,
					y: kind === "switch" ? 90 : 50,
					"text-anchor": "middle",
					class: "nms-device-name",
				},
				n.short_name || n.name,
			),
		);
	}
	/**
	 * Handles draw.
	 */
	function draw() {
		const core = data.nodes.find(isCore);
		if (core) {
			core.x = ((panX + width() / zoom / 2) * 100) / width();
			core.y = ((panY + height() / zoom / 2) * 100) / height();
		}
		hideTip();
		svg.replaceChildren();
		const defs = el("defs");
		[
			["nms-rack-metal", "#334155", "#0f172a"],
			["nms-port-bg", "#000000", "#1e293b"],
			["nms-rack-ears", "#cbd5e1", "#64748b"],
		].forEach(([id, a, b]) => {
			const gradient = el("linearGradient", {
				id,
				x1: "0%",
				y1: "0%",
				x2: "0%",
				y2: "100%",
			});
			gradient.append(
				el("stop", { offset: "0%", "stop-color": a }),
				el("stop", { offset: "100%", "stop-color": b }),
			);
			defs.append(gradient);
		});
		svg.append(defs);
		svg.setAttribute(
			"viewBox",
			`${panX} ${panY} ${width() / zoom} ${height() / zoom}`,
		);
		document.getElementById("nms-topology-count").textContent =
			(data.site_name || "Site") +
			" · " +
			data.nodes.length +
			" devices · " +
			data.links.length +
			" discovered links";
		const byId = new Map(data.nodes.map((n) => [n.id, n]));
		data.links.forEach((l) => {
			const a = byId.get(l.a),
				b = byId.get(l.b);
			if (!a || !b) return;
			const g = el("g", {
					"data-edge": l.id,
					opacity:
						source !== null && source !== l.a && source !== l.b
							? 0.15
							: 1,
					tabindex: 0,
					role: "button",
					"aria-label": l.label + " " + l.state,
				}),
				pa = portPoint(a, l.a_port),
				pb = portPoint(b, l.b_port),
				mid = (pa.y + pb.y) / 2,
				line = el("path", {
					d: `M ${pa.x} ${pa.y} V ${mid} H ${pb.x} V ${pb.y}`,
					fill: "none",
					class: "nms-topology-wire",
					stroke: l.inferred
						? "#b7791f"
						: l.current
							? "#3b82f6"
							: "#94a3b8",
					"stroke-width": selected === l.id ? 4 : 2,
					"stroke-dasharray": l.inferred
						? "3 5"
						: l.current
							? ""
							: "9 5",
				});
			const hit = line.cloneNode();
			hit.setAttribute("stroke", "transparent");
			hit.setAttribute("stroke-width", "14");
			hit.setAttribute("class", "nms-topology-wire-hit");
			g.append(hit, line);
			const inspect = () => {
				source = null;
				selected = l.id;
				showPorts(null, l.id);
				message(l.label + " — " + l.state);
				draw();
			};
			hover(g, "Connection", [
				["Endpoints", a.name + " ↔ " + b.name],
				["Ports", l.a_port + " ↔ " + l.b_port],
				["Bandwidth", formatBandwidth(l.speed)],
				[
					"Capacity source",
					l.speed
						? "IF MIB reported speed"
						: "No IF MIB speed reported",
				],
				["Evidence", l.label],
				["State", l.state],
				[
					"Protocol checks",
					diagnosticSummary(a) + "; " + diagnosticSummary(b),
				],
				[
					"Diagnostic scope",
					"On demand only; Ping, Traceroute and ARP check the collector path. iPerf3, Netperf and Pathchar require an authorised endpoint.",
				],
			]);
			g.addEventListener("click", inspect);
			g.addEventListener("keydown", (e) => {
				if (e.key === "Enter" || e.key === " ") {
					e.preventDefault();
					inspect();
				}
			});
			svg.append(g);
		});
		data.nodes.forEach((n) => {
			const g = el("g", {
				transform: `translate(${(n.x * width()) / 100},${(n.y * height()) / 100})`,
				"data-node": n.id,
				tabindex: 0,
				role: "button",
				"aria-label": n.name + " " + (states[n.status] || "Unknown"),
				class: "nms-canvas-node" + (isCore(n) ? " nms-core-fixed" : ""),
			});
			deviceGraphic(n, g);
			hover(g, n.name, [
				["Type", n.device_type || deviceKind(n)],
				["IP address", n.address],
				["Status", states[n.status] || "Unknown"],
				["Physical ports", n.physical_port_count || "Not reported"],
				["Port source", n.physical_port_source || "Not reported"],
				[
					"Reported interfaces",
					(n.interfaces || []).length || "Not reported",
				],
				["Connected ports", ports(n).filter((p) => p.edge).length],
				...data.links
					.filter((l) => l.a === n.id || l.b === n.id)
					.map((l) => [
						"Connection",
						(l.a === n.id ? l.a_port : l.b_port) +
							" ↔ " +
							(data.nodes.find(
								(d) => d.id === (l.a === n.id ? l.b : l.a),
							)?.name || "Unknown") +
							" · " +
							(l.a === n.id ? l.b_port : l.a_port),
					]),
			]);
			g.addEventListener("pointerdown", (e) => {
				if (!editable || isFixedSwitch(n)) return;
				e.preventDefault();
				drag = { n, startX: n.x, startY: n.y };
				svg.setPointerCapture(e.pointerId);
			});
			g.addEventListener("click", () => {
				source = n.id;
				selected = null;
				showPorts(n.id);
				draw();
			});
			g.addEventListener("keydown", async (e) => {
				if (e.key === "Enter") {
					source = n.id;
					selected = null;
					showPorts(n.id);
					draw();
					return;
				}
				if (
					![
						"ArrowLeft",
						"ArrowRight",
						"ArrowUp",
						"ArrowDown",
					].includes(e.key) ||
					!editable ||
					isCore(n)
				)
					return;
				e.preventDefault();
				const old = { x: n.x, y: n.y };
				n.x = Math.max(
					9,
					Math.min(
						91,
						n.x +
							(e.key === "ArrowLeft"
								? -1
								: e.key === "ArrowRight"
									? 1
									: 0),
					),
				);
				n.y = Math.max(
					6,
					Math.min(
						94,
						n.y +
							(e.key === "ArrowUp"
								? -1
								: e.key === "ArrowDown"
									? 1
									: 0),
					),
				);
				draw();
				await savePosition(n, old);
			});
			svg.append(g);
		});
		palette();
	}
	/**
	 * Handles point.
	 */
	function point(e) {
		const p = svg.createSVGPoint();
		p.x = e.clientX;
		p.y = e.clientY;
		return p.matrixTransform(svg.getScreenCTM().inverse());
	}
	svg.addEventListener("pointerdown", (e) => {
		if (e.target === svg) {
			panning = { x: e.clientX, y: e.clientY, px: panX, py: panY };
			svg.setPointerCapture(e.pointerId);
		}
	});
	svg.addEventListener("pointermove", (e) => {
		if (panning) {
			const scale = svg.getScreenCTM();
			panX = panning.px - (e.clientX - panning.x) / scale.a;
			panY = panning.py - (e.clientY - panning.y) / scale.d;
			draw();
			return;
		}
		if (!drag) return;
		const p = point(e);
		drag.n.x = Math.max(9, Math.min(91, (p.x * 100) / width()));
		drag.n.y = Math.max(6, Math.min(94, (p.y * 100) / height()));
		draw();
	});
	/**
	 * Updates save Position.
	 */
	async function savePosition(n, old) {
		try {
			await post("canvas_position", { host_id: n.id, x: n.x, y: n.y });
			message("Position saved.");
		} catch (e) {
			n.x = old.x;
			n.y = old.y;
			draw();
			message(e.message);
		}
	}
	svg.addEventListener("pointerup", async () => {
		panning = null;
		if (!drag) return;
		const d = drag;
		drag = null;
		await savePosition(d.n, { x: d.startX, y: d.startY });
	});
	svg.addEventListener("pointercancel", () => {
		panning = null;
		if (drag) {
			drag.n.x = drag.startX;
			drag.n.y = drag.startY;
			drag = null;
			draw();
		}
	});
	/**
	 * Handles post.
	 */
	async function post(action, fields) {
		const body = new URLSearchParams({
			...fields,
			nms_action: action,
			__csrf_magic: root.dataset.csrf,
		});
		const r = await fetch(
			`topology.php?tab=discovered&site_id=${root.dataset.site}&canvas_api=1`,
			{ method: "POST", credentials: "same-origin", body },
		);
		if (!r.ok) throw Error("Save failed; check session and permissions.");
		const out = await r.json();
		if (!out.ok) throw Error(out.error || "Save failed.");
		return out;
	}
	/**
	 * Handles palette.
	 */
	function palette() {
		const connected = new Set();
		data.links
			.filter((l) => l.current && !l.inferred)
			.forEach((l) => {
				connected.add(l.a);
				connected.add(l.b);
			});
		document.getElementById("nms-palette-title").textContent =
			"No discovered connection";
		list.replaceChildren();
		const summary = document.createElement("p");
		summary.className = "nms-connection-summary";
		summary.textContent =
			connected.size +
			" connected · " +
			(data.nodes.length - connected.size) +
			" without a current direct connection";
		list.append(summary);
		const query = document
			.getElementById("nms-device-search")
			.value.toLowerCase();
		data.nodes
			.filter((n) => !connected.has(n.id))
			.filter((n) =>
				(n.name + " " + n.address).toLowerCase().includes(query),
			)
			.forEach((n) => {
				const b = document.createElement("button");
				b.type = "button";
				b.textContent =
					n.name +
					" · " +
					(data.links.some((l) => l.a === n.id || l.b === n.id)
						? "Check connection evidence"
						: (data.observations || []).some(
									(o) => o.host_id === n.id && o.current,
							  )
							? "Neighbour observed; match unresolved"
							: "No current connection evidence");
				b.style.borderLeft =
					"4px solid " + (colors[n.status] || colors[0]);
				b.draggable = !isCore(n);
				b.onclick = () => {
					source = n.id;
					selected = null;
					zoom = Math.max(1, width() / 900);
					panX = (n.x * width()) / 100 - width() / zoom / 2;
					panY = (n.y * height()) / 100 - height() / zoom / 2;
					showPorts(n.id);
					draw();
				};
				b.addEventListener("dragstart", (e) =>
					e.dataTransfer.setData("text/plain", String(n.id)),
				);
				list.append(b);
			});
	}
	/**
	 * Handles show Ports.
	 */
	function showPorts(id, edge) {
		details.replaceChildren();
		const node = data.nodes.find((n) => n.id === id),
			title = document.createElement("h3");
		title.textContent = id
			? node?.name + " — connection evidence"
			: "Connection ports";
		details.append(title);
		const add = (text) => {
			const p = document.createElement("p");
			p.textContent = text;
			details.append(p);
		};
		(node?.discovery_warnings || []).forEach(add);
		const links = data.links.filter((l) =>
			edge ? l.id === edge : l.a === id || l.b === id,
		);
		if (!links.length)
			add(
				"No resolved connections. Observed neighbours and matching reasons are listed below.",
			);
		links.forEach((l) => {
			const a = data.nodes.find((n) => n.id === l.a),
				b = data.nodes.find((n) => n.id === l.b);
			add(
				a.name +
					" [" +
					(l.a_port || "Unknown port") +
					"] ↔ " +
					b.name +
					" [" +
					(l.b_port || "Unknown port") +
					"] — " +
					l.state,
			);
		});
		const observations = (data.observations || []).filter(
			(o) => o.host_id === id,
		);
		if (observations.length)
			add(
				"Showing " +
					Math.min(500, observations.length) +
					" of " +
					observations.length +
					" stored observations.",
			);
		observations
			.slice(0, 500)
			.forEach((o) =>
				add(
					(o.current ? "" : "Historical / stale · ") +
						(o.text || o.reason || "Observation"),
				),
			);
		if (id && !links.length && !observations.length)
			add(
				"No neighbour observations collected. Check the device discovery assignment and latest method results.",
			);
	}
	document
		.getElementById("nms-device-search")
		.addEventListener("input", palette);
	svg.addEventListener("dragover", (e) => {
		if (editable) e.preventDefault();
	});
	svg.addEventListener("drop", async (e) => {
		e.preventDefault();
		if (!editable) return;
		const n = data.nodes.find(
			(n) => String(n.id) === e.dataTransfer.getData("text/plain"),
		);
		if (!n || isCore(n)) return;
		const old = { x: n.x, y: n.y },
			p = point(e);
		n.x = Math.max(9, Math.min(91, (p.x * 100) / width()));
		n.y = Math.max(6, Math.min(94, (p.y * 100) / height()));
		source = n.id;
		draw();
		showPorts(n.id);
		await savePosition(n, old);
	});
	/**
	 * Handles refresh.
	 */
	async function refresh() {
		if (drag || panning || busy) return;
		busy = true;
		try {
			const r = await fetch(
				`topology.php?tab=discovered&site_id=${root.dataset.site}&canvas_api=1`,
				{ credentials: "same-origin", cache: "no-store" },
			);
			if (!r.ok || r.redirected) throw Error("Refresh failed");
			const next = await r.json();
			if (!Array.isArray(next.nodes) || !Array.isArray(next.links))
				throw Error("Session unavailable");
			data = next;
			draw();
			if (source !== null) showPorts(source);
			else if (selected !== null) showPorts(null, selected);
			return true;
		} catch (e) {
			message(
				"Refresh failed — displayed data may be outdated. Reload to verify your session.",
			);
			return false;
		} finally {
			busy = false;
		}
	}
	document.getElementById("nms-zoom-in").onclick = () => {
		zoom = Math.min(8, zoom * 1.25);
		draw();
	};
	document.getElementById("nms-zoom-out").onclick = () => {
		zoom = Math.max(0.5, zoom / 1.25);
		draw();
	};
	document.getElementById("nms-fit").onclick = () => {
		zoom = 1;
		panX = 0;
		panY = 0;
		draw();
	};
	draw();
	setInterval(
		() => {
			if (!document.hidden) refresh();
		},
		Math.max(10, data.refresh || 30) * 1000,
	);
})();
