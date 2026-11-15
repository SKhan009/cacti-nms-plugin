(() => {
    'use strict';
    const form = document.querySelector('#alarm-ack-form');
    if (!form) return;
    const note = form.elements.note,
        count = document.querySelector('#alarm-ack-count'),
        submit = document.querySelector('#alarm-ack-submit'),
        feedback = document.querySelector('#alarm-ack-feedback');
    function update() {
        count.textContent = note.value.length + '/512';
        submit.disabled = note.disabled || !note.value.trim();
    }
    note.addEventListener('input', update);
    update();
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submit.disabled) return;
        if (!(await icctShowMessage({
            title: 'Confirm acknowledgement',
            text: 'Are you sure you want to save your remarks and acknowledge this alarm?',
            confirm: true,
            accept: 'Acknowledge',
        }))) return;
        submit.disabled = true;
        feedback.textContent = '';
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
            });
            if (response.redirected || !response.headers.get('content-type')?.includes('application/json')) {
                throw Error('Session expired. Reload and sign in.');
            }
            const result = await response.json();
            if (!response.ok) throw Error(result.error || 'Unable to acknowledge alarm.');
            location.assign('alarms/controllers/alarms.php');
        } catch (error) {
            feedback.textContent = error.message;
            update();
        }
    });
})();
