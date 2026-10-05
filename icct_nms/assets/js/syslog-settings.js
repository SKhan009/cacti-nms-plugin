"use strict";
// Hidden JSON controls keep presets and device drafts compatible with one validated format.
(() => {
  const menus=[...document.querySelectorAll('.syslog-select-menu')];
  document.querySelectorAll('[data-syslog-selection]').forEach(section=>{
    const hidden=section.querySelector('input[type=hidden]'),checks=[...section.querySelectorAll('input[type=checkbox]')],count=section.querySelector('.syslog-selected-count');
    const render=()=>{const selected=JSON.parse(hidden.value||'[]');checks.forEach(c=>c.checked=selected.includes(Number(c.value)));count.textContent=String(selected.length);};
    const sync=()=>{hidden.value=JSON.stringify(checks.filter(c=>c.checked).map(c=>Number(c.value)));hidden.dispatchEvent(new Event('input',{bubbles:true}));};
    checks.forEach(c=>c.addEventListener('change',sync));
    section.querySelector('.syslog-select-all').addEventListener('click',()=>{checks.forEach(c=>c.checked=true);sync();});
    section.querySelector('.syslog-select-none').addEventListener('click',()=>{checks.forEach(c=>c.checked=false);sync();});
    hidden.addEventListener('input',render);render();
  });
  menus.forEach(menu=>{
    menu.querySelector('summary').addEventListener('click',()=>{if(!menu.open)menus.forEach(other=>{if(other!==menu)other.open=false;});});
    menu.addEventListener('keydown',event=>{if(event.key==='Escape'){menu.open=false;menu.querySelector('summary').focus();}});
  });
  document.addEventListener('click',event=>menus.forEach(menu=>{if(!menu.contains(event.target))menu.open=false;}));
  document.querySelectorAll('.syslog-keywords').forEach(section=>{
    const hidden=section.querySelector('input[type=hidden]'),input=section.querySelector('.syslog-keyword-input'),chips=section.querySelector('.syslog-keyword-chips'),counter=section.querySelector('.syslog-keyword-counter');
    let values=JSON.parse(hidden.value||'[]'),writing=false;
    const candidates=()=>[...new Set([...values,...input.value.split(',').map(s=>s.trim()).filter(Boolean)])];
    const sync=()=>{const next=candidates(),length=Array.from(next.join(', ')).length;input.setCustomValidity(length>148?'Match strings must total no more than 148 characters.':'');counter.textContent=length+'/148';if(length<=148){writing=true;hidden.value=JSON.stringify(next);hidden.dispatchEvent(new Event('input',{bubbles:true}));writing=false;}};
    const render=()=>{chips.replaceChildren();values.forEach(value=>{const chip=document.createElement('span');chip.className='syslog-keyword-chip';const remove=document.createElement('button');remove.type='button';remove.textContent='×';remove.setAttribute('aria-label','Remove '+value);remove.addEventListener('click',()=>{values=values.filter(v=>v!==value);render();sync();});chip.append(remove,document.createTextNode(value));chips.append(chip);});counter.textContent=Array.from(values.join(', ')).length+'/148';};
    const commit=()=>{sync();if(input.validationMessage)return;values=candidates();input.value='';render();sync();};
    input.addEventListener('input',sync);
    input.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===','){event.preventDefault();commit();}});
    input.addEventListener('blur',commit);
    hidden.addEventListener('input',()=>{if(!writing){values=JSON.parse(hidden.value||'[]');input.value='';render();}});
    render();
  });
})();
