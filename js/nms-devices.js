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
