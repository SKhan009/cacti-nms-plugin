/** Read into a temporary session draft; only explicit Save/Proceed persists files. */
(()=>{'use strict';
 const dialog=document.querySelector('#mibUploadDialog');if(!dialog)return;
 const title=document.querySelector('#mibUploadTitle'),chooser=document.querySelector('#mibUploadChooser'),progress=document.querySelector('#mibUploadProgress'),bar=document.querySelector('#mibUploadBar'),count=document.querySelector('#mibUploadCount'),filename=document.querySelector('#mibUploadFilename'),error=document.querySelector('#mibUploadError');
 const intro=document.querySelector('#mibUploadIntro'),nextStep=document.querySelector('#mibUploadNext'),nextForm=document.querySelector('#mibUploadNextForm'),proceed=document.querySelector('#mibUploadProceed'),readNext=document.querySelector('#mibUploadReadNext'),readyText=document.querySelector('#mibUploadReadyText'),progressText=document.querySelector('#mibUploadProgressText');
 const warning=document.querySelector('#mibUploadRepositoryWarning'),matches=document.querySelector('#mibUploadRepositoryMatches'),consent=document.querySelector('#mibUploadRepositoryConsent'),ack=document.querySelector('#mibUploadRepositoryAcknowledged');
 const closeButtons=[...dialog.querySelectorAll('[data-mib-upload-close]')];
 let busy=false,saving=false,returnFocus=null,completed=null,activeToken=null,activeRequest=null;
 const endpoint=new URL(chooser.getAttribute('action'),document.baseURI);
 function working(value){busy=value;closeButtons.forEach(button=>button.disabled=value&&saving);dialog.setAttribute('aria-busy',String(value));}
 function controls(action,token){const body=new FormData(chooser);body.delete('mibs[]');body.set('action',action);body.set('upload_token',token);return body;}
 function showError(message){error.textContent=message;error.hidden=false;}
 function repositoryWarning(comparison){
  matches.replaceChildren();ack.checked=false;warning.hidden=!comparison?.warnings?.length;consent.hidden=warning.hidden;ack.required=!warning.hidden;
  for(const match of comparison?.warnings||[]){const row=document.createElement('li');row.textContent=(match.same_module?'Same module with different content: ':'Overlapping OIDs with module: ')+match.module+' — '+match.file+'. '+(match.oid_comparison_complete?'OID counts — shared: '+match.shared_oids+', added: '+match.added_oids+', removed: '+match.removed_oids+', changed: '+match.changed_oids+'.':'OID comparison unavailable because some MIB definitions are unresolved.');matches.append(row);}
 }
 function validComparison(value){return value&&typeof value.blocked==='boolean'&&Array.isArray(value.warnings)&&Array.isArray(value.exact)&&/^[a-f0-9]{64}$/.test(value.fingerprint);}
 function discardDraft(token){return fetch(endpoint.href,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body:controls('discard_upload',token),keepalive:true}).catch(()=>{});}
 function discard(){
  if(saving)return;
  const token=activeToken;activeToken=null;completed=null;
  if(activeRequest){activeRequest.abort();activeRequest=null;}
  working(false);chooser.reset();nextForm.reset();chooser.querySelector('[type=submit]').disabled=false;
  chooser.hidden=false;progress.hidden=true;nextStep.hidden=true;intro.hidden=false;error.hidden=true;readNext.hidden=true;proceed.disabled=false;
  repositoryWarning(null);dialog.classList.remove('mib-upload-next-step');title.textContent='Upload MIB';
  if(token)discardDraft(token);
  if(returnFocus?.isConnected)returnFocus.focus();
 }
 closeButtons.forEach(button=>button.addEventListener('click',()=>{if(!saving)dialog.close();}));
 dialog.addEventListener('cancel',event=>{if(saving)event.preventDefault();});
 dialog.addEventListener('close',discard);
 document.querySelectorAll('[data-mib-upload-open]').forEach(button=>button.addEventListener('click',event=>{if(event.metaKey||event.ctrlKey||event.shiftKey||event.altKey||event.button!==0)return;event.preventDefault();returnFocus=button;dialog.showModal();}));
 if(dialog.dataset.autoOpen==='true')dialog.showModal();
 readNext.addEventListener('click',()=>{if(!completed||busy)return;progress.hidden=true;nextStep.hidden=false;dialog.classList.add('mib-upload-next-step');title.textContent='Choose Next Step';proceed.focus();});
 function choice(){proceed.textContent=nextForm.querySelector('[name=upload_next]:checked').value==='repository'?'Save':'Proceed';proceed.disabled=busy||!!completed?.repository_check?.blocked||(!consent.hidden&&!ack.checked);}
 nextForm.addEventListener('change',choice);
 nextForm.addEventListener('submit',async event=>{
  event.preventDefault();if(!completed||busy)return;
  if(completed.repository_check?.blocked)return;
  if(!consent.hidden&&!ack.checked){showError('Review and acknowledge the repository warning before saving.');ack.focus();return;}
  const token=activeToken,body=controls('commit_upload',token);body.set('repository_ack',ack.checked?completed.repository_check.fingerprint:'');body.set('upload_next',nextForm.querySelector('[name=upload_next]:checked').value);
  saving=true;working(true);proceed.disabled=true;error.hidden=true;title.textContent='Saving MIB…';
  try{
   const response=await fetch(endpoint.href,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body});
   if(!response.headers.get('content-type')?.includes('application/json'))throw Error('Your session expired. Reopen the repository.');
   const result=await response.json();if(!response.ok||!result.ok){if(validComparison(result.repository_check)){completed.repository_check=result.repository_check;repositoryWarning(result.repository_check);}throw Error(result.error||'Could not save the MIB file.');}
   if(typeof result.redirect!=='string')throw Error('The save response was incomplete. Retry Save to check the same upload.');
   const next=new URL(result.redirect,document.baseURI);if(next.origin!==location.origin||next.pathname!==endpoint.pathname)throw Error('Invalid repository destination.');
   activeToken=null;completed=null;location.assign(next.href);
  }catch(failure){saving=false;working(false);proceed.disabled=false;title.textContent='Choose Next Step';showError(failure.message);choice();}
 });
 chooser.addEventListener('submit',event=>{
  event.preventDefault();if(busy||!chooser.reportValidity())return;
  const files=[...chooser.querySelector('[type=file]').files];if(!files.length)return;
  const fileError=files.length!==1?'Select one MIB file.':!(/\.(mib|my|txt)$/i.test(files[0].name))?'Select a .mib, .my or .txt MIB file.':files[0].size>1048576?'The MIB file must be no larger than 1 MiB.':'';
  if(fileError){showError(fileError);return;}
  if(activeToken)discardDraft(activeToken);
  const token=crypto.randomUUID().replace(/-/g,'');activeToken=token;completed=null;
  repositoryWarning(null);readNext.hidden=true;working(true);chooser.querySelector('[type=submit]').disabled=true;title.textContent='Reading MIB';error.hidden=true;chooser.hidden=true;progress.hidden=false;bar.max=100;bar.value=0;
  progressText.textContent='Reading the file. It will only be saved when you choose Save or Proceed.';filename.textContent='Uploading “'+files[0].name+'”';count.textContent='Uploading file…';
  const body=new FormData(chooser);body.set('upload_response','json');body.set('upload_progress','1');body.set('upload_token',token);
  const xhr=new XMLHttpRequest();activeRequest=xhr;xhr.open('POST',endpoint.href,true);xhr.responseType='text';xhr.timeout=180000;xhr.setRequestHeader('Accept','application/x-ndjson');
  function fail(message){if(activeToken!==token)return;activeRequest=null;working(false);chooser.querySelector('[type=submit]').disabled=false;title.textContent='Upload MIB';chooser.hidden=false;progress.hidden=true;showError(message);}
  let received=0,pending='',streamResult=null;
  function readUpdates(){
   if(activeToken!==token)return;
   const text=xhr.responseText||'';pending+=text.slice(received);received=text.length;
   let newline;while((newline=pending.indexOf('\n'))>=0){const line=pending.slice(0,newline).trim();pending=pending.slice(newline+1);if(!line)continue;let update;try{update=JSON.parse(line);}catch(_){continue;}
    if(update.type==='complete'||update.type==='error'){streamResult=update;continue;}
    if(update.type!=='progress')continue;
    if(update.phase==='scan'&&Number.isInteger(update.processed)&&Number.isInteger(update.total)&&update.total>=0&&update.processed>=0&&update.processed<=update.total){bar.max=Math.max(1,update.total);bar.value=update.processed;count.textContent=update.processed+'/'+update.total+' OIDs Read & Scanned';filename.textContent='Scanning “'+files[0].name+'”'+(update.symbol?' — '+update.symbol:'');}
    else if(typeof update.message==='string')count.textContent=update.message;
   }
  }
  xhr.addEventListener('progress',readUpdates);
  xhr.upload.addEventListener('progress',event=>{if(activeToken===token&&event.lengthComputable){bar.max=100;bar.value=event.loaded/event.total*100;count.textContent='Uploading file: '+Math.round(bar.value)+'%';}});
  xhr.upload.addEventListener('load',()=>{if(activeToken===token){bar.removeAttribute('value');count.textContent='File uploaded temporarily. Scanning OIDs…';}});
  xhr.addEventListener('load',()=>{
   if(activeToken!==token)return;
   readUpdates();let result=streamResult;try{if(!result)result=typeof xhr.response==='object'?xhr.response:JSON.parse(xhr.responseText||xhr.response||'null');}catch(_){}
   if(xhr.status<200||xhr.status>=300||!result?.ok){fail(result?.error||'The file could not be read. Check the file and retry.');return;}
   const summary=result.summary;
   if(!validComparison(summary?.repository_check)){fail('The server did not return a complete repository duplicate check. Read the file again.');return;}
   if(summary.repository_check.blocked){fail(summary.repository_check.message);return;}
   if(result.upload_token!==token||!summary||typeof summary.name!=='string'||typeof summary.can_configure!=='boolean'||!['object_count','supported_count','existing_count'].every(key=>Number.isInteger(summary[key])&&summary[key]>=0)||summary.existing_count>summary.supported_count||summary.supported_count>summary.object_count||result.object_count!==summary.object_count){fail('The server returned an incomplete MIB summary. Close the popup and read the file again.');return;}
   completed=summary;repositoryWarning(summary.repository_check);activeRequest=null;working(false);chooser.querySelector('[type=submit]').disabled=false;intro.hidden=true;readNext.hidden=false;title.textContent=summary.parse_error?'MIB Read — Action Required':'MIB Read & Scanned';
   bar.max=100;bar.value=100;filename.textContent='File: '+files[0].name;count.textContent=summary.object_count+'/'+summary.object_count+' OIDs Read & Scanned';
   progressText.textContent='File reading completed. Nothing has been saved to the repository.';
   readyText.textContent=summary.parse_error?'Resolve these dependencies to configure monitoring, or save the file to the repository only.':'Choose Save or Proceed to keep this file. Closing discards it.';
   nextStep.querySelectorAll('[data-upload-summary]').forEach(cell=>cell.textContent=String(summary[cell.dataset.uploadSummary]??'—'));
   const automatic=nextForm.querySelector('[value=automatic]'),repositoryChoice=nextForm.querySelector('[value=repository]');automatic.disabled=!summary.can_configure;automatic.checked=!!summary.can_configure;repositoryChoice.checked=!summary.can_configure;choice();
   if(summary.parse_error)showError(summary.parse_error);readNext.focus();
  });
  xhr.addEventListener('error',()=>fail('The connection was interrupted. No file was saved to the repository. Retry reading the file.'));
  xhr.addEventListener('timeout',()=>fail('Reading timed out. No file was saved to the repository. Close the popup and retry.'));
  xhr.send(body);
 });
})();
