<?php
/** Keep page-specific diagnostic selections available until the operator changes them. */
?>
<script>
(function() {
    // Chrome toolbar reload and keyboard reload both report navigation type "reload".
    var navigation = performance.getEntriesByType('navigation')[0];
    if (navigation && navigation.type === 'reload') {
        history.scrollRestoration = 'manual';
        var refreshedUrl = new URL(location.href);
        refreshedUrl.hash = '';
        // Keep an active request visible; completed results remain available in history.
        if (!document.getElementById('nms-diagnostic-status')) {
            refreshedUrl.searchParams.delete('job_id');
        }
        refreshedUrl.searchParams.delete('profile_new');
        refreshedUrl.searchParams.delete('profile_id');
        if (refreshedUrl.href !== location.href) {
            location.replace(refreshedUrl.href);
            return;
        }
        function resetReloadView() {
            window.scrollTo({top: 0, left: 0, behavior: 'instant'});
        }
        resetReloadView();
        window.addEventListener('pageshow', function() {
            resetReloadView();
            requestAnimationFrame(resetReloadView);
        }, { once: true });
    }

	// Opening a profile is a one-time action; refresh should show the list.
	var profileDialog = document.getElementById('nmsConfigDialog');
	if (profileDialog && profileDialog.dataset.autoOpen === 'true') {
		var pageUrl = new URL(window.location.href);
		if (pageUrl.searchParams.has('profile_new') || pageUrl.searchParams.has('profile_id')) {
			pageUrl.searchParams.delete('profile_new');
			pageUrl.searchParams.delete('profile_id');
			window.history.replaceState(window.history.state, '', pageUrl.href);
		}
	}
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
		var saved = '';
        try { saved = restoreSaved ? window.localStorage.getItem(storageKey()) : ''; } catch (error) {}
		if (saved && allowed.includes(saved)) tool.value = saved;
		if (!tool.selectedOptions[0] || tool.selectedOptions[0].disabled) tool.value = allowed[0] || '';
	}

	function updateReadiness() {
        var selected = host.selectedOptions[0];
        var source = document.getElementById('nms-diagnostic-runners');
        var runners = source ? JSON.parse(source.textContent) : {};
        var runner = selected ? runners[selected.dataset.collector] : null;
        document.querySelectorAll('[data-diagnostic-tool]').forEach(function(card) {
            var installed = runner && runner.tools[card.dataset.diagnosticTool];
            card.classList.toggle('ready', !!installed);
            card.classList.toggle('unavailable', !installed);
            card.querySelector('[data-diagnostic-availability]').textContent = !runner ? 'Collector runner unavailable' : installed ? 'Installed on collector' : 'Not installed on collector';
        });
    }
    host.addEventListener('change', function() { applyAllowedTools(true); updateReadiness(); });
	tool.addEventListener('change', function() { try { window.localStorage.setItem(storageKey(), tool.value); } catch (error) {} });
	tool.form.addEventListener('submit', function() {
        try { window.localStorage.setItem(storageKey(), tool.value); } catch (error) {}
        var button = tool.form.querySelector('button[type="submit"]');
        button.disabled = true; button.textContent = 'Starting test…';
    });
	applyAllowedTools(false);
    updateReadiness();
    var progress = document.getElementById('nms-diagnostic-status');
    if (progress) {
        var started = Date.now();
        async function updateProgress() {
            try {
                var response = await fetch('diagnostics.php?job_status=1&job_id=' + encodeURIComponent(progress.dataset.jobId), {credentials:'same-origin', cache:'no-store'});
                if (!response.ok || response.redirected) throw new Error('Status unavailable');
                var state = await response.json();
                if (state.finished) { window.location.reload(); return; }
                progress.querySelector('[data-diagnostic-progress-title]').textContent = state.status === 'running' ? 'Test running…' : 'Starting test…';
                progress.querySelector('[data-diagnostic-progress-message]').textContent = state.status === 'running' ? 'The collector is running your test. Results will appear automatically.' : 'Contacting the collector runner…';
                if (Date.now() - started < 125000) { setTimeout(updateProgress, 1000); return; }
            } catch (error) {}
            progress.querySelector('[data-diagnostic-progress-message]').textContent = 'Automatic status updates stopped. Use Refresh result to check this test; it will not run again.';
        }
        updateProgress();
    }
})();
</script>
