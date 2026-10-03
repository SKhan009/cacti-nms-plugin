"use strict";
// Preview feedback describes the local action without claiming a live device save.
document.querySelectorAll('form[data-static-preview]').forEach(form => {
    if (form.hasAttribute('data-delete-segment') || form.hasAttribute('data-delete-type') || form.hasAttribute('data-delete-connection') || form.hasAttribute('data-remove-graph-template') || form.hasAttribute('data-remove-data-query')) return;
    form.addEventListener('submit', event => {
        event.preventDefault();
        const value = name => form.elements.namedItem(name)?.value || '';
        const action = value('action');
        const protocol = value('protocol').toUpperCase();
        let title = 'Configuration updated';
        let text = 'Settings updated in this preview.';
        if (action === 'save_segment') {
            title = 'Segment preview';
            text = 'Save segment changes in the deployed plugin.';
        } else if (action === 'toggle_protocol') {
            const enabled = value('enabled') === '1';
            title = enabled ? 'Protocol enabled' : 'Protocol disabled';
            text = `${protocol} ${enabled ? 'enabled' : 'disabled'}. Saved parameters are retained.`;
        } else if (action === 'remove_protocol') {
            title = 'Protocol removed';
            text = `${protocol} removed from this preview.`;
            const section = document.getElementById(`protocol-${value('protocol')}`);
            if (section) section.hidden = true;
        } else if (action.endsWith('_data_query')) {
            title = 'Data query updated';
            if (action === 'add_data_query') text = `${form.elements.namedItem('snmp_query_id')?.selectedOptions[0]?.textContent || 'Data query'} selected in this preview.`;
            else if (action === 'change_data_query') text = 'Re-index method selected in this preview.';
            else text = 'Run this query in the deployed plugin to fetch current results.';
        } else if (action === 'add_graph_template') {
            title = 'Graph template selected';
            text = `${form.elements.namedItem('graph_template_id')?.selectedOptions[0]?.textContent || 'Graph template'} selected in this preview.`;
        } else {
            const names = {snmp:'SNMP', ssh:'SSH', serial:'Serial communication', diagnostics:'Device diagnostics', discovery:'Discovery'};
            const deviceName = document.querySelector('h1')?.textContent.trim();
            text = `${names[action] || 'Device'} settings${deviceName ? ` for “${deviceName}”` : ''} updated in this preview.`;
        }
        icctShowMessage({title, text: `${text} Live device configuration is unchanged.`});
    });
});
