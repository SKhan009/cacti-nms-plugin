(function(){
'use strict';
const dialog=document.querySelector('#shared-diagnostic-dialog');if(!dialog)return;
const form=document.querySelector('#shared-diagnostic-form'),select=document.querySelector('#shared-diagnostic-tool'),status=document.querySelector('#shared-diagnostic-status'),output=document.querySelector('#shared-diagnostic-output'),title=document.querySelector('#shared-diagnostic-title'),run=form.querySelector('[type=submit]');
const endpoint=new URL('inventory/diagnostics/controllers/diagnostics.php',document.baseURI);let timer=null,version=0,host=0,job=0,pending=false,busy=false,returnFocus=null;
// Collector recovery instructions are informational; the browser never runs them.
const recovery=document.createElement('section');recovery.id='shared-diagnostic-recovery';recovery.hidden=true;
const recoveryTitle=document.createElement('h3');recoveryTitle.textContent='Diagnostic service recovery';recovery.append(recoveryTitle);
const serviceRecovery=document.createElement('div');recovery.append(serviceRecovery);
const recoveryNote=document.createElement('p');recoveryNote.textContent='An administrator can run these commands on the assigned collector.';serviceRecovery.append(recoveryNote);
for(const [label,command] of [
 ['To restart it manually:','sudo systemctl restart icct-nms-diagnostics.service'],
 ['To check status or errors:','sudo systemctl status icct-nms-diagnostics.service\nsudo journalctl -u icct-nms-diagnostics.service -n 50']
]){const text=document.createElement('p');text.textContent=label;const code=document.createElement('pre');code.textContent=command;serviceRecovery.append(text,code);}
const pathcharRecovery=document.createElement('div');recovery.append(pathcharRecovery);
for(const [tag,text] of [
 ['h3','Pathchar raw-socket permission'],
 ['p','If Pathchar reports “socket: Operation not permitted”, an administrator can grant the pchar executable raw-socket access. These commands assume the collector runs as apache.'],
 ['pre','sudo chown root:apache /usr/local/bin/pchar\nsudo chmod 750 /usr/local/bin/pchar\nsudo setcap cap_net_raw=ep /usr/local/bin/pchar\ngetcap /usr/local/bin/pchar'],
 ['p','Then test as the collector account. Replace REMOTE_DEVICE_IP with the remote device address, then run the diagnostic again.'],
 ['pre','sudo -u apache /usr/local/bin/pchar -n -H 20 -R 3 -I 128 REMOTE_DEVICE_IP'],
 ['p','If the collector-account test works but this popup still fails, check the diagnostic service restrictions:'],
 ['pre','sudo systemctl show icct-nms-diagnostics.service -p User -p NoNewPrivileges -p CapabilityBoundingSet'],
 ['p','Reapply the executable capability after replacing or reinstalling pchar.']
]){const element=document.createElement(tag);element.textContent=text;pathcharRecovery.append(element);}
dialog.querySelector('.message-actions').before(recovery);
const runnerFailure=message=>/diagnostic runner.*(offline|unavailable)|collector.*(offline|unavailable)|worker (stopped|timed out)|request expired/i.test(message||'');
function showRecovery(message='',expired=false){const runner=expired||runnerFailure(message);serviceRecovery.hidden=!runner;pathcharRecovery.hidden=select.value!=='pathchar'||pending||busy;recovery.hidden=serviceRecovery.hidden&&pathcharRecovery.hidden;}
select.addEventListener('change',()=>showRecovery(output.textContent));
function controls(){run.disabled=busy||pending||!select.options.length;select.disabled=busy||pending;}
function fail(message){busy=false;pending=false;status.textContent=message;showRecovery(message);controls();}
function display(data){job=data.job_id;pending=['queued','running'].includes(data.status);status.textContent='Result: '+data.status;output.textContent=data.output||'';output.hidden=!data.output;showRecovery(data.output,data.status==='expired');controls();if(pending)timer=setTimeout(()=>poll(version),500);}
async function request(url,options){const response=await fetch(url,{credentials:'same-origin',cache:'no-store',...options});if(!response.headers.get('content-type')?.includes('application/json'))throw Error('Session expired or diagnostics unavailable. Sign in and reopen diagnostics.');const data=await response.json();if(!response.ok||!data.ok)throw Error(data.error||'Unable to read diagnostic.');return data;}
async function poll(v){try{const url=new URL(endpoint);url.search=new URLSearchParams({action:'status',host_id:host,job_id:job});const data=await request(url);if(v===version&&dialog.open)display(data);}catch(e){if(v===version)fail(e.message);}}
async function open(url,focus){clearTimeout(timer);const v=++version;host=Number(url.searchParams.get('host_id'));job=Number(url.searchParams.get('job_id'));pending=false;busy=true;returnFocus=focus;select.replaceChildren();title.textContent='Device Diagnostics';status.textContent='Loading…';output.textContent='';output.hidden=true;recovery.hidden=true;controls();if(!dialog.open)dialog.showModal();
 try{const optionsUrl=new URL(endpoint);optionsUrl.search=new URLSearchParams({action:'options',host_id:host,...(url.searchParams.get('tool')?{tool:url.searchParams.get('tool')}:{})});const data=await request(optionsUrl);if(v!==version||!dialog.open)return;title.textContent=data.name+' — Diagnostics';Object.entries(data.tools).forEach(([key,label])=>select.append(new Option(label,key,false,key===data.tool)));form.elements.host_id.value=host;busy=false;status.textContent=select.options.length?'Select a method and run the diagnostic.':'No diagnostics selected for this device.';controls();showRecovery();if(job){pending=true;controls();showRecovery();poll(v);}}
 catch(e){if(v===version)fail(e.message);}
}
form.addEventListener('submit',async e=>{e.preventDefault();if(busy||pending||!select.options.length)return;const v=version,body=new FormData(form);busy=true;recovery.hidden=true;status.textContent='Requesting diagnostic…';controls();try{const data=await request(endpoint,{method:'POST',body});if(v===version&&dialog.open){busy=false;if(location.pathname===endpoint.pathname){const url=new URL(location.href);url.searchParams.set('tool',select.value);url.searchParams.set('job_id',data.job_id);history.replaceState(null,'',url);}display(data);}}catch(e){if(v===version)fail(e.message);}});
dialog.querySelectorAll('[data-shared-diagnostic-close]').forEach(button=>button.addEventListener('click',()=>dialog.close()));dialog.addEventListener('close',()=>{clearTimeout(timer);version++;if(returnFocus?.isConnected)returnFocus.focus();});window.addEventListener('pagehide',()=>{clearTimeout(timer);version++;});
document.addEventListener('click',e=>{const link=e.target.closest('a[href]');if(!link||e.defaultPrevented||e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;const url=new URL(link.href,location.href);if(url.origin!==location.origin||url.pathname!==endpoint.pathname||!url.searchParams.has('host_id'))return;e.preventDefault();open(url,link.closest(".device-diagnostic-menu")?.querySelector("summary")||link);});
if(location.pathname===endpoint.pathname&&document.querySelector('#diagnostic-live-result')){const section=document.querySelector('.diagnostics-page');section.hidden=true;const reopen=document.createElement('button');reopen.type='button';reopen.className='button primary';reopen.textContent='Open Diagnostics';section.after(reopen);reopen.addEventListener('click',()=>open(new URL(location.href),reopen));open(new URL(location.href),reopen);}
})();
