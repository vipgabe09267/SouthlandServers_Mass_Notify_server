'use strict';
const assert=require('assert'),fs=require('fs'),path=require('path'),vm=require('vm');
const source=fs.readFileSync(path.join(__dirname,'../slsmassnotifyserver/views/operations.js'),'utf8');
const start=source.indexOf('async function call(action,payload){'),end=source.indexOf('\nfunction close()',start);
assert(start>=0&&end>start,'The actual operations request helper was not found.');

async function exercise(kind,action='send'){
 let deadline=null,calls=0,cleared=0,abortCount=0;
 const context={AbortController,URLSearchParams,Error,data:{endpoint:'/private-operations',csrf:'private-token'},
  setTimeout:(callback,delay)=>{assert.strictEqual(delay,15000);deadline=callback;return 1;},
  clearTimeout:timer=>{assert.strictEqual(timer,1);cleared++;},
  fetch:(url,options)=>{
   calls++;assert.strictEqual(url,'/private-operations');assert.strictEqual(options.credentials,'same-origin');
   assert.strictEqual(options.cache,'no-store');assert.strictEqual(options.body.get('slsmassnotifyserver_action'),action);
   assert.strictEqual(options.body.get('slsmassnotifyserver_csrf'),'private-token');
   assert.deepStrictEqual(JSON.parse(options.body.get('payload')),{message:'Private fixture'});
   const stalled=()=>new Promise((resolve,reject)=>options.signal.addEventListener('abort',()=>{abortCount++;reject(new Error('aborted'));},{once:true}));
   if(kind==='headers_hung')return stalled();
   if(kind==='network_error')return Promise.reject(new Error('Private transport unavailable.'));
   const result=kind==='rejected'?{success:false,message:'Recipient revoked.',errors:['No delivery admitted.']}
     :kind==='bad_errors'?{success:false,errors:'not an array'}
     :kind==='null'?null:kind==='array'?[]:kind==='job'?{success:false,job_id:'job_private',state:'failed'}:{success:true};
   return Promise.resolve({ok:true,json:kind==='body_hung'?stalled:()=>Promise.resolve(result)});
  }};
 vm.runInNewContext(source.slice(start,end)+'\nglobalThis.invoke=call;',context,{timeout:1000});
 const request=context.invoke(action,{message:'Private fixture'}).then(
  result=>({result}),failure=>({error:failure.message}));
 await new Promise(resolve=>setImmediate(resolve));
 if(kind.endsWith('_hung'))deadline();
 const {result,error}=await request;
 assert.strictEqual(calls,1,'A request was replayed automatically.');
 assert.strictEqual(cleared,1,'The request timer was retained.');
 return {result,error,abortCount};
}

(async()=>{
 assert.strictEqual((await exercise('accepted')).result.success,true);
 for(const kind of ['headers_hung','body_hung']){
  const observed=await exercise(kind);assert.match(observed.error,/15 seconds.*history.*not retried/);assert.strictEqual(observed.abortCount,1);
 }
 assert.match((await exercise('rejected')).error,/Recipient revoked.*\nNo delivery admitted/);
 assert.match((await exercise('bad_errors')).error,/rejected/);
 for(const kind of ['null','array'])assert.match((await exercise(kind)).error,/invalid response/);
 assert.match((await exercise('network_error')).error,/transport unavailable/);
 assert.strictEqual((await exercise('job','job')).result.state,'failed');
 console.log('Operations transport: accepted replies, header/body deadlines, no replay, rejection details, malformed replies and terminal job evidence passed.');
})().catch(error=>{console.error(error);process.exitCode=1;});
