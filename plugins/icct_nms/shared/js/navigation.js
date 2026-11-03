/** Keep section links on the current feature page while assets use the plugin base. */
document.addEventListener(
    'click',
    function (event) {
        const link = event.target.closest('a[href^="#"]');
        if (
            !link ||
            event.defaultPrevented ||
            event.button !== 0 ||
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey
        )
            return;
        event.preventDefault();
        const hash = link.getAttribute('href');
        if (hash !== '#') window.location.hash = hash;
    },
    true,
);

// Settings uses a side flyout, shared by pointer, keyboard and touch navigation.
document.addEventListener('DOMContentLoaded', function () {
    const menu = document.querySelector('.installation-settings-menu');
    if (!menu) return;
    const trigger = menu.querySelector('.settings-menu-trigger');
    const panel = menu.querySelector('.settings-submenu');
    const setOpen = function (open) {
        trigger.setAttribute('aria-expanded', String(open));
        panel.hidden = !open;
    };
    menu.addEventListener('mouseenter', function () { setOpen(true); });
    menu.addEventListener('mouseleave', function () {
        if (!menu.contains(document.activeElement)) setOpen(false);
    });
    menu.addEventListener('focusin', function () { setOpen(true); });
    menu.addEventListener('focusout', function () {
        Promise.resolve().then(function () {
            if (!menu.contains(document.activeElement)) setOpen(false);
        });
    });
    trigger.addEventListener('click', function () { setOpen(true); });
    menu.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            trigger.focus();
            setOpen(false);
            event.stopPropagation();
        } else if (event.key === 'ArrowRight' && event.target === trigger) {
            event.preventDefault();
            setOpen(true);
            panel.querySelector('a').focus();
        } else if (event.key === 'ArrowLeft' && panel.contains(event.target)) {
            event.preventDefault();
            trigger.focus();
            setOpen(false);
        }
    });
});
