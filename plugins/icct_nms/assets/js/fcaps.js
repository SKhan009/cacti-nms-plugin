"use strict";
(() => {
  const panel=document.querySelector('#device-fcaps');if(!panel)return;
  const form=document.querySelector('#fault-form'),list=document.querySelector('#fault-rule-list'),hidden=form.elements.fault_rules;
  const catalogue=JSON.parse(document.querySelector('#fault-catalogue').textContent),saved=JSON.parse(document.querySelector('#saved-fault-rules').textContent);
  function associated(){return new Set([...document.querySelectorAll('.graph-association:not([hidden]) [name=graph_template_id],.wizard-pending[data-graph-template-id]')].map(node=>Number(node.value||node.dataset.graphTemplateId)));}
  function sync(){hidden.value=JSON.stringify([...list.children].map(row=>({template_id:row.querySelector('[data-template]').value,metric_id:row.querySelector('[data-metric]').value,minimum:row.querySelector('[data-minimum]').value,maximum:row.querySelector('[data-maximum]').value,name:row.querySelector('[data-name]').value,corrective_action:row.querySelector('[data-corrective-action]').value,email_recipients:row.querySelector('[data-email-recipients]').value,email:row.querySelector('[data-email]').checked,audio:row.querySelector('[data-audio]').checked,severity:row.querySelector('[data-severity]').value,enabled:row.querySelector('[data-enabled]').checked})));}
  function option(select,value,label){const o=document.createElement('option');o.value=String(value);o.textContent=label;select.append(o);}
  function field(row,label,kind,key){const wrapper=document.createElement('label');wrapper.className='field';const caption=document.createElement('span');caption.className='field-label';caption.textContent=label;const input=document.createElement(kind==='select'?'select':'input');input.setAttribute('data-'+key,'');if(kind!=='select'){input.type=kind;if(kind==='number')input.step='any';}wrapper.append(caption,input);row.append(wrapper);return input;}
  function addRule(rule={}) {
    const row=document.createElement('div');row.className='fault-rule';
    const name=field(row,'Rule Name','text','name');name.maxLength=120;name.placeholder='Enter rule name';name.value=rule.name||'';
    const template=field(row,'Graph Template','select','template'),metric=field(row,'Data Source','select','metric'),minimum=field(row,'Minimum','number','minimum'),maximum=field(row,'Maximum','number','maximum'),severity=field(row,'Severity','select','severity');
    template.required=metric.required=severity.required=true;minimum.placeholder=maximum.placeholder='No bound';
    option(template,'','Select graph template');const allowed=associated();const seen=new Set();
    catalogue.forEach(item=>{if(allowed.has(Number(item.template_id))&&!seen.has(item.template_id)){seen.add(item.template_id);option(template,item.template_id,item.template_name);}});
    if(rule.template_id&&!allowed.has(Number(rule.template_id)))option(template,rule.template_id,'Removed graph template — remove this rule');
    template.value=String(rule.template_id||'');
    option(severity,'','Select severity');['Information','Minor','Warning','Major','Critical'].forEach(value=>option(severity,value,value));severity.value=rule.severity||'';
    minimum.value=rule.minimum ?? '';maximum.value=rule.maximum ?? '';
    function metrics(reset){metric.replaceChildren();option(metric,'','Select data source');catalogue.filter(item=>String(item.template_id)===template.value).forEach(item=>option(metric,item.metric_id,item.data_source_name));if(!reset)metric.value=String(rule.metric_id||'');}
    function defaults(){const item=catalogue.find(item=>String(item.template_id)===template.value&&String(item.metric_id)===metric.value);minimum.value=item&&Number.isFinite(Number(item.rrd_minimum))&&item.rrd_minimum!==''?item.rrd_minimum:'';maximum.value=item&&Number.isFinite(Number(item.rrd_maximum))&&item.rrd_maximum!==''?item.rrd_maximum:'';sync();}
    metrics(false);template.addEventListener('change',()=>{metrics(true);minimum.value=maximum.value='';sync();});metric.addEventListener('change',defaults);
    const enabled=field(row,'Enabled','checkbox','enabled');enabled.checked=rule.enabled ?? true;
    const remove=document.createElement('button');remove.type='button';remove.className='button';remove.textContent='Remove';remove.addEventListener('click',()=>{row.remove();sync();});
    const guidance=field(row,'Corrective Guidance (optional)','text','corrective-action');guidance.maxLength=1000;guidance.placeholder='Suggested action when this threshold is exceeded';guidance.value=rule.corrective_action||'';guidance.closest('.field').classList.add('fault-guidance');
    const email=field(row,'Email','checkbox','email'),audio=field(row,'Audio','checkbox','audio');email.checked=!!rule.email;audio.checked=!!rule.audio;
    const recipients=field(row,'Email Recipients','text','email-recipients');recipients.maxLength=1000;recipients.placeholder='operator@example.com, team@example.com';recipients.value=rule.email_recipients||'';recipients.closest('.field').classList.add('fault-recipients');
    function emailRequirement(){recipients.required=email.checked;}email.addEventListener('change',emailRequirement);emailRequirement();row.append(remove);
    row.addEventListener('input',sync);row.addEventListener('change',sync);list.append(row);sync();
  }
  saved.forEach(addRule);sync();document.querySelector('#add-fault-rule').addEventListener('click',()=>{if(list.children.length<100)addRule();});
  new MutationObserver(()=>{for(const row of list.children){const select=row.querySelector('[data-template]'),value=select.value;const allowed=associated();for(const item of catalogue){if(allowed.has(Number(item.template_id))&&![...select.options].some(o=>o.value===String(item.template_id)))option(select,item.template_id,item.template_name);}for(const o of [...select.options])if(o.value&&!allowed.has(Number(o.value))&&o.value!==value)o.remove();}sync();}).observe(document.querySelector('.graph-accordion-list'),{childList:true,subtree:true,attributes:true,attributeFilter:['hidden']});
  const basic=document.querySelector('#device-form');
  const deviceSummary=document.querySelector('#configuration-device-summary');
  const protocolSummary=document.querySelector('#configuration-protocol-summary');
  // Explicit allowlist: never copy credentials or hidden form metadata into the summary.
  const settingNames=new Set(['snmp_version','snmp_port','snmp_timeout','max_oids','availability_method','ping_method','ping_timeout','ping_retries','snmp_auth_protocol','snmp_priv_protocol','snmp_security_level','interval_seconds','stale_seconds','refresh_seconds','port','connect_timeout','auth_method','command_timeout','retries','keepalive','monitoring','serial_interface','baud_rate','data_bits','parity','stop_bits','flow_control','response_timeout','serial_retries','serial_interval','serial_protocol','version','active_timeout','inactive_timeout','sampling_rate','timeout','poll_interval','maximum_offset','authentication']);
  function valueText(control){
    if(control.type==='checkbox')return control.checked?'Enabled':'Disabled';
    if(control.type==='radio'&&!control.checked)return null;
    if(control.tagName==='SELECT')return control.selectedOptions[0]?.textContent.trim()||'Not selected';
    if(control.type==='radio')return control.closest('label')?.textContent.trim()||control.value;
    return control.value.trim()||'Not set';
  }
  function entry(target,label,value){const dt=document.createElement('dt'),dd=document.createElement('dd');dt.textContent=label;dd.textContent=value;const group=document.createElement('div');group.append(dt,dd);target.append(group);}
  function configurationSummary(){
    deviceSummary.replaceChildren();
    [['Device Name','description'],['Address','hostname'],['Device Type','device_type'],['Segment','category_id'],['Site','site_id'],['Rack','rack_id'],['Rack Placement','rack_position'],['Device Status','enabled']].forEach(([label,name])=>{
      const control=basic.querySelector('[name="'+name+'"]');if(control)entry(deviceSummary,label,valueText(control));
    });
    protocolSummary.replaceChildren();
    const protocols=[...document.querySelectorAll('#protocol-workspace .protocol-item')].filter(item=>!item.hidden);
    protocols.forEach(item=>{
      const section=document.createElement('section');section.className='configuration-protocol';
      const heading=document.createElement('h4');heading.textContent=item.querySelector('summary > span:not(.accordion-chevron)')?.textContent.trim()||item.id.replace('protocol-','').toUpperCase();section.append(heading);
      const enable=item.querySelector('.protocol-enable input'),pending=item.querySelector('.pending-protocol-status');
      const state=document.createElement('p');state.textContent=pending?'Not available yet':enable&&!enable.checked?'Disabled':'Enabled';section.append(state);
      const settings=document.createElement('dl');settings.className='configuration-summary';
      const names=new Set();
      item.querySelectorAll('input,select').forEach(control=>{
        if(!settingNames.has(control.name)||names.has(control.name)||control.type==='hidden'||control.type==='password'||item.contains(control.closest('[hidden]')))return;
        const value=valueText(control);if(value===null)return;
        const caption=control.closest('.field')?.querySelector('.field-label');
        const label=caption?[...caption.childNodes].filter(node=>node.nodeType===3).map(node=>node.textContent).join('').trim():control.closest('.radio-group')?.querySelector('legend')?.textContent.trim()||control.name.replaceAll('_',' ');
        entry(settings,control.name==='monitoring'?'SSH Monitoring':label,value);names.add(control.name);
      });
      section.append(settings);protocolSummary.append(section);
    });
    if(!protocols.length){const message=document.createElement('p');message.textContent='No protocols selected. Add protocols in Protocol Config.';protocolSummary.append(message);}
  }
  basic.addEventListener('input',configurationSummary);basic.addEventListener('change',()=>queueMicrotask(configurationSummary));
  document.querySelector('#protocol-workspace').addEventListener('input',configurationSummary);
  document.querySelector('#protocol-workspace').addEventListener('change',()=>queueMicrotask(configurationSummary));
  new MutationObserver(configurationSummary).observe(document.querySelector('#protocol-workspace'),{subtree:true,attributes:true,attributeFilter:['hidden']});
  configurationSummary();
  const backups=JSON.parse(document.querySelector('#configuration-backups').textContent);
  document.querySelector('#fcaps-configuration').addEventListener('click',event=>{
    const button=event.target.closest('.configuration-download');if(!button)return;
    const backup=backups.find(item=>item.id===button.dataset.backupId);if(!backup)return;
    const url=URL.createObjectURL(new Blob([JSON.stringify(backup,null,2)],{type:'application/json'}));
    const link=document.createElement('a');link.href=url;link.download='device-'+document.querySelector('#device-wizard').dataset.deviceId+'-settings-'+backup.id+'.json';link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
  });
  document.querySelector('#configuration-backup').addEventListener('click',async event=>{
    const button=event.currentTarget,result=document.querySelector('#configuration-backup-result');
    if(basic.dataset.staticPreview){result.textContent='Backups are available in the live application.';return;}
    button.disabled=true;result.textContent='Backing up saved settings…';
    try {
      const data=new FormData();data.set('host_id',document.querySelector('#device-wizard').dataset.deviceId);data.set('action','configuration_backup');
      const token=basic.querySelector('[name="__csrf_magic"]');if(token)data.set(token.name,token.value);
      const response=await fetch('wizard_save.php',{method:'POST',body:data});const body=await response.json();if(!response.ok||!body.ok)throw new Error(body.error||'Backup failed.');
      backups.splice(0,backups.length,...body.backups);
      const rows=document.querySelector('#configuration-history-rows');rows.replaceChildren();
      for(const backup of backups){
        const row=document.createElement('tr');
        for(const value of [backup.time,backup.user||'User '+backup.user_id,backup.action]){const cell=document.createElement('td');cell.textContent=value;row.append(cell);}
        const cell=document.createElement('td'),download=document.createElement('button');download.type='button';download.className='button configuration-download';download.dataset.backupId=backup.id;download.textContent='Download Backup';cell.append(download);
        const details=document.createElement('details'),summary=document.createElement('summary');summary.textContent=Object.keys(backup.changes).length+' changed settings';details.append(summary);
        for(const [field,change] of Object.entries(backup.changes)){const text=document.createElement('p');text.textContent=field+': '+(change.before??'Not set')+' → '+(change.after??'Not set');details.append(text);}cell.append(details);row.append(cell);rows.append(row);
      }
      result.textContent='Saved settings backup created. Unsaved drafts remain unchanged.';
    }catch(error){result.textContent=error.message;}finally{button.disabled=false;}
  });
  const tabs=[...panel.querySelectorAll('[role=tab]')];
  function activate(tab){configurationSummary();tabs.forEach(item=>{const selected=item===tab;item.setAttribute('aria-selected',String(selected));item.tabIndex=selected?0:-1;document.getElementById(item.getAttribute('aria-controls')).hidden=!selected;});}
  tabs.forEach((tab,index)=>{tab.addEventListener('click',()=>activate(tab));tab.addEventListener('keydown',event=>{let next;if(event.key==='ArrowRight')next=(index+1)%tabs.length;if(event.key==='ArrowLeft')next=(index+tabs.length-1)%tabs.length;if(event.key==='Home')next=0;if(event.key==='End')next=tabs.length-1;if(next!==undefined){event.preventDefault();activate(tabs[next]);tabs[next].focus();}});});
})();
