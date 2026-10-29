/* Cancellation, session-only reading, explicit commit and actual progress events. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
class Element{
 constructor(){this.events={};this.dataset={};this.hidden=false;this.disabled=false;this.textContent='';this.isConnected=true;this.classList={add(){},remove(){}};}
 setAttribute(name,value){this[name]=value;}
 addEventListener(name,callback){this.events[name]=callback;}
 querySelectorAll(){return [];}
 removeAttribute(name){if(name==='value')this.indeterminate=true;}
 showModal(){this.open=true;}
 close(){this.open=false;this.events.close?.();}
 focus(){}
 replaceChildren(){this.children=[];}
 append(element){(this.children??=[]).push(element);}
 reset(){this.resets=(this.resets||0)+1;}
}
function fixture(settings={}){
 const ids=['mibUploadDialog','mibUploadTitle','mibUploadChooser','mibUploadProgress','mibUploadBar','mibUploadCount','mibUploadFilename','mibUploadError','mibUploadIntro','mibUploadNext','mibUploadNextForm','mibUploadProceed','mibUploadReadNext','mibUploadReadyText','mibUploadProgressText','mibUploadRepositoryWarning','mibUploadRepositoryMatches','mibUploadRepositoryConsent','mibUploadRepositoryAcknowledged'];
 const elements=Object.fromEntries(ids.map(id=>['#'+id,new Element()])),form=elements['#mibUploadChooser'],dialog=elements['#mibUploadDialog'],opener=new Element(),close=new Element(),submit=new Element(),file={files:[{name:'fixture.mib',size:100}]};
 elements['#mibUploadNext'].hidden=true;elements['#mibUploadReadNext'].hidden=true;
 form.getAttribute=()=> 'protocols/snmp/mibs/controllers/mib_repository.php';form.querySelector=selector=>selector==='[type=file]'?file:submit;form.reportValidity=()=>true;
 form.reset=()=>{form.resets=(form.resets||0)+1;file.files=[];};dialog.querySelectorAll=()=>[close];
 const automatic=new Element(),repository=new Element();automatic.value='automatic';automatic.checked=true;repository.value='repository';
 elements['#mibUploadNextForm'].querySelector=selector=>selector==='[value=automatic]'?automatic:selector==='[value=repository]'?repository:repository.checked?repository:automatic;
 const cells=['name','object_count','supported_count','existing_count'].map(key=>Object.assign(new Element(),{dataset:{uploadSummary:key}}));elements['#mibUploadNext'].querySelectorAll=()=>cells;
 const requests=[],fetches=[],navigations=[];let sequence=0;
 class Request extends Element{
  constructor(){super();this.upload=new Element();requests.push(this);}
  open(...args){this.openArgs=args;}
  setRequestHeader(){}
  send(body){this.body=body;}
  abort(){this.aborted=true;}
 }
 class FormData extends Map{constructor(){super([['__csrf_magic','fixture-csrf'],['action','upload'],['mibs[]','file-bytes']]);}set(key,value){super.set(key,value);return this;}}
 const context={document:{createElement:()=>new Element(),baseURI:'http://localhost/cacti/plugins/icct_nms/',querySelector:selector=>elements[selector]||null,querySelectorAll:selector=>selector==='[data-mib-upload-open]'?[opener]:[]},location:{origin:'http://localhost',assign:href=>navigations.push(href)},URL,FormData,XMLHttpRequest:Request,crypto:{randomUUID:()=> (++sequence).toString(16).padStart(32,'0')},fetch:async(url,options)=>{fetches.push({url,options});const result=settings.responses?.shift()||{ok:true,redirect:'protocols/snmp/mibs/controllers/mib_repository.php?uploaded=1'};return {ok:result.ok,headers:{get:()=> 'application/json'},json:async()=>result};}};
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../protocols/snmp/mibs/js/mib-upload.js'),'utf8'),context);
 const start=()=>form.events.submit({preventDefault(){}});
 const finish=(summary={name:'TEST-MIB',object_count:4,supported_count:2,existing_count:1,can_configure:true})=>{summary={repository_check:{blocked:false,warnings:[],exact:[],fingerprint:'a'.repeat(64)},...summary};const xhr=requests.at(-1);xhr.status=200;xhr.response={ok:true,upload_token:xhr.body.get('upload_token'),object_count:summary.object_count,summary};xhr.events.load();};
 return {elements,form,dialog,opener,close,submit,file,automatic,repository,cells,requests,fetches,navigations,start,finish};
}
(async()=>{
 const f=fixture();f.start();const xhr=f.requests[0];assert.equal(xhr.body.get('upload_progress'),'1');assert.equal(xhr.body.get('upload_token').length,32);assert.equal(f.close.disabled,false,'Cross remains available while reading');
 xhr.upload.events.progress({lengthComputable:true,loaded:50,total:100});assert.equal(f.elements['#mibUploadBar'].value,50);
 xhr.upload.events.load();assert(f.elements['#mibUploadBar'].indeterminate);
 xhr.responseText=JSON.stringify({type:'progress',phase:'scan',processed:2,total:4,symbol:'TEST-MIB::reading'})+'\n';xhr.events.progress();assert.equal(f.elements['#mibUploadCount'].textContent,'2/4 OIDs Read & Scanned');
 f.finish();assert.equal(f.fetches.length,0,'Reading never triggers a commit');assert.equal(f.navigations.length,0);assert.equal(f.elements['#mibUploadNext'].hidden,true);assert.equal(f.elements['#mibUploadReadNext'].hidden,false);
 f.elements['#mibUploadReadNext'].events.click();assert.equal(f.elements['#mibUploadNext'].hidden,false);assert.equal(f.fetches.length,0,'Next never saves');
 await f.elements['#mibUploadNextForm'].events.submit({preventDefault(){}});assert.equal(f.fetches.length,1);assert.equal(f.fetches[0].options.body.get('action'),'commit_upload');assert.equal(f.fetches[0].options.body.get('upload_next'),'automatic');assert(!f.fetches[0].options.body.has('mibs[]'),'Commit does not upload the file again');assert(f.navigations[0].includes('?uploaded=1'));
 const reading=fixture();reading.start();const abandoned=reading.requests[0];reading.close.events.click();assert(abandoned.aborted);assert.equal(reading.fetches[0].options.body.get('action'),'discard_upload');assert(!reading.fetches[0].options.body.has('mibs[]'));assert.equal(reading.file.files.length,0);reading.finish();assert.equal(reading.elements['#mibUploadReadNext'].hidden,true,'Late upload response cannot reopen a cancelled popup');assert.equal(reading.navigations.length,0);
 const completed=fixture();completed.start();completed.finish();completed.close.events.click();assert.equal(completed.fetches.length,1);assert.equal(completed.fetches[0].options.body.get('action'),'discard_upload');assert.equal(completed.navigations.length,0,'Close after reading never navigates or commits');
 const escaped=fixture();escaped.start();let escapePrevented=false;escaped.dialog.events.cancel({preventDefault(){escapePrevented=true;}});assert.equal(escapePrevented,false,'Escape is available during reading');escaped.dialog.close();assert(escaped.requests[0].aborted);assert.equal(escaped.fetches[0].options.body.get('action'),'discard_upload');
 const selected=fixture();selected.close.events.click();assert.equal(selected.requests.length,0);assert.equal(selected.fetches.length,0);assert.equal(selected.file.files.length,0,'Close clears an unsubmitted selection');
 const dependency=fixture();dependency.start();dependency.finish({name:'DEPENDENCY-MIB',object_count:0,supported_count:0,existing_count:0,can_configure:false,parse_error:'Missing dependencies'});assert(dependency.automatic.disabled);assert(dependency.repository.checked);assert.equal(dependency.elements['#mibUploadError'].textContent,'Missing dependencies');assert.equal(dependency.elements['#mibUploadProceed'].textContent,'Save');dependency.elements['#mibUploadReadNext'].events.click();await dependency.elements['#mibUploadNextForm'].events.submit({preventDefault(){}});assert.equal(dependency.fetches[0].options.body.get('upload_next'),'repository');
 for(const files of [[{name:'bad.php'}],[{name:'one.mib'},{name:'two.mib'}],[{name:'large.txt',size:1048577}]]){const invalid=fixture();invalid.file.files=files;invalid.start();assert.equal(invalid.requests.length,0);assert.equal(invalid.elements['#mibUploadError'].hidden,false);}
 const overlap=fixture();overlap.start();overlap.finish({name:'TEST-MIB',object_count:4,supported_count:2,existing_count:1,can_configure:true,repository_check:{blocked:false,warnings:[{same_module:false,module:'OTHER-MIB',file:'renamed.txt',oid_comparison_complete:true,shared_oids:3,added_oids:1,removed_oids:0,changed_oids:1}],exact:[],fingerprint:'b'.repeat(64)}});
 assert.equal(overlap.elements['#mibUploadRepositoryWarning'].hidden,false);assert.equal(overlap.elements['#mibUploadProceed'].disabled,true);await overlap.elements['#mibUploadNextForm'].events.submit({preventDefault(){}});assert.equal(overlap.fetches.length,0,'Unacknowledged module variant cannot save');overlap.elements['#mibUploadRepositoryAcknowledged'].checked=true;overlap.elements['#mibUploadNextForm'].events.change();assert.equal(overlap.elements['#mibUploadProceed'].disabled,false);await overlap.elements['#mibUploadNextForm'].events.submit({preventDefault(){}});assert.equal(overlap.fetches[0].options.body.get('repository_ack'),'b'.repeat(64));
 const duplicate=fixture();duplicate.start();duplicate.requests[0].status=400;duplicate.requests[0].response={ok:false,error:'Exact MIB content already exists.'};duplicate.requests[0].events.load();assert.equal(duplicate.elements['#mibUploadReadNext'].hidden,true);assert.equal(duplicate.elements['#mibUploadError'].textContent,'Exact MIB content already exists.');assert.equal(duplicate.fetches.length,0);
 const changed=fixture({responses:[{ok:false,error:'Repository changed. Review the new warning.',repository_check:{blocked:false,warnings:[{same_module:false,module:'OTHER-MIB',file:'newer.mib',oid_comparison_complete:true,shared_oids:4,added_oids:0,removed_oids:0,changed_oids:1}],exact:[],fingerprint:'c'.repeat(64)}}]});changed.start();changed.finish();await changed.elements['#mibUploadNextForm'].events.submit({preventDefault(){}});assert.equal(changed.navigations.length,0);assert.equal(changed.elements['#mibUploadRepositoryConsent'].hidden,false);assert.equal(changed.elements['#mibUploadRepositoryAcknowledged'].checked,false);assert.equal(changed.elements['#mibUploadProceed'].disabled,true,'Save-time repository changes require a fresh acknowledgment');
 console.log('PASS: exact duplicates stay blocked; cross-module OID warning requires explicit acknowledgment and submits its current fingerprint.');
 console.log('PASS: actual progress, explicit Next, no automatic save, explicit commit, transfer abort, discard after reading, late-response isolation, cleared selection, no duplicate file transfer, dependency-only Save and file validation.');
})().catch(error=>{console.error(error);process.exitCode=1;});
