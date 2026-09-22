/* Switch sections without discarding unsaved configuration or paginated inputs. */
(function ($) {
    $(function () {
        const tabs = document.getElementById('tp-discovery-tabs');
        if (!tabs || !document.getElementById('tp-discovery-save') || !document.getElementById('tp-discovery-tab')) return;
        function show(tab) {
            document.querySelectorAll('[data-discovery-panel]').forEach(panel => {panel.hidden = panel.dataset.discoveryPanel !== tab;});
            document.getElementById('tp-discovery-save').hidden = !['settings','protocols'].includes(tab);
            document.getElementById('tp-discovery-tab').value = tab;
            tabs.querySelectorAll('a').forEach(link => {
                const selected = new URL(link.href).searchParams.get('tab') === tab;
                link.classList.toggle('selected',selected);
                if(selected)link.setAttribute('aria-current','page');else link.removeAttribute('aria-current');
            });
        }
        tabs.addEventListener('click', function (event) {
            const link = event.target.closest('a');
            if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
            const url = new URL(link.href), tab = url.searchParams.get('tab');
            if (!['settings','protocols','results','profiles'].includes(tab)) return;
            event.preventDefault();event.stopImmediatePropagation();show(tab);history.pushState(null,'',url.href);
        }, true);
        window.addEventListener('popstate', function () {
            if(!document.body.contains(tabs))return;
            const tab = new URL(location.href).searchParams.get('tab') || 'settings';
            if(['settings','protocols','results','profiles'].includes(tab))show(tab);
        });
    });
})(jQuery);
