(function(){
'use strict';
const root=document.querySelector('#view-ports');if(!root)return;
const search=root.querySelector('#device-port-search'),rows=Array.from(root.querySelectorAll('[data-port-row]')),count=root.querySelector('#device-port-count'),empty=root.querySelector('#device-port-empty');
if(!search||!count||!empty)return; // Serial devices have register readings rather than IF-MIB ports.
function filter(){const query=search.value.trim().toLocaleLowerCase();let visible=0;for(const row of rows){row.hidden=!row.textContent.toLocaleLowerCase().includes(query);if(!row.hidden)visible++;}count.textContent=visible+' of '+rows.length+' interfaces';empty.hidden=visible>0;empty.textContent=rows.length?'No interfaces match your search.':'No interfaces reported for the current configuration.';}
search.addEventListener('input',filter);
root.querySelectorAll('[data-port-index]').forEach(button=>button.addEventListener('click',()=>{search.value='';filter();const row=rows.find(row=>row.dataset.portRow===button.dataset.portIndex);if(!row)return;rows.forEach(row=>row.classList.remove('port-row-selected'));row.classList.add('port-row-selected');row.scrollIntoView({block:'nearest',behavior:'smooth'});}));
function csvCell(value){value=value.trim();if(/^[=+@-]/.test(value))value="'"+value;return '"'+value.replaceAll('"','""')+'"';}
root.querySelector('#device-port-export').addEventListener('click',()=>{const header=root.querySelector('thead tr'),exportRows=[header,...rows.filter(row=>!row.hidden)],csv=exportRows.map(row=>Array.from(row.cells).map(cell=>csvCell(cell.textContent)).join(',')).join('\r\n');const url=URL.createObjectURL(new Blob(['\uFEFF'+csv],{type:'text/csv;charset=utf-8'})),link=document.createElement('a');link.href=url;link.download='device-interfaces.csv';link.hidden=true;document.body.appendChild(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);});
filter();
})();
