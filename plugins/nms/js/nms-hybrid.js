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
	let editable = false, savingPosition = false, autoArranging = false;
	const undoMoves = [], redoMoves = [];
	let editBaseline = new Map(), exitPending = false;
	const canEdit = root.dataset.edit === "1",
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
	function message(text, error = false) {
		const failed = error || /fail|error|unavailable/i.test(text);
		const successful = text === "Layout changes saved.";
		notice.textContent = "";
		notice.hidden = true;
		if (!failed && !successful) return;
		const label = successful ? "Changes saved successfully." : text;
		if (window.nmsNotify) window.nmsNotify(label, failed);
		else { notice.textContent = label; notice.hidden = false; }
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
		return Math.max(1200, switchNodes().length * 600, Math.ceil(Math.sqrt(data.nodes.length)) * 300);
	}
	function height() {
		return Math.max(900, Math.ceil(Math.sqrt(data.nodes.length)) * 200);
	}
	// Coordinates may extend beyond the initial view; retain only the database's numeric range.
	function coordinate(value) { return Math.max(-9999, Math.min(9999, value)); }
	function fitDevices() {
		if (!data.nodes.length) return;
		const left = Math.min(...data.nodes.map(n => n.x * width()/100 - chassisWidth(n)/2 - 60));
		const right = Math.max(...data.nodes.map(n => n.x * width()/100 + chassisWidth(n)/2 + 60));
		const top = Math.min(...data.nodes.map(n => n.y * height()/100 - 100));
		const bottom = Math.max(...data.nodes.map(n => n.y * height()/100 + 120 + Math.ceil(ports(n).length/12)*12));
		const spanX = right - left, spanY = bottom - top;
		zoom = Math.min(4, width() / spanX, height() / spanY);
		panX = left - (width() / zoom - spanX) / 2;
		panY = top - (height() / zoom - spanY) / 2;
		draw();
	}

	/**
	 * Handles ports.
	 */
	function ports(n) {
		const found = new Map();
		(n.interfaces || []).forEach((p) => {
			const name = p.name || "ifIndex " + p.index;
			found.set(name, { ...p, name, edge: null });
		});
		(n.ports || []).forEach((p) => {
			if (p.name) found.set(p.name, { ...(found.get(p.name) || {}), ...p, edge: null });
		});
		data.links.forEach((l) => {
			const name = l.a === n.id ? l.a_port : l.b === n.id ? l.b_port : null;
            if (l.manual && !found.has(name)) return;
			if (name && name !== "Unknown port")
				found.set(name, { ...(found.get(name) || {}), name, edge: l.id });
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
	 * Handles chassis Width.
	 */
	function chassisWidth(n) {
		return deviceKind(n) === "switch"
			? 480
			: Math.max(70, Math.min(12, ports(n).length) * 12 + 24);
	}
	/**
	 * Returns the compact, readable topology label supplied by the server.
	 */
	function deviceLabel(n) {
		return String(n.short_name || n.name || "DEVICE")
			.trim()
			.slice(0, 8);
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
			gap = Math.floor(col / 4) * 8;
		return { x: -160 + col * 12 + gap, y: isTop ? -10 : 10 };
	}
	/**
	 * Handles port Point.
	 */
	function portPoint(n, name) {
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
			if (editable) return;
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
			...(p.availability ? [["Port state", p.availability]] : []),
			...(!links.length && !p.availability
				? [["Connection", "No discovered neighbour"]]
				: []),
		];
	}
	/**
	 * Handles port Tone.
	 */
	function portTone(p) {
		const edge = p.edge && data.links.find((l) => l.id === p.edge);
		if (!edge) {
			if (/unavailable|disabled/i.test(p.availability || "")) return "#64748b";
			if (/link down/i.test(p.availability || "")) return "#ef4444";
			if (/link up/i.test(p.availability || "")) return "#f59e0b";
			return "#334155";
		}
		if (edge.manual) return "#64748b";
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
		if (kind !== "switch")
			body.append(
				el("rect", {
					x: -w / 2 - 14,
					y: -48,
					width: w + 28,
					height: 112 + Math.ceil(ps.length / 12) * 12,
					rx: 12,
					fill: "none",
					stroke: source === n.id ? "#2563eb" : color,
					"stroke-width": source === n.id ? 2 : 1.5,
				}),
			);
		if (kind === "switch") {
			const rackHeight =
				90 + Math.max(0, Math.ceil(ps.length / 24) - 2) * 28;
			body.append(
				el("rect", {
					x: -240,
					y: -45,
					width: 480,
					height: rackHeight,
					rx: 6,
					fill: "url(#nms-rack-metal)",
					stroke: source === n.id ? "#2563eb" : color,
					"stroke-width": 3,
					class: "nms-reference-rack",
				}),
			);
			if (n.color)
				body.append(
				el("rect", {
					x: -236,
					y: -41,
					width: 472,
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
						x: side < 0 ? -255 : 240,
						y: -37,
						width: 15,
						height: rackHeight - 16,
						rx: 2,
						fill: "url(#nms-rack-ears)",
					}),
				);
				[-25, 0, 25].forEach((y) =>
					body.append(
						el("circle", {
							cx: side * 247,
							cy: y / 1.45,
							r: 3,
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
						x: -220,
						y: -14,
						fill: "#f8fafc",
						"font-size": 14,
						"font-weight": 800,
						"letter-spacing": 1,
					},
					deviceLabel(n),
				),
				el(
					"text",
					{
						x: -220,
						y: 1,
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
					x: 150,
					y: -31,
					width: 65,
					height: 24,
					fill: "#020617",
					rx: 4,
					stroke: "#334155",
				}),
				el("circle", {
					cx: 163,
					cy: -19,
					r: 4,
					fill: color,
					class: n.status === 3 ? "nms-rack-led" : "",
				}),
				el(
					"text",
					{
						x: 175,
						y: -16,
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
				x: x - (rack ? 6 : 4),
				y: y - (rack ? 6 : 4),
				width: rack ? 12 : 8,
				height: rack ? 12 : 8,
				rx: rack ? 1.5 : 1,
				fill: rack ? tone : "#020617",
				stroke: rack ? "#0f172a" : "#fff",
				"stroke-width": rack ? 1 : 1,
				class: "nms-observed-port",
				tabindex: 0,
				role: "button",
				"aria-label":
					"Port " + p.name + ": " + (p.availability || (p.available ? "Available socket" : "Observed by SNMP")),
			});
			hover(r, n.name, portRows(n, p));
			const inspect = (e) => {
				e.stopPropagation();
				source = n.id;
				selected = p.edge;
				showPorts(p.edge ? null : n.id, p.edge, p.edge ? null : {name: p.name, index: p.index});
				message("Observed port: " + p.name);
				draw();
			};
			r.addEventListener("pointerdown", (e) => { if (!editable) e.stopPropagation(); });
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
						d: `M ${x} ${y < 0 ? -45 : 45} V ${y}`,
						stroke: active ? "#3b82f6" : "#94a3b8",
						"stroke-width": 1.5,
						"pointer-events": "none",
					}),
				);
			body.append(r);
			if (rack) {
				body.append(
					el("rect", {
						x: x - 4,
						y: y - 4,
						width: 8,
						height: 8,
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
					y: kind === "switch" ? 64 : 52,
					"text-anchor": "middle",
					class: "nms-device-name",
				},
				deviceLabel(n),
			),
		);
	}
	/**
	 * Handles draw.
	 */
	function draw() {
		const switches = switchNodes();
		switches.forEach((n, i) => { n.x = 50 + (i - (switches.length - 1) / 2) * 620 / width() * 100; n.y = 50; });
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
			" connections";
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
				pa = l.manual && !l.a_ifindex ? {x:a.x * width()/100,y:a.y * height()/100} : portPoint(a, l.a_port),
				pb = l.manual && !l.b_ifindex ? {x:b.x * width()/100,y:b.y * height()/100} : portPoint(b, l.b_port),
				mid = (pa.y + pb.y) / 2,
				line = el("path", {
					d: `M ${pa.x} ${pa.y} V ${mid} H ${pb.x} V ${pb.y}`,
					fill: "none",
					class: "nms-topology-wire",
					stroke: l.manual ? l.color : l.inferred
						? "#b7791f"
						: l.current
							? "#3b82f6"
							: "#94a3b8",
					"stroke-width": selected === l.id ? 4 : 2,
					"stroke-dasharray": l.manual ? (l.dash || "") : l.inferred
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
            if (l.manual && l.symbol !== "none") {
                [pa,pb].forEach(p => {
                    const shape = l.symbol === "circle" ? el("circle", {cx:p.x,cy:p.y,r:5}) :
                        l.symbol === "square" ? el("rect", {x:p.x-5,y:p.y-5,width:10,height:10}) :
                        el("path", {d:`M ${p.x-5} ${p.y-6} L ${p.x+5} ${p.y} L ${p.x-5} ${p.y+6} Z`});
                    shape.setAttribute("fill", l.color); g.append(shape);
                });
            }
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
					l.manual ? "Manually configured capacity" : l.speed
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
				class: "nms-canvas-node" + (editable && deviceKind(n) !== "switch" ? "" : " nms-node-readonly"),
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
				e.stopPropagation();
                if (!editable || savingPosition || autoArranging || deviceKind(n) === "switch" || e.button !== 0 || drag || panning) return;
				e.preventDefault();
				const p = point(e);
                drag = { n, startX: n.x, startY: n.y, offsetX:p.x-n.x*width()/100, offsetY:p.y-n.y*height()/100 };
                svg.classList.add("nms-dragging");
				svg.setPointerCapture(e.pointerId);
			});
			g.addEventListener("click", () => {
				source = n.id;
				selected = null;
				showPorts(n.id);
				draw();
			});
			g.addEventListener("keydown", async (e) => {
				if (e.key === "Enter" || e.key === " ") {
                    e.preventDefault();
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
					!editable || savingPosition || autoArranging || deviceKind(n) === "switch"
				)
					return;
				e.preventDefault();
				const old = { x: n.x, y: n.y };
				n.x = coordinate(n.x + (e.key === "ArrowLeft" ? -1 : e.key === "ArrowRight" ? 1 : 0));
				n.y = coordinate(n.y + (e.key === "ArrowUp" ? -1 : e.key === "ArrowDown" ? 1 : 0));
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
		if (e.target === svg && e.button === 0 && !drag && !panning) {
            e.preventDefault(); svg.classList.add("nms-panning");
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
		drag.n.x = coordinate(((p.x - drag.offsetX) * 100) / width());
		drag.n.y = coordinate(((p.y - drag.offsetY) * 100) / height());
		draw();
	});
	/**
	 * Updates save Position.
	 */
	async function savePosition(n, old, record = true) {
		if (record) {
			undoMoves.push({id:n.id, before:{...old}, after:{x:n.x,y:n.y}});
			redoMoves.length = 0;
		}
		message("Unsaved layout changes."); updateMoveButtons();
		return true;
	}
	function changedNodes() {
		return data.nodes.filter(n => {
			const old = editBaseline.get(n.id);
			return deviceKind(n) !== "switch" && old && (old.x !== n.x || old.y !== n.y);
		});
	}
	async function finishEditing() {
		if (exitPending || savingPosition || autoArranging || drag || panning) return;
		exitPending = true;
		try {
			const changes = changedNodes();
			if (changes.length) {
				const choice = await askLayoutSave();
				if (choice === "cancel") return;
				if (choice === "save") {
					savingPosition = true;
					try {
						for (const n of changes) {
							await post("canvas_position", {host_id:n.id,x:n.x,y:n.y});
							editBaseline.set(n.id,{x:n.x,y:n.y});
						}
					} catch (e) { message("Could not save all positions. Your remaining changes are kept for retry. " + e.message,true); return; }
					finally { savingPosition = false; updateMoveButtons(); }
					message("Layout changes saved.");
				} else {
					for (const n of changes) Object.assign(n,editBaseline.get(n.id));
					message("Layout changes discarded.");
				}
			}
			setEditMode(false); await leaveFullscreen();
		} finally { exitPending = false; }
	}
	function askLayoutSave() {
		return new Promise(resolve => {
			const dialog = document.createElement("dialog");
			dialog.className = "nms-layout-save-dialog";
			dialog.setAttribute("aria-label", "Save layout changes?");
			const heading = document.createElement("h2"); heading.textContent = "Save layout changes?";
			const text = document.createElement("p"); text.textContent = "Your device positions have not been saved.";
			const actions = document.createElement("div"); actions.className = "nms-layout-save-actions";
			const done = choice => { dialog.close(); dialog.remove(); resolve(choice); };
			for (const [label,choice] of [["Save changes","save"],["Discard changes","discard"],["Keep editing","cancel"]]) {
				const button = document.createElement("button"); button.type = "button"; button.textContent = label;
				button.onclick = () => done(choice); actions.append(button);
			}
			dialog.append(heading,text,actions); root.append(dialog);
			dialog.addEventListener("cancel", e => { e.preventDefault(); done("cancel"); });
			dialog.showModal();
		});
	}
	window.addEventListener("beforeunload", e => {
		if (editable && changedNodes().length) { e.preventDefault(); e.returnValue = ""; }
	});

	svg.addEventListener("pointerup", async (e) => {
        svg.classList.remove("nms-dragging", "nms-panning");
        if (svg.hasPointerCapture(e.pointerId)) svg.releasePointerCapture(e.pointerId);
		panning = null;
		if (!drag) return;
		const d = drag;
		drag = null;
		if (d.n.x !== d.startX || d.n.y !== d.startY) await savePosition(d.n, { x: d.startX, y: d.startY });
	});
	svg.addEventListener("pointercancel", () => {
        svg.classList.remove("nms-dragging", "nms-panning");
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
				b.draggable = editable && deviceKind(n) !== "switch";
				b.onclick = () => {
					source = n.id;
					selected = null;
					showPorts(n.id);
					draw();
				};
				b.addEventListener("dragstart", (e) =>
					e.dataTransfer.setData("text/plain", String(n.id)),
				);
				list.append(b);
			});
	}
	let detailSelection = null;
	const missing = "Not collected";
	function metricRows(parent, rows) {
		const dl = document.createElement("dl");
		dl.className = "nms-detail-metrics";
		rows.forEach(([label, value]) => {
			const dt = document.createElement("dt"), dd = document.createElement("dd");
			dt.textContent = label;
			dd.textContent = value === null || value === undefined || value === "" ? missing : String(value);
			dl.append(dt, dd);
		});
		parent.append(dl);
	}
	function trafficText(value, speed) {
		if (value === null || value === undefined || !Number.isFinite(Number(value))) return missing;
		const rate = Number(value), units = ["bps", "Kbps", "Mbps", "Gbps", "Tbps"];
		let amount = rate, unit = 0;
		while (amount >= 1000 && unit < units.length - 1) { amount /= 1000; unit++; }
		return amount.toLocaleString(undefined, { maximumFractionDigits: 3 }) + " " + units[unit]
			+ (speed > 0 ? " (" + (rate * 100 / speed).toFixed(2) + "%)" : " (capacity unknown)");
	}
	function closeDetails() {
		const previous = detailSelection;
		details.hidden = true;
		detailSelection = null;
		source = selected = null;
		draw();
		const targets = svg.querySelectorAll(previous?.edge ? "[data-edge]" : "[data-node]");
		[...targets].find((element) => previous?.edge
			? element.dataset.edge === String(previous.edge)
			: element.dataset.node === String(previous?.id))?.focus();
	}
	function showPorts(id, edge, port = null, restoreFocus = false) {
		if (editable) return;
		const hadFocus = details.contains(document.activeElement);
        const evidenceOpen = restoreFocus && details.querySelector("details")?.open;
        detailSelection = { id, edge, port };
		details.replaceChildren();
		details.hidden = false;
		const node = data.nodes.find((n) => n.id === id), link = data.links.find((l) => l.id === edge);
		const heading = document.createElement("header"), title = document.createElement("h3"), close = document.createElement("button");
		title.id = "nms-detail-title";
		title.textContent = link ? "Network link" : port ? (node?.name || "Device") + " · " + port.name : node?.name || "Selection unavailable";
		close.type = "button"; close.textContent = "×"; close.setAttribute("aria-label", "Close details"); close.onclick = closeDetails;
		heading.append(title, close); details.append(heading);
		const add = (text, className = "") => {
			const p = document.createElement("p"); p.textContent = text; p.className = className; details.append(p);
		};
		const interfaceCard = (device, name, index, fallback = null) => {
			const section = document.createElement("section"), h = document.createElement("h4");
			section.className = "nms-detail-interface";
			const candidates = link?.manual && !index ? [] : (device?.interfaces || []).filter((p) => index ? Number(p.index) === Number(index) : p.name === name);
			const p = candidates.length === 1 ? candidates[0] : fallback || {};
			h.textContent = (device?.name || "Unknown device") + " · " + (p.name || name || "Unknown interface");
			section.append(h);
            if (link?.manual && !candidates.length) {
                const status = document.createElement("p"); status.textContent = "Not monitored — no matching interface readings.";
                section.append(status); details.append(section); return;
            }
			const speed = p.high_speed_mbps > 0 ? p.high_speed_mbps * 1000000 : p.speed_bps || 0;
			metricRows(section, [
				["Device name", device?.name], ["Interface", p.name || name],
				["Admin status", {1:"Up",2:"Down",3:"Testing"}[p.admin]],
				["Oper status", {1:"Up",2:"Down",3:"Testing",4:"Unknown",5:"Dormant",6:"Not present",7:"Lower layer down"}[p.oper]],
				["Speed", speed ? formatBandwidth(speed) : missing],
				["In traffic", trafficText(p.in_bps, speed)], ["Out traffic", trafficText(p.out_bps, speed)],
				["Description", p.description], ["Alias", p.alias],
				["Observed", p.observed_at ? new Date(p.observed_at * 1000).toLocaleString() : null],
				["Traffic interval", p.sample_seconds ? p.sample_seconds + " seconds (average)" : "Requires two valid counter samples"],
			]);
			const note = document.createElement("p"); note.textContent = "In and out are relative to this device interface."; section.append(note);
			details.append(section);
		};
		if (link) {
			add((link.manual ? "Manual connection" : link.current ? "Current" : "Historical / stale") + " · " + (link.state || "Unknown"), "nms-detail-subtitle");
			add(link.label || "Connection");
            if (link.detected_type) add("Interface type: " + link.detected_type);
            if (link.manual) {
                add("Configured capacity: " + (link.speed ? formatBandwidth(link.speed) : "Unknown") + ". Interface readings below are measured separately.");
                if (editable) {
                    const edit = document.createElement("a"); edit.className = "nms-catalog-button";
                    edit.href = "topology.php?tab=connections&edit=" + link.manual_id + "#connection-form";
                    edit.textContent = "Edit / delete connection"; details.append(edit);
                }
            }
			["a", "b"].forEach((side) => interfaceCard(data.nodes.find((n) => n.id === link[side]), link[side + "_port"], link[side + "_ifindex"]));
		} else if (node && port) {
			interfaceCard(node, port.name, port.index);
		} else if (node) {
			add((states[node.status] || "Unknown") + " · " + node.address + " · " + (node.device_type || node.template || "Unknown model"), "nms-detail-subtitle");
			metricRows(details, [
				["Availability", states[node.status] || "Unknown"], ["Category", node.category],
				["Device type", node.device_type || node.template], ["Serial number", node.identity?.serial],
				["Response time", node.response_ms == null ? null : node.response_ms + " ms"],
				["Packet loss", node.packet_loss == null ? null : node.packet_loss + "%"],
				["Poll availability", node.poll_availability == null ? null : node.poll_availability.toFixed(2) + "% (lifetime)"],
				["Last polled", node.last_polled],
			]);
			const alarm = document.createElement("section"), alarmTitle = document.createElement("h4");
			alarm.className = "nms-detail-alarm"; alarmTitle.textContent = "Recent active alarm"; alarm.append(alarmTitle);
			const a = node.recent_alarm;
			metricRows(alarm, a ? [["Title", a.title], ["Severity", a.severity], ["Status", a.status], ["Message", a.message], ["Last seen", a.last_seen]] : [["Status", "No active NMS alarms"]]);
			details.append(alarm);
			(node.discovery_warnings || []).forEach((warning) => add(warning));
			const evidence = document.createElement("details"), summary = document.createElement("summary");
			summary.textContent = "Connections and discovery evidence"; evidence.append(summary);
            evidence.open = Boolean(evidenceOpen);
			const connections = data.links.filter((l) => l.a === id || l.b === id);
			connections.forEach((l) => {
				const button = document.createElement("button"); button.type = "button";
				button.textContent = l.label + " · " + l.state;
				button.onclick = () => { source = null; selected = l.id; showPorts(null, l.id); draw(); }; evidence.append(button);
			});
			(data.observations || []).filter((o) => o.host_id === id).slice(0, 500).forEach((o) => {
				const p = document.createElement("p"); p.textContent = (o.current ? "" : "Historical / stale · ") + (o.text || o.reason || "Observation"); evidence.append(p);
			});
			if (!connections.length) { const p = document.createElement("p"); p.textContent = "No resolved connections."; evidence.append(p); }
			details.append(evidence);
		} else {
			add("This device or link is no longer present in the latest topology. Select another item.");
		}
		if (!restoreFocus) details.scrollTop = 0;
        if (!restoreFocus || hadFocus) close.focus({preventScroll: true});
	}
	details.addEventListener("keydown", (e) => { if (e.key === "Escape") { e.preventDefault(); closeDetails(); } });
	root.addEventListener("keydown", (e) => { if (e.key === "Escape" && !details.hidden) { e.preventDefault(); closeDetails(); } });

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
		if (!n || !editable || savingPosition || autoArranging || deviceKind(n) === "switch") return;
		const old = { x: n.x, y: n.y },
			p = point(e);
		n.x = coordinate((p.x * 100) / width());
		n.y = coordinate((p.y * 100) / height());
		source = n.id;
		draw();
		showPorts(n.id);
		await savePosition(n, old);
	});
	/**
	 * Handles refresh.
	 */
	async function refresh() {
		if (editable || drag || panning || busy || savingPosition || autoArranging) return;
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
			if (detailSelection && !details.hidden) {
                const {id, edge, port} = detailSelection;
                const scroll = details.scrollTop;
                showPorts(id, edge, port, true);
                details.scrollTop = scroll;
            }
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
		zoom = Math.max(0.01, zoom / 1.25);
		draw();
	};
	document.getElementById("nms-fit").onclick = fitDevices;
	const arrangeButton = document.getElementById("nms-auto-arrange");
	if (arrangeButton) arrangeButton.onclick = async () => {
		if (!editable || savingPosition || autoArranging || drag || panning) return;
		autoArranging = true; arrangeButton.disabled = true;
		const nodes = data.nodes.filter(n => deviceKind(n) !== "switch");
		const columns = Math.max(1, Math.ceil(Math.sqrt(nodes.length)));
		const columnGap = Math.max(300, ...nodes.map(n => chassisWidth(n) + 120));
		const rowGap = Math.max(240, ...nodes.map(n => 180 + Math.ceil(ports(n).length / 12) * 12));
		let failures = 0;
		try {
			for (let i = 0; i < nodes.length; i++) {
				const n = nodes[i], old = {x:n.x,y:n.y};
				const row = Math.floor(i / columns), col = i % columns;
				n.x = 50 + (col - (Math.min(columns,nodes.length-row*columns)-1)/2) * columnGap / width() * 100;
				n.y = 50 + (row % 2 ? 1 : -1) * (280 + Math.floor(row/2)*rowGap) / height() * 100;
				if (!await savePosition(n,old)) failures++;
			}
		} finally { autoArranging = false; arrangeButton.disabled = false; updateMoveButtons(); fitDevices(); }
		message(failures ? "Some positions could not be saved. Please retry Auto arrange." : "Auto arranged. Changes are unsaved.", failures > 0);
	};
	const editButton = document.getElementById("nms-edit-mode");
	function setEditMode(active) {
		if (active && !editable) { editBaseline = new Map(data.nodes.map(n => [n.id,{x:n.x,y:n.y}])); undoMoves.length = redoMoves.length = 0; }
		editable = active && canEdit;
		root.classList.toggle("nms-editing", editable);
		hideTip(); details.hidden = true; detailSelection = null; source = selected = null;
		if (editButton) {
			editButton.textContent = editable ? "Done editing" : "Edit mode";
			editButton.setAttribute("aria-pressed", String(editable));
		}
		updateMoveButtons(); draw();
	}
	async function enterFullscreen() {
		try { await root.requestFullscreen(); }
		catch (e) { root.classList.add("nms-fullscreen-fallback"); }
		syncFullscreen();
	}
	async function leaveFullscreen() {
		if (document.fullscreenElement === root) await document.exitFullscreen();
		root.classList.remove("nms-fullscreen-fallback");
		syncFullscreen();
	}
	if (editButton && canEdit) editButton.onclick = async () => {
		if (drag || panning || savingPosition) return;
		if (editable) { await finishEditing(); }
		else { setEditMode(true); await enterFullscreen(); }
	};

	document.getElementById("nms-map-refresh").onclick = refresh;
	function updateMoveButtons() {
		for (const [id, stack] of [["nms-undo",undoMoves],["nms-redo",redoMoves]]) {
			if (document.getElementById(id)) document.getElementById(id).disabled = !editable || savingPosition || autoArranging || !stack.length;
		}
	}
	async function replayMove(from, to) {
		if (!editable || savingPosition || autoArranging || drag || panning || !from.length) return;
		const move = from[from.length - 1], n = data.nodes.find(n => n.id === move.id);
		if (!n || deviceKind(n) === "switch") { from.pop(); updateMoveButtons(); return; }
		const old = {x:n.x,y:n.y}, target = from === undoMoves ? move.before : move.after;
		Object.assign(n,target); draw();
		if (await savePosition(n,old,false)) { from.pop(); to.push(move); }
		updateMoveButtons();
	}
	if (canEdit) document.getElementById("nms-undo").onclick = () => replayMove(undoMoves,redoMoves);
	if (canEdit) document.getElementById("nms-redo").onclick = () => replayMove(redoMoves,undoMoves);
	const fullButton = document.getElementById("nms-fullscreen");
	function syncFullscreen() {
		const active = document.fullscreenElement === root || root.classList.contains("nms-fullscreen-fallback");
		if (!active && editable) finishEditing();
		updateMoveButtons();
		fullButton.textContent = active ? "Exit full screen" : "Full screen";
		fullButton.setAttribute("aria-pressed", String(active));
	}
	fullButton.onclick = async () => {
		if (drag || panning || savingPosition) return;
		if (editable) await finishEditing();
		else if (document.fullscreenElement === root || root.classList.contains("nms-fullscreen-fallback")) await leaveFullscreen();
		else { setEditMode(false); await enterFullscreen(); }
	};
	document.addEventListener("fullscreenchange", syncFullscreen);
	document.addEventListener("keydown", (e) => {
		if (e.key === "Escape") { root.classList.remove("nms-fullscreen-fallback"); syncFullscreen(); }
	});

	updateMoveButtons();
	draw();
	setInterval(
		() => {
			if (!document.hidden) refresh();
		},
		Math.max(10, data.refresh || 30) * 1000,
	);
})();
