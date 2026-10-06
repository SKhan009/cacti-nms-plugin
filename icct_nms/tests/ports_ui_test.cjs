const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
class Element {
 constructor(tag='div'){this.tagName=tag;this.children=[];this.listeners={};this.dataset={};this.value='';this.checked=false;this.className='';this.classList={add:()=>{}};}
 addEventListener(event,fn){this.listeners[event]=fn;}
 append(...children){this.children.push(...children);}
 replaceChildren(){this.children=[];}
 setAttribute(){}
 add(option){this.children.push(option);}
 querySelectorAll(selector){const all=this.children.flatMap(c=>c instanceof Element?[c,...c.querySelectorAll('*')]:[]);return selector==='*'?all:all.filter(c=>selector==='[data-port-severity]'?c.dataset.portSeverity:selector==='.port-config-row'?c.className==='port-config-row':false);}
}
const ids=['device-ports','device-form','device-wizard','port-monitor-form','port-rows','port-monitor-enabled','port-monitor-label','port-error','port-observations'];
const elements=Object.fromEntries(ids.map(id=>[id,new Element()]));
elements['device-form'].elements={hostname:new Element(),poller_id:new Element()};elements['device-form'].elements.hostname.value='host';elements['device-form'].elements.poller_id.value='1';
elements['port-monitor-enabled'].checked=true;
elements['port-monitor-form'].elements={port_settings:new Element()};elements['port-monitor-form'].elements.port_settings.value=JSON.stringify({enabled:true,ports:{2:{name:'eth0',alarm:true,cnms:false,oper_severity:'Major',admin_severity:'Minor'}}});
elements['port-observations'].textContent=JSON.stringify({ports:[{index:1,name:'lo',status:'Unknown'},{index:2,name:'eth0',status:'In use'}]});
const document={querySelector:selector=>elements[selector.slice(1)]||null,createElement:tag=>new Element(tag),createTextNode:text=>({textContent:text}),hidden:false};
vm.runInNewContext(fs.readFileSync(__dirname+'/../ports/js/ports.js','utf8'),{document,window:{addEventListener(){}},location:{hash:'#basic'},setInterval(){},Option:function(text,value){this.text=text;this.value=value;},console});
const rows=elements['port-rows'];assert.equal(rows.children.length,2);assert.equal(rows.children[1].children.length,7);
const severity=rows.children[1].querySelectorAll('[data-port-severity]');assert.equal(severity[0].value,'Major');assert.equal(severity[0].required,true);
severity[0].value='Critical';severity[0].listeners.change();rows.children[1].listeners.change();assert.equal(JSON.parse(elements['port-monitor-form'].elements.port_settings.value).ports[2].oper_severity,'Critical');
elements['port-monitor-enabled'].checked=false;elements['port-monitor-enabled'].listeners.change();assert.equal(severity[0].required,false);assert.equal(JSON.parse(elements['port-monitor-form'].elements.port_settings.value).enabled,false);
elements['device-form'].elements.hostname.value='different';elements['device-form'].elements.hostname.listeners.input();assert.equal(rows.children.length,1);assert.match(rows.children[0].textContent,/Save connection/);
console.log('PASS: automatic interface rows, saved severities, draft edits, monitoring toggle and endpoint isolation.');

// First edit with PHP's empty [] must create a keyed object, without null index 0.
elements['device-form'].elements.hostname.value='host';
elements['port-monitor-form'].elements.port_settings.value=JSON.stringify({enabled:true,ports:[]});
elements['port-monitor-enabled'].checked=true;
vm.runInNewContext(fs.readFileSync(__dirname+'/../ports/js/ports.js','utf8'),{document,window:{addEventListener(){}},location:{hash:'#basic'},setInterval(){},Option:function(text,value){this.text=text;this.value=value;},console});
const firstSeverity=rows.children[1].querySelectorAll('[data-port-severity]')[0];firstSeverity.value='Major';firstSeverity.listeners.change();rows.children[1].listeners.change();
const firstSave=JSON.parse(elements['port-monitor-form'].elements.port_settings.value);
assert.equal(Array.isArray(firstSave.ports),false);assert.deepEqual(Object.keys(firstSave.ports),['2']);assert.equal(firstSave.ports[2].name,'eth0');
console.log('PASS: first-time port settings serialize by interface index without sparse array padding.');
