"use strict";
const fs = require("node:fs");
const vm = require("node:vm");
const assert = require("node:assert/strict");
const path = require("node:path");
const {execFileSync} = require("node:child_process");
class Control {
 constructor(name, value="", type="text") { Object.assign(this,{name,value,type,tagName:"INPUT",style:{},handlers:{}}); }
 addEventListener(type, handler) { (this.handlers[type] ??= []).push(handler); }
 change(value) { this.value = value; (this.handlers.change ?? []).forEach(handler=>handler({})); }
}
const amount1 = new Control("amount"), amount2 = new Control("amount"), total = new Control("total");
const prototype = new Control("__proto__");
const shared = new Control("shared"), contextControl = new Control("context");
const locked = new Control("locked","FR"); locked.tagName="SELECT";
locked.options = [{value:"FR",selected:true},{value:"BE",selected:false}];
const controls=[amount1,amount2,total,prototype,locked,shared,contextControl];
const rootForm={elements:controls,handlers:{},addEventListener(type,handler){this.handlers[type]=handler;}};
const document={
 readyState:"complete",
 querySelector: () => rootForm,
 defaultView:{alert:()=>{},addEventListener:()=>{}},
 getElementById:()=>null
};
const context = {document};
vm.runInNewContext(fs.readFileSync(process.argv[2],"utf8"),context,{timeout:1000});
assert.equal(amount1.value,"4");
assert.equal(amount2.value,"4");
assert.equal(total.value,"$8");
assert.equal(prototype.value,"safe");
assert.equal(shared.value,"6");
assert.equal(contextControl.value,"true");
assert.equal(Object.prototype.hasOwnProperty.call(context,"sharedSeed"),false);
assert.equal(Object.prototype.hasOwnProperty.call(context,"doubleAmount"),false);
assert.equal(Object.prototype.hasOwnProperty.call(context,"methods"),false);
amount1.change("5");
assert.equal(amount2.value,"5");
assert.equal(total.value,"$10");
amount1.change("-1");
assert.equal(amount1.value,"5");
assert.equal(amount2.value,"5");
locked.options[0].selected=false; locked.options[1].selected=true;
locked.change("BE");
assert.equal(locked.options[0].selected,true);
assert.equal(locked.options[1].selected,false);
let resetPrevented=false;
rootForm.handlers.reset({preventDefault(){resetPrevented=true;}});
assert.equal(resetPrevented,true);
assert.equal(amount1.value,"1");
assert.equal(amount2.value,"1");
assert.equal(total.value,"$2");
const withoutFormSource=execFileSync(process.env.PDF_RUNTIME_TEST_PHP ?? "php",[path.join(__dirname,"runtime-fixture.php"),"--without-form"],{encoding:"utf8"});
const alerts=[];
const withoutFormDocument={
 readyState:"complete",
 querySelector:()=>null,
 defaultView:{alert:value=>alerts.push(value),addEventListener:()=>{}},
 getElementById:()=>null
};
vm.runInNewContext(withoutFormSource,{document:withoutFormDocument},{timeout:1000});
assert.deepEqual(alerts,["document without form","page without form"]);
const widgetSource=execFileSync(process.env.PDF_RUNTIME_TEST_PHP ?? "php",[path.join(__dirname,"runtime-fixture.php"),"--widget-actions"],{encoding:"utf8"});
const widgetFirst = new Control("shared-widget"), widgetSecond = new Control("shared-widget");
const legacyFirst = new Control("legacy-widget"), legacySecond = new Control("legacy-widget");
const widgetAlerts=[];
const widgetDocument={
 readyState:"complete",
 querySelector:()=>({elements:[widgetFirst,widgetSecond,legacyFirst,legacySecond],addEventListener:()=>{}}),
 defaultView:{alert:value=>widgetAlerts.push(value),addEventListener:()=>{}},
 getElementById:()=>null
};
vm.runInNewContext(widgetSource,{document:widgetDocument},{timeout:1000});
widgetFirst.handlers.focus[0]({});
widgetSecond.handlers.focus[0]({});
legacyFirst.handlers.focus[0]({});
legacySecond.handlers.focus[0]({});
assert.deepEqual(widgetAlerts,["first-focus:shared-widget:initial","second-focus:shared-widget:initial","legacy-first-focus:legacy-widget:legacy","legacy-second-focus:legacy-widget:legacy"]);
widgetFirst.handlers.mouseup[0]({});
assert.equal(widgetFirst.value,"first-value");
assert.equal(widgetSecond.value,"first-value");
widgetSecond.handlers.mouseup[0]({});
assert.equal(widgetFirst.value,"second-value");
assert.equal(widgetSecond.value,"second-value");
widgetSecond.change("changed-by-second");
assert.equal(widgetFirst.value,"changed-by-second");
widgetFirst.change("invalid");
assert.equal(widgetFirst.value,"changed-by-second");
assert.equal(widgetSecond.value,"changed-by-second");
const boundOutput=JSON.parse(execFileSync(process.env.PDF_RUNTIME_TEST_PHP ?? "php",[path.join(__dirname,"runtime-fixture.php"),"--bound-widget-actions"],{encoding:"utf8"}));
const boundControls=Array.from(boundOutput.html.matchAll(/<input\b([^>]*)>/g), match => {
 const attributes=Object.fromEntries(Array.from(match[1].matchAll(/([\w-]+)="([^"]*)"/g), attribute => [attribute[1],attribute[2]]));
 const control=new Control(attributes.name,attributes.value,attributes.type);
 control.id=attributes.id;
 return control;
});
assert.deepEqual(boundControls.map(control=>control.id),["foreign","__proto__","constructor"]);
const boundById=new Map(boundControls.map(control=>[control.id,control]));
const boundAlerts=[];
const boundDocument={
 readyState:"complete",
 querySelector:()=>({elements:boundControls,addEventListener:()=>{}}),
 defaultView:{alert:value=>boundAlerts.push(value),addEventListener:()=>{}},
 getElementById:id=>boundById.get(id) ?? null
};
vm.runInNewContext(boundOutput.script,{document:boundDocument},{timeout:1000});
assert.deepEqual(Object.keys(boundById.get("foreign").handlers),[]);
assert.equal(boundById.get("foreign").value,"unrelated");
boundById.get("__proto__").handlers.focus[0]({});
boundById.get("constructor").handlers.focus[0]({});
assert.deepEqual(boundAlerts,["inner-focus","outer-focus"]);
boundById.get("constructor").handlers.mouseup[0]({});
assert.equal(boundById.get("__proto__").value,"outer-value");
assert.equal(boundById.get("constructor").value,"outer-value");
assert.equal(boundById.get("foreign").value,"unrelated");
boundById.get("__proto__").handlers.mouseup[0]({});
assert.equal(boundById.get("constructor").value,"inner-value");
const missingBoundSource=execFileSync(process.env.PDF_RUNTIME_TEST_PHP ?? "php",[path.join(__dirname,"runtime-fixture.php"),"--missing-bound-widget"],{encoding:"utf8"});
const unmatched=new Control("shared-bound","unrelated"); unmatched.id="foreign";
vm.runInNewContext(missingBoundSource,{
 document:{...boundDocument,querySelector:()=>({elements:[unmatched],addEventListener:()=>{}}),getElementById:()=>null}
},{timeout:1000});
assert.deepEqual(Object.keys(unmatched.handlers),[]);
assert.equal(unmatched.value,"unrelated");
const actionOutput=JSON.parse(execFileSync(process.env.PDF_RUNTIME_TEST_PHP ?? "php",[path.join(__dirname,"runtime-fixture.php"),"--form-actions"],{encoding:"utf8"}));
assert.equal(actionOutput.script.includes("disabled-document-script"),false);
function actionControl(source) {
 const attributes=source.attributes;
 const control=new Control(attributes.name,attributes.value ?? "",attributes.type ?? "");
 Object.assign(control,{id:attributes.id,tagName:source.tag.toUpperCase(),multiple:Object.hasOwn(attributes,"multiple"),disabled:false,required:Object.hasOwn(attributes,"required")});
 control.options=source.options.map(option=>({...option,selected:false}));
 Object.defineProperty(control,"selectedOptions",{get(){return this.options.filter(option=>option.selected);}});
 control.getAttribute=name=>attributes[name] ?? null;
 control.getBoundingClientRect=()=>({left:10,top:20,width:200,height:40});
 return control;
}
const actionControls=actionOutput.controls.map(actionControl);
const unrelatedActionControl=new Control("Person.Name","foreign"); unrelatedActionControl.id="unbound-action-control";
const actionById=new Map(actionControls.map(control=>[control.id,control]));
const actionByName=new Map(actionControls.map(control=>[control.name,control]));
const actionRoot={elements:[unrelatedActionControl,...actionControls],handlers:{},addEventListener(type,handler){this.handlers[type]=handler;}};
actionControls.forEach(control=>{control.form=actionRoot;});
const actionDocument={
 readyState:"complete",
 querySelector:()=>actionRoot,
 defaultView:{alert:()=>{throw new Error("No PDF source script may execute in this profile");},addEventListener:()=>{}},
 getElementById:id=>actionById.get(id) ?? null
};
vm.runInNewContext(actionOutput.script,{document:actionDocument},{timeout:1000});
function clickAction(name,details={}) {
 let prevented=false;
 actionByName.get(name).handlers.click[0]({preventDefault(){prevented=true;},detail:1,...details});
 return prevented;
}
actionByName.get("Person.Name").change("User value");
assert.equal(clickAction("ResetNone"),true);
assert.equal(actionByName.get("Person.Name").value,"User value");
assert.equal(clickAction("ResetPerson"),true);
assert.equal(actionByName.get("Person.Name").value,"Ada");
assert.equal(actionByName.get("Person.Address.City").value,"Paris");
assert.equal(actionByName.get("Person.NoDefault").value,"");
assert.equal(actionByName.get("Person.Secret").value,"Private default");
assert.equal(actionByName.get("Personality.Name").value,"Unrelated current");
assert.equal(unrelatedActionControl.value,"foreign");
clickAction("ResetExcluded");
assert.equal(actionByName.get("Personality.Name").value,"Unrelated default");
const multipleChoice=actionByName.get("Choice.Several");
assert.deepEqual(multipleChoice.selectedOptions.map(option=>option.value),["BE","DE"]);
assert.deepEqual(actionByName.get("Choice.NoDefault").selectedOptions,[]);
assert.equal(actionByName.get("Choice.NoDefault").selectedIndex,-1);
multipleChoice.options.forEach(option=>{option.selected=option.value==="FR";});
multipleChoice.change("");
clickAction("ResetExcluded");
assert.deepEqual(multipleChoice.selectedOptions.map(option=>option.value),["BE","DE"]);
actionByName.get("Person.Name").change("Changed again");
clickAction("ResetAll");
assert.equal(actionByName.get("Person.Name").value,"Ada");
function submitAction(name,details={}) {
 clickAction(name,details);
 let prevented=false;
 actionRoot.handlers.submit({submitter:actionByName.get(name),preventDefault(){prevented=true;}});
 const data=new FormData();
 data.append("Person.Name","foreign"); data.append("unrelated-payload","must not leak");
 if (!prevented) actionRoot.handlers.formdata({formData:data});
 return {prevented,entries:Array.from(data.entries())};
}
assert.deepEqual(submitAction("SubmitPerson"),{prevented:false,entries:[["customer_name","Ada"],["Person.Address.City","Paris"]]});
assert.deepEqual(submitAction("SubmitExcluded").entries,[["Personality.Name","Unrelated default"],["Choice.Several","BE"],["Choice.Several","DE"]]);
assert.deepEqual(submitAction("SubmitAll").entries,[["customer_name","Ada"],["Person.Address.City","Paris"],["Person.NoDefault",""],["Person.Empty",""],["Personality.Name","Unrelated default"],["Choice.Several","BE"],["Choice.Several","DE"],["Choice.NoDefault",""]]);
assert.deepEqual(submitAction("SubmitNone").entries,[]);
assert.deepEqual(submitAction("SubmitCoordinates",{clientX:110,clientY:40}).entries,[["x","50"],["y","10"]]);
actionByName.get("Person.Secret").required=true;
actionByName.get("Person.Secret").checkValidity=()=>false;
actionByName.get("Personality.Name").required=true;
actionByName.get("Personality.Name").checkValidity=()=>false;
assert.equal(submitAction("SubmitPerson").prevented,false);
actionByName.get("Person.Name").required=true;
actionByName.get("Person.Name").checkValidity=()=>false;
assert.equal(submitAction("SubmitPerson").prevented,true);
actionByName.get("Person.Name").required=false;
actionByName.get("SubmitPerson").getAttribute=()=>null;
assert.equal(submitAction("SubmitPerson").prevented,true);
const ownedControls=actionOutput.controls.map(actionControl);
const ownedByName=new Map(ownedControls.map(control=>[control.name,control]));
const ownedById=new Map(ownedControls.map(control=>[control.id,control]));
const primaryOwner={elements:[],handlers:{},addEventListener(type,handler){this.handlers[type]=handler;}};
const explicitOwner={elements:[],handlers:{},addEventListener(type,handler){this.handlers[type]=handler;}};
ownedControls.forEach(control=>{control.form=primaryOwner;});
ownedByName.get("Person.Name").form=explicitOwner;
ownedByName.get("SubmitPerson").form=explicitOwner;
vm.runInNewContext(actionOutput.script,{document:{...actionDocument,querySelector:()=>primaryOwner,getElementById:id=>ownedById.get(id) ?? null}},{timeout:1000});
ownedByName.get("SubmitPerson").handlers.click[0]({preventDefault(){},detail:0});
explicitOwner.handlers.submit({submitter:ownedByName.get("SubmitPerson"),preventDefault(){throw new Error("Valid alternate owner submission was blocked");}});
const ownedData=new FormData();
ownedData.append("Person.Secret","must not leak"); ownedData.append("Person.Address.City","wrong form");
explicitOwner.handlers.formdata({formData:ownedData});
assert.deepEqual(Array.from(ownedData.entries()),[["customer_name","Current name"]]);
const pageSource=execFileSync(process.env.PDF_RUNTIME_TEST_PHP ?? "php",[path.join(__dirname,"runtime-fixture.php"),"--page-lifecycle"],{encoding:"utf8"});
const pageAlerts=[];
const anchors=new Map([1,3].map(number=>["PDF-Page-"+number,{id:"PDF-Page-"+number}]));
let observePages,unloadPages;
vm.runInNewContext(pageSource,{
 document:{
  readyState:"complete",querySelector:()=>null,getElementById:id=>anchors.get(id),
  defaultView:{
   alert:value=>pageAlerts.push(value),
   IntersectionObserver:class {constructor(callback){observePages=callback;} observe(){}},
   addEventListener(type,handler){if(type==="beforeunload") unloadPages=handler;}
  }
 }
},{timeout:1000});
observePages([{target:anchors.get("PDF-Page-1"),isIntersecting:true}]);
observePages([{target:anchors.get("PDF-Page-1"),isIntersecting:true}]);
observePages([{target:anchors.get("PDF-Page-3"),isIntersecting:true}]);
observePages([{target:anchors.get("PDF-Page-1"),isIntersecting:false}]);
observePages([{target:anchors.get("PDF-Page-1"),isIntersecting:false}]);
unloadPages();
assert.deepEqual(pageAlerts,["open:0:PageOpen:true","open:2:PageOpen:true","close:0:PageClose:true","close:2:PageClose:true"]);
const formatSource=execFileSync(process.env.PDF_RUNTIME_TEST_PHP ?? "php",[path.join(__dirname,"runtime-fixture.php"),"--format-values"],{encoding:"utf8"});
const rawFormatted=new Control("raw"), rawInspection=new Control("inspect");
vm.runInNewContext(formatSource,{document:{...document,querySelector:()=>({elements:[rawFormatted,rawInspection],addEventListener:()=>{}})}},{timeout:1000});
assert.equal(rawFormatted.value,"$10");
assert.equal(rawInspection.value,"10:10");
rawFormatted.change("12");
assert.equal(rawFormatted.value,"$12");
assert.equal(rawInspection.value,"12:12");
console.log("PASS document scripts and page lifecycle, exact bindings, targeted reset/defaults, opt-in submission, raw/formatted values, validation, readonly and prototype names");
const primarySource=execFileSync(process.env.PDF_RUNTIME_TEST_PHP ?? "php",[path.join(__dirname,"runtime-fixture.php"),"--primary-widget-action"],{encoding:"utf8"});
const primaryFirst=new Control("action", "", "button"), primarySecond=new Control("action", "", "button");
const primaryAlerts=[];
vm.runInNewContext(primarySource,{document:{...document,
 querySelector:()=>({elements:[primaryFirst,primarySecond],addEventListener:()=>{}}),
 defaultView:{alert:value=>primaryAlerts.push(value),addEventListener:()=>{}}
}},{timeout:1000});
primarySecond.handlers.click[0]({preventDefault(){}});
primaryFirst.handlers.click[0]({preventDefault(){}});
assert.deepEqual(primaryAlerts,["second-primary","first-primary"]);
console.log("PASS primary JavaScript actions bind to the activated widget");
