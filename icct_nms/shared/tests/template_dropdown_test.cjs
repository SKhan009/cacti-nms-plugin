const fs=require('fs'),vm=require('vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(require('path').join(__dirname,'../../shared/js/inventory.js'),'utf8');
const code=source.slice(source.indexOf('// Search the installed core templates'),source.indexOf('const typeProfileData'));
for(const mode of ['legacy','modern','broken-api']) {
 let document,changes=0,showCalls=0;
 class Element {
  constructor(tag){this.tag=tag;this.children=[];this.events={};this.attrs={};this.dataset={};this.style={};this.hidden=false;this.disabled=false;this.value='';this.textContent='';const classes=new Set();this.classList={add:x=>classes.add(x),remove:x=>classes.delete(x),contains:x=>classes.has(x)};if(mode!=='legacy'&&tag==='div'){this.showPopover=()=>{showCalls++;if(mode==='broken-api')throw Error('unsupported');};this.hidePopover=()=>{};}}
  setAttribute(k,v){this.attrs[k]=v;} removeAttribute(k){delete this.attrs[k];}
  matches(selector){if(selector.includes('popover'))throw Error('Unsupported selector');return this.disabled;}
  append(...nodes){this.children.push(...nodes);} replaceChildren(){this.children=[];}
  addEventListener(k,fn){(this.events[k]??=[]).push(fn);} emit(k,data={}){for(const fn of this.events[k]||[])fn({target:this,preventDefault(){},...data});}
  dispatchEvent(e){if(e.type==='change')changes++;}
  focus(){document.activeElement=this;} contains(x){return this===x||this.children.some(c=>c.contains(x));}
  querySelector(){return this.children.find(x=>x.tag==='button'&&!x.disabled);}
  querySelectorAll(){return this.children.filter(x=>x.tag==='button'&&!x.disabled);}
  closest(selector){return selector==='.field'?field:selector==='.select-wrap'?wrap:null;}
  getBoundingClientRect(){return {top:100,bottom:140,left:20,width:300};}
 }
 const field=new Element('label'),wrap=new Element('span'),select=new Element('select');
 select.options=[{textContent:'None',value:'0',selected:true},{textContent:'Local Linux Machine',value:'21',selected:false},{textContent:'Net-SNMP Device',value:'8',selected:false}];select.selectedOptions=[select.options[0]];
 document={body:new Element('body'),querySelectorAll:()=>[select],createElement:tag=>new Element(tag),events:{},addEventListener(k,fn){(this.events[k]??=[]).push(fn);}};
 const window={addEventListener(){}};
 vm.runInNewContext(code,{document,window,innerHeight:700,innerWidth:1000,Event});
 const trigger=field.children[0],panel=document.body.children[0],search=panel.children[0],list=panel.children[1];
 assert.equal(panel.hidden,true);
 trigger.emit('click');assert.equal(panel.hidden,false);assert.equal(trigger.attrs['aria-expanded'],'true');assert.equal(list.children.length,3);
 search.value='linux';search.emit('input');assert.equal(list.children.length,1);assert.equal(list.children[0].textContent,'Local Linux Machine');
 list.children[0].emit('click');assert.equal(select.value,'21');assert.equal(changes,1);assert.equal(panel.hidden,true);
 trigger.emit('click');panel.emit('keydown',{key:'Escape'});assert.equal(panel.hidden,true);
 trigger.emit('click');for(const fn of document.events.click)fn({target:new Element('outside')});assert.equal(panel.hidden,true);
 assert.equal(mode==='legacy'?showCalls===0:showCalls>0,true);
 console.log(mode+': open, search, select, Escape and outside close passed');
}
