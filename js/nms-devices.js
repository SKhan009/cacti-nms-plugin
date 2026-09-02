/**
 * @file nms-devices.js
 * Enhance device and graph forms with protocol-dependent fields, searchable selectors, color previews, and scale help.
 */
(/** Initialize protocol-dependent fields on the device form when its SNMP selector exists. */ function () {
	'use strict';

	var version = document.getElementById('nmsSnmpVersion');
	if (!version) return;

	var community = document.getElementById('nmsSnmpCommunity');
	var username = document.getElementById('nmsSnmpUsername');
	var authProtocol = document.getElementById('nmsSnmpAuthProtocol');
	var authPassword = document.getElementById('nmsSnmpPassword');
	var privacyProtocol = document.getElementById('nmsSnmpPrivProtocol');
	var privacyPassphrase = document.getElementById('nmsSnmpPrivPassphrase');
	var context = document.getElementById('nmsSnmpContext');
	var engineId = document.getElementById('nmsSnmpEngineId');
	var modeNote = document.getElementById('nmsSnmpModeNote');

	/** Synchronize a conditional field's visibility, enabled state, and required flag. */
	function setFieldState(input, visible, required) {
		if (!input) return;
		var field = input.closest('.nms-conditional-field');
		if (field) field.hidden = !visible;
		input.disabled = !visible;
		input.required = visible && Boolean(required);
	}

	/** Show and require credentials appropriate to the selected SNMP version and security protocols. */
	function updateSnmpFields() {
		var isV3 = version.value === '3';
		var authenticationEnabled = isV3 && authProtocol.value !== '[None]';

		if (!authenticationEnabled && privacyProtocol) privacyProtocol.value = '[None]';
		var privacyEnabled = authenticationEnabled && privacyProtocol.value !== '[None]';

		setFieldState(community, !isV3, !isV3);
		setFieldState(username, isV3, isV3);
		setFieldState(authProtocol, isV3, false);
		setFieldState(authPassword, authenticationEnabled, authenticationEnabled);
		setFieldState(privacyProtocol, authenticationEnabled, false);
		setFieldState(privacyPassphrase, privacyEnabled, privacyEnabled);
		setFieldState(context, isV3, false);
		setFieldState(engineId, isV3, false);

		if (!modeNote) return;
		if (!isV3) {
			modeNote.textContent = 'Version ' + version.value + ' uses a community string.';
		} else if (!authenticationEnabled) {
			modeNote.textContent = 'SNMP v3 username only (no authentication or encryption).';
		} else if (!privacyEnabled) {
			modeNote.textContent = 'SNMP v3 authentication enabled without encryption.';
		} else {
			modeNote.textContent = 'SNMP v3 authentication and encryption enabled.';
		}
	}

	version.addEventListener('change', updateSnmpFields);
	authProtocol.addEventListener('change', updateSnmpFields);
	privacyProtocol.addEventListener('change', updateSnmpFields);
	updateSnmpFields();
})();

(/** Initialize searchable selectors and graph-style form controls on the current page. */ function () {
	'use strict';

	/** Enhance a native select with searchable choices while preserving its submitted value and change events. */
	function upgradeSearchSelect(select) {
		if (!select || select.dataset.searchReady === 'true') return;
		select.dataset.searchReady = 'true';
		var wasRequired = select.required;
		select.required = false;
		select.classList.add('nms-search-select-source');

		var wrapper = document.createElement('span');
		wrapper.className = 'nms-search-select-control';
		var trigger = document.createElement('button');
		trigger.type = 'button';
		trigger.className = 'nms-search-select-trigger';
		trigger.disabled = select.disabled;
		trigger.setAttribute('aria-haspopup', 'listbox');
		trigger.setAttribute('aria-expanded', 'false');
		var panel = document.createElement('span');
		panel.className = 'nms-search-select-panel';
		panel.hidden = true;
		var search = document.createElement('input');
		search.type = 'search';
		search.autocomplete = 'off';
		search.placeholder = select.dataset.searchPlaceholder || 'Search options';
		search.setAttribute('aria-label', search.placeholder);
		search.setAttribute('data-nms-no-tooltip', '');
		var list = document.createElement('span');
		list.className = 'nms-search-select-options';
		list.setAttribute('role', 'listbox');
		var empty = document.createElement('span');
		empty.className = 'nms-search-select-empty';
		empty.textContent = 'No matching options';
		empty.hidden = true;

		/** Copy the selected option's text into the searchable selector trigger. */
		function syncLabel() {
			var option = select.options[select.selectedIndex] || select.options[0];
			trigger.textContent = option ? option.text : 'Select an option';
		}
		/** Close the options panel and update its accessibility state. */
		function close() {
			panel.hidden = true;
			trigger.setAttribute('aria-expanded', 'false');
		}

		Array.prototype.slice.call(select.options, 1).forEach(/** Create a selectable button for each native option. */ function (option) {
			var choice = document.createElement('button');
			choice.type = 'button';
			choice.className = 'nms-search-select-option';
			choice.textContent = option.text;
			choice.dataset.value = option.value;
			choice.setAttribute('role', 'option');
			choice.addEventListener('click', /** Commit the chosen native value, notify listeners, and return focus to the trigger. */ function () {
				select.value = option.value;
				select.dispatchEvent(new Event('change', { bubbles: true }));
				syncLabel();
				trigger.classList.remove('invalid');
				close();
				trigger.focus();
			});
			list.appendChild(choice);
		});

		trigger.addEventListener('click', /** Toggle this options panel after closing other open searchable panels. */ function () {
			var opening = panel.hidden;
			document.querySelectorAll('.nms-search-select-panel:not([hidden])').forEach(/** Hide another open options panel. */ function (other) { other.hidden = true; });
			panel.hidden = !opening;
			trigger.setAttribute('aria-expanded', opening ? 'true' : 'false');
			if (opening) { search.value = ''; search.dispatchEvent(new Event('input')); search.focus(); }
		});
		search.addEventListener('input', /** Filter searchable choices and update the no-results indicator. */ function () {
			var normalize = /** Normalize case, separators, and whitespace for forgiving option searches. */ function (value) { return value.toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim(); };
			var term = normalize(search.value);
			var shown = 0;
			list.querySelectorAll('.nms-search-select-option').forEach(/** Hide choices that do not match the normalized search and count visible results. */ function (choice) {
				choice.hidden = term !== '' && normalize(choice.textContent).indexOf(term) === -1;
				if (!choice.hidden) shown++;
			});
			empty.hidden = shown !== 0;
		});
		wrapper.addEventListener('keydown', /** Close the options panel on Escape and restore trigger focus. */ function (event) { if (event.key === 'Escape') { close(); trigger.focus(); } });
		document.addEventListener('click', /** Close the selector when a click occurs outside its wrapper. */ function (event) { if (!wrapper.contains(event.target)) close(); });
		if (wasRequired && select.form) select.form.addEventListener('submit', /** Prevent submission of an empty required selector and focus its invalid trigger. */ function (event) {
			if (select.value === '') { event.preventDefault(); trigger.classList.add('invalid'); trigger.focus(); }
		});

		select.parentNode.insertBefore(wrapper, select);
		wrapper.appendChild(select);
		wrapper.appendChild(trigger);
		panel.appendChild(search);
		panel.appendChild(list);
		panel.appendChild(empty);
		wrapper.appendChild(panel);
		syncLabel();
	}

	document.querySelectorAll('select.nms-search-select').forEach(upgradeSearchSelect);

	var color = document.getElementById('nmsGraphColor');
	var swatch = document.getElementById('nmsGraphColorSwatch');
	if (color && swatch) {
		/** Preview the selected graph color only when its value is valid six-digit hexadecimal. */
		function updateColorSwatch() {
			var option = color.options[color.selectedIndex];
			var hex = option ? option.getAttribute('data-hex') : '';
			if (hex && /^[0-9a-f]{6}$/i.test(hex)) swatch.style.backgroundColor = '#' + hex;
		}
		color.addEventListener('change', updateColorSwatch);
		updateColorSwatch();
	}

	var scaleMethod = document.getElementById('nmsAutoScaleMethod');
	var lowerLimit = document.getElementById('nmsLowerLimit');
	var upperLimit = document.getElementById('nmsUpperLimit');
	var scaleHelp = document.getElementById('nmsScaleHelp');
	if (scaleMethod && lowerLimit && upperLimit) {
		/** Show the limits and help text appropriate to Cacti's selected autoscale method. */
		function updateScaleInputs() {
			var method = scaleMethod.value;
			lowerLimit.hidden = method === '1' || method === '3';
			upperLimit.hidden = method === '1' || method === '2';
			if (!scaleHelp) return;
			if (method === '1') scaleHelp.textContent = 'Cacti calculates both limits from collected values.';
			else if (method === '2') scaleHelp.textContent = 'Enter the lower limit; Cacti calculates the upper limit.';
			else if (method === '3') scaleHelp.textContent = 'Enter the upper limit; Cacti calculates the lower limit.';
			else scaleHelp.textContent = 'Enter both lower and upper limits.';
		}
		scaleMethod.addEventListener('change', updateScaleInputs);
		updateScaleInputs();
	}
})();
