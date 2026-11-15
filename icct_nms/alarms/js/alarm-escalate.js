(() => {
    'use strict';
    const form = document.querySelector('#alarm-escalate-form');
    if (!form) return;
    const name = form.elements.ticket_name,
        note = form.elements.note,
        count = document.querySelector('#alarm-escalate-count'),
        submit = document.querySelector('#alarm-escalate-submit'),
        feedback = document.querySelector('#alarm-escalate-feedback'),
        showMore = document.querySelector('#alarm-escalate-show-more');
    function update() {
        count.textContent = note.value.length + '/512';
        submit.disabled = name.disabled || !name.value.trim() || !note.value.trim();
    }
    name.addEventListener('input', update);
    note.addEventListener('input', update);
    update();
    showMore?.addEventListener('click', () => {
        const expanded = showMore.getAttribute('aria-expanded') === 'true';
        document.querySelectorAll('.alarm-escalate-history tbody tr').forEach((row, index) => {
            if (index) row.hidden = expanded;
        });
        showMore.setAttribute('aria-expanded', String(!expanded));
        showMore.firstChild.textContent = expanded ? 'Show more ' : 'Show less ';
    });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submit.disabled) return;
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
            if (!response.ok) throw Error(result.error || 'Unable to escalate alarm.');
            location.assign('alarms/controllers/alarms.php');
        } catch (error) {
            feedback.textContent = error.message;
            update();
        }
    });
})();
