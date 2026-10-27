const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
class Element{
 constructor(){this.events={};this.hidden=true;this.disabled=false;this.attributes={};}
 addEventListener(name,fn){this.events[name]=fn;}
 querySelector(){return null;}
 querySelectorAll(){return [];}
 setAttribute(key,value){this.attributes[key]=value;}
 getAttribute(key){return this.attributes[key];}
 removeAttribute(key){delete this.attributes[key];}
}
(async()=>{
 const selectors=['#mibGenerationForm','#mibGenerate','#mibGenerationProgress','#mibGenerationStatus','#mibGenerationBar','#mibGenerationError','.mib-generation-spinner','#mibGenerationCaption'];
 const elements=Object.fromEntries(selectors.map(s=>[s,new Element()])),form=elements['#mibGenerationForm'];form.setAttribute('action','protocols/snmp/mibs/controllers/mib_repository.php');
 const requests=[],navigations=[];let finish;
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../protocols/snmp/mibs/js/mib-repository.js'),'utf8'),{
 document:{baseURI:'http://localhost/cacti/plugins/icct_nms/',querySelector:s=>elements[s]||null,querySelectorAll:()=>[]},
 location:{origin:'http://localhost',assign:url=>navigations.push(url)},URL,
 FormData:class extends Map{constructor(){super();}},fetch:(url,options)=>{requests.push(options);return new Promise(resolve=>finish=resolve);}
 });
 const pending=form.events.submit({preventDefault(){}});
 assert.equal(elements['#mibGenerate'].disabled,true);assert.equal(elements['#mibGenerationProgress'].hidden,false);assert.equal(elements['.mib-generation-spinner'].hidden,false);
 assert.equal(elements['#mibGenerationProgress'].attributes['aria-busy'],'true');assert.equal(elements['#mibGenerationBar'].attributes.value,undefined,'No invented percentage while Cacti is saving');
 assert.match(elements['#mibGenerationStatus'].textContent,/Please wait/);assert.equal(navigations.length,0);
 await form.events.submit({preventDefault(){}});assert.equal(requests.length,1,'Double clicks do not send duplicate generation');
 finish({ok:true,headers:{get:()=> 'application/json'},json:async()=>({ok:true,redirect:'protocols/snmp/mibs/controllers/mib_repository.php?generate=fixture&stage=2'})});await pending;
 assert.equal(navigations.length,1,'Only a successful committed response opens the results');
 console.log('Generation loading and duplicate-submit UI checks passed.');
})().catch(error=>{console.error(error);process.exitCode=1;});
