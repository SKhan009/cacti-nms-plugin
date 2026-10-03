const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
class Element {
 constructor(){this.textContent='';this.children=[];this.listeners={};this.dataset={};this.value='';}
 addEventListener(event,fn){this.listeners[event]=fn;}
 append(child){this.children.push(child);}
 replaceChildren(){this.children=[];}
}
const elements=Object.fromEntries(['device-ports','device-form','device-wizard','device-type-profiles','port-observations','port-rows','port-summary','port-observation','ports-search'].map(id=>[id,new Element()]));
const form=elements['device-form'];form.elements=Object.fromEntries(['hostname','poller_id','category_id','device_type'].map(id=>[id,new Element()]));
Object.assign(form.elements.hostname,{value:'host'});Object.assign(form.elements.poller_id,{value:'1'});Object.assign(form.elements.category_id,{value:'1'});Object.assign(form.elements.device_type,{value:'Switch'});
elements['device-type-profiles'].textContent=JSON.stringify([{name:'Switch',category_id:1,physical_ports:96},{name:'Server',category_id:2,physical_ports:2}]);
elements['port-observations'].textContent=JSON.stringify({ports:[{name:'Gi1',index:101,connector:1,status:'In use'},{name:'Gi2',index:102,connector:1,status:'Available (link down)'},{name:'Vlan1',index:1001,connector:2,status:'In use'}],message:'Observed',collected:0});
const context={document:{querySelector:s=>elements[s.slice(1)]||null,createElement:()=>new Element(),hidden:false},window:{addEventListener(){}},location:{hash:'#basic'},setInterval(){},console};
vm.runInNewContext(fs.readFileSync(__dirname+'/../assets/js/ports.js','utf8'),context);
assert.match(elements['port-summary'].textContent,/Preset physical ports: 96/);
assert.match(elements['port-summary'].textContent,/Unknown: 94/);
assert.equal(elements['port-rows'].children.length,97);
assert.equal(elements['port-rows'].children[2].children[1].textContent,'—');
assert.equal(elements['port-rows'].children.at(-1).children[0].textContent,'Vlan1');
elements['ports-search'].value='vLaN';elements['ports-search'].listeners.input();
assert.equal(elements['port-rows'].children.length,1);
assert.equal(elements['port-rows'].children[0].children[0].textContent,'Vlan1');
elements['ports-search'].value='no-match';elements['ports-search'].listeners.input();
assert.equal(elements['port-rows'].children[0].children[0].textContent,'No ports match your search.');
elements['ports-search'].value='';elements['ports-search'].listeners.input();
assert.equal(elements['port-rows'].children.length,97);
form.elements.category_id.value='2';form.elements.device_type.value='Server';form.elements.device_type.listeners.change();
assert.match(elements['port-summary'].textContent,/Preset physical ports: 2/);
form.elements.hostname.value='different';form.elements.hostname.listeners.input();
assert.equal(elements['port-rows'].children.length,2);
assert.equal(elements['port-rows'].children[0].children[3].textContent,'Unknown');
assert.match(elements['port-observation'].textContent,/Save to discover/);
console.log('Preset selection, unknown capacity, full-list search and changed-device isolation passed.');
