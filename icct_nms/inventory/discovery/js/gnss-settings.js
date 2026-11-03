(() => {
    const transport = document.querySelector('#gnssTransport');
    if (!transport) return;
    const update = () =>
        document.querySelectorAll('[data-gnss-transports]').forEach((section) => {
            const visible = section.dataset.gnssTransports.split(' ').includes(transport.value);
            section.hidden = !visible;
            section.querySelectorAll('input,select').forEach((field) => {
                field.disabled = !visible;
            });
        });
    transport.addEventListener('change', update);
    update();
})();
