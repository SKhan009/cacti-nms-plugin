/** Explicit node membership: filtering never selects or moves a device automatically. */
(function () {
    'use strict';
    var editor = document.querySelector('[data-node-editor]');
    if (editor) {
        var site = editor.querySelector('[data-node-site]'), search = editor.querySelector('[data-node-search]');
        function filterMembers(siteChanged) {
            var count = 0;
            editor.querySelectorAll('[data-node-member]').forEach(function (row) {
                var box = row.querySelector('input'), sameSite = row.dataset.site === site.value;
                if (siteChanged && !sameSite) box.checked = false;
                box.disabled = !sameSite || row.dataset.other === '1';
                row.hidden = !sameSite || !row.textContent.toLowerCase().includes(search.value.toLowerCase());
                if (!row.hidden) count++;
            });
            editor.querySelector('[data-node-empty]').hidden = count !== 0;
        }
        site.addEventListener('change', function () { filterMembers(true); });
        search.addEventListener('input', function () { filterMembers(false); });
        filterMembers(false);
    }
    var node = document.querySelector('[data-device-node]'), deviceSite = document.querySelector('select[name="site_id"]');
    if (node && deviceSite) {
        function filterNodes() {
            Array.from(node.options).forEach(function (option) {
                option.hidden = option.value !== '0' && option.dataset.site !== deviceSite.value;
                option.disabled = option.hidden;
            });
            if (node.selectedOptions[0] && node.selectedOptions[0].disabled) node.setCustomValidity('Choose a node for the selected site or explicitly select Unassigned.');
            else node.setCustomValidity('');
        }
        deviceSite.addEventListener('change',filterNodes); node.addEventListener('change',filterNodes); filterNodes();
    }
})();
