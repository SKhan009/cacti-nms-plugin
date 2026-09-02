(function () {
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

	function setFieldState(input, visible, required) {
		if (!input) return;
		var field = input.closest('.nms-conditional-field');
		if (field) field.hidden = !visible;
		input.disabled = !visible;
		input.required = visible && Boolean(required);
	}

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

(function () {
	'use strict';

	function bindSelectSearch(searchId, selectId) {
		var search = document.getElementById(searchId);
		var select = document.getElementById(selectId);
		if (!search || !select) return;
		var options = Array.prototype.slice.call(select.options, 1);
		search.addEventListener('input', function () {
			var term = search.value.toLowerCase().trim();
			options.forEach(function (option) {
				option.hidden = term !== '' && option.text.toLowerCase().indexOf(term) === -1;
			});
			if (select.selectedIndex > 0 && select.options[select.selectedIndex].hidden) select.value = '';
		});
	}

	bindSelectSearch('nmsDataTemplateSearch', 'nmsDataTemplateSelect');
	bindSelectSearch('nmsGraphTemplateSearch', 'nmsGraphTemplateSelect');
	bindSelectSearch('nmsDataQuerySearch', 'nmsDataQuerySelect');

	var color = document.getElementById('nmsGraphColor');
	var swatch = document.getElementById('nmsGraphColorSwatch');
	if (color && swatch) {
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
