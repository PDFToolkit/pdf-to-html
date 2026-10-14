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
console.log("PASS document-scoped scripts, per-widget actions and legacy compatibility, shared values, validation, calculations, reset, readonly and prototype field names");
