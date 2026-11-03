/* Installation stays explicit; opening this dialog never starts a system command. */
(function () {
    'use strict';
    var dialog = document.getElementById('installation-dialog');
    if (!dialog) return;
    var previous;
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-install-action]');
        if (button) {
            previous = button;
            document.getElementById('installation-dialog-title').textContent = 'Install ' + button.dataset.installComponent;
            dialog.querySelectorAll('[data-install-help]').forEach(function (section) {
                section.hidden = section.dataset.installHelp !== button.dataset.installAction;
            });
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        }
        if (event.target.closest('[data-install-close]')) {
            if (typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
            if (previous) previous.focus();
        }
    });
}());
