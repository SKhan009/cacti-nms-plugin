/** Real rack placement editor; all writes use the same service as Add/Edit Device. */
(() => {
  'use strict';
  const source=document.querySelector('#rackViewData');if(!source)return;
  let data=JSON.parse(source.textContent),editing=false,busy=false,scale=1,rackOffset=0,savedData=structuredClone(data),changes=new Map(),reservedChanges=new Map(),selectedNode='',leavePending=false,bypassLeave=false;
  const node=document.querySelector('#rackViewNode'),cabinets=document.querySelector('#rackCabinets'),pool=document.querySelector('#rackDevicePool'),message=document.querySelector('#rackViewMessage'),actions=document.querySelector('#rackEditActions');
  const saveButton=document.querySelector('#rackSaveDraft'),dialog=document.querySelector('#rackDraftDialog');
  const el=(tag,cls,text)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=text;return n;};
  function currentNode(){return data.nodes.find(n=>String(n.id)===node.value);}
  function nodeDevices(){const selected=currentNode();return selected?data.devices.filter(d=>Number(d.site_id)===Number(selected.site_id)&&(!d.node_id||Number(d.node_id)===Number(selected.id))):[];}
  function populateNodes(){const selected=node.value;node.replaceChildren(new Option('Select node',''));data.nodes.forEach(n=>node.add(new Option(n.name+' — '+n.site_name,String(n.id))));node.value=data.nodes.some(n=>String(n.id)===selected)?selected:data.nodes.length?String(data.nodes[0].id):'';}
  function card(device){const n=el('div','rack-device '+(device.status==='Up'?'online':device.status==='Down'?'offline':'other'));n.dataset.deviceId=device.id;n.draggable=editing;n.tabIndex=0;const link=el('span','rack-device-name',device.name);n.append(link,el('small','',device.status));n.title=device.name+' · '+device.status;n.addEventListener('dragstart',event=>{if(!editing||busy){event.preventDefault();return;}event.dataTransfer.setData('text/plain',String(device.id));});return n;}
  function droppable(target,rackId,unit,peripheral=false){target.addEventListener('dragover',event=>{if(editing&&!busy){event.preventDefault();target.classList.add('drag-over');}});target.addEventListener('dragleave',()=>target.classList.remove('drag-over'));target.addEventListener('drop',event=>{event.preventDefault();target.classList.remove('drag-over');const value=event.dataTransfer.getData('text/plain'),id=Number(value);if(editing&&value.startsWith('reserve:'))stageReservation(value,rackId,unit);else if(editing&&nodeDevices().some(d=>d.id===id))stage(id,rackId,unit,peripheral);});}
  function render(){
    const selected=currentNode(),racks=data.racks.filter(r=>String(r.node_id)===node.value),devices=nodeDevices();
    rackOffset=Math.min(rackOffset,Math.max(0,Math.floor((racks.length-1)/3)*3));
    const previous=document.querySelector('#rackPrevious'),next=document.querySelector('#rackNext');
    previous.hidden=next.hidden=racks.length<=3;previous.disabled=rackOffset===0;next.disabled=rackOffset+3>=racks.length;
    document.querySelector('#rackPageStatus').textContent=racks.length>3?'Racks '+(rackOffset+1)+'–'+Math.min(rackOffset+3,racks.length)+' of '+racks.length:'';
    const nodeHome=document.querySelector('#rackNodeToolbar'),nodeField=document.querySelector('#rackNodeField'),nodeContainer=editing?document.querySelector('#rackPoolNode'):nodeHome;
    if(nodeField.parentElement!==nodeContainer)nodeContainer.append(nodeField);nodeHome.hidden=editing;
    cabinets.replaceChildren();pool.hidden=!editing;actions.hidden=!editing;saveButton.disabled=busy||!(changes.size||reservedChanges.size);node.disabled=busy;document.querySelector('#rackViewEdit').hidden=!data.management;
    document.querySelector('#rackViewEdit').setAttribute('aria-pressed',String(editing));
    const list=document.querySelector('#rackDeviceList');list.replaceChildren();if(editing)list.append(reservationCard('reserve:new'));devices.forEach(d=>list.append(card(d)));droppableOncePool();
    if(!selected){cabinets.append(el('p','','Add a node and rack configuration in Presets to get started.'));return;}
    if(!racks.length)cabinets.append(el('p','','This node has no racks. Select its rack configuration in Presets.'));
    racks.slice(rackOffset,rackOffset+3).forEach(rack=>{
      const shell=el('section','rack-cabinet'),heading=el('header','');heading.append(el('h3','',rack.name),el('small','',selected.name+' · Rack '+rack.rack_number+' · '+rack.unit_count+' U'));shell.append(heading);
      const frame=el('div','rack-frame'),slots=el('div','rack-slots');slots.style.gridTemplateRows='repeat('+rack.unit_count+',minmax(0,1fr))';
      const occupied=new Set(rack.blocked.map(Number));(rack.reservations||[]).forEach(item=>{for(let u=item.start;u<item.start+item.height;u++)occupied.add(u);});devices.filter(d=>d.rack_id===Number(rack.id)&&!d.peripheral).forEach(d=>{for(let u=d.start;u<d.start+d.height;u++)occupied.add(u);});
      for(let u=1;u<=Number(rack.unit_count);u++){const slot=el('div','rack-slot'+(occupied.has(u)?' occupied':''));slot.style.gridRow=String(u);slot.append(el('span','rack-unit-label',u+'U'));slot.title=occupied.has(u)?u+'U — In use':u+'U — Available';droppable(slot,Number(rack.id),u);slots.append(slot);}
      devices.filter(d=>d.rack_id===Number(rack.id)&&!d.peripheral).forEach(d=>{const n=card(d);n.style.gridRow=d.start+' / span '+d.height;droppable(n,Number(rack.id),d.start);slots.append(n);});
      frame.append(slots);shell.append(frame);
      (rack.reservations||[]).forEach(item=>{const n=reservationCard('reserve:'+rack.id+':'+item.id);n.style.gridRow=item.start+' / span '+item.height;slots.append(n);});cabinets.append(shell);
    });
  }
  let poolBound=false;function droppableOncePool(){if(!poolBound){droppable(pool,0,0);poolBound=true;}}
  async function refresh(){const response=await fetch('rack_placement.php',{credentials:'same-origin',cache:'no-store'});const result=await response.json();if(!response.ok||!result.ok)throw Error(result.error||'Unable to refresh racks.');data=result.data;savedData=structuredClone(data);populateNodes();render();}
  function reservationCard(value){const n=el('div','rack-device reserved');n.append(el('span','rack-device-name','Reserved slot'));n.draggable=editing;n.tabIndex=0;n.title='Reserved rack space';if(editing&&value!=='reserve:new'){const remove=el('button','','×');remove.type='button';remove.setAttribute('aria-label','Clear reserved slot');remove.addEventListener('click',()=>stageReservation(value,0,0));n.append(remove);}n.addEventListener('dragstart',event=>{if(!editing||busy){event.preventDefault();return;}event.dataTransfer.setData('text/plain',value);});return n;}
  function trackReservations(rack){const original=savedData.racks.find(r=>Number(r.id)===Number(rack.id));if(JSON.stringify(rack.reservations||[])===JSON.stringify(original.reservations||[]))reservedChanges.delete(Number(rack.id));else reservedChanges.set(Number(rack.id),{rack_id:Number(rack.id),revision:original.reservation_revision,items:rack.reservations||[]});}
  function stageReservation(value,rackId,start){
    if(busy)return;const [,sourceId,itemId]=value.split(':'),sourceRack=data.racks.find(r=>String(r.id)===sourceId),existing=sourceRack?.reservations?.find(item=>item.id===itemId),height=existing?.height||1,rack=data.racks.find(r=>Number(r.id)===rackId);
    if(rackId){const end=start+height-1;if(!rack||end>Number(rack.unit_count)||(rack.blocked||[]).some(u=>u>=start&&u<=end)||(rack.reservations||[]).some(item=>item!==existing&&item.start<=end&&item.start+item.height-1>=start)||data.devices.some(d=>d.rack_id===rackId&&!d.peripheral&&d.start<=end&&d.start+d.height-1>=start)){message.textContent='These rack units are unavailable.';return;}}
    if(sourceRack){sourceRack.reservations=sourceRack.reservations.filter(item=>item.id!==itemId);trackReservations(sourceRack);}
    if(rackId){rack.reservations= rack.reservations||[];rack.reservations.push({id:existing?.id||crypto.randomUUID(),start,height});trackReservations(rack);}
    message.textContent='';render();
  }
  function placement(d){return [d.rack_id,d.start,d.peripheral,d.node_id];}
  function stage(id,rackId,start,peripheral){
    const device=data.devices.find(d=>d.id===id),rack=data.racks.find(r=>Number(r.id)===rackId);if(!device||busy)return;
    const height=device.height||1;
    if(rackId&&!peripheral){
      if(start+height-1>Number(rack.unit_count)){message.textContent='The device extends beyond the rack capacity.';return;}
      const end=start+height-1;
      const collision=(rack.blocked||[]).some(u=>Number(u)>=start&&Number(u)<=end)||(rack.reservations||[]).some(item=>item.start<=end&&item.start+item.height-1>=start)||data.devices.some(d=>d.id!==id&&d.rack_id===rackId&&!d.peripheral&&d.start<=end&&d.start+d.height-1>=start);
      if(collision){message.textContent='These rack units are already occupied.';return;}
    }
    Object.assign(device,{rack_id:rackId,start:rackId&&!peripheral?start:0,peripheral:!!(rackId&&peripheral),node_id:rackId?Number(rack.node_id):0});
    const original=savedData.devices.find(d=>d.id===id);
    if(JSON.stringify(placement(device))===JSON.stringify(placement(original)))changes.delete(id);
    else changes.set(id,{host_id:id,rack_id:rackId,units:rackId&&!peripheral?Array.from({length:height},(_,i)=>start+i):[],peripheral:device.peripheral,revision:original.revision});
    message.textContent='';render();
  }
  async function saveDraft(){
    if(busy)return false;if(!(changes.size||reservedChanges.size))return true;
    const body=new FormData(document.querySelector('#rackViewToken'));body.set('moves',JSON.stringify([...changes.values()]));body.set('reservations',JSON.stringify([...reservedChanges.values()]));busy=true;render();message.textContent='Saving…';
    try{const response=await fetch('rack_placement.php',{method:'POST',credentials:'same-origin',body});const result=await response.json();if(!response.ok||!result.ok)throw Error(result.error||'Changes could not be saved.');data=result.data;savedData=structuredClone(data);changes.clear();reservedChanges.clear();populateNodes();message.textContent='';return true;}
    catch(error){message.textContent=error.message;return false;}
    finally{busy=false;render();}
  }
  function discard(){data=structuredClone(savedData);changes.clear();reservedChanges.clear();message.textContent='';render();}
  async function leave(action){
    if(busy||leavePending)return;
    if(!(changes.size||reservedChanges.size)){action();return;}
    leavePending=true;dialog.returnValue='cancel';dialog.showModal();
    const choice=await new Promise(resolve=>{dialog.addEventListener('close',()=>resolve(dialog.returnValue||'cancel'),{once:true});});
    leavePending=false;
    if(choice==='discard'){discard();action();}
    else if(choice==='save'&&await saveDraft())action();
  }
  dialog.querySelectorAll('[data-rack-choice]').forEach(button=>button.addEventListener('click',()=>dialog.close(button.dataset.rackChoice)));
  node.addEventListener('change',()=>{const requested=node.value;node.value=selectedNode;leave(()=>{node.value=requested;selectedNode=requested;rackOffset=0;message.textContent='';render();});});
  document.querySelector('#rackViewEdit').addEventListener('click',()=>{if(editing)leave(()=>{editing=false;render();});else{editing=true;selectedNode=node.value;render();}});
  saveButton.addEventListener('click',saveDraft);
  document.addEventListener('click',event=>{
    if(bypassLeave||!editing)return;
    const target=event.target.closest('a[href],.icct-view-tabs [role="tab"]');
    if(!target||target.dataset.view==='rack'||target.getAttribute('href')?.startsWith('#'))return;
    if(busy||(changes.size||reservedChanges.size)){event.preventDefault();event.stopImmediatePropagation();leave(()=>{editing=false;render();bypassLeave=true;target.click();bypassLeave=false;});}
    else{editing=false;render();}
  },true);
  document.addEventListener('icct:before-view-change',event=>{
    if(bypassLeave||!editing||event.detail.tab.dataset.view==='rack')return;
    if(busy||(changes.size||reservedChanges.size)){event.preventDefault();leave(()=>{editing=false;render();bypassLeave=true;event.detail.tab.click();bypassLeave=false;});}
    else{editing=false;render();}
  });
  // Browser reload/close uses the native unsaved-changes warning.
  window.addEventListener('beforeunload',event=>{if((changes.size||reservedChanges.size)||busy){event.preventDefault();event.returnValue='';}});
  function zoom(){cabinets.style.setProperty('--rack-scale',scale);}
  document.querySelector('#rackPrevious').addEventListener('click',()=>{rackOffset=Math.max(0,rackOffset-3);render();});
  document.querySelector('#rackNext').addEventListener('click',()=>{rackOffset+=3;render();});
  document.querySelector('#rackViewZoomIn').addEventListener('click',()=>{scale=Math.min(2,scale+.1);zoom();});
  document.querySelector('#rackViewZoomOut').addEventListener('click',()=>{scale=Math.max(.6,scale-.1);zoom();});
  document.querySelector('#rackViewFit').addEventListener('click',()=>{scale=1;zoom();document.querySelector('#icct-panel-rack').scrollTop=0;});
  document.querySelector('#rackViewFullscreen').addEventListener('click',()=>{if(document.fullscreenElement)document.exitFullscreen();else document.querySelector('.icct-map-panel').requestFullscreen().catch(()=>message.textContent='Fullscreen is unavailable.');});
  populateNodes();selectedNode=node.value;render();setInterval(()=>{if(!editing&&!busy&&!document.querySelector('#icct-panel-rack').hidden)refresh().catch(error=>message.textContent=error.message);},30000);
})();
