/** Consistent feedback for confirmed server results and native form submissions. */
(function () {
    'use strict';
    var region;
    window.nmsNotify = function (text, error) {
        if (!text || !text.trim()) return;
        if (!region) {
            region = document.createElement('div');
            region.className = 'nms-feedback-toast';
            document.body.appendChild(region);
        }
        region.replaceChildren();
        region.classList.toggle('error', !!error);
        region.setAttribute('role', error ? 'alert' : 'status');
        region.setAttribute('aria-live', error ? 'assertive' : 'polite');
        var message = document.createElement('span');
        message.textContent = text;
        var close = document.createElement('button');
        close.type = 'button';
        close.textContent = '×';
        close.setAttribute('aria-label', 'Dismiss notification');
        close.addEventListener('click', function () { region.remove(); region = null; });
        region.append(message, close);
    };
    function initialize() {
        var notices = document.querySelectorAll('.nms-notice, .nms-form-message, .nms-config-notice, .nms-config-error, .nms-saved, .nms-action-feedback');
        var messages = [], errors = [];
        notices.forEach(function (notice) {
            if (!notice.textContent.trim() || notice.hidden) return;
            var error = notice.classList.contains('error') || notice.classList.contains('nms-config-error') || notice.getAttribute('role') === 'alert';
            notice.classList.add('nms-feedback-banner');
            notice.classList.toggle('nms-feedback-error', error);
            notice.setAttribute('role', error ? 'alert' : 'status');
            notice.setAttribute('aria-atomic', 'true');
            var text = notice.innerText.trim();
            if (text && !(error ? errors : messages).includes(text)) (error ? errors : messages).push(text);
        });
        if (errors.length || messages.length) window.nmsNotify((errors.length ? errors : messages).join(' '), !!errors.length);
    }
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (event.defaultPrevented || form.method.toLowerCase() !== 'post') return;
        var button = event.submitter;
        if (!button || !/save|create|add|apply|import|upload|delete|remove/i.test(button.textContent || button.value || '')) return;
        // Success is shown only from a server-confirmed result, never on click.
        var message = document.createElement('p');
        message.className = 'nms-feedback-pending';
        message.setAttribute('role', 'status');
        message.textContent = /delete|remove/i.test(button.textContent || button.value) ? 'Removing…' : 'Saving changes…';
        form.querySelectorAll('.nms-feedback-pending').forEach(function (old) { old.remove(); });
        form.appendChild(message);
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
    else initialize();
})();
