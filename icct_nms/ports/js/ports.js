"use strict";
(() => {
  const panel=document.querySelector('#device-ports');if(!panel)return;
  const basic=document.querySelector('#device-form'),wizard=document.querySelector('#device-wizard'),form=document.querySelector('#port-monitor-form');
  const rows=document.querySelector('#port-rows'),enabled=document.querySelector('#port-monitor-enabled'),serialized=form.elements.port_settings;
  const settings=JSON.parse(serialized.value);// Interface indices are keys, not array positions (ifIndex starts at 1).
  settings.ports=Object.fromEntries(Object.entries(settings.ports||{}).filter(([,row])=>row!==null));
  let observation=JSON.parse(document.querySelector('#port-observations').textContent),changedEndpoint=false;
  const originalHostname=basic.elements.hostname.value,originalPoller=basic.elements.poller_id.value;
  function sync(){settings.enabled=enabled.checked;serialized.value=JSON.stringify(settings);document.querySelector('#port-monitor-label').textContent=enabled.checked?'Yes':'No';}
  function field(title,tip,control){const box=document.createElement('label');box.className='field';const label=document.createElement('span');label.className='field-label';label.append(document.createTextNode(title+' '));const info=document.createElement('span');info.className='field-info';info.tabIndex=0;info.dataset.tooltip=tip;info.setAttribute('aria-label','About '+title);info.textContent='ⓘ';label.append(info);box.append(label,control);return box;}
  function readonly(value,tip){const input=document.createElement('input');input.type='text';input.value=String(value);input.readOnly=true;input.className='port-identity';input.title=tip;return input;}
  function checkbox(row,key){const wrap=document.createElement('span');wrap.className='port-alarm-checkbox';const input=document.createElement('input');input.type='checkbox';input.checked=!!row[key];input.addEventListener('change',()=>{row[key]=input.checked;sync();updateValidation();});wrap.append(input,document.createTextNode('Enable'));return wrap;}
  const severities=[['','Select Severity'],['Critical','Critical'],['Major','Major'],['Minor','Minor'],['Warning','Warning'],['Information','Info']];
  function severity(row,key){const wrap=document.createElement('span');wrap.className='select-wrap';const select=document.createElement('select');severities.forEach(([value,label])=>select.add(new Option(label,value)));select.value=row[key]||'';select.dataset.portSeverity=key;select.addEventListener('change',()=>{row[key]=select.value;sync();});wrap.append(select);return wrap;}
  function updateValidation(){rows.querySelectorAll('.port-config-row').forEach(box=>{const rule=settings.ports[box.dataset.index];box.querySelectorAll('[data-port-severity]').forEach(select=>select.required=enabled.checked&&!!rule?.alarm);});}
  function render(){
    const changed=changedEndpoint||basic.elements.hostname.value!==originalHostname||basic.elements.poller_id.value!==originalPoller;
    const ports=changed?[]:observation.ports;rows.replaceChildren();
    for(const p of ports){
      const saved=settings.ports[p.index];const rule=saved?.name===p.name?saved:{name:p.name,alarm:false,cnms:false,oper_severity:'',admin_severity:''};
      // Discovery is not a configuration change; default rows serialize only after a user edit.
      const box=document.createElement('div');box.className='port-config-row';box.dataset.index=String(p.index);
      const bind=()=>{settings.ports[p.index]=rule;};
      box.addEventListener('change',()=>{bind();sync();updateValidation();});
      const status=p.status||'Unknown';
      box.append(field('Port Number','Device interface index. Filled from discovery.',readonly(p.panel_port||p.index,'ifIndex '+p.index)),field('Port Name','Interface name reported by the device. Current status: '+status+'.',readonly(p.name,status)),field('Enable Alarm','Raise a fault when this interface is operationally or administratively down.',checkbox(rule,'alarm')),field('Escalate to CNMS?','Save the forwarding preference. CNMS delivery requires an integration.',checkbox(rule,'cnms')),field('If, Operational Down','Severity when the device reports link down.',severity(rule,'oper_severity')),field('If, Admin Down','Severity when the interface is administratively disabled.',severity(rule,'admin_severity')));
      const clear=document.createElement('select');clear.add(new Option('Clear','Normal'));clear.disabled=true;const wrap=document.createElement('span');wrap.className='select-wrap';wrap.append(clear);const clearField=field('When, UP Status','An enabled interface with link up clears its port fault.',wrap);clearField.classList.add('port-clear-field');box.append(clearField);rows.append(box);
    }
    if(!ports.length){const empty=document.createElement('p');empty.className='empty-state';empty.textContent=changed?'Save connection settings to discover interfaces.':'No interfaces discovered yet.';rows.append(empty);}
    updateValidation();
  }
  enabled.addEventListener('change',()=>{sync();updateValidation();});
  basic.elements.hostname.addEventListener('input',render);basic.elements.poller_id.addEventListener('change',render);
  ['#protocol-snmp','#protocol-ssh'].forEach(selector=>document.querySelector(selector)?.addEventListener('change',()=>{changedEndpoint=true;render();}));
  let loading=false;
  async function refresh(){
    if(loading||location.hash!=='#ports'||document.hidden||basic.dataset.staticPreview||!Number(wizard.dataset.deviceId))return;
    loading=true;
    try{const response=await fetch('ports/controllers/ports.php?id='+encodeURIComponent(wizard.dataset.deviceId),{credentials:'same-origin',cache:'no-store'});if(!response.headers.get('content-type')?.includes('application/json'))throw new Error('Reload the page and check your Cacti session.');const payload=await response.json();if(!response.ok||!payload.ok)throw new Error(payload.error||'Interfaces could not load.');observation=payload.data;document.querySelector('#port-error').hidden=true;render();}
    catch(error){const status=document.querySelector('#port-error');status.textContent=error.message;status.hidden=false;}
    finally{loading=false;}
  }
  window.addEventListener('hashchange',refresh);setInterval(refresh,30000);render();refresh();
})();
