const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(require('node:path').join(__dirname,'../js/inventory.js'),'utf8');
const start=source.indexOf('  const copyDefaults = protocol => {');
const end=source.indexOf('  for(const protocol of Object.keys(defaults))copyDefaults(protocol);',start);
assert(start>=0&&end>start,'Default application code must exist');
function apply(saved,newDevice,oldValue,presetValue,protocol='ssh'){
  const control={type:'number',value:oldValue,dispatchEvent(){}};
  const form={elements:{},querySelector(){return null;}};
  const context={document:{getElementById(){return {dataset:{saved},querySelector(){return form;}};}},copied:new Set(),defaults:{[protocol]:{port:presetValue}},overrides:{},dirty:new Map(),newDevice,controlsFor:()=>[control],discoveryTimingNames:new Set(),Event:class {}};
  vm.runInNewContext(source.slice(start,end)+`copyDefaults(${JSON.stringify(protocol)});`,context);
  return control.value;
}
for(const protocol of ['snmp','ssh','lldp','cdp','serial','syslog']){
  assert.equal(apply('1',false,'22','2222',protocol),'22','Saved settings must survive a changed preset');
  assert.equal(apply('0',false,'22','2222',protocol),'2222','New protocol should use current defaults');
  assert.equal(apply('1',true,'22','2222',protocol),'2222','New device draft should use defaults');
}
assert.equal(apply('1',false,'23','2222'),'23','Another device retains its own values');
console.log('PASS: preset changes leave saved device protocols untouched; only new protocols/devices receive defaults.');
