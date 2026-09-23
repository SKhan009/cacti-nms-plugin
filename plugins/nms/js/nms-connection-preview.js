(() => {
    'use strict';
    document.querySelectorAll('[data-open-dialog]').forEach(button => button.addEventListener('click', () => {
        const dialog = document.getElementById(button.dataset.openDialog);
        // Add always starts with a blank record after closing an edit popup.
        const form = dialog.querySelector('form');
        form.reset();
        if (form.elements.original_type) {
            form.elements.original_type.value = ''; form.elements.type.value = '';
            dialog.querySelector('h2').textContent = 'Add connection type';
        } else {
            form.elements.id.value = '0'; form.elements.a.value = ''; form.elements.b.value = '';
            form.elements.label.value = ''; form.elements.speed_mbps.value = '0';
            dialog.querySelector('h2').textContent = 'Add connection';
        }
        form.dispatchEvent(new Event('input')); dialog.showModal();
    }));
    document.querySelectorAll('.nms-connection-dialog').forEach(dialog => {
        dialog.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => dialog.close()));
        dialog.addEventListener('close', () => {
            const url = new URL(location.href); url.searchParams.delete('edit'); url.searchParams.delete('type_edit');
            history.replaceState(null, '', url);
        });
        if (dialog.dataset.autoOpen === '1') dialog.showModal();
    });
    document.querySelectorAll('[data-confirm-delete]').forEach(form => form.addEventListener('submit', event => {
        if (!window.confirm(form.dataset.confirmDelete)) event.preventDefault();
    }));
    const ns = 'http://www.w3.org/2000/svg';
    document.querySelectorAll('.nms-connection-preview').forEach(svg => {
        const form = svg.closest('form');
        const patterns = JSON.parse(svg.dataset.patterns);
        const make = (name, attrs) => {
            const node = document.createElementNS(ns, name);
            Object.entries(attrs).forEach(([key,value]) => node.setAttribute(key,value));
            return node;
        };
        const update = () => {
            const color = form?.elements.color?.value || svg.dataset.color;
            const style = form?.elements.line_style?.value || svg.dataset.style;
            const symbol = form?.elements.symbol?.value || svg.dataset.symbol;
            svg.replaceChildren(make('line', {x1:14,y1:16,x2:226,y2:16,stroke:color,'stroke-width':3,'stroke-dasharray':patterns[style] || ''}));
            [14,226].forEach(x => {
                const shape = symbol === 'circle' ? make('circle',{cx:x,cy:16,r:5}) :
                    symbol === 'square' ? make('rect',{x:x-5,y:11,width:10,height:10}) :
                    symbol === 'arrow' ? make('path',{d:`M ${x-5} 10 L ${x+5} 16 L ${x-5} 22 Z`}) : null;
                if (shape) {shape.setAttribute('fill',color); svg.append(shape);}
            });
            svg.setAttribute('aria-label',`${form?.elements.type?.value || 'Connection'}: ${style} line, ${symbol} endpoints, ${color}`);
        };
        form?.addEventListener('input',update);
        form?.addEventListener('change',update);
        update();
    });
})();
