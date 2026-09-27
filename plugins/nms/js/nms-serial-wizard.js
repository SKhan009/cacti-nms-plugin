(function () {
    'use strict';
    function init() {
        var form = document.getElementById('nmsSerialWizard');
        if (!form) return;
        var data = JSON.parse(document.getElementById('nmsSerialFormData').textContent);
        var connection = form.elements.connection_id;
        var preset = document.getElementById('nmsSerialPresetFields');
        var settingNames = ['baud_rate','data_bits','parity','stop_bits','flow_control','timeout_ms','retries'];
        var labels = {baud_rate:'Baud rate',data_bits:'Data bits',parity:'Parity',stop_bits:'Stop bits',flow_control:'Flow control',timeout_ms:'Timeout (ms)',retries:'Read retries'};
        function pairs(target, values) {
            target.replaceChildren();
            values.forEach(function (pair) {
                var key=document.createElement('dt'), value=document.createElement('dd');
                key.textContent=pair[0]; value.textContent=pair[1]; target.append(key,value);
            });
        }
        form.querySelectorAll('[data-serial-association]').forEach(function (panel) {
            var selected = panel.querySelector('[data-serial-selected]');
            var choice = panel.querySelector('[data-serial-choice]');
            var add = panel.querySelector('[data-serial-add]');
            var pending = panel.querySelector('[data-serial-pending]');
            var empty = panel.querySelector('[data-serial-empty]');
            var hasSaved = !!panel.querySelector('.nms-association-row:not(.heading)');
            var status = panel.querySelector('[data-serial-selection-status]');
            function render() {
                pending.replaceChildren();
                Array.from(selected.selectedOptions).forEach(function (option) {
                    var row = document.createElement('div');
                    row.className = 'nms-association-row graph';
                    var name = document.createElement('strong'); name.textContent = option.textContent;
                    var state = document.createElement('span'); state.textContent = 'Added on save';
                    var action = document.createElement('span');
                    var remove = document.createElement('button'); remove.type = 'button'; remove.textContent = 'Remove';
                    remove.setAttribute('aria-label', 'Remove ' + option.textContent + ' from selection');
                    remove.addEventListener('click', function () { option.selected = false; status.textContent = option.textContent + ' removed from selection.'; render(); });
                    action.append(remove); row.append(name, state, action); pending.append(row);
                });
                empty.hidden = hasSaved || selected.selectedOptions.length > 0;
                review();
                add.disabled = !choice.value || Array.from(selected.selectedOptions).some(function (o) { return o.value === choice.value; });
            }
            choice.addEventListener('change', render);
            add.addEventListener('click', function () {
                var option = Array.from(selected.options).find(function (o) { return o.value === choice.value; });
                if (!option || option.selected) return;
                option.selected = true;
                status.textContent = option.textContent + ' added to selection.';
                choice.value = ''; choice.dispatchEvent(new Event('change', {bubbles:true}));
            });
            render();
        });
        function profileSettings() {
            var profile=data.profiles[form.elements.profile_id.value];
            if (!profile) return;
            settingNames.forEach(function (name) { form.elements[name].value=profile.settings[name]; });
            form.elements.profile_revision.value=profile.revision;
        }
        function review() {
            var names={description:'Device name',hostname:'Hostname / identifier',host_template_id:'Device Template',site_id:'Site',node_id:'Node',poller_id:'Data Collector',connection_id:'Connection',endpoint:'Endpoint',device_address:'Modbus address',equipment_profile_id:'Reading profile',interval_seconds:'Reading interval (seconds)'};
            var values=Object.keys(names).map(function(name){
                var el=form.elements[name];
                return [names[name],el.tagName==='SELECT'?(el.selectedOptions[0]?.textContent || 'None'):(el.value || 'Default')];
            });
            var shared=data.connections[connection.value];
            if(shared) values=values.map(function(pair){return pair[0]==='Endpoint'?['Endpoint',shared.endpoint]:pair;});
            ['graph_template_ids[]','data_query_ids[]'].forEach(function(name){
                values.push([name==='graph_template_ids[]'?'Add graph templates':'Add data queries',Array.from(form.elements[name].selectedOptions).map(function(o){return o.textContent;}).join(', ') || 'None']);
            });
            values.push(['Create reading graphs',form.elements.create_serial_graphs.checked?'Yes':'No']);
            pairs(document.getElementById('nmsSerialReview'),values);
        }
        function equipment() {
            var profile=data.equipment[form.elements.equipment_profile_id.value];
            var target=document.getElementById('nmsSerialReadingRows');target.replaceChildren();
            document.getElementById('nmsSerialEquipmentInfo').textContent=profile?profile.manufacturer+' / '+profile.model+' · '+profile.manual_reference:'No register readings will be collected.';
            (profile?JSON.parse(profile.fields_json):[]).forEach(function(field){
                var row=document.createElement('tr');
                [field.label,field.key,field.offset,field.function===3?'03 · Holding':'04 · Input',field.type+(field.unit?' / '+field.unit:''),field.min+' … '+field.max].forEach(function(text){var cell=document.createElement('td');cell.textContent=text;row.appendChild(cell);});
                target.appendChild(row);
            });
        }
        function sync() {
            var shared=data.connections[connection.value];
            var isShared=connection.value!=='0';
            preset.hidden=isShared;
            preset.querySelectorAll('input,select').forEach(function(el){el.disabled=isShared;});
            var endpoint=document.getElementById('nmsSerialEndpointFields');endpoint.hidden=isShared;
            endpoint.querySelectorAll('input,select').forEach(function(el){el.disabled=isShared;});
            if(shared) form.elements.poller_id.value=shared.poller_id;
            form.elements.poller_id.disabled=isShared;
            var tcp=form.elements.transport.value==='rtu_tcp';
            form.elements.port.disabled=isShared || !tcp;
            form.elements.port.closest('label').hidden=!tcp;
            var custom=!isShared && form.elements.custom_serial_settings.checked;
            settingNames.forEach(function(name){form.elements[name].disabled=!custom;});
            var profile=data.profiles[form.elements.profile_id.value];
            var settings=shared?shared.settings:(profile?profile.settings:null);
            if(custom) { settings={};settingNames.forEach(function(name){settings[name]=form.elements[name].value;}); }
            pairs(document.getElementById('nmsSerialEffective'),settings?settingNames.map(function(name){return [labels[name],settings[name]];}):[['Settings','Select a serial profile.']]);
            document.getElementById('nmsSerialConnectionInfo').textContent=shared?'Shared endpoint: '+shared.endpoint+'. Its saved settings apply to every device on this bus.':(tcp?'Enter a literal gateway IP. Configure matching serial settings on the gateway.':'Use the serial port on the assigned collector, preferably /dev/serial/by-id/.');
            Array.from(form.elements.node_id.options).forEach(function(option){
                option.disabled=option.value!=='0' && option.dataset.site!==form.elements.site_id.value;
            });
            equipment();review();
        }
        form.addEventListener('change',function(event){
            if(event.target.name==='profile_id') profileSettings();
            if(event.target.name==='custom_serial_settings' && !event.target.checked) profileSettings();
            sync();
        });
        form.addEventListener('input',review);
        form.addEventListener('submit',function(event){
            // Reveal collapsed native fields before reporting validation errors.
            var invalid=Array.from(form.elements).find(function(el){return !el.disabled && !el.checkValidity();});
            if(invalid){event.preventDefault();var details=invalid.closest('details');if(details) details.open=true;invalid.reportValidity();return;}
            if(form.elements.create_serial_graphs.checked && form.elements.equipment_profile_id.value==='0') {
                event.preventDefault();form.elements.equipment_profile_id.setCustomValidity('Select a reading profile to create graphs.');form.elements.equipment_profile_id.reportValidity();
            }
        });
        form.elements.equipment_profile_id.addEventListener('change',function(){this.setCustomValidity('');});
        form.noValidate=true;
        if(!data.submitted) profileSettings();
        sync();
    }
    if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
