// Exercises the actual dashboard polling functions with isolated browser stubs.
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');
const source = fs.readFileSync(path.join(__dirname, '../slsmassnotifyserver/dashboard/views/sections/sls-mass-notify-announcement.php'), 'utf8');
const start = source.indexOf('\tfunction rememberJob(id)');
const end = source.indexOf('\tlifecycle.intervals.push(window.setInterval(pollJob, 2000));', start);
assert(start > 0 && end > start);
let resolveFetch;
let requests = 0;
let receivedRows = [];
const context = {
    activeJob: '', receiptJob: '', receiptDeadline: 0, viewedJob: '', jobPollBusy: false, requestInFlight: false,
    sessionStorage: {setItem() {}, removeItem() {}}, instanceActive: () => true, document: {hidden:false},
    fetch: () => { requests++; return new Promise(resolve => { resolveFetch = resolve; }); },
    parseJsonResponse: value => value, renderDeliveryStatus() {}, renderCooldown() {}, setAnnouncementStatus() {},
    showReceipts: data => { receivedRows = data.receipts; },
    setSubmitBusy: pending => { context.requestInFlight = pending; }
};
vm.createContext(context);
vm.runInContext(source.slice(start, end), context);
const drain = () => new Promise(resolve => setImmediate(resolve));
// Use the actual receipt renderer to check failure visibility and text-only output.
class Element {
    constructor(tag) { this.tag=tag; this.children=[]; this.style={}; this.open=false; this.textContent=''; }
    appendChild(child) { this.children.push(child); }
    querySelector(tag) { return this.children.find(child=>child.tag===tag) || null; }
    set innerHTML(value) { assert.strictEqual(value,''); this.children=[]; }
    addEventListener() {}
}
const panel = new Element('div');
const renderer = {receiptsPanel:panel,lastReceiptView:'',viewedJob:firstPlaceholder(),document:{createElement:tag=>new Element(tag)},pollJob(){}};
function firstPlaceholder() { return 'job_'+'a'.repeat(32); }
vm.createContext(renderer);
vm.runInContext(source.slice(source.indexOf('\tfunction showReceipts(data)'),source.indexOf('\tfunction renderDeliveryStatus(data)')),renderer);
renderer.showReceipts({receipts:[{channel:'desktop',target:'fixture',state:'published',detail:'Awaiting receipt.'}]});
assert.strictEqual(panel.querySelector('details').open,false);
renderer.showReceipts({receipts:[{channel:'external_voice',target:'<img src=x onerror=alert(1)>',state:'failed',detail:'Route rejected.'}]});
assert.strictEqual(panel.querySelector('details').open,true,'Failed destinations remained hidden');
assert(panel.querySelector('details').querySelector('ul').children[0].textContent.includes('<img src=x'));
const first = 'job_' + 'a'.repeat(32);
const second = 'job_' + 'b'.repeat(32);
const complete = {state: 'complete', success: true, created_at: new Date().toISOString(), receipt_poll_pending: true,
    receipts: [{channel:'desktop', state:'published'}]};
(async () => {
    let cooldownRequests=0, cooldownResolve, now=1000000, interval;
    const listeners={}, cooldownContext={
        lifecycle:{events:[],intervals:[]},document:{hidden:false,addEventListener:(name,fn)=>{listeners[name]=fn;}},
        form:{addEventListener:(name,fn)=>{listeners[name]=fn;}},window:{setInterval:(fn,delay)=>{interval={fn,delay};return 1;}},
        instanceActive:()=>true,Date:{now:()=>now},remaining:0,deliveryOutcomeUnknown:true,
        fetch:()=>{cooldownRequests++;return new Promise(resolve=>{cooldownResolve=resolve;});},
        parseJsonResponse:value=>value,renderCooldown(){},pollJob(){}
    };
    vm.createContext(cooldownContext);
    vm.runInContext(source.slice(source.indexOf('\tvar cooldownPollBusy ='),source.indexOf("\tform.addEventListener('submit'")),cooldownContext);
    assert.strictEqual(interval.delay,60000,'Idle dashboard refresh became unnecessarily frequent');
    cooldownContext.document.hidden=true;interval.fn();listeners.focusin();listeners.visibilitychange();
    assert.strictEqual(cooldownRequests,0,'Background dashboard issued cooldown requests');
    cooldownContext.document.hidden=false;listeners.visibilitychange();interval.fn();listeners.focusin();
    assert.strictEqual(cooldownRequests,1,'Parallel cooldown requests were allowed');
    cooldownResolve({cooldowns:{announcement:{remaining:17}}});await drain();
    assert.strictEqual(cooldownContext.remaining,17);assert.strictEqual(cooldownContext.deliveryOutcomeUnknown,false);
    now+=5000;listeners.focusin();assert.strictEqual(cooldownRequests,1,'Focus changes caused rapid polling');
    now+=5000;listeners.focusin();assert.strictEqual(cooldownRequests,2,'Focused controls did not refresh current cooldown');
    cooldownResolve({cooldowns:{announcement:{remaining:0}}});await drain();
    context.rememberJob(first);
    context.document.hidden=true; context.pollJob();
    assert.strictEqual(requests,0,'Background tab continued automatic delivery polling');
    context.document.hidden=false;
    context.pollJob(); resolveFetch(complete); await drain();
    assert.strictEqual(context.activeJob, '');
    assert.strictEqual(context.requestInFlight, false, 'Waiting for receipts must not block a second send');
    assert.strictEqual(context.receiptJob, first);
    context.pollJob(); resolveFetch({...complete, receipt_poll_pending:false, receipts:[{channel:'desktop',state:'received'}]}); await drain();
    assert.strictEqual(receivedRows[0].state, 'received');
    assert.strictEqual(context.receiptJob, '');
    const before = requests; context.pollJob(); assert.strictEqual(requests, before);
    context.pollJob(true); assert.strictEqual(requests, before+1, 'Manual refresh stopped working');
    context.rememberJob(second);
    resolveFetch(complete); await drain();
    assert.strictEqual(context.activeJob, second, 'Late first-job response overwrote second send');
    context.pollJob(); resolveFetch(complete); await drain();
    assert.strictEqual(context.receiptJob, second);
    context.receiptDeadline = Date.now()-1;
    const bounded = requests; context.pollJob(); assert.strictEqual(requests, bounded, 'Receipt polling exceeded ten minutes');
    const operations = fs.readFileSync(path.join(__dirname, '../slsmassnotifyserver/views/operations.js'), 'utf8');
    const operationStart = operations.indexOf('async function trackJob(id){');
    const operationEnd = operations.indexOf('\nfunction payload()', operationStart);
    assert(operationStart > 0 && operationEnd > operationStart);
    let operationTime = 0, operationRequests = 0, operationResponses = [];
    const operationPanel = {children:[], hidden:true, replaceChildren(...rows){this.children=rows;},append(row){this.children.push(row);}};
    const operatorContext = {
        deliveryGeneration:0, Date:{now:()=>operationTime},document:{hidden:false},
        root:{querySelector:()=>operationPanel},node:(tag,text)=>({tag,text}),
        setTimeout:(fn,delay)=>{operationTime+=delay;fn();},
        call:async(action,payload)=>{operationRequests++;assert.strictEqual(action,'job');return operationResponses.shift();}
    };
    vm.createContext(operatorContext);
    vm.runInContext(operations.slice(operationStart,operationEnd),operatorContext);
    operationResponses=[complete,{...complete,receipts:[{channel:'desktop',state:'received',detail:'Received by desktop app.'}]}];
    await operatorContext.trackJob(first);
    assert.strictEqual(operationRequests,2,'Operator polling stopped before a late desktop receipt.');
    assert(operationPanel.children.some(row=>row.text.includes('Received by desktop app.')));
    operatorContext.document.hidden=true;operationTime=0;const beforeHidden=operationRequests;
    await operatorContext.trackJob(first);
    assert.strictEqual(operationRequests,beforeHidden,'Hidden operator portal continued receipt requests.');
    operatorContext.document.hidden=false;operationTime=0;
    operatorContext.call=async()=>{operationRequests++;return complete;};const beforeBounded=operationRequests;
    await operatorContext.trackJob(first);
    assert.strictEqual(operationRequests-beforeBounded,300,'Operator receipt polling was not bounded to ten minutes.');
    operationTime=0;let oldReply;
    operatorContext.call=(action,payload)=>payload.job_id===first ? new Promise(resolve=>{oldReply=resolve;})
        : Promise.resolve({...complete,receipts:[{channel:'desktop',target:'new job',state:'received'}]});
    const oldRequest=operatorContext.trackJob(first);await operatorContext.trackJob(second);
    oldReply({...complete,receipts:[{channel:'desktop',target:'stale job',state:'received'}]});await oldRequest;
    assert(operationPanel.children.some(row=>row.text.includes('new job'))&&!operationPanel.children.some(row=>row.text.includes('stale job')),'Late operator job response overwrote a newer announcement.');
    assert(operations.includes('body.oninput=null')&&operations.includes('body.oninput=update')&&!operations.includes("body.addEventListener('input',update)"),'Incident dialogs accumulated input handlers.');
    console.log('PASS: dashboard/operator receipts, hidden/idle request bounds, single-flight cooldowns, stale responses, late acknowledgments, manual refresh and modal lifecycle.');
})().catch(error => { console.error(error); process.exitCode=1; });
