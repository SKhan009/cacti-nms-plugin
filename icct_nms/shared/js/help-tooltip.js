'use strict';

// Initialize shared help independently of feature-specific scripts.
(() => {
    const fieldHelp = {
        source_address:
            'IP address of the sender as seen by the receiver, or the hostname in its Syslog header. Use the sender IP when available.',
        transport: 'Accept UDP, TCP, or both on the collector’s Syslog port 514.',
        max_severity:
            'Store this severity and all more urgent levels. Informational includes levels 0–6; Debug includes all levels.',
        description: 'Name used to identify this device in inventory.',
        short_name:
            'Created automatically from the device name. You can enter your own short name, up to 8 characters.',
        hostname: 'Hostname or IP address used by the configured protocols to contact this device.',
        category_id: 'Choose the segment to populate the associated device type.',
        device_type: 'Populated from the selected segment and its saved classification.',
        site_id: 'Site where this device is installed.',
        rack_id: 'Rack containing this device.',
        rack_position: 'Starting rack unit occupied by this device.',
        mac_address:
            'Populates from configured SNMP when reported. A manual value takes precedence.',
        serial_number:
            'Populates from configured SNMP when reported. A manual value takes precedence.',
        chassis_id:
            'Populates from configured SNMP when reported. A manual value takes precedence.',
        host_template_id: 'Search and select the device template used for this device.',
        poller_id: 'Collector responsible for polling this device.',
        device_threads: 'Number of collection threads allocated to this device.',
        cross_launch_url: 'Saved HTTP or HTTPS address opened by the Cross Launch URL action.',
        notes: 'Optional notes describing this device.',
        enabled:
            'Enable polling for this device, or disable it while retaining its saved configuration.',
        mode: 'Collect neighbours using SNMP.',
        interval_seconds: 'Use a multiple of the poller interval.',
        stale_seconds: 'At least twice the collection interval.',
        refresh_seconds: 'Refresh displayed discovery data.',
        snmp_version: 'Choose the version supported by the device.',
        snmp_security_level:
            'V3: no security, authentication only, or authentication and encryption.',
        connection_id: 'Choose a saved endpoint on this collector.',
        serial_interface: 'Direct ports: RS-232 or RS-485. TCP gateways manage this setting.',
        serial_protocol: 'RTU or ASCII; TCP gateways support RTU only.',
        baud_rate: 'Direct-port speed: 300–230400 baud; match the device.',
        data_bits: 'RTU: 8 bits. ASCII: 7–8 bits.',
        parity: 'Match the device: no parity, Even, Odd, Mark or Space.',
        stop_bits: '1–2 stop bits; match the device.',
        flow_control: 'RS-485: None. RS-232: None or RTS/CTS.',
        response_timeout: 'Wait for the serial response.',
        serial_retries: 'Retry failed serial reads.',
        serial_interval: 'Time between serial polls.',
        device_address: 'Modbus unit address.',
        serial_gateway_address: 'Address from the selected TCP gateway; read-only.',
        serial_gateway_port: 'Gateway port: 1–65535; read-only.',
        port: 'SSH server port.',
        connect_timeout: 'Wait for the SSH connection.',
        command_timeout: 'Wait for an SSH command.',
        retries: 'Retry failed SSH connections.',
        keepalive: 'SSH keepalive interval; 0 disables it.',
        auth_method: 'Use a password or private key.',
        username: 'SSH account on the device.',
        secret: 'Blank keeps the saved password.',
        passphrase: 'Private-key passphrase; blank keeps the saved value.',
        snmp_auth_protocol: 'SNMP V3 authentication algorithm.',
        snmp_username: 'SNMP V3 account name.',
        snmp_password:
            'At least 8 characters when authentication is enabled; blank keeps saved value.',
        snmp_password_confirm: 'Repeat the new authentication password.',
        snmp_priv_protocol: 'SNMP V3 encryption algorithm.',
        snmp_priv_passphrase:
            'At least 8 characters when privacy is enabled; blank keeps saved value.',
        snmp_priv_confirm: 'Repeat the new privacy passphrase.',
        snmp_context: 'SNMP V3 context; leave blank for the default.',
        snmp_community: 'Match the device’s SNMP V1/V2 community.',
        snmp_port: 'SNMP UDP port.',
        snmp_timeout: 'Wait for an SNMP response.',
        max_oids: 'OIDs per SNMP request; choose a listed value.',
        availability_method: 'How device reachability is checked.',
        ping_method: 'Probe used for reachability checks.',
        ping_timeout: 'Wait for a reachability response.',
        ping_retries: 'Retry failed reachability probes.',
        ping_count: 'Packets per ping test.',
        mtr_cycles:
            'Readings per MTR report, sampled once per second. Automatic monitoring repeats the report at the saved monitoring interval.',
        mtr_interval:
            'Seconds between automatic MTR reports for this device (60–3600). Monitoring runs on its assigned Cacti collector while the page is closed.',
        trace_hops: 'Maximum traceroute hops.',
        bandwidth_seconds: 'Bandwidth-test duration.',
    };
    // Match Cacti's Site field help without reusing device-specific descriptions.
    const siteFieldHelp = {
        name: 'The primary name for the site.',
        address1: 'The primary address for the site.',
        address2: 'Additional address information for the site.',
        city: 'The city or locality for the site.',
        state: 'The state for the site.',
        postal_code: 'The postal or zip code for the site.',
        country: 'The country for the site.',
        timezone: 'The timezone for the site.',
        latitude: 'The latitude for this site, in degrees.',
        longitude: 'The longitude for this site, in degrees.',
        zoom: 'Default map zoom for this site. Some regions support a maximum zoom of 15.',
        notes: 'Additional notes related to this site.',
        alternate_id: 'An alternate name used to describe this site.',
    };
    function addFieldHelp(label, text) {
        const icon = document.createElement('span');
        icon.className = 'field-info';
        icon.tabIndex = 0;
        icon.setAttribute('role', 'img');
        icon.setAttribute('aria-label', `Information about ${label.textContent.trim()}`);
        icon.dataset.tooltip = text;
        icon.innerHTML =
            '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8"/><path d="M10 9v5M10 6v.5"/></svg>';
        // A help icon inside a label must not toggle or focus the labelled form control.
        icon.addEventListener('click', (event) => event.preventDefault());
        label.append(icon);
    }
    document.querySelectorAll('[data-field-help]').forEach((label) => {
        const control = label.closest('label.field')?.querySelector('input, select, textarea');
        if (control && !control.hasAttribute('aria-label') && !control.hasAttribute('aria-labelledby')) {
            control.setAttribute('aria-label', label.textContent.trim());
        }
        addFieldHelp(label, label.dataset.fieldHelp);
    });
    document.querySelectorAll('.field-label, .radio-group legend').forEach((label) => {
        const field = label
            .closest('.field, .enable-field, .radio-group')
            ?.querySelector('input, select, textarea');
        let help =
            (label.closest('#site-editor') ? siteFieldHelp[field?.name] : fieldHelp[field?.name]) ||
            '';
        if (field?.type === 'number' && !field.readOnly) {
            const min = field.getAttribute('min'),
                max = field.getAttribute('max');
            const caption = label.textContent.trim();
            const unit = /\(ms\)/i.test(caption)
                ? ' ms'
                : /\(sec(?:onds)?\)/i.test(caption)
                  ? ' sec'
                  : '';
            if (min !== null || max !== null) {
                const range =
                    min !== null && max !== null
                        ? `Range: ${min}–${max}${unit}.`
                        : min !== null
                          ? `Minimum: ${min}${unit}.`
                          : `Max: ${max}${unit}.`;
                help = `${help} ${range}`.trim();
            }
        }
        if (field?.name === 'max_oids') {
            const values = Array.from(field.options)
                .map((option) => Number(option.value))
                .filter((value) => Number.isFinite(value) && value > 0);
            if (values.length) help += ` Range: ${Math.min(...values)}–${Math.max(...values)}.`;
        }
        if (!help || label.querySelector('.field-info')) return;
        if (!field.hasAttribute('aria-label') && !['checkbox', 'radio'].includes(field.type))
            field.setAttribute('aria-label', label.textContent.trim());
        addFieldHelp(label, help);
    });
    document
        .querySelectorAll(
            '.icon-button, .export-button, .row-actions [aria-label], .delete-protocol, .password-toggle, #page-previous, #page-next, [data-close-dialog]',
        )
        .forEach((icon) => {
            if (icon.matches('.header-menu > button')) return;
            const text = icon.getAttribute('aria-label');
            if (text) icon.dataset.tooltip = text;
        });
    // Give each diagnostic option an explicit help target without changing its checkbox.
    document.querySelectorAll('.diagnostic-methods .check-row[data-tooltip]').forEach((label) => {
        addFieldHelp(label, label.dataset.tooltip);
        label.removeAttribute('data-tooltip');
    });
    const tooltip = document.createElement('div');
    tooltip.className = 'help-tooltip';
    tooltip.id = 'icct-help-tooltip';
    tooltip.setAttribute('role', 'tooltip');
    tooltip.hidden = true;
    document.body.append(tooltip);
    let activeHelp = null;
    const hideHelp = () => {
        if (activeHelp) activeHelp.removeAttribute('aria-describedby');
        activeHelp = null;
        tooltip.hidden = true;
    };
    const positionHelp = (target) => {
        const box = target.getBoundingClientRect();
        const size = tooltip.getBoundingClientRect();
        const left = target.classList.contains('heading-help')
            ? box.left
            : box.left + box.width / 2 - size.width / 2;
        tooltip.style.left = `${Math.max(8, Math.min(left, window.innerWidth - size.width - 8))}px`;
        const above = box.top - size.height - 8;
        tooltip.style.top = `${above >= 8 ? above : Math.min(box.bottom + 8, window.innerHeight - size.height - 8)}px`;
    };
    const showHelp = (target) => {
        hideHelp();
        activeHelp = target;
        tooltip.textContent = target.classList.contains('field-info')
            ? target.dataset.tooltip
            : target.getAttribute('aria-label') || target.dataset.tooltip;
        tooltip.hidden = false;
        target.setAttribute('aria-describedby', tooltip.id);
        positionHelp(target);
    };
    document.addEventListener('mouseover', (event) => {
        const target = event.target.closest('[data-tooltip]');
        if (target && target !== activeHelp) showHelp(target);
    });
    document.addEventListener('mouseout', (event) => {
        if (activeHelp && !activeHelp.contains(event.relatedTarget)) hideHelp();
    });
    document.addEventListener('focusin', (event) => {
        const target = event.target.closest('[data-tooltip]');
        if (target) showHelp(target);
        else hideHelp();
    });
    document.addEventListener('focusout', hideHelp);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') hideHelp();
    });
    document.addEventListener('scroll', () => {
        if (activeHelp && document.activeElement === activeHelp) {
            const box = activeHelp.getBoundingClientRect();
            if (box.bottom > 0 && box.top < window.innerHeight) {
                positionHelp(activeHelp);
                return;
            }
        }
        hideHelp();
    }, true);
    window.addEventListener('resize', hideHelp);
})();
