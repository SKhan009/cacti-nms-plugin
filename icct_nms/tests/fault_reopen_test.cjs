const fs=require('fs'),vm=require('vm'),assert=require('node:assert/strict');
class E{constructor(tag='div'){this.tagName=tag;this.dataset={};this.children=[];this.attrs={};this.listeners={};this.value='';this.className='';this.classList={add:()=>{}};}append(...items){items.forEach(x=>{x.parent=this;this.children.push(x)})}setAttribute(k,v){this.attrs[k]=v}addEventListener(n,f){this.listeners[n]=f}replaceChildren(...items){this.children=[];this.append(...items)}closest(selector){if(selector==='.fault-rule')return this.className==='fault-rule'?this:this.parent?.closest(selector);if(selector==='.field')return this.className==='field'?this:this.parent?.closest(selector);return null}matches(sel){return sel.startsWith('[data-')?Object.hasOwn(this.attrs,sel.slice(1,-1)):(sel==='.field-label'||sel==='.field-title'||sel==='.field-info')&&this.className===sel.slice(1)}querySelector(sel){for(const x of this.children){if(x.matches(sel))return x;const found=x.querySelector(sel);if(found)return found}return null}remove(){this.parent.children=this.parent.children.filter(child=>child!==this)}dispatchEvent(event){this.listeners[event.type]?.(event)}get options(){return this.children}}
const nodes={};for(const key of ['#device-fcaps','#fault-form','#fault-rule-list','#fault-rules-value','#add-fault-rule','#fault-catalogue','#saved-fault-rules','#fault-current-readings','.graph-accordion-list','#fault-alarm-navigation','#fault-alarm-empty','#fault-oid-section','#fault-oid-navigation','#fault-graph-section'])nodes[key]=new E();nodes['#fault-form'].elements={fault_rules:nodes['#fault-rules-value']};nodes['#fault-catalogue'].textContent=JSON.stringify([{template_id:1,metric_id:2,template_name:'CPU',data_source_name:'cpu',rrd_minimum:0,rrd_maximum:100}]);nodes['#saved-fault-rules'].textContent=JSON.stringify([{template_id:1,metric_id:2,maximum:80,minimum:null,severity:'Warning',enabled:true},{source:'snmp',parameter:'Battery',oid:'1.3.6.1.4.1.99.1.0',units:'%',scale:2,minimum:20,maximum:null,condition:'below',severity:'Major'}]);nodes['#fault-current-readings'].textContent='[]';
nodes['#fault-parameters']=new E();nodes['#fault-parameters'].textContent=JSON.stringify([{oid:'1.3.6.1.4.1.99.1.0',parameter:'Battery charge',units:'%',scale:1,type_id:'',label:'Battery charge',template_id:1,metric_id:2}]);
nodes['#device-wizard']=new E();nodes['#device-wizard'].dataset.deviceId='2';
const token=new E('input');token.value='valid-first-token';
const oldQuery=nodes['#fault-form'].querySelector.bind(nodes['#fault-form']);
nodes['#fault-form'].querySelector=selector=>selector==='input[name="__csrf_magic"]'?token:oldQuery(selector);
nodes['#fault-form'].elements.__csrf_magic={value:'',length:2};
let reads=0;const sourceRadios=[new E('input'),new E('input')];sourceRadios[0].value='rrd';sourceRadios[1].value='snmp';
const code=fs.readFileSync('plugins/icct_nms/fcaps/js/fcaps.js','utf8');vm.runInNewContext(code.slice(0,code.indexOf('  const basic='))+'})();',{document:{querySelector:s=>nodes[s]||null,querySelectorAll:selector=>selector==='.fault-source-options input'?sourceRadios:[{value:1}],createElement:tag=>new E(tag)},MutationObserver:class{observe(){}},Set,JSON,Number,String,Object,FormData,Event,queueMicrotask,fetch:async(url,opts)=>{reads++;assert.equal(opts.body.get('__csrf_magic'),'valid-first-token');return {ok:true,redirected:false,headers:{get:()=> 'application/json'},json:async()=>({value:42})};}});

setTimeout(()=>{
 const row=nodes['#fault-rule-list'].children[1];
 assert.equal(reads,1,'Opening a saved SNMP rule must fetch its reading without a change event');
 assert.equal(row.querySelector('[data-reading]').value,'84 %');
 assert.equal(row.querySelector('[data-parameter-choice]').value,'0');
 assert.equal(row.querySelector('[data-scale]').value,2,'Reopening must preserve the saved conversion');
 assert.equal(row.querySelector('[data-scale]').closest('.field').hidden,true);
 assert.equal(row.querySelector('[data-units]').closest('.field').hidden,true);
 assert.equal(row.querySelector('[data-minimum]').value,20);
 console.log('Reopening restores the SNMP parameter, fetches a numeric reading automatically, preserves thresholds and keeps scale hidden.');
},0);
