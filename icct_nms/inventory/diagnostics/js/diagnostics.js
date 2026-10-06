(function(){
'use strict';
const form=document.querySelector('#diagnostic-run-form'),result=document.querySelector('#diagnostic-live-result');if(!result)return;
const status=document.querySelector('#diagnostic-live-status'),output=document.querySelector('#diagnostic-live-output'),progress=document.querySelector('#diagnostic-live-progress'),error=document.querySelector('#diagnostic-live-error');
let timer=null,job=Number(result.dataset.jobId),pending=['queued','running'].includes(result.dataset.status),busy=false;
function controls(){if(form){form.querySelector('button[type="submit"]').disabled=pending||busy;form.querySelector('select').disabled=pending||busy;}}
function fail(message){error.textContent=message;error.hidden=false;pending=false;busy=false;progress.hidden=true;controls();}
function display(data){job=data.job_id;pending=['queued','running'].includes(data.status);result.hidden=false;status.textContent=data.status;output.textContent=data.output||'';output.hidden=!data.output;progress.hidden=!pending;controls();if(pending)timer=setTimeout(poll,500);}
async function poll(){try{const response=await fetch('inventory/diagnostics/controllers/diagnostics.php?action=status&host_id='+encodeURIComponent(result.dataset.hostId)+'&job_id='+encodeURIComponent(job),{credentials:'same-origin',cache:'no-store'});const data=await response.json();if(!response.ok||!data.ok)throw Error(data.error||'Unable to read diagnostic result.');display(data);}catch(e){fail(e.message);}}
if(form)form.addEventListener('submit',async e=>{e.preventDefault();if(busy||pending)return;error.hidden=true;const body=new FormData(form);body.set('action','queue');busy=true;controls();try{const response=await fetch('inventory/diagnostics/controllers/diagnostics.php',{method:'POST',body,credentials:'same-origin'}),data=await response.json();if(!response.ok||!data.ok)throw Error(data.error||'Unable to run diagnostic.');busy=false;const url=new URL(location.href);url.searchParams.set('tool',body.get('tool'));url.searchParams.set('job_id',data.job_id);history.replaceState(null,'',url);display(data);}catch(e){fail(e.message);}});
controls();if(pending)poll();window.addEventListener('pagehide',()=>clearTimeout(timer));
})();
