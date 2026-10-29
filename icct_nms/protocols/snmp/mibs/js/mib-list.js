// Show recoverable removal in the shared success toast, outside the filter layout.
(()=>{'use strict';
 const form=document.querySelector('#mibUndoForm');if(!form)return;
 icctToast({title:'MIB file deleted',text:'The file was removed from the repository.',duration:12000,action:{label:'Undo',onClick:()=>HTMLFormElement.prototype.submit.call(form)}});
})();

// Block the delete POST until the shared project dialog is explicitly accepted.
(()=>{'use strict';
 document.querySelectorAll('[data-mib-delete]').forEach(form=>{
  let pending=false;
  form.addEventListener('submit',async event=>{
   event.preventDefault();if(pending)return;pending=true;
   try{
    const accepted=await icctShowMessage({title:'Delete MIB file',text:`Delete “${form.dataset.mibFile}” from the repository? You can restore it with Undo. Generated Cacti templates will be retained.`,confirm:true,danger:true,accept:'Delete'});
    if(!accepted)return;
    form.querySelector('[name=delete_confirmed]').value='1';
    form.querySelector('[type=submit]').disabled=true;
    HTMLFormElement.prototype.submit.call(form);
   }finally{pending=false;}
  });
 });
})();

// Use the same local filtering and page controls as the Inventory table.
(()=>{'use strict';
 const list=document.querySelector('[data-mib-saved-list]');if(!list)return;
 const rows=[...list.querySelectorAll('[data-list-row]')],filters=[...list.querySelectorAll('[data-list-filter]')],search=list.querySelector('[data-list-search]'),size=list.querySelector('[data-list-size]'),pageSelect=list.querySelector('[data-list-page]'),clear=list.querySelector('[data-list-clear]'),sort=list.querySelector('[data-list-sort]');
 let page=1,ascending=true;
 function rowValues(row,key){return key==='module'&&row.dataset.modules?JSON.parse(row.dataset.modules):[row.dataset[key]];}
 filters.forEach(filter=>{
  const values=[...new Set(rows.flatMap(row=>rowValues(row,filter.dataset.listFilter)).filter(Boolean))].sort((a,b)=>a.localeCompare(b));
  values.forEach(value=>filter.add(new Option(value,value)));
  filter.addEventListener('change',()=>{page=1;render();});
 });
 function render(){
  const query=search.value.trim().toLowerCase(),matches=rows.filter(row=>row.dataset.search.toLowerCase().includes(query)&&filters.every(filter=>!filter.value||rowValues(row,filter.dataset.listFilter).includes(filter.value))).sort((a,b)=>(ascending?1:-1)*a.dataset.name.localeCompare(b.dataset.name,undefined,{numeric:true}));
  const limit=Number(size.value),pages=Math.max(1,Math.ceil(matches.length/limit));page=Math.max(1,Math.min(page,pages));
  rows.forEach(row=>row.hidden=true);list.querySelectorAll('[data-list-editor]').forEach(editor=>editor.hidden=true);list.querySelectorAll('[data-repository-edit]').forEach(button=>button.setAttribute('aria-expanded','false'));
  matches.slice((page-1)*limit,page*limit).forEach(row=>{row.hidden=false;row.parentNode.append(row);const id=row.querySelector('[data-repository-edit]')?.dataset.repositoryEdit;if(id)row.parentNode.append(document.getElementById('mib-edit-'+id));});
  const empty=list.querySelector('[data-list-empty]');empty.hidden=matches.length>0;empty.parentNode.append(empty);
  list.querySelector('[data-list-range]').textContent=(matches.length?`${(page-1)*limit+1}–${Math.min(page*limit,matches.length)}`:'0')+` of ${matches.length} items`;
  pageSelect.replaceChildren(...Array.from({length:pages},(_,i)=>new Option(String(i+1),String(i+1),false,i+1===page)));
  list.querySelector('[data-list-pages]').textContent=`of ${pages} pages`;list.querySelector('[data-list-prev]').disabled=page===1;list.querySelector('[data-list-next]').disabled=page===pages;
  clear.disabled=!query&&!filters.some(filter=>filter.value);
 }
 search.addEventListener('input',()=>{page=1;render();});size.addEventListener('change',()=>{page=1;render();});pageSelect.addEventListener('change',()=>{page=Number(pageSelect.value);render();});
 list.querySelector('[data-list-prev]').addEventListener('click',()=>{page--;render();});list.querySelector('[data-list-next]').addEventListener('click',()=>{page++;render();});
 clear.addEventListener('click',()=>{search.value='';filters.forEach(filter=>filter.value='');page=1;render();});
 sort.addEventListener('click',()=>{ascending=!ascending;sort.closest('th').setAttribute('aria-sort',ascending?'ascending':'descending');sort.querySelector('span').textContent=ascending?'↑':'↓';render();});
 list.querySelectorAll('[data-repository-edit]').forEach(button=>button.addEventListener('click',()=>{const editor=document.getElementById('mib-edit-'+button.dataset.repositoryEdit);editor.hidden=!editor.hidden;button.setAttribute('aria-expanded',String(!editor.hidden));if(!editor.hidden)editor.querySelector('[name=name]').focus();}));
 list.querySelectorAll('[data-repository-cancel]').forEach(button=>button.addEventListener('click',()=>{const editor=button.closest('[data-list-editor]');editor.querySelector('form').reset();editor.hidden=true;const trigger=list.querySelector('[data-repository-edit="'+editor.dataset.listEditor+'"]');trigger.setAttribute('aria-expanded','false');trigger.focus();}));
 render();
})();
