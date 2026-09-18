(function () {
	"use strict";
	const root = document.getElementById("nms-ssh-console");
	if (!root) return;
	const hint = root.querySelector("[data-workspace-status]");
	if (!window.Guacamole) {
		hint.textContent = "Apache Guacamole client assets are missing.";
		return;
	}
	let csrf = root.dataset.csrf,
		sequence = 0,
		active = null;
	const devices = JSON.parse(
			root.querySelector("[data-devices]").textContent,
		),
		sessions = new Map();
	const target = root.querySelector("[data-target]"),
		add = root.querySelector("[data-new-terminal]");
	/**
	 * Handles api.
	 */
	async function api(action, extra = {}) {
		const controller = new AbortController(),
			timeout = setTimeout(() => controller.abort(), 35000);
		try {
			const r = await fetch(root.dataset.api, {
				method: "POST",
				credentials: "same-origin",
				cache: "no-store",
				redirect: "error",
				signal: controller.signal,
				headers: {
					"Content-Type": "application/x-www-form-urlencoded",
				},
				body: new URLSearchParams({
					action,
					__csrf_magic: csrf,
					...extra,
				}),
			});
			const m = await r.json();
			if (!r.ok || !m.ok)
				throw new Error(m.error || "Cacti authorization failed.");
			if (m.csrf) csrf = m.csrf;
			return m.data;
		} finally {
			clearTimeout(timeout);
		}
	}
	// Explicit HTTPS transport. No WebSocket or alternate transport is attempted.
	/**
	 * Handles tunnel For.
	 */
	function tunnelFor(device, area, status) {
		const tunnel = new Guacamole.Tunnel();
		let sid = null,
			closed = false,
			writing = false,
			pending = "",
			renew = null;
		const parser = new Guacamole.Parser();
		parser.oninstruction = (op, args) => {
			if (tunnel.oninstruction) tunnel.oninstruction(op, args);
		};
		/**
		 * Handles finish.
		 */
		function finish(message) {
			if (closed) return;
			closed = true;
			if (renew) clearInterval(renew);
			tunnel.setState(Guacamole.Tunnel.State.CLOSED);
			status.textContent = message;
			if (sid) api("disconnect", { session_id: sid }).catch(() => {});
		}
		tunnel.disconnect = () => finish("Disconnected");
		tunnel.connect = async () => {
			tunnel.setState(Guacamole.Tunnel.State.CONNECTING);
			try {
				const r = await api("connect", {
					host_id: device.id,
					width: Math.max(100, Math.min(4096, area.clientWidth)),
					height: Math.max(100, Math.min(2160, area.clientHeight)),
				});
				sid = r.id;
				if (closed) {
					await api("disconnect", { session_id: sid });
					return;
				}
				tunnel.setUUID(sid);
				tunnel.setState(Guacamole.Tunnel.State.OPEN);
				renew = setInterval(
					() =>
						api("renew", { session_id: sid }).catch((e) =>
							finish(e.message),
						),
					8000,
				);
				while (!closed) {
					const r = await api("read", { session_id: sid });
					if (closed) break;
					for (const m of r.messages) {
						if (m.type === "guac")
							parser.receive(
								new TextDecoder("utf-8", {
									fatal: true,
								}).decode(
									Uint8Array.from(atob(m.data), (c) =>
										c.charCodeAt(0),
									),
								),
							);
						else if (m.type === "error") throw new Error(m.error);
					}
				}
			} catch (e) {
				finish(e.message || "Guacamole tunnel failed.");
			}
		};
		tunnel.sendMessage = function () {
			if (closed || !sid) return;
			const instruction =
				[...arguments]
					.map((x) => {
						const s = String(x);
						return [...s].length + "." + s;
					})
					.join(",") + ";";
			pending += instruction;
			if (new TextEncoder().encode(pending).length > 32768) {
				finish("Terminal input limit exceeded.");
				return;
			}
			if (writing) return;
			writing = true;
			(async () => {
				try {
					while (pending && !closed) {
						const raw = pending;
						pending = "";
						const bytes = new TextEncoder().encode(raw);
						await api("write", {
							session_id: sid,
							data: btoa(String.fromCharCode(...bytes)),
						});
					}
				} catch (e) {
					finish(e.message);
				} finally {
					writing = false;
				}
			})();
		};
		return tunnel;
	}
	/**
	 * Handles select.
	 */
	function select(id) {
		active = id;
		sessions.forEach((s) => {
			s.panel.hidden = s.id !== id;
			s.tab.setAttribute("aria-selected", String(s.id === id));
			s.tab.tabIndex = s.id === id ? 0 : -1;
		});
		const s = sessions.get(id);
		if (s) {
			s.resize();
			s.area.focus();
		}
	}
	/**
	 * Handles create.
	 */
	function create() {
		if (sessions.size >= 2) return;
		const device = devices.find((d) => String(d.id) === target.value);
		if (!device) return;
		const id = ++sequence,
			panel = root
				.querySelector("[data-session-template]")
				.content.firstElementChild.cloneNode(true),
			group = document.createElement("div"),
			tab = document.createElement("button"),
			close = document.createElement("button");
		group.className = "ssh-tab-group";
		tab.type = close.type = "button";
		tab.textContent = device.name + " · " + id;
		tab.setAttribute("role", "tab");
		tab.id = "guac-tab-" + id;
		panel.id = "guac-panel-" + id;
		tab.setAttribute("aria-controls", panel.id);
		panel.setAttribute("aria-labelledby", tab.id);
		close.textContent = "×";
		close.className = "ssh-tab-close";
		close.setAttribute("aria-label", "Close terminal for " + device.name);
		group.append(tab, close);
		root.querySelector("[data-tabs]").append(group);
		root.querySelector("[data-panels]").append(panel);
		panel.querySelector("[data-identity]").textContent =
			device.name + " · " + device.endpoint;
		panel.querySelector("[data-connection-info]").textContent =
			device.preset + " · " + device.username + " · Apache Guacamole";
		panel.querySelector("[data-settings-link]").href =
			root.dataset.settings + "?id=" + device.id;
		const area = panel.querySelector("[data-terminal]"),
			status = panel.querySelector("[data-status]"),
			connect = panel.querySelector("[data-connect]"),
			disconnect = panel.querySelector("[data-disconnect]");
		area.tabIndex = 0;
		area.style.overflow = "hidden";
		let client = null,
			tunnel = null;
		const keyboard = new Guacamole.Keyboard(area);
		keyboard.onkeydown = (k) => {
			if (client) {
				client.sendKeyEvent(1, k);
				return false;
			}
		};
		keyboard.onkeyup = (k) => {
			if (client) client.sendKeyEvent(0, k);
		};
		area.addEventListener("blur", () => keyboard.reset());
		const mouse = new Guacamole.Mouse(area);
		mouse.onmousedown =
			mouse.onmouseup =
			mouse.onmousemove =
				(s) => {
					if (client) client.sendMouseState(s);
				};
		area.addEventListener("mousedown", () => area.focus());
		/**
		 * Handles resize.
		 */
		function resize() {
			if (client && !panel.hidden)
				client.sendSize(
					Math.max(100, Math.min(4096, area.clientWidth)),
					Math.max(100, Math.min(2160, area.clientHeight)),
				);
		}
		/**
		 * Handles end.
		 */
		function end() {
			keyboard.reset();
			if (client) client.disconnect();
			client = null;
			tunnel = null;
			connect.disabled = !device.trusted;
			disconnect.disabled = true;
		}
		connect.disabled = !device.trusted;
		connect.addEventListener("click", () => {
			if (client) return;
			connect.disabled = true;
			disconnect.disabled = false;
			area.replaceChildren();
			status.textContent = "Connecting through Apache Guacamole…";
			tunnel = tunnelFor(device, area, status);
			client = new Guacamole.Client(tunnel);
			area.append(client.getDisplay().getElement());
			client.onerror = () => {
				status.textContent = "Guacamole connection failed.";
				end();
			};
			tunnel.onstatechange = (s) => {
				if (s === Guacamole.Tunnel.State.OPEN) {
					status.textContent = "Connected · Apache Guacamole";
					area.focus();
				}
				if (s === Guacamole.Tunnel.State.CLOSED) {
					client = null;
					connect.disabled = !device.trusted;
					disconnect.disabled = true;
				}
			};
			client.connect();
		});
		disconnect.addEventListener("click", end);
		const observer = new ResizeObserver(resize);
		observer.observe(area);
		const session = {
			id,
			panel,
			tab,
			area,
			resize,
			dispose: () => {
				end();
				observer.disconnect();
				group.remove();
				panel.remove();
				sessions.delete(id);
				add.disabled = false;
				if (active === id) select(sessions.keys().next().value);
			},
		};
		sessions.set(id, session);
		tab.addEventListener("click", () => select(id));
		close.addEventListener("click", session.dispose);
		add.disabled = sessions.size >= 2;
		select(id);
	}
	add.addEventListener("click", create);
	if (devices.length) create();
	window.addEventListener("pagehide", () =>
		[...sessions.values()].forEach((s) => s.dispose()),
	);
})();
