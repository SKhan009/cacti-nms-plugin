'use strict';

document.querySelectorAll('[data-ntp-settings]').forEach((settings) => {
    const method = settings.querySelector('[name="ntp_method"]');
    const addressing = settings.querySelector('[data-ntp-addressing]');
    const feedback = method.form.querySelector('[data-ntp-ssh-error]');
    const sshPanel = document.getElementById('protocol-ssh');
    const updateSshFeedback = () => {
        if (!feedback) return;
        let message = '';
        if (method.value === 'ssh') {
            const toggle = sshPanel?.querySelector('.protocol-enable input');
            const enabled = sshPanel?.hidden !== true &&
                (toggle ? toggle.checked : feedback.dataset.sshEnabled === '1');
            if (!enabled) {
                message = 'Enable the SSH protocol for this device to use NTP over SSH.';
            } else if (feedback.dataset.sshVerified !== '1') {
                message = 'Assign an enabled SSH profile and verify the device’s host key to use NTP over SSH.';
            }
        }
        feedback.hidden = message === '';
        if (feedback.textContent !== message) feedback.textContent = message;
        method.setCustomValidity(message);
    };
    const update = () => {
        const staticSource = addressing.querySelector('input:checked').value === 'static';
        for (const group of settings.querySelectorAll('[data-ntp-methods]')) {
            const visible = group.dataset.ntpMethods.split(' ').includes(method.value) &&
                (!group.hasAttribute('data-ntp-static-source') || staticSource) &&
                (!group.hasAttribute('data-ntp-device-source') || !staticSource);
            group.hidden = !visible;
            for (const input of group.querySelectorAll('input, select')) input.disabled = !visible;
        }
        updateSshFeedback();
    };
    method.addEventListener('change', update);
    addressing.addEventListener('change', update);
    if (sshPanel && feedback) {
        sshPanel.addEventListener('change', updateSshFeedback);
        new MutationObserver(updateSshFeedback).observe(sshPanel, {attributeFilter: ['hidden']});
    }
    update();
});
