<?php
/** Keep page-specific tool-selection behavior outside the page markup. */
?>
<script>
(function() {
	var host = document.getElementById('nmsDiagnosticHost');
	var tool = document.getElementById('nmsDiagnosticTool');
	if (!host || !tool) return;

	/**
	 * Handles apply Allowed Tools.
	 */
	function applyAllowedTools() {
		var selected = host.selectedOptions[0];
		var allowed = selected ? selected.dataset.tools.split(',') : [];

		Array.from(tool.options).forEach(function(option) {
			option.hidden = !allowed.includes(option.value);
			option.disabled = !allowed.includes(option.value);
		});

		if (tool.selectedOptions[0] && tool.selectedOptions[0].disabled) {
			tool.value = allowed[0] || '';
		}
	}

	host.addEventListener('change', applyAllowedTools);
	applyAllowedTools();
})();
</script>
