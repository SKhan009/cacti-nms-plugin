/* Upload UI uses real transfer events and server counts, never simulated OID progress. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
class Element{
 constructor(){this.events={};this.hidden=false;this.textContent='';this.disabled=false;this.isConnected=true;}
 addEventListener(name,callback){this.events[name]=callback;}
 querySelectorAll(){return [];}
 removeAttribute(name){if(name==='value')this.indeterminate=true;}
 showModal(){this.open=true;}
 close(){this.open=false;}
 focus(){}
}
function fixture(){
 const ids=['mibUploadDialog','mibUploadTitle','mibUploadChooser','mibUploadProgress','mibUploadBar','mibUploadCount','mibUploadFilename','mibUploadError'];
 const elements=Object.fromEntries(ids.map(id=>['#'+id,new Element()]));
 const form=new Element(),submit=new Element();form.action={value:'upload'}; // Native named form field shadows form.action.
 form.getAttribute=name=>name==='action'?'protocols/snmp/mibs/controllers/mib_repository.php':null;
 form.querySelector=selector=>selector==='[type=file]'?{files:[{name:'fixture.mib'}]}:submit;form.reportValidity=()=>true;
 const requests=[],timers=[],navigations=[];
 class Request extends Element{
  constructor(){super();this.upload=new Element();requests.push(this);}
  open(...args){this.openArgs=args;}
  setRequestHeader(){}
  send(body){this.body=body;}
 }
 const context={
  document:{baseURI:'http://localhost/cacti/plugins/icct_nms/',querySelector:selector=>elements[selector]||null,querySelectorAll:selector=>selector==='[data-mib-upload-form]'?[form]:[]},
  location:{href:'http://localhost/cacti/plugins/icct_nms/protocols/snmp/mibs/controllers/mib_repository.php',origin:'http://localhost',assign:href=>navigations.push(href)},URL,
  FormData:class{set(key,value){this[key]=value;}},XMLHttpRequest:Request,setTimeout:callback=>timers.push(callback)
 };
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../protocols/snmp/mibs/js/mib-repository.js'),'utf8'),context);
 return {elements,form,submit,requests,timers,navigations};
}
const f=fixture();f.form.events.submit({preventDefault(){}});const request=f.requests[0];
assert.equal(request.openArgs[0],'POST');assert.equal(request.openArgs[2],true);
assert.equal(request.openArgs[1],'http://localhost/cacti/plugins/icct_nms/protocols/snmp/mibs/controllers/mib_repository.php');
assert.equal(request.body.upload_response,'json');
request.upload.events.progress({lengthComputable:true,loaded:50,total:100});
assert.equal(f.elements['#mibUploadBar'].value,50);
assert.equal(f.elements['#mibUploadCount'].textContent,'Uploading file: 50%');
request.upload.events.load();assert.equal(f.elements['#mibUploadBar'].indeterminate,true);
request.status=200;request.response={ok:true,object_count:17,redirect:'protocols/snmp/mibs/controllers/mib_repository.php?wizard=fixture'};request.events.load();
assert.equal(f.elements['#mibUploadCount'].textContent,'17/17 OIDs Uploaded & Scanned');
f.timers[0]();assert(f.navigations[0].endsWith('?wizard=fixture'));
const failed=fixture();failed.form.events.submit({preventDefault(){}});failed.requests[0].status=400;failed.requests[0].response={ok:false,error:'Invalid MIB fixture'};failed.requests[0].events.load();
assert.equal(failed.elements['#mibUploadError'].textContent,'Invalid MIB fixture');
assert.equal(failed.elements['#mibUploadError'].hidden,false);assert.equal(failed.submit.disabled,false);
assert.equal(failed.elements['#mibUploadChooser'].hidden,false);
console.log('PASS: asynchronous endpoint, named-action collision, byte progress, scanning state, actual OID count, redirect and retryable errors.');
