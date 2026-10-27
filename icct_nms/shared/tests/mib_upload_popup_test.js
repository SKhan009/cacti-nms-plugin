/* Upload UI uses real transfer events and server counts, never simulated OID progress. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
class Element{
 constructor(){this.events={};this.hidden=false;this.textContent='';this.disabled=false;this.isConnected=true;this.classList={add(){}};}
 addEventListener(name,callback){this.events[name]=callback;}
 querySelectorAll(){return [];}
 removeAttribute(name){if(name==='value')this.indeterminate=true;}
 showModal(){this.open=true;}
 close(){this.open=false;}
 focus(){}
}
function fixture(){
 const ids=['mibUploadDialog','mibUploadTitle','mibUploadChooser','mibUploadProgress','mibUploadBar','mibUploadCount','mibUploadFilename','mibUploadError','mibUploadIntro','mibUploadNext','mibUploadNextForm','mibUploadProceed'];
 const elements=Object.fromEntries(ids.map(id=>['#'+id,new Element()]));
 const form=new Element(),submit=new Element();form.action={value:'upload'}; // Native named form field shadows form.action.
 form.getAttribute=name=>name==='action'?'protocols/snmp/mibs/controllers/mib_repository.php':null;
 form.querySelector=selector=>selector==='[type=file]'?{files:[{name:'fixture.mib'}]}:submit;form.reportValidity=()=>true;
 const automatic=new Element(),repository=new Element();automatic.value='automatic';automatic.checked=true;repository.value='repository';
 const nextForm=elements['#mibUploadNextForm'];nextForm.querySelector=selector=>selector==='[value=automatic]'?automatic:selector==='[value=repository]'?repository:(repository.checked?repository:automatic);
 const cells=['name','object_count','supported_count','existing_count'].map(key=>Object.assign(new Element(),{dataset:{uploadSummary:key}}));elements['#mibUploadNext'].querySelectorAll=()=>cells;
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
 return {elements,form,submit,requests,timers,navigations,automatic,repository,cells};
}
const f=fixture();f.form.events.submit({preventDefault(){}});const request=f.requests[0];
assert.equal(request.openArgs[0],'POST');assert.equal(request.openArgs[2],true);
assert.equal(request.openArgs[1],'http://localhost/cacti/plugins/icct_nms/protocols/snmp/mibs/controllers/mib_repository.php');
assert.equal(request.body.upload_response,'json');
request.upload.events.progress({lengthComputable:true,loaded:50,total:100});
assert.equal(f.elements['#mibUploadBar'].value,50);
assert.equal(f.elements['#mibUploadCount'].textContent,'Uploading file: 50%');
request.upload.events.load();assert.equal(f.elements['#mibUploadBar'].indeterminate,true);
request.status=200;request.response={ok:true,object_count:17,summary:{name:'FIXTURE-MIB',object_count:17,supported_count:5,existing_count:2,can_configure:true},redirect:'protocols/snmp/mibs/controllers/mib_repository.php?wizard=fixture'};request.events.load();
assert.equal(f.elements['#mibUploadCount'].textContent,'17/17 OIDs Uploaded & Scanned');
f.timers[0]();assert.equal(f.navigations.length,0,'Success skipped the next-step choice');assert.equal(f.elements['#mibUploadNext'].hidden,false);
assert.equal(f.cells[3].textContent,'2');assert.equal(f.elements['#mibUploadProceed'].textContent,'Proceed');
f.elements['#mibUploadNextForm'].events.submit({preventDefault(){}});assert(f.navigations[0].endsWith('?wizard=fixture'));
f.repository.checked=true;f.automatic.checked=false;f.elements['#mibUploadNextForm'].events.change();assert.equal(f.elements['#mibUploadProceed'].textContent,'Save');
f.elements['#mibUploadNextForm'].events.submit({preventDefault(){}});assert(f.navigations[1].endsWith('/mib_repository.php'));
const failed=fixture();failed.form.events.submit({preventDefault(){}});failed.requests[0].status=400;failed.requests[0].response={ok:false,error:'Invalid MIB fixture'};failed.requests[0].events.load();
assert.equal(failed.elements['#mibUploadError'].textContent,'Invalid MIB fixture');
assert.equal(failed.elements['#mibUploadError'].hidden,false);assert.equal(failed.submit.disabled,false);
assert.equal(failed.elements['#mibUploadChooser'].hidden,false);
console.log('PASS: asynchronous endpoint, named-action collision, byte progress, scanning state, actual OID count, summary, both next-step choices and retryable errors.');

for(const files of [[{name:'bad.php'}],[{name:'one.mib'},{name:'two.mib'}],[{name:'large.txt',size:1048577}]]){
 const invalid=fixture();invalid.form.querySelector=selector=>selector==='[type=file]'?{files}:invalid.submit;invalid.form.events.submit({preventDefault(){}});assert.equal(invalid.requests.length,0,'Invalid file rejected before transfer');assert.equal(invalid.elements['#mibUploadError'].hidden,false);
}
console.log('PASS: single-file, extension and size validation before upload.');
