"use strict";
// Drafts stay in this page, including credentials; navigation does not submit forms.
(() => {
  const wizard = document.querySelector('#device-wizard');
  if (!wizard) return;
  const basic = document.querySelector('#device-form');
  const panels = document.querySelector('#wizard-panels');
  const steps = ['basic','protocol','diagnostics','graphs','data-query'];
  const nav = [...basic.querySelectorAll('.steps li')].slice(0,5);
  const dialog = document.querySelector('#unsaved-dialog');
  const previous = document.querySelector('#wizard-previous');
  const next = document.querySelector('#wizard-next');
  let id = Number(wizard.dataset.deviceId), leaving = false, busy = false;
  const forms = [...panels.querySelectorAll('form')].filter(f => ['snmp','ssh','serial','discovery','diagnostics'].includes(f.elements.action?.value));
  
  const snapshot = f => JSON.stringify([...new FormData(f)].filter(([n]) => n !== '__csrf_magic').map(([n,v]) => [n,v instanceof File ? (v.name ? `${v.name}:${v.size}:${v.lastModified}` : '') : v]));
  const baseline = new Map([basic,...forms].map(f => [f,snapshot(f)]));
  const protocolInitial = new Map([...document.querySelectorAll('.protocol-item')].map(p => [p.id,{hidden:p.hidden,enabled:p.querySelector('.protocol-enable input')?.checked}]));
  const staged = new Map();
  const protocolChanges = () => [...protocolInitial].filter(([name,state]) => {
    const p=document.getElementById(name);
    return p.hidden !== state.hidden || p.querySelector('.protocol-enable input')?.checked !== state.enabled;
  });
  const dirtyForms = () => [basic,...forms].filter(f => snapshot(f) !== baseline.get(f));
  const dirty = () => dirtyForms().length > 0 || staged.size > 0 || protocolChanges().length > 0;
  const currentStep = () => steps.includes(location.hash.slice(1)) ? location.hash.slice(1) : wizard.dataset.initialStep || 'basic';
  function showStep() {
    const step = currentStep(), index = Math.max(0,steps.indexOf(step));
    basic.querySelector('.device-fields').hidden = index !== 0;
    basic.querySelector('.clone-instructions')?.toggleAttribute('hidden',index !== 0);
    panels.hidden = index === 0;
    document.querySelector('#protocol-workspace').hidden = step !== 'protocol';
    document.querySelector('#diagnostics').hidden = step !== 'diagnostics';
    document.querySelector('#device-graphs').hidden = step !== 'graphs';
    document.querySelector('#device-data-queries').hidden = step !== 'data-query';
    nav.forEach((item,i)=>{item.classList.toggle('current',i===index);item.removeAttribute('aria-disabled'); if(i===index)item.setAttribute('aria-current','step');else item.removeAttribute('aria-current');});
    previous.disabled = index === 0;
    next.textContent = index === 4 ? 'Done →' : 'Next →';
  }
  nav.forEach((item,i)=>{
    const a = item.querySelector('a') || document.createElement('a');
    if (!a.parentNode) { a.innerHTML = item.innerHTML; item.replaceChildren(a); }
    a.href = '#'+steps[i];
  });
  previous.addEventListener('click',()=>{location.hash=steps[Math.max(0,steps.indexOf(currentStep())-1)];});
  next.addEventListener('click',()=>{
    const index=steps.indexOf(currentStep());
    if(index===4) { leave('index.html'); return; }
    // Navigation keeps incomplete drafts; validation occurs only on explicit Save.
    location.hash=steps[index+1];
  });
  window.addEventListener('hashchange',showStep);
  showStep();
  let templateRequest = 0;
  async function updateTemplateAssociations() {
    if (id || basic.dataset.staticPreview) return;
    const request = ++templateRequest;
    try {
      const response = await fetch('wizard_templates.php?template_id='+encodeURIComponent(basic.elements.host_template_id.value), {credentials:'same-origin'});
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.error || 'Template associations could not load.');
      if (request !== templateRequest) return;
      const parsed = new DOMParser();
      const graphDoc = parsed.parseFromString(payload.graphs,'text/html');
      const queryDoc = parsed.parseFromString(payload.queries,'text/html');
      document.querySelector('.graph-accordion-list').replaceChildren(...graphDoc.querySelector('.graph-accordion-list').childNodes);
      document.querySelector('#device-data-queries tbody').replaceChildren(...queryDoc.querySelector('tbody').childNodes);
    } catch(error) { await icctShowMessage({title:'Unable to load template',text:error.message,danger:true}); }
  }
  basic.elements.host_template_id.addEventListener('change',updateTemplateAssociations);
  updateTemplateAssociations();
  async function post(data) {
    if(basic.dataset.staticPreview)return {ok:true,id,message:'Changes kept in this preview. Live configuration is unchanged.'};
    data.set('host_id',String(id));
    const token=basic.querySelector('input[name="__csrf_magic"]');
    if(token)data.set('__csrf_magic',token.value);
    const response=await fetch('wizard_save.php',{method:'POST',body:data,credentials:'same-origin'});
    const payload=await response.json();
    if(!response.ok || !payload.ok)throw new Error(payload.error || 'Changes could not be saved.');
    id=Number(payload.id);wizard.dataset.deviceId=String(id);
    return payload;
  }
  function valid(form) {
    if(form.checkValidity())return true;
    const invalid=form.querySelector(':invalid');
    if(form===basic)location.hash='basic';
    else if(form.closest('#diagnostics'))location.hash='diagnostics';
    else location.hash='protocol';
    showStep(); invalid?.focus();form.reportValidity();return false;
  }
  async function save() {
    if(busy)return false;
    wizard.querySelectorAll('.inline-feedback.error').forEach(node=>node.remove());
    wizard.querySelectorAll('[aria-invalid]').forEach(node=>node.removeAttribute('aria-invalid'));
    const changed=dirtyForms().filter(f => f===basic || !f.closest('.protocol-item')?.hidden);
    for(const form of forms){const panel=form.closest('.protocol-item');if(panel && !panel.hidden && protocolInitial.get(panel.id)?.hidden && !changed.includes(form))changed.push(form);}
    if(!id && !changed.includes(basic))changed.unshift(basic);
    const active=changed.filter(f=> f===basic || !f.closest('.protocol-item') || f.closest('.protocol-item').querySelector('.protocol-enable input')?.checked);
    if(active.some(f=>!valid(f)))return false;
    busy=true;wizard.inert=true;
    document.querySelectorAll('[data-wizard-save]').forEach(b=>b.disabled=true);
    let saved=0, errorContext=basic;
    try {
      if(active.includes(basic)) {
        errorContext=basic;
        const data=new FormData(basic);data.set('action','basic');await post(data);baseline.set(basic,snapshot(basic));saved++;
      }
      for(const [name,state] of protocolChanges()) {
        const p=document.getElementById(name),enabled=p.querySelector('.protocol-enable input')?.checked;
        errorContext=p;
        const data=new FormData();data.set('protocol',name.replace('protocol-',''));
        if(p.hidden && !state.hidden)data.set('action','remove_protocol');
        else if(!p.hidden && enabled !== state.enabled){data.set('action','toggle_protocol');data.set('enabled',enabled?'1':'0');}
        else continue;
        await post(data);protocolInitial.set(name,{hidden:p.hidden,enabled});saved++;
      }
      for(const form of active.filter(f=>f!==basic)) {
        errorContext=form;
        await post(new FormData(form));baseline.set(form,snapshot(form));saved++;
      }
      for(const [k,data] of staged) {errorContext=document.querySelector(k.startsWith('graph:')?'#device-graphs':'#device-data-queries');await post(data);staged.delete(k);saved++;}
      // Hidden/disabled draft edits are discarded after a successful explicit save.
      for(const form of changed)baseline.set(form,snapshot(form));
      for(const [name] of protocolInitial) {
        const p=document.getElementById(name);protocolInitial.set(name,{hidden:p.hidden,enabled:p.querySelector('.protocol-enable input')?.checked});
      }
      if(!basic.dataset.staticPreview)history.replaceState(null,'','device.php?id='+id+location.hash);
      return true;
    } catch(error) {
      const panel=errorContext.closest('.protocol-item');
      if(panel){panel.hidden=false;panel.open=true;location.hash='protocol';showStep();}
      else if(errorContext===basic){location.hash='basic';showStep();}
      else if(errorContext.closest('#diagnostics')){location.hash='diagnostics';showStep();}
      else if(errorContext.id==='device-graphs'){location.hash='graphs';showStep();}
      else if(errorContext.id==='device-data-queries'){location.hash='data-query';showStep();}
      await icctShowMessage({title:'Unable to save changes',text:`${error.message}${saved ? ' Some changes were saved. Remaining edits are still in this draft.' : ' Your edits are still in this draft.'}`,danger:true,context:errorContext});
      return false;
    } finally {busy=false;wizard.inert=false;document.querySelectorAll('[data-wizard-save]').forEach(b=>b.disabled=false);}
  }
  async function leave(url) {
    if(busy)return;
    if(!dirty()){leaving=true;location.href=url;return;}
    if(dialog.open)return;
    dialog.showModal();
    const choice=await new Promise(resolve=>dialog.addEventListener('close',()=>resolve(dialog.returnValue),{once:true}));
    if(choice==='discard' || (choice==='save' && await save())){if(choice==='save')icctQueueToast({title:'Saved',text:basic.dataset.staticPreview ? 'Changes saved in this preview. Live device configuration is unchanged.' : 'Device changes saved successfully.'});leaving=true;location.href=url;}
  }
  dialog.querySelectorAll('[data-draft-choice]').forEach(b=>b.addEventListener('click',()=>dialog.close(b.dataset.draftChoice)));
  async function explicitSave(){
    if(!await save())return;
    const message={title:'Saved',text:basic.dataset.staticPreview ? 'Changes saved in this preview. Live device configuration is unchanged.' : 'Device changes saved successfully.'};
    if(basic.dataset.staticPreview)icctToast(message);
    else {icctQueueToast(message);leaving=true;location.reload();}
  }
  document.querySelectorAll('[data-wizard-save]').forEach(b=>b.addEventListener('click',explicitSave));
  document.addEventListener('click',event=>{
    const link=event.target.closest('a[href]');if(!link)return;
    const url=new URL(link.href,location.href);
    if(url.origin===location.origin && url.pathname===location.pathname && url.hash)return;
    if(url.protocol==='javascript:')return;
    if(link.closest('.steps'))return;
    if(link.target==='_blank')return;
    event.preventDefault();leave(link.href);
  },true);
  document.addEventListener('change',event=>{
    if(event.target.matches('[data-query-method]')){
      const form=event.target.form,data=new FormData(form),key='query:'+data.get('snmp_query_id');
      if(data.get('reindex_method')===form.querySelector('input[checked]')?.value)staged.delete(key);else staged.set(key,data);
    }
  });
  document.addEventListener('submit',event=>{
    if(!wizard.contains(event.target))return;
    event.preventDefault();event.stopImmediatePropagation();
    const form=event.target,action=form.elements.action?.value;
    if(form===basic || ['snmp','ssh','serial','discovery','diagnostics'].includes(action)) {
      explicitSave();return;
    }
    const data=new FormData(form);
    const association = action?.includes('graph_template') ? 'graph' : 'query';
    const itemId=data.get('graph_template_id') || data.get('snmp_query_id');
    if(['reload_data_query','verbose_data_query'].includes(action)) {
      if(!id || dirty()){icctShowMessage({title:'Save changes first',text:'Save the device changes before running a data query.',inline:true,context:document.querySelector('#device-data-queries')});return;}
      busy=true;wizard.inert=true;
      post(data).then(payload=>{if(action==='verbose_data_query')icctInlineMessage({title:'Data query details',text:payload.message,context:document.querySelector('#device-data-queries')});else {icctQueueToast({title:'Data query reloaded',text:payload.message});leaving=true;location.reload();}}).catch(error=>icctShowMessage({title:'Unable to run data query',text:error.message,danger:true,context:document.querySelector('#device-data-queries')})).finally(()=>{busy=false;wizard.inert=false;});return;
    }
    if(action === 'change_data_query' && data.get('reindex_method') === form.querySelector('input[checked]')?.value)staged.delete(association+':'+itemId);
    else staged.set(association+':'+itemId,data);
    if(action?.startsWith('remove_')) {form.closest('.graph-association, tr')?.setAttribute('hidden','');}
    else if(action?.startsWith('add_')) {
      const select=form.querySelector('select');
      const entry=document.createElement('p');entry.className='wizard-pending';entry.textContent=select.selectedOptions[0].textContent+' — Added to draft';
      if(action==='add_graph_template') {
        document.querySelector('.graph-accordion-list').append(entry);
        document.querySelector('#graph-add-panel').hidden=true;
        document.querySelector('.add-graph-button').setAttribute('aria-expanded','false');
      } else form.after(entry);
      select.value='';select.dispatchEvent(new Event('change',{bubbles:true}));
    }
  },true);
  window.addEventListener('beforeunload',event=>{if(!leaving && dirty()){event.preventDefault();event.returnValue='';}});
})();
