#!/usr/bin/env node
'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'../slsmassnotifyserver/api/sls-mass-notify/edge-view.php'),'utf8').match(/<script>([\s\S]*?)<\/script>/)[1];
class Element {
 constructor(){this.children=[];this.dataset={};this.value='';this.checked=false;this.disabled=false;this.textContent='';}
 append(...nodes){for(const node of nodes){node.parent=this;this.children.push(node);}}
 prepend(node){node.parent=this;this.children.unshift(node);}
 remove(){if(this.parent)this.parent.children=this.parent.children.filter(node=>node!==this);}
}
function browser({uncertainReceipt=false,audio=false,corrupt=false}={}){
 const ids=Object.fromEntries(['connect','status','events','device','token','sound','start','stop'].map(id=>[id,new Element()]));
 ids.device.value='owned-device';ids.token.value='T'.repeat(48);ids.sound.checked=audio;
 const store=new Map(),calls=[],timers=[],intervals=[],players=[];let now=1700000000;
 if(corrupt)store.set('sls-edge-history-v1:owned-device','{"bad":true}');
 const event={id:'site-event-a',delivery_id:'delivery-a',created_at:now,expires_at:now+10,channels:audio?['desktop','local_audio']:['desktop'],message:'<script>inert text</script>',title:'Local site',audio_asset_id:audio?'asset-a':''};
 class Clock extends Date{static now(){return now*1000;}}
 class Audio{constructor(url){this.url=url;this.ended=false;players.push(this);}play(){return Promise.resolve();}pause(){if(this.onpause)this.onpause();}}
 const storage={getItem:key=>store.get(key)||null,setItem:(key,value)=>store.set(key,value)};
 const context=vm.createContext({document:{getElementById:id=>ids[id],createElement:()=>new Element()},location:{href:'https://local.test/api/sls-mass-notify/edge-view.php'},localStorage:storage,Date:Clock,URL,Audio,Blob,
  setTimeout:callback=>{timers.push(callback);return timers.length;},clearTimeout:()=>{},setInterval:callback=>intervals.push(callback),
  fetch:async(url,options)=>{calls.push({action:url.searchParams.get('action'),options});assert.equal(options.redirect,'error');assert.equal(options.credentials,'omit');assert.equal(options.headers.Authorization,'Bearer '+'T'.repeat(48));
   if(url.searchParams.get('action')==='receipt'){assert(store.get('sls-edge-history-v1:owned-device').includes('uncertain'),'Receipt preceded persistent uncertainty.');assert.equal(ids.events.children.length,1);if(uncertainReceipt)throw new Error('isolated lost receipt response');return{ok:true,json:async()=>({kind:'displayed'})};}
   if(url.searchParams.get('action')==='media')return{ok:true,headers:{get:()=> 'audio/wav'},blob:async()=>new Blob(['RIFF1234WAVE'],{type:'audio/wav'})};
   return{ok:true,json:async()=>({events:[event]})};}});
 vm.runInContext(source,context,{filename:'edge-view.php'});
 return{ids,store,calls,timers,intervals,players,event,connect:()=>ids.connect.onsubmit({preventDefault(){}}),advance:seconds=>{now+=seconds;for(const interval of intervals)interval();}};
}
const settle=()=>new Promise(resolve=>setImmediate(resolve));
(async()=>{
 const ordinary=browser();assert.equal(ordinary.calls.length,0,'GET automatically connected a device.');ordinary.connect();await settle();
 assert.equal(ordinary.ids.events.children.length,1);assert.equal(ordinary.ids.events.children[0].children[1].textContent,'<script>inert text</script>');
 assert.equal(JSON.parse(ordinary.calls.find(call=>call.action==='receipt').options.body).kind,'displayed');
 ordinary.timers.shift()();await settle();assert.equal(ordinary.calls.filter(call=>call.action==='receipt').length,1,'Duplicate feed caused duplicate effect/receipt.');
 ordinary.advance(11);assert.equal(ordinary.ids.events.children.length,0,'Expired display remained visible.');
 const unknown=browser({uncertainReceipt:true});unknown.connect();await settle();unknown.timers.shift()();await settle();assert.equal(unknown.ids.events.children.length,1);assert.equal(unknown.calls.filter(call=>call.action==='receipt').length,1,'Lost receipt response caused blind retry.');
 unknown.ids.stop.onclick();unknown.ids.token.value='T'.repeat(48);unknown.connect();await settle();assert.equal(unknown.ids.events.children.length,1,'Reconnect redisplayed uncertain event.');
 const playback=browser({audio:true});playback.connect();await settle();assert.equal(playback.players.length,1);playback.advance(11);await settle();assert.equal(playback.ids.events.children.length,0);assert.equal(playback.calls.filter(call=>call.action==='receipt').length,0,'Expired audio was reported played.');assert(playback.ids.status.textContent.includes('will not automatically replay'));
 const damaged=browser({corrupt:true});damaged.connect();await settle();assert.equal(damaged.calls.length,0,'Damaged device history permitted automatic replay.');
 const invalid=browser();invalid.ids.token.value='short';invalid.connect();await settle();assert.equal(invalid.calls.length,0);
 console.log('Enterprise edge viewer: explicit connect, display deduplication, receipt uncertainty, reconnect, expiry, audio interruption and corrupt-history fence passed; no network or device used.');
})().catch(error=>{console.error(error);process.exitCode=1;});
