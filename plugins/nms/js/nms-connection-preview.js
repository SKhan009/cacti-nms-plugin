(() => {
    'use strict';
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
            const color = form.elements.color.value;
            const style = form.elements.line_style.value;
            const symbol = form.elements.symbol.value;
            svg.replaceChildren(make('line', {x1:14,y1:16,x2:226,y2:16,stroke:color,'stroke-width':3,'stroke-dasharray':patterns[style] || ''}));
            [14,226].forEach(x => {
                const shape = symbol === 'circle' ? make('circle',{cx:x,cy:16,r:5}) :
                    symbol === 'square' ? make('rect',{x:x-5,y:11,width:10,height:10}) :
                    symbol === 'arrow' ? make('path',{d:`M ${x-5} 10 L ${x+5} 16 L ${x-5} 22 Z`}) : null;
                if (shape) {shape.setAttribute('fill',color); svg.append(shape);}
            });
            svg.setAttribute('aria-label',`${form.elements.type.value}: ${style} line, ${symbol} endpoints, ${color}`);
        };
        form.addEventListener('input',update);
        form.addEventListener('change',update);
        update();
    });
})();
