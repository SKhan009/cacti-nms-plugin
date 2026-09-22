/* Native Cacti navigation markup; retain editable rows when switching pages. */
(function ($) {
    $(function () {
        document.querySelectorAll('.tp-paged-list:not([data-pager-ready])').forEach(function (root) {
            root.dataset.pagerReady = '1';
            const table = root.querySelector('table.cactiTable');
            const source = root.querySelector('.tp-native-pages');
            if (!table || !source) return;
            const navs = JSON.parse(source.textContent), total = Number(root.dataset.total);
            const rows = total ? Array.from(table.querySelectorAll('tbody > tr')).slice(-total) : [];
            const bars = root.querySelectorAll('.tp-native-nav'), select = root.querySelector('.tp-page-size');
            let page = 1, size = 25;
            function render() {
                page = Math.min(page, Math.max(1, Math.ceil(total / size)));
                rows.forEach((row, i) => { row.hidden = i < (page - 1) * size || i >= page * size; row.style.display = row.hidden ? 'none' : ''; });
                bars.forEach(bar => { bar.innerHTML = navs[size][page]; });
            }
            // Capture native navigation before Cacti's AJAX handler so unsaved inputs stay intact.
            root.addEventListener('click', function (event) {
                const link = event.target.closest('.tp-native-nav a[data-url]');
                if (!link || !root.contains(link)) return;
                event.preventDefault();event.stopImmediatePropagation();
                const target = Number(new URL(link.dataset.url, location.href).searchParams.get('tp_page'));
                if (Number.isInteger(target) && navs[size][target]) { page = target;render(); }
            }, true);
            if (select) {
                function resize() { size = Number(select.value);page = 1;render(); }
                select.addEventListener('change', resize);$(select).on('selectmenuchange', resize);
            }
            render();
        });
    });
})(jQuery);
