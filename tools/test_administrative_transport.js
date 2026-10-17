'use strict';
const assert=require('assert'),fs=require('fs'),path=require('path'),vm=require('vm');
const read=name=>fs.readFileSync(path.join(__dirname,'../slsmassnotifyserver/views',name),'utf8');
const enterprise=read('enterprise.js'),cluster=read('cluster.php'),readiness=read('deployment_readiness.php'),automations=read('automations.js'),sms=read('announcement_sms.php'),integrations=read('integrations.js');
const dashboard=fs.readFileSync(path.join(__dirname,'../slsmassnotifyserver/dashboard/views/sections/sls-mass-notify-announcement.php'),'utf8');
assert(!cluster.includes('crypto.subtle')&&!cluster.includes('crypto.randomUUID'),'Cluster form still depends on secure-context browser cryptography for immutable job creation.');
const between=(source,start,end)=>{
 const first=source.indexOf(start),last=source.indexOf(end,first);
 assert(first>=0&&last>first,'Actual administrative source was not found.');return source.slice(first,last);
};
const scripts={
 enterprise:between(enterprise,'  const post = async','\n  function tab')+'\nglobalThis.invoke=()=>post("fixture",{});',
 cluster:between(cluster,'async function call(action,input={})','\nform.onsubmit')+'\nglobalThis.invoke=()=>call("fixture",{});',
 upload:between(enterprise,"      upload.addEventListener('change'",'\n      map.addEventListener')+'\nglobalThis.invoke=()=>upload.events.change();',
 automation:between(automations,'    async function save(remove)','\n    root.querySelectorAll')+'\nglobalThis.invoke=()=>save(false);',
 sms:between(sms,"root.querySelector('[data-sms-check-sender]').addEventListener",'\nroot.querySelectorAll(\'[data-sms-money]')+'\nglobalThis.invoke=()=>root.querySelector("[data-sms-check-sender]").events.click.call(root.querySelector("[data-sms-check-sender]"));',
 integration:between(integrations,'  async function post(action, payload)','\n  function renderLists')+'\nglobalThis.invoke=()=>post("fixture",{});',
 warning:between(read('labs_danger.js'),'  const post = async','\n  const waitForBetaNotice')+'\nglobalThis.invoke=()=>post("fixture",{});',
 identity:between(read('identity_labs.js'),'  const call = async','\n  const run')+'\nglobalThis.invoke=()=>call("fixture",{});',
 operator:between(read('operators.js'),'  async function request(action,payload)','\n  function closeReset')+'\nglobalThis.invoke=()=>request("fixture",\'{"id":"private"}\');',
 calendar:between(read('schedule_calendar.php'),'    async function request(data)','\n    function setBusy')+'\nglobalThis.invoke=()=>request(new URLSearchParams());',
 widget:between(dashboard,'\tasync function widgetRequest(','\n\troot.setAttribute(\'data-ready\'')+'\nglobalThis.invoke=()=>widgetRequest("/fixture",{credentials:"same-origin"},30000);',
 readiness:between(readiness,'<script>','</script>').replace('<script>','').replace(/<\?php[\s\S]*?\?>/g,'"Private fixture"')
};
function node(){return {events:{},attributes:{},disabled:false,hidden:false,textContent:'',children:[],
 addEventListener(name,handler){this.events[name]=handler;},setAttribute(name,value){this.attributes[name]=value;},
 appendChild(child){this.children.push(child);},dispatchEvent(){},click(){}};}

async function exercise(kind,outcome){
 const nodes=new Map(),get=id=>{if(!nodes.has(id))nodes.set(id,node());return nodes.get(id);};
 let deadline=null,cleared=0,calls=0,abortCount=0,feedback='';
 const expected=kind==='widget'?30000:['readiness','automation','sms','warning','operator','calendar'].includes(kind)?20000:kind==='upload'?60000:25000;
 const nativeDeadline=['warning','identity','calendar'].includes(kind);
 const controls=[{disabled:false,isConnected:true},{disabled:true,isConnected:true}];let rendered=0;
 const context={AbortController,FormData,URLSearchParams,Error,Date,isNaN,JSON,lifecycle:{requests:new Set()},parseJsonResponse:response=>response.json(),
  AbortSignal:{timeout:delay=>{assert.strictEqual(delay,expected);const controller=new AbortController();deadline=()=>controller.abort();return controller.signal;}},
  data:{csrf:'private-token'},csrf:'private-token',endpoint:'/private-labs',message:node(),output:node(),
  form:{elements:{pbx_contract:{value:''}}},upload:{...node(),files:['private-file']},image:{},map:node(),
  feedback:value=>{feedback=value;},CustomEvent:function(){},
  busy:false,inflight:false,requestBusy:false,dirty:true,draft:{name:'Private draft'},state:{},payload:()=>({revision:'private'}),el:get,boot:{csrf:'private-token',endpoint:'/private-identity'},
  root:{setAttribute(){},removeAttribute(){},querySelectorAll:()=>controls,querySelector:get,closest:()=>({querySelector:()=>({value:'private-token'})})},panel:{querySelectorAll:()=>controls},
  status:value=>{feedback=value;},display:()=>{rendered++;},
  document:{getElementById:get,createElement:node},
  setTimeout:(callback,delay)=>{assert.strictEqual(delay,expected);deadline=callback;return 1;},
  clearTimeout:value=>{assert.strictEqual(value,1);cleared++;},
  fetch:(url,options)=>{
   calls++;assert(options.signal,'Missing request deadline signal.');assert.strictEqual(options.credentials,'same-origin');
   if(kind==='operator')assert.strictEqual(JSON.parse(options.body.get('payload')).id,'private','Pre-encoded account payload was encoded twice.');
   const stalled=()=>new Promise((resolve,reject)=>options.signal.addEventListener('abort',()=>{abortCount++;const error=new Error('Private transport stalled.');error.name='AbortError';reject(error);},{once:true}));
   if(outcome==='headers_hung')return stalled();
   const payload=kind==='readiness'?{success:true,report:{schema:'sls-deployment-readiness-v1',checks:[{state:'ok',label:'Private health',detail:'Inert'}],operational_ready:true,generated_at:'2026-10-14T12:00:00Z'}}
     :kind==='upload'?{success:true,image_id:'private-image'}:{success:true,state:{revision:'saved'},message:'Private save confirmed',issues:[]};
   return Promise.resolve({ok:true,status:200,json:outcome==='body_hung'?stalled:outcome==='malformed'?()=>Promise.reject(new SyntaxError('private parser detail')):outcome==='invalid'?()=>Promise.resolve([]):outcome==='unconfirmed'?()=>Promise.resolve({}):()=>Promise.resolve(payload)});
  }};
 context.window=context;
 vm.runInNewContext('let imageId="";\n'+scripts[kind],context,{timeout:1000});
 let request;
 if(kind==='readiness'){get('sls-readiness-check').events.click();request=Promise.resolve({});}
 else request=Promise.resolve(context.invoke()).then(result=>({result}),error=>({error:error.message}));
 await new Promise(resolve=>setImmediate(resolve));
 if(['automation','cluster','integration','operator'].includes(kind)&&outcome.endsWith('_hung'))assert(controls.every(control=>control.disabled),'Editable fields were left active during an unconfirmed request.');
 if(['enterprise','cluster','integration','operator','upload'].includes(kind)&&outcome.endsWith('_hung')){
  const concurrent=await Promise.resolve(context.invoke()).then(()=>null,error=>error.message);
  if(kind==='upload')assert.match(feedback,/already in progress.*uploading a floor plan/);
  else assert.match(concurrent,/already in progress/);
  assert.strictEqual(calls,1,'Concurrent integration work was submitted.');
 }
 if(outcome.endsWith('_hung'))deadline();
 const result=await request;await new Promise(resolve=>setImmediate(resolve));
 assert.strictEqual(calls,1,'An administrative request was replayed.');assert.strictEqual(cleared,nativeDeadline?0:1,'Its deadline timer was retained.');
   if(outcome.endsWith('_hung')){
  assert.strictEqual(abortCount,1,'The stalled transport was not stopped.');
  if(kind==='readiness'){
   assert.match(get('sls-readiness-status').textContent,/20 seconds/);
   assert.strictEqual(get('sls-readiness-check').disabled,false);
   assert.strictEqual(get('sls-readiness').attributes['aria-busy'],'false');
   assert.strictEqual(get('sls-readiness-results').hidden,true);
  }else if(kind==='upload'){
   assert.match(feedback,/60 seconds.*saved images/);assert.strictEqual(context.upload.disabled,false);
  }else if(kind==='automation'){
   assert.match(feedback,/20 seconds.*saved settings.*retrying/);assert.strictEqual(rendered,0);assert.strictEqual(context.dirty,true);
  }else if(kind==='sms'){
   assert.match(get('[data-sms-check-result]').textContent,/20 seconds.*No SMS was sent/);
  }else if(kind==='warning')assert.match(result.error,/20 seconds.*Reload.*before enabling/);
  else if(kind==='identity')assert.match(result.error,/25 seconds.*saved settings.*retrying/);
  else if(kind==='operator')assert.match(result.error,/20 seconds.*saved accounts.*retrying/);
  else if(kind==='calendar')assert.match(result.error,/20 seconds.*No schedule was saved/);
  else if(kind==='widget')assert.match(result.error,/30 seconds.*delivery details before retrying/);
  else assert.match(result.error,/25 seconds.*retrying.*not retried/);
   }else if(outcome==='malformed'){
    if(kind==='upload')assert.match(feedback,/unreadable floor-plan upload response.*Reload saved images/);
    else if(kind==='identity')assert.match(result.error,/unreadable directory or operator SSO response.*Reload saved settings/);
    else if(kind==='cluster')assert.match(result.error,/unreadable cluster response.*Reload saved cluster state/);
    else{assert.strictEqual(kind,'enterprise');assert.match(result.error,/server did not return a valid result.*Reload saved state/);}
   }else if(outcome==='invalid'){
    if(kind==='upload')assert.match(feedback,/floor-plan upload was not confirmed.*Reload saved images/);
    else if(kind==='cluster')assert.match(result.error,/valid cluster response.*Reload saved cluster state/);
    else{assert.strictEqual(kind,'enterprise');assert.match(result.error,/valid structured result.*Reload saved state/);}
   }else if(outcome==='unconfirmed'){
    assert.strictEqual(kind,'enterprise');assert.match(result.error,/server did not confirm this request.*Reload saved state/);
   }else{
  if(kind==='readiness')assert.strictEqual(get('sls-readiness-download').disabled,false);
  else if(kind==='upload')assert.match(context.image.src,/image_id=private-image/);
  else if(kind==='automation'){assert.strictEqual(rendered,1);assert.strictEqual(context.dirty,false);assert.strictEqual(context.state.revision,'saved');}
  else if(kind==='sms')assert.strictEqual(get('[data-sms-check-result]').className,'text-success');
  else assert.strictEqual(result.result.success,true);
 }
 if(kind==='automation'){assert.strictEqual(context.busy,false);assert.strictEqual(controls[0].disabled,false);assert.strictEqual(controls[1].disabled,true);}
 if(kind==='cluster'){assert.strictEqual(controls[0].disabled,false);assert.strictEqual(controls[1].disabled,true);}
 if(kind==='integration'){assert.strictEqual(context.inflight,false);assert.strictEqual(controls[0].disabled,false);assert.strictEqual(controls[1].disabled,true);}
 if(kind==='operator'){assert.strictEqual(context.requestBusy,false);assert.strictEqual(controls[0].disabled,false);assert.strictEqual(controls[1].disabled,true);}
 if(kind==='upload')assert.strictEqual(context.requestBusy,false);
 if(kind==='sms')assert.strictEqual(get('[data-sms-check-sender]').disabled,false);
 if(kind==='widget')assert.strictEqual(context.lifecycle.requests.size,0,'Widget request lease was retained.');
}
function pagingTransport(kind,outcome){
 const source=read('paging.php'),controls=[{disabled:false},{disabled:true}],elements=new Map();
 let submitted,handler,requestCount=0,redirects=0,lastError='',refreshes=0;
 function element(id){
  if(!elements.has(id))elements.set(id,{attributes:{},hidden:true,checked:false,value:id==='#sls-paging-pin-length'?'4':'',
   prop(key,value){if(arguments.length===2){this[key]=value;return this;}return this[key];},
   attr(key,value){if(arguments.length===2){this.attributes[key]=value;return this;}return key==='action'?'/fixture':this.attributes[key];},
   removeAttr(key){delete this.attributes[key];return this;},val(value){if(arguments.length){this.value=value;return this;}return this.value;},
   text(){return this;},serialize(){return 'fixture=1';},each(callback){controls.forEach(item=>callback.call(item));return this;},
   find(){return element('controls');},on(event,callback){handler=callback;return this;}});
  return elements.get(id);
 }
 const $=value=>typeof value==='string'?element(value):value;
 element('controls').prop=function(key,value){controls.forEach(item=>item[key]=value);return this;};
 $.ajax=options=>{
  requestCount++;submitted={options};
  const request={done(callback){submitted.done=callback;return this;},fail(callback){submitted.fail=callback;return this;},always(callback){submitted.always=callback;return this;},abort(){}};
  return request;
 };
 const context={$,draft:{},pinRequest:null,labels:{pinPending:'pending',pinFailure:'PIN failed',tooLarge:'large'},
  error:value=>{lastError=value;},pinStatus(){},rows:[{group_id:'fixture',menu_number:1,require_pin:'0',pin_length:4}],
  editor:element('editor'),shared:{values:()=>[]},rowError:()=>'',groupName:()=>'',standalone:()=>false,byteLength:value=>Buffer.byteLength(value),
  refresh:()=>{refreshes++;},window:{location:{assign:()=>{redirects++;}}}};
 if(kind==='pin'){
  vm.runInNewContext(between(source,'    function randomize() {','\n    function openEditor')+'\nrandomize();',context);
  assert.strictEqual(submitted.options.timeout,10000);
  assert.strictEqual(element('#sls-paging-randomize,#sls-paging-save-group,#sls-paging-pin-length').disabled,true);
 }else{
  vm.runInNewContext(between(source,"    $('#sls-paging-form').on('submit'","\n    $('#sls-paging-callers')"),context);
  handler.call(element('#sls-paging-form'),{preventDefault(){}});
  assert.strictEqual(submitted.options.timeout,30000);assert(controls.every(control=>control.disabled));
  assert.strictEqual(submitted.options.data,'fixture=1&ajax=1','Disabled inputs were serialized too late.');
  handler.call(element('#sls-paging-form'),{preventDefault(){}});assert.strictEqual(requestCount,1,'Paging save was replayed while busy.');
 }
 if(outcome==='accepted')submitted.done(kind==='pin'?{success:true,pin:'1234'}:{success:true});
 else if(outcome==='malformed')submitted.done(kind==='pin'?{success:true,pin:'1'}:{success:'true'});
 else submitted.fail({},'timeout');
 submitted.always();
 assert.strictEqual(requestCount,1);
 if(kind==='pin'){
  assert.strictEqual(context.pinRequest,null);assert.strictEqual(element('#sls-paging-randomize,#sls-paging-save-group,#sls-paging-pin-length').disabled,false);
  if(outcome==='accepted')assert.strictEqual(element('#sls-paging-pin').value,'1234');else assert.strictEqual(lastError,'PIN failed');
 }else{
  assert.strictEqual(controls[0].disabled,false);assert.strictEqual(controls[1].disabled,true);assert.strictEqual(refreshes,1);
  assert.strictEqual(element('#sls-paging-form').attributes['aria-busy'],undefined);
  assert.strictEqual(redirects,outcome==='accepted'?1:0);
  if(outcome!=='accepted')assert.match(lastError,/Reload Paging.*before trying again/);
 }
}
(async()=>{
 for(const kind of Object.keys(scripts))for(const outcome of ['accepted','headers_hung','body_hung'])await exercise(kind,outcome);
 await exercise('identity','malformed');
 await exercise('cluster','malformed');
 await exercise('cluster','invalid');
 await exercise('enterprise','malformed');
 await exercise('enterprise','invalid');
 await exercise('enterprise','unconfirmed');
 await exercise('upload','malformed');
 await exercise('upload','invalid');
 for(const kind of ['pin','save'])for(const outcome of ['accepted','malformed','timeout'])pagingTransport(kind,outcome);
 const controls=[{disabled:false},{disabled:true}],groupForm={setAttribute(){},removeAttribute(){}},ctx={groupRequestBusy:false,root:{querySelectorAll:()=>controls},groupForm,renderCooldown(){}};
 vm.runInNewContext(between(dashboard,'\tfunction lockGroupControls()','\n\tgroupModal.on(')+'\nglobalThis.unlock=lockGroupControls();',ctx);
 assert(controls.every(control=>control.disabled));assert.strictEqual(ctx.groupRequestBusy,true);ctx.unlock();
 assert.strictEqual(controls[0].disabled,false);assert.strictEqual(controls[1].disabled,true);assert.strictEqual(ctx.groupRequestBusy,false);
 controls[0].disabled=true;ctx.unlock();assert.strictEqual(controls[0].disabled,true,'A second release overwrote a newer control state.');
 const controller=new AbortController(),disposeContext={lifecycleKey:'fixture'};disposeContext.window=disposeContext;
 vm.runInNewContext(between(dashboard,'\tvar lifecycle = {','\n\twindow[lifecycleKey] = lifecycle;')+'\nglobalThis.lifecycle=lifecycle;',disposeContext);
 disposeContext.lifecycle.requests.add(controller);disposeContext.lifecycle.dispose();assert(controller.signal.aborted);assert.strictEqual(disposeContext.lifecycle.requests.size,0);
 console.log('Actual administrative transports: twelve request implementations accept valid replies; header/body deadlines preserve guards and restore controls without replay.');
 console.log('Actual paging PIN/save handlers: bounded requests, malformed replies, no concurrent save, frozen serialized inputs and preserved disabled controls passed.');
})().catch(error=>{console.error(error);process.exitCode=1;});
