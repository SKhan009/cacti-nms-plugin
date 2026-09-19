<?php
/** Keep page-specific diagnostic selections available until the operator changes them. */
?>
<script>
(function() {
	var host = document.getElementById('nmsDiagnosticHost');
	var tool = document.getElementById('nmsDiagnosticTool');
	if (!host || !tool) return;

	function storageKey() {
		return 'nms.diagnostic.tool.' + host.value;
	}

	function applyAllowedTools(restoreSaved) {
		var selected = host.selectedOptions[0];
		var allowed = selected ? selected.dataset.tools.split(',') : [];
		Array.from(tool.options).forEach(function(option) {
			option.hidden = !allowed.includes(option.value);
			option.disabled = !allowed.includes(option.value);
		});
		var saved = restoreSaved ? window.localStorage.getItem(storageKey()) : '';
		if (saved && allowed.includes(saved)) tool.value = saved;
		if (!tool.selectedOptions[0] || tool.selectedOptions[0].disabled) tool.value = allowed[0] || '';
	}

	host.addEventListener('change', function() { applyAllowedTools(true); });
	tool.addEventListener('change', function() { window.localStorage.setItem(storageKey(), tool.value); });
	tool.form.addEventListener('submit', function() { window.localStorage.setItem(storageKey(), tool.value); });
	applyAllowedTools(false);
})();
</script>
