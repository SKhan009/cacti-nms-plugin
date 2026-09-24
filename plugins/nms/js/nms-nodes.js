/** Explicit node membership: filtering never selects or moves a device automatically. */
(function () {
    'use strict';
    var editor = document.querySelector('[data-node-editor]');
    if (editor) {
        var site = editor.querySelector('[data-node-site]'), search = editor.querySelector('[data-node-search]');
        var rows = Array.from(editor.querySelectorAll('[data-node-member]'));
        var selectVisible = editor.querySelector('[data-node-select-visible]');
        var clearSelection = editor.querySelector('[data-node-clear-selection]');
        /** Filter candidates without changing selections when a search changes. */
        function filterMembers(siteChanged) {
            var count = 0, selectable = 0, selected = 0;
            var query = search.value.trim().toLowerCase();
            rows.forEach(function (row) {
                var box = row.querySelector('input'), sameSite = row.dataset.site === site.value;
                if (siteChanged && !sameSite) box.checked = false;
                box.disabled = !sameSite || row.dataset.other === '1';
                row.hidden = (site.value !== '' && !sameSite) || !row.textContent.toLowerCase().includes(query);
                if (!row.hidden) { count++; if (!box.disabled) selectable++; }
                if (box.checked && !box.disabled) selected++;
            });
            var empty = editor.querySelector('[data-node-empty]');
            empty.hidden = count !== 0;
            empty.textContent = query ? 'No devices match your search.' :
                (site.value ? 'No devices at this site. Assign devices to this site in Device Edit.' : 'No accessible devices available.');
            editor.querySelector('[data-node-guidance]').textContent = site.value ?
                'Select one or more devices. Unchecking a member unassigns it without deleting the device. Devices in another node must be moved through Device Edit.' :
                'All accessible devices are listed below. Select a site above to enable device selection.';
            editor.querySelector('[data-node-selection]').textContent = count + ' shown · ' + selected + ' selected';
            selectVisible.disabled = selectable === 0;
            clearSelection.disabled = selected === 0;
        }
        site.addEventListener('change', function () { filterMembers(true); });
        search.addEventListener('input', function () { filterMembers(false); });
        rows.forEach(function (row) { row.querySelector('input').addEventListener('change', function () { filterMembers(false); }); });
        selectVisible.addEventListener('click', function () {
            rows.forEach(function (row) { var box = row.querySelector('input'); if (!row.hidden && !box.disabled) box.checked = true; });
            filterMembers(false);
        });
        clearSelection.addEventListener('click', function () {
            rows.forEach(function (row) { var box = row.querySelector('input'); if (!box.disabled) box.checked = false; });
            filterMembers(false);
        });
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
