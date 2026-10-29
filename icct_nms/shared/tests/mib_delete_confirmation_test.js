const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
(async()=>{
 const events={},flag={value:'0'},button={disabled:false},dialogs=[];let submitted=0,resolve;
 const form={dataset:{mibFile:'Vendor <test> MIB.txt'},addEventListener:(name,fn)=>events[name]=fn,querySelector:selector=>selector==='[name=delete_confirmed]'?flag:button};
 const context={document:{querySelectorAll:selector=>selector==='[data-mib-delete]'?[form]:[],querySelector:()=>null},icctShowMessage:options=>{dialogs.push(options);return new Promise(done=>resolve=done);},HTMLFormElement:{prototype:{submit(){submitted++;}}}};
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../protocols/snmp/mibs/js/mib-list.js'),'utf8'),context);
 let prevented=false;const first=events.submit({preventDefault(){prevented=true;}});assert(prevented);assert.equal(submitted,0);assert.equal(flag.value,'0');assert(dialogs[0].text.includes('Vendor <test> MIB.txt'));assert.equal(dialogs[0].accept,'Delete');
 await events.submit({preventDefault(){}});assert.equal(dialogs.length,1,'Repeated clicks do not open another confirmation');
 resolve(false);await first;assert.equal(submitted,0,'Cancel/close/Escape never submit');assert.equal(flag.value,'0');assert.equal(button.disabled,false);
 const accepted=events.submit({preventDefault(){}});assert.equal(submitted,0);resolve(true);await accepted;assert.equal(flag.value,'1');assert.equal(button.disabled,true);assert.equal(submitted,1);
 console.log('PASS: delete waits for explicit confirmation, names the selected file, blocks repeated clicks, cancels safely, and submits once with confirmation.');
})().catch(error=>{console.error(error);process.exitCode=1;});
let restoreSubmissions=0,notification;
const undoForm={};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../protocols/snmp/mibs/js/mib-list.js'),'utf8'),{document:{querySelector:selector=>selector==='#mibUndoForm'?undoForm:null,querySelectorAll:()=>[]},icctToast:options=>notification=options,HTMLFormElement:{prototype:{submit(){restoreSubmissions++;}}}});
assert.equal(restoreSubmissions,0);assert.equal(notification.title,'MIB file deleted');assert.equal(notification.action.label,'Undo');assert.equal(notification.duration,12000);notification.action.onClick();assert.equal(restoreSubmissions,1);
console.log('PASS: deletion uses an actionable success toast; restoration is submitted only when Undo is clicked.');
