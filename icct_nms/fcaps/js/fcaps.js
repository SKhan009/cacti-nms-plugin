'use strict';
(() => {
    const panel = document.querySelector('#device-fcaps');
    if (!panel) return;
    const form = document.querySelector('#fault-form'),
        list = document.querySelector('#fault-rule-list'),
        hidden = form.elements.fault_rules;
    const catalogue = JSON.parse(document.querySelector('#fault-catalogue').textContent),
        saved = JSON.parse(document.querySelector('#saved-fault-rules').textContent);
    const navigation = document.querySelector('#fault-alarm-navigation'),
        empty = document.querySelector('#fault-alarm-empty');
    const oidSection = document.querySelector('#fault-oid-section'),
        oidNavigation = document.querySelector('#fault-oid-navigation'),
        mibNavigation = document.querySelector('#fault-mib-navigation');
    const sourceRadios = [...document.querySelectorAll('.fault-source-options input')];
    const passiveForm = document.querySelector('#passive-fault-form');
    const netflowSummary = document.querySelector('#fault-netflow-summary');
    const netflowActions = [...document.querySelectorAll('[data-netflow-action]')];
    const snmpRuleType = document.querySelector('#snmp-fault-rule-type');
    const snmpRuleRadios = [...document.querySelectorAll('[name="snmp_fault_view"]')];
    const ntpSection = document.querySelector('#fault-ntp-section'),
        ntpNavigation = document.querySelector('#fault-ntp-navigation'),
        mibPicker = document.querySelector('#fault-mib-presets');
    const ntpParameters = JSON.parse(
            document.querySelector('#fault-ntp-parameters')?.textContent || '{}',
        ),
        ntpReadings = JSON.parse(
            document.querySelector('#fault-ntp-readings')?.textContent || '{}',
        ),
        mibPresets = JSON.parse(
            document.querySelector('#fault-mib-preset-values')?.textContent || '[]',
        );

    let selectedSource = saved[0]?.source || 'rrd',
        selectedRow = null;
    let discardTrapDraft = () => {};
    const graphSection = document.querySelector('#fault-graph-section');
    const alarmList = document.querySelector('#fault-saved-alarm-list');
    const repositoryEdit = document.querySelector('#fault-mib-repository-edit');
    let selectedRepositoryItem = null;
    let savedPollingCounts = countRulesByOid(saved, 'snmp');
    function oidKey(oid) {
        return (oid || '').trim().replace(/^\.+/, '');
    }
    function countRulesByOid(rules, source) {
        const counts = new Map();
        for (const rule of rules) {
            if (rule.source !== source) continue;
            const oid = oidKey(rule.oid);
            if (oid) counts.set(oid, (counts.get(oid) || 0) + 1);
        }
        return counts;
    }
    function showOidRuleCount(badge, counts, oid) {
        const count = counts.get(oidKey(oid)) || 0;
        badge.textContent = `${count} saved`;
        badge.title = `${count} saved ${count === 1 ? 'rule' : 'rules'}`;
    }
    function renderAlarmList(container, entries, passive, edit) {
        if (!container) return;
        container.replaceChildren();
        for (const row of entries) {
            const field = (key) => row.querySelector(passive ? `[data-field="${key}"]` : `[data-${key}]`);
            const name = field('name').value.trim() || 'Untitled alarm';
            const entry = document.createElement('li');
            const text = document.createElement('div');
            const title = document.createElement('strong');
            const detail = document.createElement('small');
            title.textContent = name;
            detail.textContent = [
                row.dataset.alarmSaved ? 'Saved' : 'Draft',
                row.dataset.presetKey ? 'MIB preset' : '',
                field('severity').value,
                field('enabled').checked ? 'Enabled' : 'Disabled',
            ].filter(Boolean).join(' · ');
            text.append(title, detail);
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'icon-button fault-alarm-edit';
            button.innerHTML = '<svg class="icct-edit-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m16 3 5 5L8 21l-5 1 1-5ZM14 5l5 5"/></svg>';
            button.setAttribute('aria-label', `Edit ${name}`);
            button.title = `Edit ${name}`;
            button.addEventListener('click', () => {
                edit(row);
                const nameField = field('name');
                const control = row.querySelector('.template-select-trigger') || nameField;
                control.focus({ preventScroll: true });
                control.scrollIntoView({ block: 'center' });
            });
            entry.append(text, button);
            container.append(entry);
        }
        if (!entries.length) {
            const message = document.createElement('li');
            message.textContent = 'No alarm rules for this source.';
            container.append(message);
        }
    }
    const netflowForm = document.querySelector('#netflow-fault-form');
    if (netflowForm) {
        const choices = [...netflowForm.querySelectorAll('[data-netflow-metric]')];
        const labels = new Map(choices.map((choice) => [choice.dataset.netflowMetric, choice.dataset.metricLabel]));
        const editors = netflowForm.querySelector('[data-netflow-rule-editors]');
        const template = netflowForm.querySelector('[data-netflow-rule-template]');
        const list = netflowForm.querySelector('[data-netflow-rule-list]');
        const empty = netflowForm.querySelector('[data-netflow-rule-empty]');
        const stored = netflowForm.elements.netflow_rules;
        const add = document.querySelector('#add-netflow-fault');
        let metric = choices[0].dataset.netflowMetric;
        let selected = null;
        const field = (row, key) => row.querySelector(`[data-field="${key}"]`);
        const fields = (row) => [...row.querySelectorAll('[data-field]')];
        function render() {
            const rows = [...editors.children];
            choices.forEach((choice) => {
                const key = choice.dataset.netflowMetric;
                choice.setAttribute('aria-pressed', String(key === metric));
                const count = rows.filter((row) => row.dataset.alarmSaved && row.dataset.ruleMetric === key).length;
                const detail = choice.querySelector('[data-netflow-rule-count]');
                detail.textContent = `${detail.dataset.unit} · ${count} saved`;
            });
            rows.forEach((row) => (row.hidden = row !== selected));
            empty.hidden = Boolean(selected);
            list.hidden = rows.length === 0;
            add.disabled = rows.length >= 20;
            renderAlarmList(list, rows, true, (row) => {
                selected = row;
                metric = row.dataset.ruleMetric;
                render();
            });
        }
        function sync() {
            stored.value = JSON.stringify([...editors.children].map((row) => ({
                id: row.dataset.ruleId,
                metric: row.dataset.ruleMetric,
                ...Object.fromEntries(fields(row).map((input) => [
                    input.dataset.field, input.type === 'checkbox' ? input.checked : input.value,
                ])),
            })));
        }
        function append(rule, saved) {
            const row = template.content.firstElementChild.cloneNode(true);
            row.dataset.ruleId = rule.id;
            row.dataset.ruleMetric = rule.metric;
            if (saved) row.dataset.alarmSaved = '1';
            row.querySelector('[data-netflow-metric-display]').value = labels.get(rule.metric);
            for (const input of fields(row)) {
                const value = rule[input.dataset.field];
                if (input.type === 'checkbox') input.checked = Boolean(value);
                else input.value = value ?? '';
            }
            if (rule.metric === 'distributed_syn_rate') {
                field(row, 'src_addr').closest('label').hidden = true;
                field(row, 'dst_addr').setAttribute('aria-required', 'true');
                row.querySelector('[data-netflow-destination-label]').textContent = 'Destination IP (required)';
                row.querySelector('[data-netflow-distributed-help]').hidden = false;
            }
            editors.append(row);
            return row;
        }
        JSON.parse(stored.value).forEach((rule) => append(rule, true));
        choices.forEach((choice) => choice.addEventListener('click', () => {
            metric = choice.dataset.netflowMetric;
            selected = [...editors.children].find((row) => row.dataset.ruleMetric === metric) || null;
            render();
        }));
        add.addEventListener('click', () => {
            const id = Array.from(crypto.getRandomValues(new Uint8Array(12)), (byte) =>
                byte.toString(16).padStart(2, '0')).join('');
            selected = append({ id, metric, severity: 'Major', enabled: true }, false);
            sync();
            render();
            field(selected, 'name').focus();
        });
        editors.addEventListener('input', () => {
            sync();
            render();
        });
        editors.addEventListener('click', (event) => {
            const button = event.target.closest('[data-netflow-rule-remove]');
            if (!button) return;
            selected.remove();
            selected = null;
            sync();
            render();
            add.focus();
        });
        netflowForm.addEventListener('icct-fault-saved', () => {
            for (const row of editors.children) {
                row.dataset.alarmSaved = '1';
            }
            render();
        });
        render();
    }
    function sameMeasurement(row, target) {
        if (
            !target ||
            row.querySelector('[data-source]').value !== target.querySelector('[data-source]').value
        )
            return false;
        const source = target.querySelector('[data-source]').value;
        if (source === 'ntp')
            return (
                row.querySelector('[data-parameter]').value ===
                target.querySelector('[data-parameter]').value
            );
        if (source === 'snmp') {
            const oid = target.querySelector('[data-oid]').value;
            return oid ? row.querySelector('[data-oid]').value === oid : row === target;
        }
        const template = target.querySelector('[data-template]').value,
            metric = target.querySelector('[data-metric]').value;
        return template && metric
            ? row.querySelector('[data-template]').value === template &&
                  row.querySelector('[data-metric]').value === metric
            : row === target;
    }
    function renderMeasurements(preferred = null) {
        const rows = [...list.children],
            matching = rows.filter(
                (row) => row.querySelector('[data-source]').value === selectedSource,
            );
        if (preferred && matching.includes(preferred)) selectedRow = preferred;
        if (!matching.includes(selectedRow)) selectedRow = matching[0] || null;
        if (!form.hidden) {
            sourceRadios.forEach((radio) => (radio.checked = radio.value === selectedSource));
            snmpRuleType.hidden = selectedSource !== 'snmp';
            if (selectedSource === 'snmp') {
                snmpRuleRadios.forEach((radio) => (radio.checked = radio.value === 'snmp'));
            }
        }
        empty.hidden = !!selectedRow;
        rows.forEach((row) => (row.hidden = !sameMeasurement(row, selectedRow)));
        renderAlarmList(alarmList, matching, false, (row) => renderMeasurements(row));
        graphSection.hidden = selectedSource !== 'rrd';
        navigation.replaceChildren();
        if (selectedSource === 'rrd') {
            const items = catalogue.filter((item) => associated().has(Number(item.template_id))),
                seen = new Set(),
                groups = new Map(),
                sections = [];
            // Preserve access to saved rules whose template association was removed.
            matching.forEach((row) => {
                const template = row.querySelector('[data-template]'),
                    metric = row.querySelector('[data-metric]');
                if (
                    template.value &&
                    metric.value &&
                    !items.some(
                        (item) =>
                            String(item.template_id) === template.value &&
                            String(item.metric_id) === metric.value,
                    )
                )
                    items.push({
                        template_id: template.value,
                        metric_id: metric.value,
                        template_name: 'Unavailable template ' + template.value,
                        data_source_name:
                            row.querySelector('[data-parameter]').value ||
                            'Data source ' + metric.value,
                    });
            });
            items.forEach((item) => {
                const key = String(item.template_id) + '|' + String(item.metric_id);
                if (seen.has(key)) return;
                seen.add(key);
                let group = groups.get(String(item.template_id));
                if (!group) {
                    const section = document.createElement('details');
                    section.className = 'fault-template-group';
                    section.setAttribute('name', 'fault-graph-templates');
                    section.open = selectedRow
                        ? String(item.template_id) ===
                          selectedRow.querySelector('[data-template]').value
                        : groups.size === 0;
                    sections.push(section);
                    section.addEventListener('toggle', () => {
                        if (section.open)
                            sections.forEach((other) => {
                                if (other !== section) other.open = false;
                            });
                    });
                    const title = document.createElement('summary');
                    title.textContent = item.template_name;
                    section.append(title);
                    group = document.createElement('div');
                    group.className = 'fault-data-sources';
                    group.setAttribute('aria-label', 'Data sources');
                    section.append(group);
                    navigation.append(section);
                    groups.set(String(item.template_id), group);
                }
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'fault-data-source-link';
                button.textContent = item.data_source_name;
                button.setAttribute(
                    'aria-pressed',
                    selectedRow &&
                        String(item.template_id) ===
                            selectedRow.querySelector('[data-template]').value &&
                        String(item.metric_id) === selectedRow.querySelector('[data-metric]').value
                        ? 'true'
                        : 'false',
                );
                button.addEventListener('click', () =>
                    selectMeasurement({ ...item, source: 'rrd' }),
                );
                group.append(button);
            });
        }
        renderOids();
        renderNtp();
        renderMibPresets();
    }
    function renderNtp() {
        if (!ntpSection) return;
        ntpSection.hidden = selectedSource !== 'ntp';
        ntpNavigation.replaceChildren();
        if (selectedSource !== 'ntp') return;
        for (const [parameter, item] of Object.entries(ntpParameters)) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'fault-data-source-link';
            button.textContent = item.label;
            button.setAttribute(
                'aria-pressed',
                selectedRow?.querySelector('[data-parameter]').value === parameter
                    ? 'true'
                    : 'false',
            );
            button.addEventListener('click', () =>
                selectMeasurement({
                    source: 'ntp',
                    parameter,
                    units: item.units,
                    label: item.label,
                }),
            );
            ntpNavigation.append(button);
        }
    }
    function renderMibPresets() {
        if (!mibPicker) return;
        const presetSection = document.querySelector('#fault-mib-preset-section');
        if (presetSection) presetSection.hidden = selectedSource !== 'snmp' || !mibPresets.length;
        mibPicker.replaceChildren();
        if (!mibPresets.length) {
            const empty = document.createElement('p');
            empty.className = 'fault-oid-empty';
            empty.textContent = 'No saved MIB fault presets associated with this device.';
            mibPicker.append(empty);
            return;
        }
        for (const preset of mibPresets) {
            const row = [...list.children].find(
                (item) => item.dataset.presetKey === preset.preset_key,
            );
            const label = document.createElement('label');
            label.className = 'check-row';
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.checked = row ? row.querySelector('[data-enabled]').checked : !!preset.enabled;
            checkbox.setAttribute('aria-label', preset.name);
            const title = document.createElement('span');
            title.textContent = preset.name + ' · ';
            const badge = document.createElement('span');
            badge.className = 'severity-badge';
            badge.textContent = preset.severity;
            icctSetSeverity(badge, preset.severity);
            title.append(badge);
            label.append(checkbox, title);
            checkbox.addEventListener('change', () => {
                const current = [...list.children].find(
                    (item) => item.dataset.presetKey === preset.preset_key,
                );
                if (current) current.querySelector('[data-enabled]').checked = checkbox.checked;
                else if (list.children.length < 100)
                    addRule({ ...preset, enabled: checkbox.checked });
                else {
                    checkbox.checked = false;
                    return;
                }
                sync();
            });
            const edit = document.createElement('button');
            edit.type = 'button';
            edit.className = 'button';
            edit.textContent = 'Edit thresholds';
            edit.addEventListener('click', () => {
                let target = [...list.children].find(
                    (item) => item.dataset.presetKey === preset.preset_key,
                );
                if (!target && list.children.length < 100) {
                    addRule(preset);
                    target = list.children[list.children.length - 1];
                }
                if (target) {
                    selectedSource = target.querySelector('[data-source]').value;
                    renderMeasurements(target);
                }
            });
            mibPicker.append(label, edit);
        }
    }
    function selectMeasurement(item) {
        const row = [...list.children].find(
            (row) =>
                row.querySelector('[data-source]').value === item.source &&
                (item.source === 'ntp'
                    ? row.querySelector('[data-parameter]').value === item.parameter
                    : item.source === 'snmp'
                      ? row.querySelector('[data-oid]').value === item.oid
                      : row.querySelector('[data-template]').value === String(item.template_id) &&
                        row.querySelector('[data-metric]').value === String(item.metric_id)),
        );
        if (row) {
            renderMeasurements(row);
            return;
        }
        if (list.children.length >= 100) return;
        addRule({
            source: item.source,
            name: item.label || item.parameter || item.data_source_name || '',
            parameter: item.parameter || '',
            oid: item.oid || '',
            units: item.units || '',
            scale: item.scale || 1,
            template_id: item.template_id || 0,
            metric_id: item.metric_id || 0,
        });
        const added = list.children[list.children.length - 1];
        renderMeasurements(added);
        sync();
    }
    function showFaultSource(source) {
        const tab = source === 'trap' ? 'snmp' : source;
        const passive = source === 'syslog' || source === 'trap';
        const netflow = source === 'netflow';
        sourceRadios.forEach((radio) => (radio.checked = radio.value === tab));
        snmpRuleType.hidden = tab !== 'snmp';
        if (tab === 'snmp') {
            snmpRuleRadios.forEach((radio) => (radio.checked = radio.value === source));
        }
        form.hidden = passive || netflow;
        if (netflowSummary) netflowSummary.hidden = !netflow;
        netflowActions.forEach((action) => (action.hidden = !netflow));
        if (passiveForm) {
            if (source !== 'trap') discardTrapDraft();
            passiveForm.hidden = !passive;
            passiveForm.querySelectorAll('[data-passive-panel]').forEach((pane) => {
                pane.hidden = pane.dataset.passivePanel !== source;
            });
        }
        if (!passive && !netflow) {
            selectedSource = source;
            renderMeasurements();
        }
    }
    sourceRadios.forEach((radio) => radio.addEventListener('change', () => {
        if (radio.checked) {
            const snmpView = snmpRuleRadios.find((option) => option.checked)?.value;
            showFaultSource(radio.value === 'snmp' ? snmpView || 'snmp' : radio.value);
        }
    }));
    snmpRuleRadios.forEach((radio) => radio.addEventListener('change', () => {
        if (radio.checked) showFaultSource(radio.value);
    }));
    if (passiveForm) {
        passiveForm.addEventListener('click', (event) => {
            if (event.target.closest('label .field-info')) event.preventDefault();
        });
        const panes = [...passiveForm.querySelectorAll('[data-passive-panel]')];
        const trapPane = panes.find((pane) => pane.dataset.passivePanel === 'trap');
        const trapChoices = [...trapPane.querySelectorAll('[data-trap-choice]')];
        function updateTrapRuleCounts(rules) {
            const counts = countRulesByOid(rules, 'trap');
            for (const choice of trapChoices) {
                showOidRuleCount(choice.closest('label').querySelector('[data-trap-rule-count]'), counts, choice.value);
            }
        }
        const payloadOids = JSON.parse(document.querySelector('#passive-trap-payload-oids').textContent);
        const fields = (row) => [...row.querySelectorAll('[data-field]')];
        const rows = (pane) => [...pane.querySelector('[data-passive-rules]').children];
        const trapOid = (row) => row.querySelector('[data-field="oid"]').value;
        const atRuleLimit = () => panes.flatMap(rows).length >= 100;
        let selectedTrapOid = '';
        let untouchedTrapDraft = null;
        function syncPassive() {
            trapChoices.forEach((choice) => (choice.checked = choice.value === selectedTrapOid));
            passiveForm.elements.passive_rules.value = JSON.stringify(panes.flatMap((pane) => {
                const entries = rows(pane);
                if (pane === trapPane) {
                    entries.forEach((row) => (row.hidden = trapOid(row) !== selectedTrapOid));
                }
                pane.querySelector('[data-passive-empty]').hidden = entries.some((row) => !row.hidden);
                renderAlarmList(pane.querySelector('[data-passive-alarm-list]'), entries, true, (row) => {
                    if (pane === trapPane) selectedTrapOid = trapOid(row);
                    syncPassive();
                });
                return entries.map((row) => ({
                    source: pane.dataset.passivePanel,
                    ...Object.fromEntries(fields(row).map((field) => [
                        field.dataset.field,
                        field.type === 'checkbox' ? field.checked : field.value,
                    ])),
                }));
            }));
        }
        function updateTrapPayload(row, selectedOid) {
            const oid = row.querySelector('[data-field="oid"]')?.value;
            const varbind = row.querySelector('[data-field="varbind"]');
            const expected = row.querySelector('[data-field="value"]');
            varbind.replaceChildren(new Option('Any payload', ''));
            for (const fieldOid of payloadOids[oid] || []) {
                varbind.add(new Option(fieldOid, fieldOid));
            }
            varbind.value = [...varbind.options].some((option) => option.value === selectedOid) ? selectedOid : '';
            expected.disabled = varbind.value === '';
            expected.required = !expected.disabled;
            if (expected.disabled) expected.value = '';
        }
        function addPassive(pane, rule) {
            const row = pane.querySelector('template').content.firstElementChild.cloneNode(true);
            for (const field of fields(row)) {
                if (field.type === 'checkbox') field.checked = !!rule[field.dataset.field];
                else {
                    const value = rule[field.dataset.field] ?? '';
                    if (field.matches('select[data-field="name"]') && value &&
                        ![...field.options].some((option) => option.value === value)) {
                        // A saved alarm may predate the catalog or use a previous device keyword.
                        field.add(new Option(value, value));
                    }
                    field.value = value;
                }
            }
            if (pane.dataset.passivePanel === 'trap') updateTrapPayload(row, rule.varbind ?? '');
            pane.querySelector('[data-passive-rules]').append(row);
            const cleanupSearch = pane.dataset.passivePanel === 'syslog'
                ? window.icctMakeSearchableSelect?.(
                    row.querySelector('select[data-field="name"]'), 'Alarm Name', 'Alarm Keywords', true,
                )
                : null;
            row.querySelector('[data-passive-remove]').addEventListener('click', () => {
                if (row === untouchedTrapDraft) untouchedTrapDraft = null;
                cleanupSearch?.();
                row.remove();
                syncPassive();
            });
            return row;
        }
        const savedPassiveRules = JSON.parse(document.querySelector('#saved-passive-rules').textContent);
        updateTrapRuleCounts(savedPassiveRules);
        for (const rule of savedPassiveRules) {
            const pane = panes.find((item) => item.dataset.passivePanel === rule.source);
            if (pane) addPassive(pane, rule).dataset.alarmSaved = '1';
        }
        if (rows(trapPane).length) selectedTrapOid = trapOid(rows(trapPane)[0]);

        function addTrapDraft(choice) {
            const symbol = choice.closest('label').querySelector('[data-trap-name]').textContent;
            return addPassive(trapPane, { oid: choice.value, name: symbol.split('::').pop().slice(0, 160) });
        }
        discardTrapDraft = () => {
            if (!untouchedTrapDraft) return;
            untouchedTrapDraft.remove();
            untouchedTrapDraft = null;
            syncPassive();
        };
        trapPane.addEventListener('change', (event) => {
            const choice = event.target;
            if (!choice.matches('[data-trap-choice]') || !choice.checked) return;
            if (untouchedTrapDraft && trapOid(untouchedTrapDraft) !== choice.value) {
                discardTrapDraft();
            }
            selectedTrapOid = choice.value;
            if (!atRuleLimit() && !rows(trapPane).some((row) => trapOid(row) === selectedTrapOid)) {
                untouchedTrapDraft = addTrapDraft(choice);
            }
            syncPassive();
        });
        passiveForm.addEventListener('input', (event) => {
            const row = event.target.closest('.passive-rule');
            if (!row) return;
            if (row === untouchedTrapDraft) untouchedTrapDraft = null;
            if (event.target.matches('select[data-field="name"]')) {
                const choice = event.target.selectedOptions[0];
                if (choice?.dataset.message) {
                    row.querySelector('[data-field="message"]').value = choice.dataset.message;
                    row.querySelector('[data-field="severity"]').value = choice.dataset.alarmSeverity;
                }
            }
            if (event.target.dataset.field === 'varbind') {
                updateTrapPayload(row, row.querySelector('[data-field="varbind"]').value);
            }
            syncPassive();
        });
        panel.querySelector('[data-passive-add]').addEventListener('click', () => {
            const pane = panes.find((item) => !item.hidden);
            if (!pane || atRuleLimit()) return;
            const choice = trapChoices.find((item) => item.checked);
            if (pane === trapPane && !choice) {
                trapChoices[0]?.focus();
                return;
            }
            const row = pane === trapPane
                ? (untouchedTrapDraft && trapOid(untouchedTrapDraft) === choice.value
                    ? untouchedTrapDraft : addTrapDraft(choice))
                : addPassive(pane, {});
            syncPassive();
            (row.querySelector('.template-select-trigger') || row.querySelector('[data-field="name"]')).focus();
        });
        passiveForm.addEventListener('invalid', (event) => {
            // Keep the first invalid rule visible when the wizard checks multiple source panels.
            if (event.target !== passiveForm.querySelector(':invalid:not(fieldset)')) return;
            const source = event.target.closest('[data-passive-panel]')?.dataset.passivePanel;
            if (source === 'trap') {
                selectedTrapOid = trapOid(event.target.closest('.passive-rule'));
                syncPassive();
            }
            if (source) showFaultSource(source);
        }, true);
        passiveForm.addEventListener('icct-fault-saved', (event) => {
            updateTrapRuleCounts(JSON.parse(event.detail));
            if (passiveForm.elements.passive_rules.value !== event.detail) return;
            panes.flatMap(rows).forEach((row) => (row.dataset.alarmSaved = '1'));
            syncPassive();
        });
        syncPassive();
    }
    let validationRow = null;
    form.addEventListener(
        'invalid',
        (event) => {
            const row = event.target.closest('.fault-rule');
            if (row && !validationRow) {
                validationRow = row;
                showFaultSource(row.querySelector('[data-source]').value);
                renderMeasurements(row);
                queueMicrotask(() => (validationRow = null));
            }
        },
        true,
    );
    function associated() {
        return new Set(
            [
                ...document.querySelectorAll(
                    '.graph-association:not([hidden]) [name=graph_template_id],.wizard-pending[data-graph-template-id]',
                ),
            ].map((node) => Number(node.value || node.dataset.graphTemplateId)),
        );
    }
    const parameters = JSON.parse(document.querySelector('#fault-parameters')?.textContent || '[]');
    const observations = JSON.parse(
        document.querySelector('#fault-current-readings')?.textContent || '[]',
    );
    function renderOids() {
        oidSection.hidden = selectedSource !== 'snmp';
        oidNavigation.replaceChildren();
        mibNavigation?.replaceChildren();
        if (repositoryEdit) repositoryEdit.hidden = true;
        if (selectedSource !== 'snmp') return;
        const type = document.querySelector('#device-form [name=device_type]')?.value || '';
        const repository = parameters.filter((item) => item.repository);
        if (repositoryEdit) {
            const selectedOid = selectedRow?.querySelector('[data-oid]').value;
            const item = selectedRepositoryItem?.oid === selectedOid
                ? selectedRepositoryItem
                : repository.find((candidate) => candidate.oid === selectedOid);
            if (item) {
                repositoryEdit.href = item.repository_url;
                repositoryEdit.hidden = false;
            }
        }
        const available = parameters.filter(
            (item) => !item.repository && (!item.type_id || String(item.type_id) === type),
        );
        for (const row of list.children)
            if (row.querySelector('[data-source]').value === 'snmp') {
                const oid = row.querySelector('[data-oid]').value;
                if (oid && !available.some((item) => item.oid === oid) && !repository.some((item) => item.oid === oid))
                    available.push({ oid, parameter: row.querySelector('[data-parameter]').value || oid });
            }
        for (const [navigation, items, emptyText] of [
            [oidNavigation, available, 'No known OIDs for this device.'],
            [mibNavigation, repository, 'No numeric scalar OIDs in the MIB Repository.'],
        ]) {
            if (!navigation) continue;
            const seen = new Set();
            for (const item of items) {
                const key = item.repository ? `${item.repository_url}#${item.oid}` : item.oid;
                if (seen.has(key)) continue;
                seen.add(key);
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'fault-oid-link';
                const heading = document.createElement('span');
                heading.className = 'fault-oid-heading';
                const title = document.createElement('span');
                title.textContent = item.repository ? item.label : item.parameter;
                const badge = document.createElement('span');
                badge.className = 'fault-oid-rule-count';
                showOidRuleCount(badge, savedPollingCounts, item.oid);
                heading.append(title, badge);
                const oid = document.createElement('small');
                oid.textContent = item.oid;
                button.append(heading, oid);
                button.setAttribute(
                    'aria-pressed',
                    selectedRow?.querySelector('[data-oid]').value === item.oid ? 'true' : 'false',
                );
                button.addEventListener('click', () => {
                    selectedRepositoryItem = item.repository ? item : null;
                    selectMeasurement({ ...item, source: 'snmp' });
                });
                if (!item.repository) {
                    navigation.append(button);
                    continue;
                }
                const status = document.createElement('small');
                const presets = item.repository_faults || [];
                status.textContent = presets.length ? `MIB presets · ${presets.join(', ')}` : 'No MIB preset';
                button.append(status);
                navigation.append(button);
            }
            if (!seen.size) {
                const message = document.createElement('p');
                message.className = 'fault-oid-empty';
                message.textContent = emptyText;
                navigation.append(message);
            }
        }
    }
    document
        .querySelector('#device-form [name=device_type]')
        ?.addEventListener('change', () => renderMeasurements());
    function sync() {
        hidden.value = JSON.stringify(
            [...list.children].map((row) => ({
                preset_key: row.dataset.presetKey || '',
                description: row.querySelector('[data-description]').value,
                source: row.querySelector('[data-source]').value,
                parameter: row.querySelector('[data-parameter]').value,
                oid: row.querySelector('[data-oid]').value,
                expected_source: row.querySelector('[data-expected-source]')?.value || '',
                units: row.querySelector('[data-units]').value,
                scale: row.querySelector('[data-scale]').value,
                condition: row.querySelector('[data-condition]').value,
                template_id: row.querySelector('[data-template]').value,
                metric_id: row.querySelector('[data-metric]').value,
                minimum: row.querySelector('[data-minimum]').value,
                maximum: row.querySelector('[data-maximum]').value,
                name: row.querySelector('[data-name]').value,
                corrective_action: row.querySelector('[data-corrective-action]').value,
                email_recipients: row.querySelector('[data-email-recipients]').value,
                email: row.querySelector('[data-email]').checked,
                audio: row.querySelector('[data-audio]').checked,
                severity: row.querySelector('[data-severity]').value,
                enabled: row.querySelector('[data-enabled]').checked,
            })),
        );
    }
    function option(select, value, label) {
        const o = document.createElement('option');
        o.value = String(value);
        o.textContent = label;
        select.append(o);
    }
    const fieldHelp = {
        name: 'A short name for this alarm, e.g. UPS battery low.',
        source: 'Use collected Cacti, SNMP or NTP measurements.',
        'expected-source':
            'Expected NTP source IP for this rule. The comparison uses the selected source reported by chrony; it never changes the time server.',
        'parameter-choice':
            'Select a known SNMP parameter to fill its OID and available details. Choose Manual OID for another parameter.',
        parameter: 'Name of the measurement, e.g. Battery charge or Temperature.',
        template:
            'Select an associated graph template containing the measurement you want to monitor.',
        metric: 'Select the actual measurement, e.g. load_1min, battery charge or temperature.',
        oid: 'Full numeric OID from the device MIB: include .0 for a scalar or the actual table index.',
        units: 'Label the measured units, e.g. %, °C or V. This label does not convert the reading.',
        scale: 'Multiply the raw SNMP value by this number. Use 1 for no conversion, or 0.1 for values reported in tenths.',
        reading: 'Read-only measurement. Missing or stale values are Unknown.',
        condition:
            'Choose >, <, outside the range, = or ≠. Above and below use strict comparisons.',
        minimum:
            'Raise a fault below this value, in the same units as the reading. E.g. 20 for battery charge below 20%.',
        maximum:
            'Raise a fault above this value, in the same units as the reading. For = or ≠, enter the numeric device status code.',
        severity: 'Alarm level for this rule. Add separate rules for other severity levels.',
        enabled: 'Enable this rule for automatic evaluation after saving.',
        description: 'Describe the condition reported by this alarm.',
        'corrective-action':
            'Steps to investigate and fix this alarm, e.g. Check UPS power supply.',
    };
    function field(row, label, kind, key) {
        const wrapper = document.createElement('label');
        wrapper.className = 'field';
        const caption = document.createElement('span');
        caption.className = 'field-label';
        const title = document.createElement('span');
        title.className = 'field-title';
        title.textContent = label;
        caption.append(title);
        if (fieldHelp[key]) {
            const icon = document.createElement('span');
            icon.className = 'field-info';
            icon.tabIndex = 0;
            icon.setAttribute('role', 'img');
            icon.setAttribute('aria-label', 'Information about ' + label);
            icon.dataset.tooltip = fieldHelp[key];
            icon.innerHTML =
                '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8"/><path d="M10 9v5M10 6v.5"/></svg>';
            icon.addEventListener('click', (event) => event.preventDefault());
            caption.append(icon);
        }
        const input = document.createElement(
            ['select', 'textarea'].includes(kind) ? kind : 'input',
        );
        input.setAttribute('data-' + key, '');
        input.setAttribute('aria-label', label);
        if (!['select', 'textarea'].includes(kind)) {
            input.type = kind;
            if (kind === 'number') input.step = 'any';
        }
        wrapper.append(caption, input);
        row.append(wrapper);
        return input;
    }
    const wrapper = (input) => input.closest('.field');
    function addRule(rule = {}, index = -1) {
        const row = document.createElement('div');
        row.className = 'fault-rule';
        row.dataset.presetKey = rule.preset_key || '';
        if (index >= 0) row.dataset.alarmSaved = '1';
        row.dataset.presetDescription = rule.description || '';
        const name = field(row, 'Alarm Name', 'text', 'name');
        name.maxLength = 120;
        name.placeholder = 'e.g. UPS battery low';
        name.value = rule.name || '';
        const source = field(row, 'Measurement Source', 'select', 'source');
        option(source, 'rrd', 'Cacti Data Source');
        option(source, 'snmp', 'SNMP OID');
        option(source, 'ntp', 'NTP');
        source.value = rule.source || 'rrd';
        wrapper(source).hidden = true;
        const picker = field(row, 'SNMP Parameter', 'select', 'parameter-choice');
        const parameter = field(row, 'Parameter Name', 'text', 'parameter');
        parameter.maxLength = 120;
        parameter.placeholder = 'e.g. Battery charge';
        parameter.value = rule.parameter || '';
        const expectedSource = field(row, 'Expected NTP source IP', 'text', 'expected-source');
        expectedSource.maxLength = 45;
        expectedSource.value = rule.expected_source || '';

        const template = field(row, 'Graph Template', 'select', 'template'),
            metric = field(row, 'Data Source', 'select', 'metric');
        const oid = field(row, 'Full Instance OID', 'text', 'oid');
        oid.maxLength = 512;
        oid.placeholder = 'Numeric OID including .0 or table index';
        oid.value = rule.oid || '';
        const units = field(row, 'Stored Units', 'hidden', 'units');
        units.value = rule.units || '';
        wrapper(units).hidden = true;
        const initialParameter =
            source.value === 'ntp'
                ? ntpParameters[rule.parameter]
                : parameters.find((item) =>
                      source.value === 'snmp'
                          ? item.oid === String(rule.oid || '').replace(/^\./, '')
                          : Number(item.metric_id) === Number(rule.metric_id) &&
                            Number(item.template_id) === Number(rule.template_id),
                  );
        if (initialParameter) units.value = initialParameter.units;
        const scale = field(row, 'Scale Multiplier', 'hidden', 'scale');
        scale.value = rule.scale ?? 1;
        wrapper(scale).hidden = true;
        const reading = field(row, 'Current Reading', 'text', 'reading');
        reading.readOnly = true;
        const values = observations.filter((item) => Number(item.rule) === index);
        reading.value = values.length
            ? values
                  .map((item) =>
                      item.value === null
                          ? 'Unknown'
                          : String(item.value) + (units.value ? ' ' + units.value : ''),
                  )
                  .join(' / ')
            : 'Awaiting collection';
        const condition = field(row, 'Condition', 'select', 'condition');
        [
            ['at_or_above', '≥ At or above'],
            ['at_or_below', '≤ At or below'],
            ['above', '> Above'],
            ['below', '< Below'],
            ['outside', '< Min or > Max'],
            ['equals', '= Equals'],
            ['not_equals', '≠ Not equal'],
        ].forEach(([value, label]) => option(condition, value, label));
        condition.value =
            rule.condition ||
            (rule.minimum != null && rule.maximum != null
                ? 'outside'
                : rule.minimum != null
                  ? 'below'
                  : 'above');
        const minimum = field(row, 'Threshold Value', 'number', 'minimum'),
            maximum = field(row, 'Threshold Value', 'number', 'maximum'),
            severity = field(row, 'Severity', 'select', 'severity');
        severity.required = true;
        option(severity, '', 'Select severity');
        ['Information', 'Minor', 'Warning', 'Major', 'Critical'].forEach((value) =>
            option(severity, value, value),
        );
        severity.value = rule.severity || '';
        minimum.value = rule.minimum ?? '';
        maximum.value = rule.maximum ?? '';
        option(template, '', 'Select associated template');
        if (rule.template_id && !associated().has(Number(rule.template_id)))
            option(template, rule.template_id, 'Removed graph template — choose another source');
        const seen = new Set();
        catalogue.forEach((item) => {
            if (associated().has(Number(item.template_id)) && !seen.has(item.template_id)) {
                seen.add(item.template_id);
                option(template, item.template_id, item.template_name);
            }
        });
        template.value = String(rule.template_id || '');
        function metrics(reset) {
            metric.replaceChildren();
            option(metric, '', 'Select parameter');
            catalogue
                .filter((item) => String(item.template_id) === template.value)
                .forEach((item) => option(metric, item.metric_id, item.data_source_name));
            if (!reset) metric.value = String(rule.metric_id || '');
        }
        function ntpReading() {
            const age =
                ntpReadings.attempted_epoch == null
                    ? null
                    : Date.now() / 1000 - ntpReadings.attempted_epoch;
            const value =
                parameter.value === 'age_seconds' && age != null
                    ? Math.floor(age)
                    : ntpReadings.values?.[parameter.value];
            reading.value =
                age == null ||
                age < 0 ||
                (age > Number(ntpReadings.stale_seconds) &&
                    !['age_seconds', 'collection_available'].includes(parameter.value)) ||
                value == null
                    ? 'Unknown'
                    : String(value) + (units.value ? ' ' + units.value : '');
            if (
                parameter.value === 'collection_available' &&
                age > Number(ntpReadings.stale_seconds) &&
                value != null
            )
                reading.value = '0';
            if (parameter.value === 'source_matches')
                reading.value = 'Save rule to evaluate expected source';
        }
        function chooseNtp() {
            parameter.value = picker.value;
            const item = ntpParameters[parameter.value];
            units.value = item?.units || '';
            scale.value = 1;
            oid.value = '';
            if (!name.value && item) name.value = item.label;
            wrapper(expectedSource).hidden = parameter.value !== 'source_matches';
            expectedSource.required = parameter.value === 'source_matches';
            if (!expectedSource.required) expectedSource.value = '';
            ntpReading();
            sync();
        }
        function choices() {
            if (source.value === 'ntp') {
                picker.replaceChildren();
                option(picker, '', 'Select NTP parameter');
                for (const [key, item] of Object.entries(ntpParameters))
                    option(picker, key, item.label);
                picker.value = parameter.value;
                return;
            }
            const previous = picker.value,
                type = document.querySelector('#device-form [name=device_type]')?.value || '';
            picker.replaceChildren();
            option(picker, '', 'Manual OID');
            parameters.forEach((item, i) => {
                if (item.repository || !item.type_id || String(item.type_id) === type || item.oid === oid.value) {
                    option(picker, i, item.label);
                }
            });
            picker.value = [...picker.options].some((o) => o.value === previous) ? previous : '';
        }
        let readGeneration = 0;
        async function readParameter() {
            const generation = ++readGeneration;
            if (source.value !== 'snmp' || !oid.value) return;
            const id = document.querySelector('#device-wizard')?.dataset.deviceId;
            if (!id || id === '0') {
                reading.value = 'Save device and SNMP setup first';
                return;
            }
            reading.value = 'Reading…';
            const data = new FormData();
            data.set('action', 'fault_parameter_read');
            data.set('oid', oid.value);
            const token = form.querySelector('input[name="__csrf_magic"]');
            if (!token?.value) {
                reading.value = 'Reload the page before reading';
                return;
            }
            data.set('__csrf_magic', token.value);
            try {
                const response = await fetch(
                    'inventory/controllers/device.php?id=' + encodeURIComponent(id),
                    {
                        method: 'POST',
                        body: data,
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                    },
                );
                if (
                    response.redirected ||
                    !response.headers.get('Content-Type')?.includes('application/json')
                )
                    throw new Error('Request expired. Reload the page and try again.');
                const result = await response.json();
                if (generation !== readGeneration) return;
                if (!response.ok) throw new Error(result.error || 'Reading unavailable');
                if (!Object.prototype.hasOwnProperty.call(result, 'value'))
                    throw new Error('Session expired. Reload the page and sign in.');
                reading.value =
                    result.value === null
                        ? 'Unsupported or no response'
                        : String(Number(result.value) * Number(scale.value || 1)) +
                          (units.value ? ' ' + units.value : '');
            } catch (error) {
                if (generation === readGeneration) reading.value = error.message;
            }
        }
        function fillParameter(item) {
            if (!item) return;
            oid.value = item.oid;
            parameter.value = item.parameter;
            units.value = item.units || '';
            scale.value = item.scale || 1;
            if (!name.value) name.value = item.parameter;
            sync();
            readParameter();
        }
        choices();
        const savedParameter = parameters.findIndex(
            (item) => item.oid === String(rule.oid || '').replace(/^\./, ''),
        );
        if (
            savedParameter >= 0 &&
            [...picker.options].some((option) => option.value === String(savedParameter))
        )
            picker.value = String(savedParameter);
        picker.addEventListener('change', () => {
            if (source.value === 'ntp') {
                chooseNtp();
                return;
            }
            ++readGeneration;
            reading.value = 'Awaiting collection';
            fillParameter(picker.value !== '' ? parameters[Number(picker.value)] : null);
        });
        document
            .querySelector('#device-form [name=device_type]')
            ?.addEventListener('change', choices);
        oid.addEventListener('change', () => {
            const item = parameters.find((p) => p.oid === oid.value.trim().replace(/^\./, ''));
            scale.value = item?.scale || 1;
            if (item?.units) units.value = item.units;
            sync();
            readParameter();
        });
        function sourceFields() {
            row.dataset.measurement = source.value;
            ++readGeneration;
            const direct = source.value === 'snmp',
                ntp = source.value === 'ntp';
            choices();
            wrapper(picker).querySelector('.field-title').textContent = ntp
                ? 'NTP Parameter'
                : 'SNMP Parameter';
            picker.setAttribute('aria-label', ntp ? 'NTP Parameter' : 'SNMP Parameter');
            wrapper(picker)
                .querySelector('.field-info')
                .setAttribute(
                    'aria-label',
                    ntp ? 'Information about NTP Parameter' : 'Information about SNMP Parameter',
                );
            wrapper(picker).querySelector('.field-info').dataset.tooltip = ntp
                ? 'Choose an actual chrony measurement or collection status. Offsets and delay use milliseconds; age uses seconds; synchronization and source-match flags use 1=yes and 0=no.'
                : fieldHelp['parameter-choice'];
            wrapper(source).hidden = true;
            wrapper(reading).querySelector('.field-info').dataset.tooltip = ntp
                ? 'Actual tracking observation from Protocol Config collection. Missing clock measurements remain Unknown; status flags use 1=yes and 0=no. Age is seconds since the last attempt.'
                : direct
                  ? 'Read-only SNMP value after automatic unit conversion. Selecting a parameter fetches a reading using saved SNMP settings.'
                  : 'Read-only latest completed AVERAGE sample from this data source, before graph transformations. Enter thresholds in its stored units.';
            for (const field of [template, metric]) {
                wrapper(field).hidden = direct || ntp;
                field.required = !direct && !ntp;
            }
            wrapper(picker).hidden = !(direct || ntp);
            picker.required = ntp;
            wrapper(expectedSource).hidden = !(ntp && parameter.value === 'source_matches');
            expectedSource.required = ntp && parameter.value === 'source_matches';
            for (const field of [oid, parameter]) wrapper(field).hidden = !direct;
            wrapper(units).hidden = true;
            wrapper(scale).hidden = true;
            oid.required = parameter.required = direct;
            reading.value = 'Awaiting collection';
        }
        function conditionFields(reset = false) {
            row.dataset.condition = condition.value;
            const lower = ['below', 'at_or_below', 'outside'].includes(condition.value),
                upper = !['below', 'at_or_below'].includes(condition.value);
            wrapper(minimum).hidden = !lower;
            wrapper(maximum).hidden = !upper;
            minimum.required = lower;
            maximum.required = upper;
            wrapper(minimum).querySelector('.field-title').textContent =
                condition.value === 'outside' ? 'Minimum Value' : 'Threshold Value';
            wrapper(maximum).querySelector('.field-title').textContent =
                condition.value === 'outside' ? 'Maximum Value' : 'Threshold Value';
            if (reset) {
                if (!lower) minimum.value = '';
                if (!upper) maximum.value = '';
            }
            sync();
        }
        metrics(false);
        sourceFields();
        reading.value = values.length
            ? values
                  .map((item) =>
                      item.value === null
                          ? 'Unknown'
                          : String(item.value) + (units.value ? ' ' + units.value : ''),
                  )
                  .join(' / ')
            : 'Awaiting collection';
        if (source.value === 'ntp' && !values.length) ntpReading();
        expectedSource.addEventListener('change', () => {
            if (source.value === 'ntp') reading.value = 'Save rule to evaluate expected source';
        });
        template.addEventListener('change', () => {
            metrics(true);
            reading.value = 'Awaiting collection';
            sync();
        });
        metric.addEventListener('change', () => {
            const item = parameters.find(
                (p) =>
                    String(p.metric_id) === metric.value &&
                    String(p.template_id) === template.value,
            );
            if (item) {
                parameter.value = item.parameter;
                units.value = item.units || '';
                oid.value = item.oid;
                scale.value = item.scale || 1;
            }
            reading.value = 'Awaiting collection';
            sync();
        });
        source.addEventListener('change', () => {
            sourceFields();
            if (source.value === 'snmp') {
                const item = parameters.find(
                    (p) =>
                        String(p.metric_id) === metric.value &&
                        String(p.template_id) === template.value,
                );
                if (item) {
                    picker.value = String(
                        parameters.findIndex((parameter) => parameter.oid === item.oid),
                    );
                    fillParameter(item);
                } else if (oid.value) readParameter();
            }
            sync();
        });
        condition.addEventListener('change', () => conditionFields(true));
        const description = field(row, 'Description', 'textarea', 'description');
        description.rows = 3;
        description.maxLength = 1000;
        description.value = rule.description || '';
        wrapper(description).classList.add('fault-description');
        const guidance = field(row, 'Fix', 'textarea', 'corrective-action');
        guidance.rows = 2;
        guidance.maxLength = 1000;
        guidance.placeholder = 'Suggested action when a fault occurs';
        guidance.value = rule.corrective_action || '';
        wrapper(guidance).classList.add('fault-guidance');
        // Preserve existing notification preferences without presenting inactive delivery controls.
        const preferences = document.createElement('div');
        preferences.hidden = true;
        row.append(preferences);
        const email = field(preferences, 'Email', 'checkbox', 'email'),
            audio = field(preferences, 'Audio', 'checkbox', 'audio'),
            recipients = field(preferences, 'Email Recipients', 'text', 'email-recipients');
        email.checked = !!rule.email;
        audio.checked = !!rule.audio;
        recipients.value = rule.email_recipients || '';
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'button';
        remove.textContent = 'Remove';
        remove.addEventListener('click', () => {
            row.remove();
            sync();
            renderMeasurements();
        });
        const actions = document.createElement('div');
        actions.className = 'fault-rule-actions';
        const enabled = field(actions, 'Enabled', 'checkbox', 'enabled');
        enabled.checked = rule.enabled ?? true;
        actions.append(remove);
        row.append(actions);
        row.addEventListener('input', () => {
            sync();
            renderMeasurements();
        });
        row.addEventListener('change', () => {
            sync();
            renderMeasurements(row);
        });
        list.append(row);
        conditionFields();
        if (source.value === 'snmp' && oid.value) readParameter();
    }
    saved.forEach((rule, index) => addRule(rule, index));
    form.addEventListener('icct-fault-saved', (event) => {
        savedPollingCounts = countRulesByOid(JSON.parse(event.detail), 'snmp');
        if (hidden.value !== event.detail) {
            renderOids();
            return;
        }
        [...list.children].forEach((row) => (row.dataset.alarmSaved = '1'));
        renderMeasurements();
    });
    sync();
    renderMeasurements();
    document.querySelector('#add-fault-rule').addEventListener('click', () => {
        if (list.children.length < 100) {
            const rule = { source: selectedSource };
            if (selectedRow)
                for (const key of [
                    'name',
                    'parameter',
                    'oid',
                    'units',
                    'scale',
                    'template',
                    'metric',
                ]) {
                    const value = selectedRow.querySelector('[data-' + key + ']').value;
                    rule[
                        key === 'template' ? 'template_id' : key === 'metric' ? 'metric_id' : key
                    ] = value;
                }
            addRule(rule);
            renderMeasurements(list.children[list.children.length - 1]);
        }
    });
    new MutationObserver(() => {
        for (const row of list.children) {
            const select = row.querySelector('[data-template]'),
                value = select.value,
                allowed = associated();
            for (const item of catalogue)
                if (
                    allowed.has(Number(item.template_id)) &&
                    ![...select.options].some((o) => o.value === String(item.template_id))
                )
                    option(select, item.template_id, item.template_name);
            for (const o of [...select.options])
                if (o.value && !allowed.has(Number(o.value)) && o.value !== value) o.remove();
        }
        sync();
    }).observe(document.querySelector('.graph-accordion-list'), {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['hidden'],
    });
    const basic = document.querySelector('#device-form');
    const deviceSummary = document.querySelector('#configuration-device-summary');
    const protocolSummary = document.querySelector('#configuration-protocol-summary');
    // Explicit allowlist: never copy credentials or hidden form metadata into the summary.
    const settingNames = new Set([
        'snmp_version',
        'snmp_port',
        'snmp_timeout',
        'max_oids',
        'availability_method',
        'ping_method',
        'ping_timeout',
        'ping_retries',
        'snmp_auth_protocol',
        'snmp_priv_protocol',
        'snmp_security_level',
        'interval_seconds',
        'stale_seconds',
        'refresh_seconds',
        'port',
        'connect_timeout',
        'auth_method',
        'command_timeout',
        'retries',
        'keepalive',
        'monitoring',
        'serial_interface',
        'baud_rate',
        'data_bits',
        'parity',
        'stop_bits',
        'flow_control',
        'response_timeout',
        'serial_retries',
        'serial_interval',
        'serial_protocol',
        'version',
        'active_timeout',
        'inactive_timeout',
        'sampling_rate',
        'timeout',
        'poll_interval',
        'authentication',
    ]);
    function valueText(control) {
        if (control.type === 'checkbox') return control.checked ? 'Enabled' : 'Disabled';
        if (control.type === 'radio' && !control.checked) return null;
        if (control.tagName === 'SELECT')
            return control.selectedOptions[0]?.textContent.trim() || 'Not selected';
        if (control.type === 'radio')
            return control.closest('label')?.textContent.trim() || control.value;
        return control.value.trim() || 'Not set';
    }
    function entry(target, label, value) {
        const dt = document.createElement('dt'),
            dd = document.createElement('dd');
        dt.textContent = label;
        dd.textContent = value;
        const group = document.createElement('div');
        group.append(dt, dd);
        target.append(group);
    }
    function configurationSummary() {
        deviceSummary.replaceChildren();
        [
            ['Device Name', 'description'],
            ['Address', 'hostname'],
            ['Device Type', 'device_type'],
            ['Segment', 'category_id'],
            ['Site', 'site_id'],
            ['Rack', 'rack_id'],
            ['Rack Placement', 'rack_position'],
            ['Device Status', 'enabled'],
        ].forEach(([label, name]) => {
            const control = basic.querySelector('[name="' + name + '"]');
            if (control) entry(deviceSummary, label, valueText(control));
        });
        protocolSummary.replaceChildren();
        const protocols = [
            ...document.querySelectorAll('#protocol-workspace .protocol-item'),
        ].filter((item) => !item.hidden);
        protocols.forEach((item) => {
            const section = document.createElement('section');
            section.className = 'configuration-protocol';
            const heading = document.createElement('h4');
            heading.textContent =
                item.querySelector('summary > span:not(.accordion-chevron)')?.textContent.trim() ||
                item.id.replace('protocol-', '').toUpperCase();
            section.append(heading);
            const enable = item.querySelector('.protocol-enable input'),
                pending = item.querySelector('.pending-protocol-status');
            const state = document.createElement('p');
            state.textContent = pending
                ? 'Not available yet'
                : enable && !enable.checked
                  ? 'Disabled'
                  : 'Enabled';
            section.append(state);
            const settings = document.createElement('dl');
            settings.className = 'configuration-summary';
            const names = new Set();
            item.querySelectorAll('input,select').forEach((control) => {
                if (
                    !settingNames.has(control.name) ||
                    names.has(control.name) ||
                    control.type === 'hidden' ||
                    control.type === 'password' ||
                    item.contains(control.closest('[hidden]'))
                )
                    return;
                const value = valueText(control);
                if (value === null) return;
                const caption = control.closest('.field')?.querySelector('.field-label');
                const label = caption
                    ? [...caption.childNodes]
                          .filter((node) => node.nodeType === 3)
                          .map((node) => node.textContent)
                          .join('')
                          .trim()
                    : control
                          .closest('.radio-group')
                          ?.querySelector('legend')
                          ?.textContent.trim() || control.name.replaceAll('_', ' ');
                entry(settings, control.name === 'monitoring' ? 'SSH Monitoring' : label, value);
                names.add(control.name);
            });
            section.append(settings);
            protocolSummary.append(section);
        });
        if (!protocols.length) {
            const message = document.createElement('p');
            message.textContent = 'No protocols selected. Add protocols in Protocol Config.';
            protocolSummary.append(message);
        }
    }
    basic.addEventListener('input', configurationSummary);
    basic.addEventListener('change', () => queueMicrotask(configurationSummary));
    document.querySelector('#protocol-workspace').addEventListener('input', configurationSummary);
    document
        .querySelector('#protocol-workspace')
        .addEventListener('change', () => queueMicrotask(configurationSummary));
    new MutationObserver(configurationSummary).observe(
        document.querySelector('#protocol-workspace'),
        { subtree: true, attributes: true, attributeFilter: ['hidden'] },
    );
    configurationSummary();
    const backups = JSON.parse(document.querySelector('#configuration-backups').textContent);
    document.querySelector('#fcaps-configuration').addEventListener('click', (event) => {
        const button = event.target.closest('.configuration-download');
        if (!button) return;
        const backup = backups.find((item) => item.id === button.dataset.backupId);
        if (!backup) return;
        const url = URL.createObjectURL(
            new Blob([JSON.stringify(backup, null, 2)], { type: 'application/json' }),
        );
        const link = document.createElement('a');
        link.href = url;
        link.download =
            'device-' +
            document.querySelector('#device-wizard').dataset.deviceId +
            '-settings-' +
            backup.id +
            '.json';
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
    document.querySelector('#configuration-backup').addEventListener('click', async (event) => {
        const button = event.currentTarget,
            result = document.querySelector('#configuration-backup-result');
        if (basic.dataset.staticPreview) {
            result.textContent = 'Backups are available in the live application.';
            return;
        }
        button.disabled = true;
        result.textContent = 'Backing up saved settings…';
        try {
            const data = new FormData();
            data.set('host_id', document.querySelector('#device-wizard').dataset.deviceId);
            data.set('action', 'configuration_backup');
            const token = basic.querySelector('[name="__csrf_magic"]');
            if (token) data.set(token.name, token.value);
            const response = await fetch('inventory/controllers/wizard_save.php', {
                method: 'POST',
                body: data,
            });
            const body = await response.json();
            if (!response.ok || !body.ok) throw new Error(body.error || 'Backup failed.');
            backups.splice(0, backups.length, ...body.backups);
            const rows = document.querySelector('#configuration-history-rows');
            rows.replaceChildren();
            for (const backup of backups) {
                const row = document.createElement('tr');
                for (const value of [
                    backup.time,
                    backup.user || 'User ' + backup.user_id,
                    backup.action,
                ]) {
                    const cell = document.createElement('td');
                    cell.textContent = value;
                    row.append(cell);
                }
                const cell = document.createElement('td'),
                    download = document.createElement('button');
                download.type = 'button';
                download.className = 'button configuration-download';
                download.dataset.backupId = backup.id;
                download.textContent = 'Download Backup';
                cell.append(download);
                const details = document.createElement('details'),
                    summary = document.createElement('summary');
                summary.textContent = Object.keys(backup.changes).length + ' changed settings';
                details.append(summary);
                for (const [field, change] of Object.entries(backup.changes)) {
                    const text = document.createElement('p');
                    text.textContent =
                        field +
                        ': ' +
                        (change.before ?? 'Not set') +
                        ' → ' +
                        (change.after ?? 'Not set');
                    details.append(text);
                }
                cell.append(details);
                row.append(cell);
                rows.append(row);
            }
            result.textContent = 'Saved settings backup created. Unsaved drafts remain unchanged.';
        } catch (error) {
            result.textContent = error.message;
        } finally {
            button.disabled = false;
        }
    });
    const tabs = [...panel.querySelectorAll('[role=tab]')];
    function activate(tab) {
        configurationSummary();
        tabs.forEach((item) => {
            const selected = item === tab;
            item.setAttribute('aria-selected', String(selected));
            item.tabIndex = selected ? 0 : -1;
            document.getElementById(item.getAttribute('aria-controls')).hidden = !selected;
        });
    }
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activate(tab));
        tab.addEventListener('keydown', (event) => {
            let next;
            if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
            if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
            if (event.key === 'Home') next = 0;
            if (event.key === 'End') next = tabs.length - 1;
            if (next !== undefined) {
                event.preventDefault();
                activate(tabs[next]);
                tabs[next].focus();
            }
        });
    });
})();
