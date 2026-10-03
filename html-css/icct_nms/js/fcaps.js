"use strict";
(() => {
  const panel=document.querySelector('#device-fcaps');if(!panel)return;
  const form=document.querySelector('#fault-form'),list=document.querySelector('#fault-rule-list'),hidden=form.elements.fault_rules;
  const catalogue=JSON.parse(document.querySelector('#fault-catalogue').textContent),saved=JSON.parse(document.querySelector('#saved-fault-rules').textContent);
  function associated(){return new Set([...document.querySelectorAll('.graph-association:not([hidden]) [name=graph_template_id],.wizard-pending[data-graph-template-id]')].map(node=>Number(node.value||node.dataset.graphTemplateId)));}
  function sync(){hidden.value=JSON.stringify([...list.children].map(row=>({template_id:row.querySelector('[data-template]').value,metric_id:row.querySelector('[data-metric]').value,minimum:row.querySelector('[data-minimum]').value,maximum:row.querySelector('[data-maximum]').value,severity:row.querySelector('[data-severity]').value,enabled:row.querySelector('[data-enabled]').checked})));}
  function option(select,value,label){const o=document.createElement('option');o.value=String(value);o.textContent=label;select.append(o);}
  function field(row,label,kind,key){const wrapper=document.createElement('label');wrapper.className='field';const caption=document.createElement('span');caption.className='field-label';caption.textContent=label;const input=document.createElement(kind==='select'?'select':'input');input.setAttribute('data-'+key,'');if(kind!=='select'){input.type=kind;if(kind==='number')input.step='any';}wrapper.append(caption,input);row.append(wrapper);return input;}
  function addRule(rule={}) {
    const row=document.createElement('div');row.className='fault-rule';
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
    const remove=document.createElement('button');remove.type='button';remove.className='button';remove.textContent='Remove';remove.addEventListener('click',()=>{row.remove();sync();});row.append(remove);
    row.addEventListener('input',sync);row.addEventListener('change',sync);list.append(row);sync();
  }
  saved.forEach(addRule);sync();document.querySelector('#add-fault-rule').addEventListener('click',()=>{if(list.children.length<100)addRule();});
  new MutationObserver(()=>{for(const row of list.children){const select=row.querySelector('[data-template]'),value=select.value;const allowed=associated();for(const item of catalogue){if(allowed.has(Number(item.template_id))&&![...select.options].some(o=>o.value===String(item.template_id)))option(select,item.template_id,item.template_name);}for(const o of [...select.options])if(o.value&&!allowed.has(Number(o.value))&&o.value!==value)o.remove();}sync();}).observe(document.querySelector('.graph-accordion-list'),{childList:true,subtree:true,attributes:true,attributeFilter:['hidden']});
  const tabs=[...panel.querySelectorAll('[role=tab]')];
  function activate(tab){tabs.forEach(item=>{const selected=item===tab;item.setAttribute('aria-selected',String(selected));item.tabIndex=selected?0:-1;document.getElementById(item.getAttribute('aria-controls')).hidden=!selected;});}
  tabs.forEach((tab,index)=>{tab.addEventListener('click',()=>activate(tab));tab.addEventListener('keydown',event=>{let next;if(event.key==='ArrowRight')next=(index+1)%tabs.length;if(event.key==='ArrowLeft')next=(index+tabs.length-1)%tabs.length;if(event.key==='Home')next=0;if(event.key==='End')next=tabs.length-1;if(next!==undefined){event.preventDefault();activate(tabs[next]);tabs[next].focus();}});});
})();
