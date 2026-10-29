const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
class Element{
 constructor(){this.events={};this.hidden=true;this.disabled=false;this.attributes={};this.dataset={};this.children=[];this.classList={remove(){}};}
 addEventListener(name,fn){this.events[name]=fn;}
 querySelector(){return null;}
 querySelectorAll(){return [];}
 setAttribute(key,value){this.attributes[key]=value;}
 getAttribute(key){return this.attributes[key];}
 removeAttribute(key){delete this.attributes[key];}
 replaceChildren(...children){this.children=children;}
 append(...children){this.children.push(...children);}
}
(async()=>{
 const selectors=['#mibGenerationForm','#mibGenerate','#mibGenerationProgress','#mibGenerationStatus','#mibGenerationBar','#mibGenerationError','.mib-generation-spinner','#mibGenerationCaption','#mibGenerationResults','#mibGenerationCount'];
 const elements=Object.fromEntries(selectors.map(s=>[s,new Element()])),form=elements['#mibGenerationForm'];form.setAttribute('action','protocols/snmp/mibs/controllers/mib_repository.php');
 const requests=[],navigations=[];let finish;
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../protocols/snmp/mibs/js/mib-repository.js'),'utf8'),{
 document:{baseURI:'http://localhost/cacti/plugins/icct_nms/',querySelector:s=>elements[s]||null,querySelectorAll:()=>[],createElement:()=>new Element(),createTextNode:text=>text},
 location:{origin:'http://localhost',assign:url=>navigations.push(url)},URL,TextDecoder,Uint8Array,
 FormData:class extends Map{constructor(){super();}},fetch:(url,options)=>{requests.push(options);return new Promise(resolve=>finish=resolve);}
 });
 const pending=form.events.submit({preventDefault(){}});
 assert.equal(elements['#mibGenerate'].disabled,true);assert.equal(elements['#mibGenerationProgress'].hidden,false);assert.equal(elements['.mib-generation-spinner'].hidden,false);
 assert.equal(elements['#mibGenerationProgress'].attributes['aria-busy'],'true');assert.equal(elements['#mibGenerationBar'].attributes.value,undefined,'No invented percentage before actual progress arrives');
 assert.match(elements['#mibGenerationStatus'].textContent,/Please wait/);assert.equal(navigations.length,0);
 await form.events.submit({preventDefault(){}});assert.equal(requests.length,1,'Double clicks do not send duplicate generation');
 const events=[{event:'start',total:1},{event:'item',completed:1,total:1,result:{kind:'Data Source',name:'Fixture',oid:'1.3.6.1'}},{event:'complete',redirect:'protocols/snmp/mibs/controllers/mib_repository.php?generate=fixture&stage=2'}].map(event=>JSON.stringify(event)).join('\n')+'\n';
 // Split a JSON record across chunks to exercise buffering of real streamed progress.
 const chunks=[events.slice(0,19),events.slice(19)];let index=0;
 finish({ok:true,headers:{get:()=> 'application/x-ndjson'},body:{getReader:()=>({read:async()=>index<chunks.length?{value:new TextEncoder().encode(chunks[index++]),done:false}:{done:true}})}});await pending;
 assert.equal(navigations.length,1,'Only a successful completed stream opens the results');
 assert.equal(elements['#mibGenerationBar'].value,100);assert.equal(elements['#mibGenerationCount'].textContent,'1 / 1 prepared');
 assert.equal(elements['#mibGenerationResults'].children.length,1,'Each actual prepared item has a result row');
 console.log('Streamed generation progress, partial chunks, result rows and duplicate-submit UI checks passed.');
})().catch(error=>{console.error(error);process.exitCode=1;});
