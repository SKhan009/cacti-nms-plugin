(() => {
    'use strict';
    const root = document.querySelector('#alarm-ticket-action-page');
    if (!root) return;
    const form = document.querySelector('#alarm-ticket-action-form'),
        note = form.elements.note,
        count = document.querySelector('#alarm-ticket-action-count'),
        submit = document.querySelector('#alarm-ticket-action-submit'),
        feedback = document.querySelector('#alarm-ticket-action-feedback'),
        showMore = document.querySelector('#alarm-ticket-action-show-more');
    let busy = false;
    function update() {
        count.textContent = note.value.length + '/512';
        submit.disabled = busy || note.disabled || !note.value.trim();
    }
    note.addEventListener('input', update);
    update();
    showMore?.addEventListener('click', () => {
        const expanded = showMore.getAttribute('aria-expanded') === 'true';
        document.querySelectorAll('.alarm-ticket-action-timeline > li').forEach((item, index) => {
            if (index) item.hidden = expanded;
        });
        showMore.setAttribute('aria-expanded', String(!expanded));
        showMore.firstChild.textContent = expanded ? 'Show more ' : 'Show less ';
    });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submit.disabled) return;
        busy = true;
        update();
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
            if (!response.ok) throw Error(result.error || 'Could not update the ticket.');
            location.assign(root.dataset.returnUrl);
        } catch (error) {
            feedback.textContent = error.message;
            busy = false;
            update();
        }
    });
})();
