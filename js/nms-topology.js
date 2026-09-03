/**
 * @file nms-topology.js
 * Render the core-backed topology payload and manage selection, dragging, zooming, panning, and saved parent/interface choices.
 * Layout writes go to the CSRF-protected controller; device facts are supplied by the server.
 */
(/** Initialize the live Cacti topology view from its server-provided configuration. */ function () {
	'use strict';
	var config = window.NMS_TOPOLOGY;
	if (!config) return;

	var inventory = document.getElementById('nmsTopologyInventory');
	var canvas = document.getElementById('nmsTopologyCanvas');
	var world = document.getElementById('nmsTopologyWorld');
	var links = document.getElementById('nmsTopologyLinks');
	var detail = document.getElementById('nmsTopologyDetail');
	var search = document.getElementById('nmsTopologySearch');
	var zoomLevel = document.getElementById('nmsZoomLevel');
	var zoom = 1;
	var panX = 0;
	var panY = 0;
	var minZoom = 0.5;
	var maxZoom = 2;
	var selectedId = config.rootId;
	var deviceById = {};
	config.devices.forEach(/** Index the device by ID for selection and parent-link lookups. */ function (device) { deviceById[device.id] = device; });

	/** Escape nullable device text before inserting it into generated HTML. */
	function escapeHtml(value) {
		return String(value == null ? '' : value).replace(/[&<>'"]/g, /** Encode one special HTML character as an entity. */ function (character) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[character];
		});
	}

	/** Normalize a device status into a CSS class fragment. */
	function statusClass(status) {
		return String(status).toLowerCase().replace(/[^a-z]+/g, '-');
	}
	/** Prefer an active fault's severity class over the device status class. */
	function healthClass(device) { return device.fault_count > 0 ? device.fault_severity : statusClass(device.status); }
	/** Describe the active fault severity or the device's current core status. */
	function healthLabel(device) { return device.fault_count > 0 ? device.fault_severity + ' fault' : device.status; }
	/** Clamp zoom to the configured bounds and round to tenths. */
	function clampZoom(value) { return Math.max(minZoom, Math.min(maxZoom, Math.round(value * 10) / 10)); }

	/** Apply pan/zoom transforms and synchronize the zoom indicator and canvas state. */
	function applyView() {
		world.style.transform = 'translate(' + panX + 'px,' + panY + 'px) scale(' + zoom + ')';
		zoomLevel.value = Math.round(zoom * 100) + '%';
		zoomLevel.textContent = zoomLevel.value;
		canvas.classList.toggle('zoomed', zoom !== 1 || panX !== 0 || panY !== 0);
	}

	/** Change zoom while keeping the chosen screen anchor at the same visual point. */
	function zoomAt(nextZoom, clientX, clientY) {
		nextZoom = clampZoom(nextZoom);
		if (nextZoom === zoom) return;
		var bounds = canvas.getBoundingClientRect();
		var originX = bounds.width / 2;
		var originY = bounds.height / 2;
		var pointX = clientX == null ? originX : clientX - bounds.left;
		var pointY = clientY == null ? originY : clientY - bounds.top;
		var worldX = originX + (pointX - originX - panX) / zoom;
		var worldY = originY + (pointY - originY - panY) / zoom;
		zoom = nextZoom;
		panX = pointX - originX - zoom * (worldX - originX);
		panY = pointY - originY - zoom * (worldY - originY);
		applyView();
	}

	/** Invert the current viewport transform to obtain canvas-relative world coordinates. */
	function screenToWorld(clientX, clientY) {
		var bounds = canvas.getBoundingClientRect();
		var originX = bounds.width / 2;
		var originY = bounds.height / 2;
		return {
			x: originX + (clientX - bounds.left - originX - panX) / zoom,
			y: originY + (clientY - bounds.top - originY - panY) / zoom,
			width: bounds.width,
			height: bounds.height
		};
	}

	/** Fit mapped devices inside the canvas by computing their bounds and updating the viewport. */
	function fitToScreen() {
		var mapped = config.devices.filter(/** Include only devices currently placed on the topology map. */ function (device) { return device.mapped; });
		var bounds = canvas.getBoundingClientRect();
		if (!mapped.length || !bounds.width || !bounds.height) {
			zoom = 1; panX = 0; panY = 0; applyView(); return;
		}
		var points = mapped.map(/** Convert a device's stored or initial layout position into canvas pixels. */ function (device) {
			var point = device.x == null ? defaultPosition(device) : device;
			return { x: bounds.width * point.x / 100, y: bounds.height * point.y / 100 };
		});
		var minX = Math.min.apply(null, points.map(/** Extract horizontal coordinates for the minimum map bound. */ function (point) { return point.x; }));
		var maxX = Math.max.apply(null, points.map(/** Extract horizontal coordinates for the maximum map bound. */ function (point) { return point.x; }));
		var minY = Math.min.apply(null, points.map(/** Extract vertical coordinates for the minimum map bound. */ function (point) { return point.y; }));
		var maxY = Math.max.apply(null, points.map(/** Extract vertical coordinates for the maximum map bound. */ function (point) { return point.y; }));
		zoom = clampZoom(Math.min((bounds.width - 50) / Math.max(180, maxX - minX + 180), (bounds.height - 50) / Math.max(90, maxY - minY + 90), 1.4));
		panX = zoom * (bounds.width / 2 - (minX + maxX) / 2);
		panY = zoom * (bounds.height / 2 - (minY + maxY) / 2);
		applyView();
	}

	/** Submit a CSRF-protected topology action and reject HTTP or application-level failures. */
	function post(action, device, extra) {
		var body = new URLSearchParams();
		body.set('__csrf_magic', config.csrfToken);
		body.set('nms_action', action);
		body.set('host_id', device.id);
		Object.keys(extra || {}).forEach(/** Append each extra action field to the submitted form body. */ function (key) { body.set(key, extra[key]); });
		return fetch(config.saveUrl, { method: 'POST', mode: 'same-origin', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() })
			.then(/** Reject failed HTTP responses before decoding the topology response JSON. */ function (response) { if (!response.ok) throw new Error('Cacti rejected the topology update.'); return response.json(); })
			.then(/** Reject unsuccessful topology actions even when their HTTP request succeeded. */ function (result) { if (!result.ok) throw new Error('The device configuration is not valid for this Cacti site.'); return result; });
	}

	/** Rebuild searchable device inventory cards with selection and drag interactions. */
	function renderInventory() {
		var query = search.value.trim().toLowerCase();
		inventory.innerHTML = '';
		config.devices.filter(/** Match the inventory search against device names, addresses, categories, and templates. */ function (device) {
			return !query || [device.name, device.hostname, device.category, device.template, device.sys_name].join(' ').toLowerCase().indexOf(query) !== -1;
		}).forEach(/** Create a device inventory card using its live status and mapping state. */ function (device) {
			var card = document.createElement('button');
			card.type = 'button';
			card.className = 'nms-inventory-card' + (selectedId === device.id ? ' selected' : '');
			card.setAttribute('data-nms-tip', device.mapped ? 'Select this live Cacti device to inspect its Cacti state and recorded connections.' : 'Drag this live Cacti device onto the map, or select it to inspect its details.');
			card.draggable = !device.locked;
			card.innerHTML = '<span class="nms-device-glyph ' + healthClass(device) + '">' + (device.locked ? 'SW' : 'DV') + '</span>' +
				'<span class="nms-inventory-copy"><strong>' + escapeHtml(device.name) + '</strong><small>' + escapeHtml(device.hostname) + ' · ' + escapeHtml(device.category || 'Unmapped') + '</small></span>' +
				'<span class="nms-map-label ' + (device.mapped ? 'mapped' : '') + '">' + (device.locked ? 'Fixed' : device.mapped ? 'On map' : 'Drag') + '</span>';
			card.addEventListener('click', /** Select this inventory device and refresh the map and detail views. */ function () { selectedId = device.id; renderAll(); });
			card.addEventListener('dragstart', /** Attach the Cacti device ID to the inventory drag payload. */ function (event) { event.dataTransfer.setData('text/nms-host-id', device.id); event.dataTransfer.effectAllowed = 'move'; });
			inventory.appendChild(card);
		});
		inventory.dispatchEvent(new CustomEvent('nms:list-updated'));
	}

	/** Choose an initial visual grid position for a device without stored coordinates. */
	function defaultPosition(device) {
		var movable = config.devices.filter(/** Exclude locked devices when computing movable-device grid positions. */ function (item) { return !item.locked; });
		var index = Math.max(0, movable.findIndex(/** Locate the current device within the movable-device list. */ function (item) { return item.id === device.id; }));
		return { x: 18 + (index % 4) * 21, y: 67 + Math.floor(index / 4) * 17 };
	}

	/** Rebuild mapped device nodes and their selection and movement handlers. */
	function renderCanvas() {
		world.querySelectorAll('.nms-topology-node').forEach(/** Remove the previous node element before rebuilding the canvas. */ function (element) { element.remove(); });
		config.devices.filter(/** Include only mapped devices when rebuilding node elements. */ function (device) { return device.mapped; }).forEach(/** Render a mapped node at its stored or initial position and bind its interactions. */ function (device) {
			var fallback = defaultPosition(device);
			var x = device.x == null ? fallback.x : device.x;
			var y = device.y == null ? fallback.y : device.y;
			var node = document.createElement('button');
			node.type = 'button';
			node.className = 'nms-topology-node ' + healthClass(device) + (device.locked ? ' locked' : '') + (selectedId === device.id ? ' selected' : '');
			node.setAttribute('data-nms-tip', device.locked ? 'This is the fixed topology root. Select it to view live Cacti information.' : 'Select for details, or drag to reposition this device on the map.');
			node.style.left = x + '%'; node.style.top = y + '%';
			node.innerHTML = '<span class="nms-device-glyph ' + healthClass(device) + '">' + (device.locked ? 'SW' : 'DV') + '</span><span><strong>' + escapeHtml(device.name) + '</strong><small>' + escapeHtml(device.hostname) + '</small></span><i></i>';
			node.addEventListener('click', /** Select the clicked map device and refresh the surrounding views. */ function () { selectedId = device.id; renderAll(); });
			if (!device.locked) node.addEventListener('pointerdown', /** Start pointer-driven movement for this unlocked device. */ function (event) { startMove(event, device); });
			world.appendChild(node);
		});
		requestAnimationFrame(renderLinks);
	}

	/** Draw only explicit connection records; a layout parent or default root is not connectivity. */
	function renderLinks() {
		var bounds = canvas.getBoundingClientRect();
		links.setAttribute('viewBox', '0 0 ' + bounds.width + ' ' + bounds.height);
		links.innerHTML = '';
		(config.relationships || []).forEach(function (edge) {
			var source = deviceById[edge.source], target = deviceById[edge.target];
			if (!source || !target || !source.mapped || !target.mapped) return;
			var start = source.x == null ? defaultPosition(source) : source;
			var end = target.x == null ? defaultPosition(target) : target;
			var line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
			line.setAttribute('x1', bounds.width * start.x / 100); line.setAttribute('y1', bounds.height * start.y / 100);
			line.setAttribute('x2', bounds.width * end.x / 100); line.setAttribute('y2', bounds.height * end.y / 100);
			if (edge.identityState !== 'recorded') line.setAttribute('stroke-dasharray', '5 5');
			var title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
			title.textContent = edge.type + ' · ' + edge.provenance + ' · ' + edge.identityState + ' (not link health)';
			line.appendChild(title);
			links.appendChild(line);
		});
	}

	/** Render selected-device facts and layout controls; connections have their own editor. */
	function renderDetail() {
		var device = deviceById[selectedId];
		if (!device) return;
		detail.innerHTML = '<div class="nms-detail-title"><span class="nms-device-glyph ' + healthClass(device) + '">' + (device.locked ? 'SW' : 'DV') + '</span><div><small>CACTI DEVICE #' + device.id + '</small><h2>' + escapeHtml(device.name) + '</h2></div></div>' +
			'<span class="nms-live-status ' + healthClass(device) + '">● ' + escapeHtml(healthLabel(device)) + '</span><dl>' +
			'<div><dt>Address</dt><dd>' + escapeHtml(device.hostname) + '</dd></div><div><dt>Serial number</dt><dd>' + escapeHtml(device.serial_number || 'Not available') + (device.serial_status === 'changed' ? ' (changed)' : '') + '</dd></div><div><dt>Category</dt><dd>' + escapeHtml(device.category || 'Unmapped') + '</dd></div><div><dt>Template</dt><dd>' + escapeHtml(device.template || 'None') + '</dd></div><div><dt>Cacti status</dt><dd>' + escapeHtml(device.status) + '</dd></div><div><dt>Availability</dt><dd>' + device.availability + '%</dd></div><div><dt>Active faults</dt><dd>' + device.fault_count + '</dd></div><div><dt>SNMP</dt><dd>Version ' + device.snmp_version + '</dd></div><div><dt>Interfaces</dt><dd>' + device.interfaces + '</dd></div><div><dt>Graphs</dt><dd>' + device.graphs + '</dd></div></dl>' +
			'<p class="nms-fixed-note">Configured physical ports: ' + (device.physical_ports == null ? 'Not configured' : escapeHtml(device.physical_ports)) + '. Capacity is not a count of discovered interfaces.</p>' +
			'<p class="nms-fixed-note">Connections are managed in the Connections section below. Dragging changes layout only.</p>' +
			(device.fault_count > 0 ? '<a class="nms-detail-action" data-nms-tip="Open active faults for this device." href="' + escapeHtml(device.faults_url) + '">Open device faults</a>' : '') + '<a class="nms-detail-secondary" data-nms-tip="Open graphs for this device in the Cacti console." href="' + escapeHtml(device.graphs_url) + '">Open Cacti graphs</a><a class="nms-detail-secondary" data-nms-tip="Open the live device configuration in the Cacti console." href="' + escapeHtml(device.device_url) + '">Configure in Cacti</a>' +
			(!device.locked && device.mapped ? '<button id="nmsRemoveFromMap" class="nms-remove-map" data-nms-tip="Remove only the saved map placement. The Cacti device is not deleted." type="button">Remove from topology</button>' : '');
		var remove = document.getElementById('nmsRemoveFromMap');
		if (remove) remove.addEventListener('click', /** Request removal of this device's visual mapping and report save errors. */ function () { post('remove_device', device).then(/** Reflect successful map removal locally and select the root device. */ function () { device.mapped = false; selectedId = config.rootId; renderAll(); }).catch(showError); });
	}

	/** Persist a device's visual position, parent device, and selected parent SNMP interface. */
	function saveDevice(device) {
		return post('save_position', device, { parent_host_id: device.parent_id || config.rootId, parent_snmp_index: device.parent_snmp_index || '', x: device.x, y: device.y });
	}

	/** Select a device and track pointer movement until its final position is saved. */
	function startMove(event, device) {
		event.preventDefault(); selectedId = device.id;
		/** Convert pointer movement to bounded percentage coordinates and redraw the moved device. */
		function move(pointerEvent) {
			var point = screenToWorld(pointerEvent.clientX, pointerEvent.clientY);
			device.x = Math.max(8, Math.min(92, point.x * 100 / point.width));
			device.y = Math.max(10, Math.min(90, point.y * 100 / point.height));
			renderCanvas();
		}
		/** Detach the movement listener and save the final device position. */
		function stop() { window.removeEventListener('pointermove', move); saveDevice(device).catch(showError); }
		window.addEventListener('pointermove', move); window.addEventListener('pointerup', stop, { once: true });
	}

	/** Display a topology save failure to the operator. */
	function showError(error) { window.alert(error.message || 'Unable to save topology configuration.'); }
	/** Refresh inventory, map nodes, and details from the shared device state. */
	function renderAll() { renderInventory(); renderCanvas(); renderDetail(); }

	canvas.addEventListener('dragover', /** Allow an inventory drop and highlight the canvas as the drag destination. */ function (event) { event.preventDefault(); event.dataTransfer.dropEffect = 'move'; canvas.classList.add('drag-active'); });
	canvas.addEventListener('dragleave', /** Clear the canvas drag highlight when the pointer leaves. */ function () { canvas.classList.remove('drag-active'); });
	canvas.addEventListener('drop', /** Place an eligible dropped device at bounded canvas coordinates and save its mapping. */ function (event) {
		event.preventDefault(); canvas.classList.remove('drag-active');
		var device = deviceById[Number(event.dataTransfer.getData('text/nms-host-id'))];
		if (!device || device.locked) return;
		var point = screenToWorld(event.clientX, event.clientY);
		device.x = Math.max(8, Math.min(92, point.x * 100 / point.width));
		device.y = Math.max(10, Math.min(90, point.y * 100 / point.height));
		device.parent_id = device.parent_id || config.rootId; device.mapped = true; selectedId = device.id;
		saveDevice(device).then(renderAll).catch(showError);
	});
	search.addEventListener('input', renderInventory);
	document.getElementById('nmsZoomOut').addEventListener('click', /** Decrease map zoom by one tenth around the default anchor. */ function () { zoomAt(zoom - 0.1); });
	document.getElementById('nmsZoomIn').addEventListener('click', /** Increase map zoom by one tenth around the default anchor. */ function () { zoomAt(zoom + 0.1); });
	document.getElementById('nmsZoomFit').addEventListener('click', fitToScreen);
	canvas.addEventListener('wheel', /** Translate wheel direction into zoom centered on the pointer. */ function (event) {
		event.preventDefault();
		zoomAt(zoom + (event.deltaY < 0 ? 0.1 : -0.1), event.clientX, event.clientY);
	}, { passive: false });
	canvas.addEventListener('pointerdown', /** Start canvas panning for a primary-button drag outside device nodes. */ function (event) {
		if (event.button !== 0 || event.target.closest('.nms-topology-node')) return;
		var startX = event.clientX;
		var startY = event.clientY;
		var initialPanX = panX;
		var initialPanY = panY;
		canvas.classList.add('panning');
		/** Update pan offsets from the pointer's displacement since the drag began. */
		function pan(pointerEvent) {
			panX = initialPanX + pointerEvent.clientX - startX;
			panY = initialPanY + pointerEvent.clientY - startY;
			applyView();
		}
		/** End canvas panning and detach its movement listener. */
		function stopPan() {
			canvas.classList.remove('panning');
			window.removeEventListener('pointermove', pan);
		}
		window.addEventListener('pointermove', pan);
		window.addEventListener('pointerup', stopPan, { once: true });
	});
	document.getElementById('nmsRefreshTopology').addEventListener('click', /** Reload server-provided topology and device readings. */ function () { window.location.reload(); });
	window.addEventListener('resize', /** Redraw links and reapply the viewport after resizing. */ function () { renderLinks(); applyView(); });
	renderAll(); applyView();
})();
