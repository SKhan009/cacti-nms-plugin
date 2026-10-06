"use strict";
(() => {
  const panel=document.querySelector('#device-fcaps');if(!panel)return;
  const form=document.querySelector('#fault-form'),list=document.querySelector('#fault-rule-list'),hidden=form.elements.fault_rules;
  const catalogue=JSON.parse(document.querySelector('#fault-catalogue').textContent),saved=JSON.parse(document.querySelector('#saved-fault-rules').textContent);
  const navigation=document.querySelector('#fault-alarm-navigation'),empty=document.querySelector('#fault-alarm-empty');
  const oidSection=document.querySelector('#fault-oid-section'),oidNavigation=document.querySelector('#fault-oid-navigation');
  const sourceRadios=[...document.querySelectorAll('.fault-source-options input')];
  let selectedSource=saved[0]?.source||'rrd',selectedRow=null;
  const graphSection=document.querySelector('#fault-graph-section');
  function sameMeasurement(row,target){
    if(!target||row.querySelector('[data-source]').value!==target.querySelector('[data-source]').value)return false;
    const source=target.querySelector('[data-source]').value;
    if(source==='snmp'){const oid=target.querySelector('[data-oid]').value;return oid?row.querySelector('[data-oid]').value===oid:row===target;}
    const template=target.querySelector('[data-template]').value,metric=target.querySelector('[data-metric]').value;
    return template&&metric?row.querySelector('[data-template]').value===template&&row.querySelector('[data-metric]').value===metric:row===target;
  }
  function renderMeasurements(preferred=null){
    const rows=[...list.children],matching=rows.filter(row=>row.querySelector('[data-source]').value===selectedSource);
    if(preferred&&matching.includes(preferred))selectedRow=preferred;
    if(!matching.includes(selectedRow))selectedRow=matching[0]||null;
    sourceRadios.forEach(radio=>radio.checked=radio.value===selectedSource);
    empty.hidden=!!selectedRow;rows.forEach(row=>row.hidden=!sameMeasurement(row,selectedRow));
    graphSection.hidden=selectedSource!=='rrd';navigation.replaceChildren();
    if(selectedSource==='rrd'){
      const items=catalogue.filter(item=>associated().has(Number(item.template_id))),seen=new Set(),groups=new Map(),sections=[];
      // Preserve access to saved rules whose template association was removed.
      matching.forEach(row=>{const template=row.querySelector('[data-template]'),metric=row.querySelector('[data-metric]');if(template.value&&metric.value&&!items.some(item=>String(item.template_id)===template.value&&String(item.metric_id)===metric.value))items.push({template_id:template.value,metric_id:metric.value,template_name:'Unavailable template '+template.value,data_source_name:row.querySelector('[data-parameter]').value||'Data source '+metric.value});});
      items.forEach(item=>{
        const key=String(item.template_id)+'|'+String(item.metric_id);if(seen.has(key))return;seen.add(key);
        let group=groups.get(String(item.template_id));
        if(!group){const section=document.createElement('details');section.className='fault-template-group';section.setAttribute('name','fault-graph-templates');section.open=selectedRow?String(item.template_id)===selectedRow.querySelector('[data-template]').value:groups.size===0;sections.push(section);section.addEventListener('toggle',()=>{if(section.open)sections.forEach(other=>{if(other!==section)other.open=false;});});const title=document.createElement('summary');title.textContent=item.template_name;section.append(title);group=document.createElement('div');group.className='fault-data-sources';group.setAttribute('aria-label','Data sources');section.append(group);navigation.append(section);groups.set(String(item.template_id),group);}
        const button=document.createElement('button');button.type='button';button.className='fault-data-source-link';button.textContent=item.data_source_name;button.setAttribute('aria-pressed',selectedRow&&String(item.template_id)===selectedRow.querySelector('[data-template]').value&&String(item.metric_id)===selectedRow.querySelector('[data-metric]').value?'true':'false');button.addEventListener('click',()=>selectMeasurement({...item,source:'rrd'}));group.append(button);
      });
    }
    renderOids();
  }
  function selectMeasurement(item){
    const row=[...list.children].find(row=>row.querySelector('[data-source]').value===item.source&&(item.source==='snmp'?row.querySelector('[data-oid]').value===item.oid:row.querySelector('[data-template]').value===String(item.template_id)&&row.querySelector('[data-metric]').value===String(item.metric_id)));
    if(row){renderMeasurements(row);return;}
    if(list.children.length>=100)return;
    addRule({source:item.source,name:item.parameter||item.data_source_name||'',parameter:item.parameter||'',oid:item.oid||'',units:item.units||'',scale:item.scale||1,template_id:item.template_id||0,metric_id:item.metric_id||0});
    const added=list.children[list.children.length-1];renderMeasurements(added);sync();
  }
  sourceRadios.forEach(radio=>radio.addEventListener('change',()=>{if(radio.checked){selectedSource=radio.value;renderMeasurements();}}));
  let validationRow=null;
  form.addEventListener('invalid',event=>{const row=event.target.closest('.fault-rule');if(row&&!validationRow){validationRow=row;selectedSource=row.querySelector('[data-source]').value;renderMeasurements(row);queueMicrotask(()=>validationRow=null);}},true);
  function associated(){return new Set([...document.querySelectorAll('.graph-association:not([hidden]) [name=graph_template_id],.wizard-pending[data-graph-template-id]')].map(node=>Number(node.value||node.dataset.graphTemplateId)));}
  const parameters=JSON.parse(document.querySelector('#fault-parameters')?.textContent||'[]');
  const observations=JSON.parse(document.querySelector('#fault-current-readings')?.textContent||'[]');
  function renderOids(){
    oidSection.hidden=selectedSource!=='snmp';oidNavigation.replaceChildren();
    if(selectedSource!=='snmp')return;
    const type=document.querySelector('#device-form [name=device_type]')?.value||'',seen=new Set();
    const available=[...parameters];
    for(const row of list.children)if(row.querySelector('[data-source]').value==='snmp'){const oid=row.querySelector('[data-oid]').value;if(oid&&!available.some(item=>item.oid===oid))available.push({oid,parameter:row.querySelector('[data-parameter]').value||oid,type_id:''});}
    available.forEach((item,index)=>{
      if((item.type_id&&String(item.type_id)!==type)||seen.has(item.oid))return;seen.add(item.oid);
      const button=document.createElement('button');button.type='button';button.className='fault-oid-link';
      const title=document.createElement('span');title.textContent=item.parameter;const oid=document.createElement('small');oid.textContent=item.oid;button.append(title,oid);
      button.setAttribute('aria-pressed',selectedRow?.querySelector('[data-oid]').value===item.oid?'true':'false');
      button.addEventListener('click',()=>selectMeasurement({...item,source:'snmp'}));oidNavigation.append(button);
    });
    if(!seen.size){const message=document.createElement('p');message.className='fault-oid-empty';message.textContent='No known OIDs for this device.';oidNavigation.append(message);}
  }
  document.querySelector('#device-form [name=device_type]')?.addEventListener('change',()=>renderMeasurements());
  function sync(){hidden.value=JSON.stringify([...list.children].map(row=>({source:row.querySelector('[data-source]').value,parameter:row.querySelector('[data-parameter]').value,oid:row.querySelector('[data-oid]').value,units:row.querySelector('[data-units]').value,scale:row.querySelector('[data-scale]').value,condition:row.querySelector('[data-condition]').value,template_id:row.querySelector('[data-template]').value,metric_id:row.querySelector('[data-metric]').value,minimum:row.querySelector('[data-minimum]').value,maximum:row.querySelector('[data-maximum]').value,name:row.querySelector('[data-name]').value,corrective_action:row.querySelector('[data-corrective-action]').value,email_recipients:row.querySelector('[data-email-recipients]').value,email:row.querySelector('[data-email]').checked,audio:row.querySelector('[data-audio]').checked,severity:row.querySelector('[data-severity]').value,enabled:row.querySelector('[data-enabled]').checked})));}
  function option(select,value,label){const o=document.createElement('option');o.value=String(value);o.textContent=label;select.append(o);}
  const fieldHelp={
    name:'A short name for this alarm, e.g. UPS battery low.',
    source:'Use a collected Cacti data source, or read a numeric OID with the saved device SNMP settings.',
    'parameter-choice':'Select a known SNMP parameter to fill its OID and available details. Choose Manual OID for another parameter.',
    parameter:'Name of the measurement, e.g. Battery charge or Temperature.',
    template:'Select an associated graph template containing the measurement you want to monitor.',
    metric:'Select the actual measurement, e.g. load_1min, battery charge or temperature.',
    oid:'Full numeric OID from the device MIB: include .0 for a scalar or the actual table index.',
    units:'Label the measured units, e.g. %, °C or V. This label does not convert the reading.',
    scale:'Multiply the raw SNMP value by this number. Use 1 for no conversion, or 0.1 for values reported in tenths.',
    reading:'Read-only measurement. Missing or stale values are Unknown.',
    condition:'Choose >, <, outside the range, = or ≠. Above and below use strict comparisons.',
    minimum:'Raise a fault below this value, in the same units as the reading. E.g. 20 for battery charge below 20%.',
    maximum:'Raise a fault above this value, in the same units as the reading. For = or ≠, enter the numeric device status code.',
    severity:'Alarm level for this rule. Add separate rules for other severity levels.',
    enabled:'Enable this rule for automatic evaluation after saving.',
    'corrective-action':'Optional action for the operator, e.g. Check UPS power supply.'
  };
  function field(row,label,kind,key){
    const wrapper=document.createElement('label');wrapper.className='field';
    const caption=document.createElement('span');caption.className='field-label';
    const title=document.createElement('span');title.className='field-title';title.textContent=label;caption.append(title);
    if(fieldHelp[key]){
      const icon=document.createElement('span');icon.className='field-info';icon.tabIndex=0;icon.setAttribute('role','img');icon.setAttribute('aria-label','Information about '+label);icon.dataset.tooltip=fieldHelp[key];
      icon.innerHTML='<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8"/><path d="M10 9v5M10 6v.5"/></svg>';
      icon.addEventListener('click',event=>event.preventDefault());caption.append(icon);
    }
    const input=document.createElement(['select','textarea'].includes(kind)?kind:'input');input.setAttribute('data-'+key,'');input.setAttribute('aria-label',label);
    if(!['select','textarea'].includes(kind)){input.type=kind;if(kind==='number')input.step='any';}
    wrapper.append(caption,input);row.append(wrapper);return input;
  }
  const wrapper=input=>input.closest('.field');
  function addRule(rule={},index=-1) {
    const row=document.createElement('div');row.className='fault-rule';
    const name=field(row,'Alarm Name','text','name');name.maxLength=120;name.placeholder='e.g. UPS battery low';name.value=rule.name||'';
    const source=field(row,'Measurement Source','select','source');option(source,'rrd','Cacti Data Source');option(source,'snmp','SNMP OID');source.value=rule.source||'rrd';wrapper(source).hidden=true;
    const picker=field(row,'SNMP Parameter','select','parameter-choice');
    const parameter=field(row,'Parameter Name','text','parameter');parameter.maxLength=120;parameter.placeholder='e.g. Battery charge';parameter.value=rule.parameter||'';
    const template=field(row,'Graph Template','select','template'),metric=field(row,'Data Source','select','metric');
    const oid=field(row,'Full Instance OID','text','oid');oid.maxLength=512;oid.placeholder='Numeric OID including .0 or table index';oid.value=rule.oid||'';
    const units=field(row,'Stored Units','hidden','units');units.value=rule.units||'';wrapper(units).hidden=true;
    const initialParameter=parameters.find(item=>source.value==='snmp'?item.oid===String(rule.oid||'').replace(/^\./,''):Number(item.metric_id)===Number(rule.metric_id)&&Number(item.template_id)===Number(rule.template_id));
    if(initialParameter?.units)units.value=initialParameter.units;
    const scale=field(row,'Scale Multiplier','hidden','scale');scale.value=rule.scale??1;wrapper(scale).hidden=true;
    const reading=field(row,'Current Reading','text','reading');reading.readOnly=true;
    const values=observations.filter(item=>Number(item.rule)===index);reading.value=values.length?values.map(item=>item.value===null?'Unknown':String(item.value)+(units.value?' '+units.value:'')).join(' / '):'Awaiting collection';
    const condition=field(row,'Condition','select','condition');[['above','> Above'],['below','< Below'],['outside','< Min or > Max'],['equals','= Equals'],['not_equals','≠ Not equal']].forEach(([value,label])=>option(condition,value,label));
    condition.value=rule.condition||(rule.minimum!=null&&rule.maximum!=null?'outside':rule.minimum!=null?'below':'above');
    const minimum=field(row,'Threshold Value','number','minimum'),maximum=field(row,'Threshold Value','number','maximum'),severity=field(row,'Severity','select','severity');
    severity.required=true;option(severity,'','Select severity');['Information','Minor','Warning','Major','Critical'].forEach(value=>option(severity,value,value));severity.value=rule.severity||'';
    minimum.value=rule.minimum??'';maximum.value=rule.maximum??'';
    option(template,'','Select associated template');
    if(rule.template_id&&!associated().has(Number(rule.template_id)))option(template,rule.template_id,'Removed graph template — choose another source');
    const seen=new Set();catalogue.forEach(item=>{if(associated().has(Number(item.template_id))&&!seen.has(item.template_id)){seen.add(item.template_id);option(template,item.template_id,item.template_name);}});template.value=String(rule.template_id||'');
    function metrics(reset){metric.replaceChildren();option(metric,'','Select parameter');catalogue.filter(item=>String(item.template_id)===template.value).forEach(item=>option(metric,item.metric_id,item.data_source_name));if(!reset)metric.value=String(rule.metric_id||'');}
    function choices(){
      const previous=picker.value,type=document.querySelector('#device-form [name=device_type]')?.value||'';
      picker.replaceChildren();option(picker,'','Manual OID');
      parameters.forEach((item,i)=>{if(!item.type_id||String(item.type_id)===type)option(picker,i,item.label);});
      picker.value=[...picker.options].some(o=>o.value===previous)?previous:'';
    }
    let readGeneration=0;
    async function readParameter(){
      const generation=++readGeneration;
      if(source.value!=='snmp'||!oid.value)return;
      const id=document.querySelector('#device-wizard')?.dataset.deviceId;
      if(!id||id==='0'){reading.value='Save device and SNMP setup first';return;}
      reading.value='Reading…';
      const data=new FormData();data.set('action','fault_parameter_read');data.set('oid',oid.value);const token=form.querySelector('input[name="__csrf_magic"]');if(!token?.value){reading.value='Reload the page before reading';return;}data.set('__csrf_magic',token.value);
      try{const response=await fetch('device.php?id='+encodeURIComponent(id),{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'}});if(response.redirected||!response.headers.get('Content-Type')?.includes('application/json'))throw new Error('Request expired. Reload the page and try again.');const result=await response.json();if(generation!==readGeneration)return;if(!response.ok)throw new Error(result.error||'Reading unavailable');if(!Object.prototype.hasOwnProperty.call(result,'value'))throw new Error('Session expired. Reload the page and sign in.');reading.value=result.value===null?'Unsupported or no response':String(Number(result.value)*Number(scale.value||1))+(units.value?' '+units.value:'');}
      catch(error){if(generation===readGeneration)reading.value=error.message;}
    }
    function fillParameter(item){if(!item)return;oid.value=item.oid;parameter.value=item.parameter;units.value=item.units||'';scale.value=item.scale||1;if(!name.value)name.value=item.parameter;sync();readParameter();}
    choices();
    const savedParameter=parameters.findIndex(item=>item.oid===String(rule.oid||'').replace(/^\./,''));
    if(savedParameter>=0&&[...picker.options].some(option=>option.value===String(savedParameter)))picker.value=String(savedParameter);
    picker.addEventListener('change',()=>{++readGeneration;reading.value='Awaiting collection';fillParameter(picker.value!==''?parameters[Number(picker.value)]:null);});
    document.querySelector('#device-form [name=device_type]')?.addEventListener('change',choices);
    oid.addEventListener('change',()=>{const item=parameters.find(p=>p.oid===oid.value.trim().replace(/^\./,''));scale.value=item?.scale||1;if(item?.units)units.value=item.units;sync();readParameter();});
    function sourceFields(){row.dataset.measurement=source.value;++readGeneration;const direct=source.value==='snmp';wrapper(source).hidden=true;wrapper(reading).querySelector('.field-info').dataset.tooltip=direct?'Read-only SNMP value after automatic unit conversion. Selecting a parameter fetches a reading using saved SNMP settings.':'Read-only latest completed AVERAGE sample from this data source, before graph transformations. Enter thresholds in its stored units.';for(const field of [template,metric]){wrapper(field).hidden=direct;field.required=!direct;}wrapper(picker).hidden=!direct;for(const field of [oid,parameter])wrapper(field).hidden=!direct;wrapper(units).hidden=true;wrapper(scale).hidden=true;oid.required=parameter.required=direct;reading.value='Awaiting collection';}
    function conditionFields(reset=false){row.dataset.condition=condition.value;const lower=['below','outside'].includes(condition.value),upper=condition.value!=='below';wrapper(minimum).hidden=!lower;wrapper(maximum).hidden=!upper;minimum.required=lower;maximum.required=upper;wrapper(minimum).querySelector('.field-title').textContent=condition.value==='outside'?'Minimum Value':'Threshold Value';wrapper(maximum).querySelector('.field-title').textContent=condition.value==='outside'?'Maximum Value':'Threshold Value';if(reset){if(!lower)minimum.value='';if(!upper)maximum.value='';}sync();}
    metrics(false);sourceFields();reading.value=values.length?values.map(item=>item.value===null?'Unknown':String(item.value)+(units.value?' '+units.value:'')).join(' / '):'Awaiting collection';
    template.addEventListener('change',()=>{metrics(true);reading.value='Awaiting collection';sync();});metric.addEventListener('change',()=>{const item=parameters.find(p=>String(p.metric_id)===metric.value&&String(p.template_id)===template.value);if(item){parameter.value=item.parameter;units.value=item.units||'';oid.value=item.oid;scale.value=item.scale||1;}reading.value='Awaiting collection';sync();});source.addEventListener('change',()=>{sourceFields();if(source.value==='snmp'){const item=parameters.find(p=>String(p.metric_id)===metric.value&&String(p.template_id)===template.value);if(item){picker.value=String(parameters.findIndex(parameter=>parameter.oid===item.oid));fillParameter(item);}else if(oid.value)readParameter();}sync();});condition.addEventListener('change',()=>conditionFields(true));
    const enabled=field(row,'Enabled','checkbox','enabled');enabled.checked=rule.enabled??true;
    const guidance=field(row,'Corrective Guidance (optional)','textarea','corrective-action');guidance.rows=2;guidance.maxLength=1000;guidance.placeholder='Suggested action when a fault occurs';guidance.value=rule.corrective_action||'';wrapper(guidance).classList.add('fault-guidance');
    // Preserve existing notification preferences without presenting inactive delivery controls.
    const preferences=document.createElement('div');preferences.hidden=true;row.append(preferences);
    const email=field(preferences,'Email','checkbox','email'),audio=field(preferences,'Audio','checkbox','audio'),recipients=field(preferences,'Email Recipients','text','email-recipients');email.checked=!!rule.email;audio.checked=!!rule.audio;recipients.value=rule.email_recipients||'';
    const remove=document.createElement('button');remove.type='button';remove.className='button';remove.textContent='Remove';remove.addEventListener('click',()=>{row.remove();sync();renderMeasurements();});row.append(remove);
    row.addEventListener('input',()=>{sync();renderMeasurements();});row.addEventListener('change',()=>{sync();renderMeasurements(row);});list.append(row);conditionFields();
    if(source.value==='snmp'&&oid.value)readParameter();
  }
  saved.forEach((rule,index)=>addRule(rule,index));sync();renderMeasurements();document.querySelector('#add-fault-rule').addEventListener('click',()=>{if(list.children.length<100){const rule={source:selectedSource};if(selectedRow)for(const key of ['name','parameter','oid','units','scale','template','metric']){const value=selectedRow.querySelector('[data-'+key+']').value;rule[key==='template'?'template_id':key==='metric'?'metric_id':key]=value;}addRule(rule);renderMeasurements(list.children[list.children.length-1]);}});
  new MutationObserver(()=>{for(const row of list.children){const select=row.querySelector('[data-template]'),value=select.value,allowed=associated();for(const item of catalogue)if(allowed.has(Number(item.template_id))&&![...select.options].some(o=>o.value===String(item.template_id)))option(select,item.template_id,item.template_name);for(const o of [...select.options])if(o.value&&!allowed.has(Number(o.value))&&o.value!==value)o.remove();}sync();}).observe(document.querySelector('.graph-accordion-list'),{childList:true,subtree:true,attributes:true,attributeFilter:['hidden']});
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
