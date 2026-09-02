(function () {
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
	config.devices.forEach(function (device) { deviceById[device.id] = device; });

	function escapeHtml(value) {
		return String(value == null ? '' : value).replace(/[&<>'"]/g, function (character) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[character];
		});
	}

	function statusClass(status) {
		return String(status).toLowerCase().replace(/[^a-z]+/g, '-');
	}
	function healthClass(device) { return device.fault_count > 0 ? device.fault_severity : statusClass(device.status); }
	function healthLabel(device) { return device.fault_count > 0 ? device.fault_severity + ' fault' : device.status; }
	function clampZoom(value) { return Math.max(minZoom, Math.min(maxZoom, Math.round(value * 10) / 10)); }

	function applyView() {
		world.style.transform = 'translate(' + panX + 'px,' + panY + 'px) scale(' + zoom + ')';
		zoomLevel.value = Math.round(zoom * 100) + '%';
		zoomLevel.textContent = zoomLevel.value;
		canvas.classList.toggle('zoomed', zoom !== 1 || panX !== 0 || panY !== 0);
	}

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

	function fitToScreen() {
		var mapped = config.devices.filter(function (device) { return device.mapped; });
		var bounds = canvas.getBoundingClientRect();
		if (!mapped.length || !bounds.width || !bounds.height) {
			zoom = 1; panX = 0; panY = 0; applyView(); return;
		}
		var points = mapped.map(function (device) {
			var point = device.x == null ? defaultPosition(device) : device;
			return { x: bounds.width * point.x / 100, y: bounds.height * point.y / 100 };
		});
		var minX = Math.min.apply(null, points.map(function (point) { return point.x; }));
		var maxX = Math.max.apply(null, points.map(function (point) { return point.x; }));
		var minY = Math.min.apply(null, points.map(function (point) { return point.y; }));
		var maxY = Math.max.apply(null, points.map(function (point) { return point.y; }));
		zoom = clampZoom(Math.min((bounds.width - 50) / Math.max(180, maxX - minX + 180), (bounds.height - 50) / Math.max(90, maxY - minY + 90), 1.4));
		panX = zoom * (bounds.width / 2 - (minX + maxX) / 2);
		panY = zoom * (bounds.height / 2 - (minY + maxY) / 2);
		applyView();
	}

	function post(action, device, extra) {
		var body = new URLSearchParams();
		body.set('__csrf_magic', config.csrfToken);
		body.set('nms_action', action);
		body.set('host_id', device.id);
		Object.keys(extra || {}).forEach(function (key) { body.set(key, extra[key]); });
		return fetch(config.saveUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() })
			.then(function (response) { if (!response.ok) throw new Error('Cacti rejected the topology update.'); return response.json(); })
			.then(function (result) { if (!result.ok) throw new Error('The device configuration is not valid for this Cacti site.'); return result; });
	}

	function renderInventory() {
		var query = search.value.trim().toLowerCase();
		inventory.innerHTML = '';
		config.devices.filter(function (device) {
			return !query || [device.name, device.hostname, device.category, device.template, device.sys_name].join(' ').toLowerCase().indexOf(query) !== -1;
		}).forEach(function (device) {
			var card = document.createElement('button');
			card.type = 'button';
			card.className = 'nms-inventory-card' + (selectedId === device.id ? ' selected' : '');
			card.setAttribute('data-nms-tip', device.mapped ? 'Select this live Cacti device to inspect or update its topology connection.' : 'Drag this live Cacti device onto the map, or select it to inspect its details.');
			card.draggable = !device.locked;
			card.innerHTML = '<span class="nms-device-glyph ' + healthClass(device) + '">' + (device.locked ? 'SW' : 'DV') + '</span>' +
				'<span class="nms-inventory-copy"><strong>' + escapeHtml(device.name) + '</strong><small>' + escapeHtml(device.hostname) + ' · ' + escapeHtml(device.category || 'Unmapped') + '</small></span>' +
				'<span class="nms-map-label ' + (device.mapped ? 'mapped' : '') + '">' + (device.locked ? 'Fixed' : device.mapped ? 'On map' : 'Drag') + '</span>';
			card.addEventListener('click', function () { selectedId = device.id; renderAll(); });
			card.addEventListener('dragstart', function (event) { event.dataTransfer.setData('text/nms-host-id', device.id); event.dataTransfer.effectAllowed = 'move'; });
			inventory.appendChild(card);
		});
		inventory.dispatchEvent(new CustomEvent('nms:list-updated'));
	}

	function defaultPosition(device) {
		var movable = config.devices.filter(function (item) { return !item.locked; });
		var index = Math.max(0, movable.findIndex(function (item) { return item.id === device.id; }));
		return { x: 18 + (index % 4) * 21, y: 67 + Math.floor(index / 4) * 17 };
	}

	function renderCanvas() {
		world.querySelectorAll('.nms-topology-node').forEach(function (element) { element.remove(); });
		config.devices.filter(function (device) { return device.mapped; }).forEach(function (device) {
			var fallback = defaultPosition(device);
			var x = device.x == null ? fallback.x : device.x;
			var y = device.y == null ? fallback.y : device.y;
			var node = document.createElement('button');
			node.type = 'button';
			node.className = 'nms-topology-node ' + healthClass(device) + (device.locked ? ' locked' : '') + (selectedId === device.id ? ' selected' : '');
			node.setAttribute('data-nms-tip', device.locked ? 'This is the fixed topology root. Select it to view live Cacti information.' : 'Select for details, or drag to reposition this device on the map.');
			node.style.left = x + '%'; node.style.top = y + '%';
			node.innerHTML = '<span class="nms-device-glyph ' + healthClass(device) + '">' + (device.locked ? 'SW' : 'DV') + '</span><span><strong>' + escapeHtml(device.name) + '</strong><small>' + escapeHtml(device.hostname) + '</small></span><i></i>';
			node.addEventListener('click', function () { selectedId = device.id; renderAll(); });
			if (!device.locked) node.addEventListener('pointerdown', function (event) { startMove(event, device); });
			world.appendChild(node);
		});
		requestAnimationFrame(renderLinks);
	}

	function renderLinks() {
		var bounds = canvas.getBoundingClientRect();
		links.setAttribute('viewBox', '0 0 ' + bounds.width + ' ' + bounds.height);
		links.innerHTML = '';
		config.devices.filter(function (device) { return device.mapped && !device.locked; }).forEach(function (device) {
			var parent = deviceById[device.parent_id] || deviceById[config.rootId];
			if (!parent || !parent.mapped) return;
			var childPoint = device.x == null ? defaultPosition(device) : device;
			var parentPoint = parent.x == null ? defaultPosition(parent) : parent;
			var line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
			line.setAttribute('x1', bounds.width * parentPoint.x / 100); line.setAttribute('y1', bounds.height * parentPoint.y / 100);
			line.setAttribute('x2', bounds.width * childPoint.x / 100); line.setAttribute('y2', bounds.height * childPoint.y / 100);
			links.appendChild(line);
		});
	}

	function renderDetail() {
		var device = deviceById[selectedId];
		if (!device) return;
		var parentOptions = config.devices.filter(function (item) { return item.mapped && item.id !== device.id; }).map(function (item) {
			var chosen = (device.parent_id || config.rootId) === item.id ? ' selected' : '';
			return '<option value="' + item.id + '"' + chosen + '>' + escapeHtml(item.name) + '</option>';
		}).join('');
		var parentDevice = deviceById[device.parent_id || config.rootId];
		var portOptions = parentDevice ? parentDevice.interface_options.map(function (item) {
			return '<option value="' + escapeHtml(item.index) + '"' + (device.parent_snmp_index === item.index ? ' selected' : '') + '>' + escapeHtml(item.label) + '</option>';
		}).join('') : '';
		detail.innerHTML = '<div class="nms-detail-title"><span class="nms-device-glyph ' + healthClass(device) + '">' + (device.locked ? 'SW' : 'DV') + '</span><div><small>CACTI DEVICE #' + device.id + '</small><h2>' + escapeHtml(device.name) + '</h2></div></div>' +
			'<span class="nms-live-status ' + healthClass(device) + '">● ' + escapeHtml(healthLabel(device)) + '</span><dl>' +
			'<div><dt>Address</dt><dd>' + escapeHtml(device.hostname) + '</dd></div><div><dt>Category</dt><dd>' + escapeHtml(device.category || 'Unmapped') + '</dd></div><div><dt>Template</dt><dd>' + escapeHtml(device.template || 'None') + '</dd></div><div><dt>Cacti status</dt><dd>' + escapeHtml(device.status) + '</dd></div><div><dt>Availability</dt><dd>' + device.availability + '%</dd></div><div><dt>Active faults</dt><dd>' + device.fault_count + '</dd></div><div><dt>SNMP</dt><dd>Version ' + device.snmp_version + '</dd></div><div><dt>Interfaces</dt><dd>' + device.interfaces + '</dd></div><div><dt>Graphs</dt><dd>' + device.graphs + '</dd></div></dl>' +
			(device.locked ? '<p class="nms-fixed-note">This root device is fixed.</p>' : '<label class="nms-parent-select" data-nms-tip="The mapped Cacti device this node connects to. Changing it redraws and saves the link.">Connected to device<select id="nmsParentDevice">' + parentOptions + '</select></label><label class="nms-parent-select" data-nms-tip="Optional SNMP interface on the parent device used for this topology connection.">Parent switch port<select id="nmsParentPort"><option value="">Not configured</option>' + portOptions + '</select></label>') +
			(device.fault_count > 0 ? '<a class="nms-detail-action" data-nms-tip="Open active faults for this device." href="' + escapeHtml(device.faults_url) + '">Open device faults</a>' : '') + '<a class="nms-detail-secondary" data-nms-tip="Open graphs for this device in the Cacti console." href="' + escapeHtml(device.graphs_url) + '">Open Cacti graphs</a><a class="nms-detail-secondary" data-nms-tip="Open the live device configuration in the Cacti console." href="' + escapeHtml(device.device_url) + '">Configure in Cacti</a>' +
			(!device.locked && device.mapped ? '<button id="nmsRemoveFromMap" class="nms-remove-map" data-nms-tip="Remove only the saved map placement. The Cacti device is not deleted." type="button">Remove from topology</button>' : '');
		var parentSelect = document.getElementById('nmsParentDevice');
		if (parentSelect) parentSelect.addEventListener('change', function () { device.parent_id = Number(parentSelect.value); device.parent_snmp_index = ''; renderDetail(); saveDevice(device).catch(showError); });
		var parentPort = document.getElementById('nmsParentPort');
		if (parentPort) parentPort.addEventListener('change', function () { device.parent_snmp_index = parentPort.value; saveDevice(device).catch(showError); });
		var remove = document.getElementById('nmsRemoveFromMap');
		if (remove) remove.addEventListener('click', function () { post('remove_device', device).then(function () { device.mapped = false; selectedId = config.rootId; renderAll(); }).catch(showError); });
	}

	function saveDevice(device) {
		return post('save_position', device, { parent_host_id: device.parent_id || config.rootId, parent_snmp_index: device.parent_snmp_index || '', x: device.x, y: device.y });
	}

	function startMove(event, device) {
		event.preventDefault(); selectedId = device.id;
		function move(pointerEvent) {
			var point = screenToWorld(pointerEvent.clientX, pointerEvent.clientY);
			device.x = Math.max(8, Math.min(92, point.x * 100 / point.width));
			device.y = Math.max(10, Math.min(90, point.y * 100 / point.height));
			renderCanvas();
		}
		function stop() { window.removeEventListener('pointermove', move); saveDevice(device).catch(showError); }
		window.addEventListener('pointermove', move); window.addEventListener('pointerup', stop, { once: true });
	}

	function showError(error) { window.alert(error.message || 'Unable to save topology configuration.'); }
	function renderAll() { renderInventory(); renderCanvas(); renderDetail(); }

	canvas.addEventListener('dragover', function (event) { event.preventDefault(); event.dataTransfer.dropEffect = 'move'; canvas.classList.add('drag-active'); });
	canvas.addEventListener('dragleave', function () { canvas.classList.remove('drag-active'); });
	canvas.addEventListener('drop', function (event) {
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
	document.getElementById('nmsZoomOut').addEventListener('click', function () { zoomAt(zoom - 0.1); });
	document.getElementById('nmsZoomIn').addEventListener('click', function () { zoomAt(zoom + 0.1); });
	document.getElementById('nmsZoomFit').addEventListener('click', fitToScreen);
	canvas.addEventListener('wheel', function (event) {
		event.preventDefault();
		zoomAt(zoom + (event.deltaY < 0 ? 0.1 : -0.1), event.clientX, event.clientY);
	}, { passive: false });
	canvas.addEventListener('pointerdown', function (event) {
		if (event.button !== 0 || event.target.closest('.nms-topology-node')) return;
		var startX = event.clientX;
		var startY = event.clientY;
		var initialPanX = panX;
		var initialPanY = panY;
		canvas.classList.add('panning');
		function pan(pointerEvent) {
			panX = initialPanX + pointerEvent.clientX - startX;
			panY = initialPanY + pointerEvent.clientY - startY;
			applyView();
		}
		function stopPan() {
			canvas.classList.remove('panning');
			window.removeEventListener('pointermove', pan);
		}
		window.addEventListener('pointermove', pan);
		window.addEventListener('pointerup', stopPan, { once: true });
	});
	document.getElementById('nmsRefreshTopology').addEventListener('click', function () { window.location.reload(); });
	window.addEventListener('resize', function () { renderLinks(); applyView(); });
	renderAll(); applyView();
})();
