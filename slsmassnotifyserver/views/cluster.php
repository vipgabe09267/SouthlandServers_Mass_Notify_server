<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$scriptJson=static fn($value)=>json_encode($value,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
$fieldHelp = [
 'cluster_id'=>['sls-lab','The same unique deployment name on every participating node. Use letters, numbers, underscores or hyphens.'],
 'node_id'=>['pbx-a','A unique name for this machine. Every configured peer must use a different node ID.'],
 'site_id'=>['site-main','The site this node owns. Use the saved site identifier, not its display name.'],
 'witness_id'=>['witness-a','Node ID of the independent witness listed under Peers. Required for coordinated notification handoff.'],
 'initial_owner'=>['pbx-a','Node ID initially permitted to send cluster notifications. It must identify a configured delivery node.'],
 'pbx_contract'=>['Measure prerequisites first','The prerequisite tool below calculates this fingerprint. Matching PBXs must report the same contract; do not invent a value.'],
 'mirror_authority'=>['pbx-a','The sole node allowed to publish the selected shared settings. Other nodes receive and validate its revisions.'],
 'mirror_epoch'=>['64 hexadecimal characters','A deployment-specific generation shared by mirror participants. Change it only during a reviewed authority handoff.'],
 'witness_epoch'=>['64 hexadecimal characters','The generation recorded in the independent witness journal. Preserve it with that journal; mismatches reject authority.'],
 'tls_cert'=>['/etc/sls-cluster/node.crt','Absolute path to this node’s installed PEM client certificate. TLS files must be provisioned locally; this form does not create a CA.'],
 'tls_key'=>['/etc/sls-cluster/node.key','Absolute path to the matching private PEM key, readable only by the trusted service account and root. Never paste the key here.'],
 'tls_ca'=>['/etc/sls-cluster/ca.crt','Absolute path to the PEM CA certificate that signs your peer certificates. Hostname checks and exact peer pins remain required.'],
 'lease_seconds'=>['30','How long the witness grants exclusive send authority. A stopped or unreachable node cannot renew it.'],
 'max_queue'=>['100','Maximum retained jobs admitted to a site queue. A full queue rejects additional work with an explicit result.'],
 'spend_limit_cents'=>['0','The total charge reservation allowed by the witness, in the currency below. Zero permits no chargeable external work.'],
 'spend_currency'=>['USD','Three-letter currency code for charge reservations, for example USD. This is an admission budget, not a provider price estimate.'],
 'voice_reservation_cents'=>['0','Amount reserved for one external call. Choose a conservative provider cost in the currency above.'],
];
$tip=static function(string $text) use($escape): string {
 return '<span class="sls-field-help" tabindex="0" role="note" data-help="'.$escape($text).'" aria-label="'.$escape($text).'"><span aria-hidden="true">?</span></span>';
};
$input=static function(string $name,string $label,string $type='text') use($config,$escape,$fieldHelp,$tip): void {
 [$example,$help]=$fieldHelp[$name];
 echo '<label class="sls-cluster-field"><span>'.$escape($label).' '.$tip($help).'</span><input class="form-control" type="'.$escape($type).'" name="'.$escape($name).'" placeholder="'.$escape($example).'" value="'.$escape($config[$name]??'').'" autocomplete="off"><small class="help-block">'.$escape($help).'</small></label>';
};
?>
<section id="sls-cluster-panel">
 <header class="sls-cluster-heading"><h2>Notification continuity and remote sites</h2><p>Connect prepared SLS nodes and manage approved shared settings.</p></header>
 <div class="panel-body">
  <p class="sls-ent-notice">Experimental: keep continuity and mirroring disabled on production systems. A standalone PBX needs no peers. Remote phones need a local PBX; a Linux or Pi edge supports approved displays and cached audio.</p>
  <form id="sls-cluster-config">
   <div class="sls-cluster-switches">
    <?php foreach (['enabled'=>'Enable enterprise cluster','mirroring_enabled'=>'Mirror approved shared policies','remote_enabled'=>'Enable remote-site jobs','offline_cache_enabled'=>'Allow explicitly approved offline cache'] as $key=>$label): ?>
     <label><input type="checkbox" name="<?= $escape($key) ?>" <?= $config[$key]?'checked':'' ?>> <?= $escape($label) ?></label>
    <?php endforeach; ?>
   </div>
   <p class="help-block">Notification HA requires two matching PBXs and an independent witness. Handoff is manual: stop new work, wait for lease expiry, isolate the previous node and review uncertain receipts. Saving settings starts no listener or delivery and does not provide phone registration, trunk or network failover.</p>
   <details class="sls-ent-disclosure sls-cluster-settings" open><summary><i class="fa fa-server" aria-hidden="true"></i> Deployment and node identity</summary><div class="sls-cluster-grid">
    <label class="sls-cluster-field"><span>Deployment <?= $tip('Standalone is local only. Remote-site mode dispatches to another prepared FreePBX. Edge mode serves local displays/audio and needs a separate local PBX for phones. HA requires two matching PBXs and an independent witness.') ?></span><select name="mode" class="form-control"><?php foreach (['standalone'=>'One standalone PBX','notification_ha'=>'Notification HA on matching PBXs','remote_site'=>'Per-site FreePBX dispatcher','edge'=>'Linux / Pi LAN edge'] as $value=>$label): ?><option value="<?= $escape($value) ?>" <?= $config['mode']===$value?'selected':'' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label>
    <label class="sls-cluster-field"><span>Local role <?= $tip('Choose what this machine does: a delivery node sends to its recipients, a coordinator distributes explicit jobs, and a witness grants exclusive authority from an independent host.') ?></span><select name="role" class="form-control"><?php foreach (['node'=>'Delivery node','witness'=>'Independent witness','coordinator'=>'Enterprise coordinator'] as $value=>$label): ?><option value="<?= $value ?>" <?= $config['role']===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
    <?php $input('cluster_id','Cluster ID'); $input('node_id','Local node ID'); $input('site_id','Owned site ID'); $input('witness_id','Independent witness node ID'); $input('initial_owner','Initial primary node ID'); $input('pbx_contract','Matching PBX contract'); $input('mirror_authority','Shared-policy authority node ID'); $input('mirror_epoch','Shared-policy epoch (64 hexadecimal characters)'); ?>
   </div></details>
   <details class="sls-ent-disclosure sls-cluster-settings"><summary><i class="fa fa-lock" aria-hidden="true"></i> Peer trust and TLS</summary><div class="sls-cluster-grid">
    <?php $input('witness_epoch','Witness epoch (64 hexadecimal characters)'); $input('tls_cert','Local TLS certificate path'); $input('tls_key','Protected local TLS private key path'); $input('tls_ca','Trusted cluster CA path'); ?>
   </div></details>
   <details class="sls-ent-disclosure sls-cluster-settings"><summary><i class="fa fa-sliders" aria-hidden="true"></i> Queue and spending limits</summary><div class="sls-cluster-grid">
    <?php $input('lease_seconds','Witness lease seconds','number'); $input('max_queue','Maximum site queue entries','number'); $input('spend_limit_cents','Spending ceiling in cents','number'); $input('spend_currency','Spending currency'); $input('voice_reservation_cents','Outbound call charge reservation, cents','number'); ?>
   </div></details>
   <p class="help-block">TLS uses a verified CA, hostname, client certificate and exact peer certificate pin. A witness uses its own persistent epoch and local journal; placing it on a delivery PBX does not provide an independent failure domain.</p>
   <details class="sls-ent-disclosure sls-cluster-settings"><summary><i class="fa fa-exchange" aria-hidden="true"></i> Trusted peers</summary>
   <p class="help-block">Add each remote node explicitly. Match its node/site IDs and HTTPS listener address. Pin the SHA256 fingerprint of its leaf certificate and use a unique 32-byte pair secret shared only by these two peers. Blank secrets preserve saved credentials.</p>
   <div class="table-responsive"><table class="table table-striped"><thead><tr><th>Node ID</th><th>Site ID</th><th>Role</th><th>HTTPS peer endpoint</th><th>Certificate SHA256</th><th>Pair secret (64 hex)</th><th></th></tr></thead><tbody id="sls-cluster-peers"></tbody></table></div>
   <button type="button" class="btn btn-default" id="sls-cluster-add-peer"><i class="fa fa-plus" aria-hidden="true"></i> Add peer</button></details>
   <div class="sls-cluster-grid" style="margin-top:16px">
    <label class="sls-cluster-field"><span>Recipient IDs owned by this site <?= $tip('Exact saved device IDs owned by this site. Remote jobs cannot add recipients outside this list. Enter one ID per line.') ?></span><textarea class="form-control" name="site_recipients" rows="4"><?= $escape(implode("\n",$config['site_recipients'])) ?></textarea></label>
    <label class="sls-cluster-field"><span>Approved offline content SHA256 <?= $tip('SHA256 digests of content reviewed for disconnected use. A matching saved job and cached asset are still required; this does not fetch new alerts without Internet.') ?></span><textarea class="form-control" name="approved_cache" rows="4"><?= $escape(implode("\n",$config['approved_cache'])) ?></textarea></label>
   </div>
   <fieldset><legend style="font-size:16px">Shared policy fields</legend><?php foreach (\SLS\MassNotify\EnterpriseClusterConfig::MIRROR_FIELDS as $field): ?><label style="margin-right:16px"><input type="checkbox" name="mirror_fields" value="<?= $escape($field) ?>" <?= in_array($field,$config['mirror_fields'],true)?'checked':'' ?>> <?= $escape(str_replace('_',' ',$field)) ?></label><?php endforeach; ?></fieldset>
   <p class="help-block">Only the configured shared-policy authority may publish a revision. Change the authority and mirror epoch together after a reviewed handoff. Node identity, AMI, trunks, credentials, advertised origins, device capacity and AES keyrings remain local. Shared plaintext travels only inside authenticated TLS and is encrypted with the receiving node's own AES key.</p>
   <details><summary>Linux / Pi edge local devices and audio cache</summary>
    <p>Local device bearer tokens remain on their devices; enter their SHA256 values below. Only this node's owned recipients are permitted. Cached audio must be an approved local WAV file.</p>
    <?php foreach (['desktop'=>'Desktop feed','local_display'=>'Local display','local_audio'=>'Local device audio'] as $key=>$label): ?><label style="margin-right:16px"><input type="checkbox" name="local_device_channels" value="<?= $key ?>" <?= in_array($key,$config['local_device_channels'],true)?'checked':'' ?>> <?= $label ?></label><?php endforeach; ?>
    <div class="table-responsive"><table class="table"><thead><tr><th>Owned device ID</th><th>Bearer token SHA256</th><th>Channels (comma separated)</th><th></th></tr></thead><tbody id="sls-cluster-devices"></tbody></table></div><button type="button" id="sls-cluster-add-device" class="btn btn-default">Add local device</button>
    <div class="table-responsive"><table class="table"><thead><tr><th>Audio asset ID</th><th>Content SHA256</th><th>Local approved WAV path</th><th></th></tr></thead><tbody id="sls-cluster-assets"></tbody></table></div><button type="button" id="sls-cluster-add-asset" class="btn btn-default">Add cached audio</button>
   </details>
   <div class="sls-ent-actions sls-cluster-toolbar"><button type="submit" class="btn btn-primary">Save continuity settings</button> <button type="button" class="btn btn-default" data-cluster-action="cluster_prerequisites">Check matching PBX prerequisites</button> <button type="button" class="btn btn-default" data-cluster-action="cluster_initialize">Initialize local journal</button> <button type="button" class="btn btn-default" data-cluster-action="cluster_status">Read local status</button> <button type="button" class="btn btn-default" data-cluster-action="cluster_mirror">Mirror approved policies now</button></div>
  </form>
  <details style="margin-top:20px"><summary>Cluster STOP, spending control and manual failback</summary>
   <form id="sls-cluster-control" class="sls-cluster-grid"><label>Authority peer ID<input name="peer_id" class="form-control" value="<?= $escape($config['witness_id']) ?>"></label><label>Spending ceiling in cents<input type="number" name="spend_limit_cents" class="form-control" value="<?= $escape($config['spend_limit_cents']) ?>"></label><label><input type="checkbox" name="stopped" checked> STOP further effect admission</label><button class="btn btn-warning" type="submit">Apply authority control</button></form>
   <p>Existing in-flight actions retain their uncertain receipts. Stop the cluster and wait for lease expiry before handoff. An outstanding action requires explicit prior-node fencing and manual receipt reconciliation.</p>
   <form id="sls-cluster-handoff"><label>Reviewed handoff target node ID <input name="node_id" class="form-control" value="<?= $escape($config['initial_owner']) ?>"></label><button class="btn btn-default" type="submit">Authorize manual handoff</button></form>
   <button type="button" class="btn btn-default" data-cluster-action="cluster_recovery" style="margin-top:12px">Read authoritative recovery records</button>
   <form id="sls-cluster-reconcile" style="margin-top:16px"><div class="sls-cluster-grid"><label>Exact uncertain effect SHA256 ID<input class="form-control" name="id" pattern="[a-f0-9]{64}" required></label><label class="sls-cluster-wide">Recorded receipt review and fencing evidence<textarea class="form-control" name="evidence" minlength="20" maxlength="2048" rows="3" required></textarea></label><label><input type="checkbox" name="prior_node_fenced" required> I have fenced the prior node and reviewed this effect's outcome.</label></div><button class="btn btn-warning" type="submit">Record manual reconciliation</button></form>
   <p class="help-block">Reconciliation permanently consumes the effect identity and permits other work to resume. It never authorizes resending this effect.</p>
  </details>
  <details style="margin-top:20px"><summary>Queue a bounded job at one owned remote site</summary>
   <form id="sls-cluster-distribute"><div class="sls-cluster-grid"><label>Delivery peer ID<input class="form-control" name="peer_id" required></label><label>Owned destination site ID<input class="form-control" name="site_id" required></label><label>Title<input class="form-control" name="title" maxlength="80" value="Announcement"></label><label>Lifetime in seconds<input class="form-control" name="ttl" type="number" min="1" max="900" value="120"></label><label class="sls-cluster-wide">Message<textarea class="form-control" name="message" rows="3" required></textarea></label>
   <?php foreach (['targets'=>'Local PBX phone extension IDs','desktop_clients'=>'Local desktop recipient IDs','email_recipient_ids'=>'Saved local email recipient IDs','sms_recipient_ids'=>'Saved local SMS recipient IDs','voice_recipient_ids'=>'Saved local outbound voice recipient IDs','webhook_ids'=>'Saved local webhook recipient IDs'] as $key=>$label): ?><label><?= $escape($label) ?><textarea class="form-control" name="<?= $key ?>" rows="2" placeholder="One per line; explicit site-owned IDs only"></textarea></label><?php endforeach; ?>
   <label>Phone audio<select class="form-control" name="audio_mode"><option value="none">No audio</option><option value="tts">Text to speech on local PBX</option><option value="tones_tts">Tones and speech on local PBX</option></select></label><label><input type="checkbox" name="edge"> Destination is a Linux / Pi edge</label><label>Edge device IDs<textarea class="form-control" name="edge_recipients" rows="2"></textarea></label><fieldset><legend style="font-size:14px">Edge delivery channels</legend><label><input type="checkbox" name="edge_channels" value="desktop" checked> Desktop feed</label> <label><input type="checkbox" name="edge_channels" value="local_display"> Local display</label> <label><input type="checkbox" name="edge_channels" value="local_audio"> Local audio</label></fieldset><label>Approved edge audio asset ID<input name="audio_asset_id" class="form-control"></label><label><input type="checkbox" name="offline_approved"> Explicitly approve matching cached content for offline use</label></div><button class="btn btn-primary" type="submit">Queue at selected site</button></form>
   <form id="sls-cluster-receipt" style="margin-top:16px"><div class="sls-cluster-grid"><label>Remote peer ID<input name="peer_id" class="form-control" required></label><label>Immutable site job ID<input name="id" class="form-control" required></label></div><button class="btn btn-default" type="submit">Read exact site receipt</button></form>
   <p class="help-block">Expired jobs are rejected and interrupted jobs remain uncertain after reconnect. A delivery receipt records provider or application behavior; it does not prove human acknowledgment.</p>
  </details>
  <div id="sls-cluster-message" role="status" style="margin-top:16px"></div><pre id="sls-cluster-result" style="max-height:240px;overflow:auto;white-space:pre-wrap" hidden></pre>
 </div>
</section>
<style>.sls-cluster-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(235px,1fr));gap:14px;margin:14px 0}.sls-cluster-field{display:flex;flex-direction:column;gap:5px}.sls-cluster-wide{grid-column:1/-1}.sls-cluster-switches{display:flex;flex-wrap:wrap;gap:12px 22px;margin:16px 0}#sls-cluster-panel label{font-weight:500}#sls-cluster-panel td input,#sls-cluster-panel td select{min-width:130px}#sls-cluster-panel summary{cursor:pointer;font-weight:600;margin:12px 0}</style>
<script>
(function(){'use strict';
const base=<?= $scriptJson($config) ?>,endpoint=<?= $scriptJson($endpoint) ?>,csrf=<?= $scriptJson($csrf) ?>;
let revision=<?= $scriptJson($revision) ?>,requestBusy=false;
const panel=document.getElementById('sls-cluster-panel'),form=document.getElementById('sls-cluster-config'),message=document.getElementById('sls-cluster-message'),output=document.getElementById('sls-cluster-result');
const lines=v=>[...new Set(String(v||'').split(/[\r\n,]+/).map(x=>x.trim()).filter(Boolean))];
const contextualHelp={
 peer_id:'The exact node ID of a saved trusted peer. It must match that peer’s local configuration.',
 site_id:'The saved site identifier owned by the destination node, not the site display name.',
 ttl:'Absolute delivery lifetime in seconds. Expired or interrupted jobs are not replayed after reconnect.',
 targets:'Existing phone extension numbers on the destination FreePBX, one per line. They must be owned by that site.',
 desktop_clients:'Saved desktop usernames on the destination site, one per line. Credentials remain on that site.',
 email_recipient_ids:'Saved email recipient IDs from the destination site, one per line; do not enter email addresses.',
 sms_recipient_ids:'Saved SMS recipient IDs from the destination site, one per line; do not enter phone numbers.',
 voice_recipient_ids:'Saved external-call recipient IDs from the destination site, one per line.',
 webhook_ids:'Saved webhook recipient IDs from the destination site, one per line; do not paste webhook URLs.',
 edge_recipients:'Device IDs enrolled on the selected Linux/Pi edge and included in its owned-recipient list.',
 audio_asset_id:'A saved asset ID on the destination edge. Its local WAV and SHA256 digest must match the approved cache.',
 offline_approved:'Allows only this explicitly approved matching content to play from the edge cache during disconnection. New Internet alerts cannot be received offline.',
 evidence:'A receipt/log reference and the action used to stop the previous node. Enter 20–2048 characters; never include passwords or tokens.',
};
for(const control of panel.querySelectorAll('[name]')){
 const help=contextualHelp[control.name];if(!help)continue;
 const label=control.closest('label');if(!label||label.querySelector('.sls-field-help'))continue;
 const tip=document.createElement('span');tip.className='sls-field-help';tip.tabIndex=0;tip.dataset.help=help;tip.setAttribute('aria-label',help);
 const icon=document.createElement('span');icon.textContent='?';icon.setAttribute('aria-hidden','true');tip.append(icon);label.insertBefore(tip,control);
}

function field(value,type='text'){const e=document.createElement('input');e.type=type;e.className='form-control';e.value=value||'';e.autocomplete='off';if(type==='password')e.placeholder='Blank keeps saved pair secret';return e;}
function row(body,values,roles){const tr=document.createElement('tr');values.forEach((v,i)=>{const td=document.createElement('td');let e;if(roles&&i===2){e=document.createElement('select');e.className='form-control';['node','witness','coordinator'].forEach(role=>{const o=document.createElement('option');o.value=o.textContent=role;o.selected=role===v;e.appendChild(o);});}else e=field(v,roles&&i===5?'password':'text');const heading=body.closest('table').querySelectorAll('thead th')[i].textContent;e.setAttribute('aria-label',heading);e.title=heading;td.dataset.label=heading;td.appendChild(e);tr.appendChild(td);});const td=document.createElement('td'),remove=document.createElement('button');remove.type='button';remove.className='btn btn-default';remove.textContent='Remove';remove.onclick=()=>tr.remove();td.appendChild(remove);tr.appendChild(td);body.appendChild(tr);}
const peers=document.getElementById('sls-cluster-peers'),devices=document.getElementById('sls-cluster-devices'),assets=document.getElementById('sls-cluster-assets');
base.peers.forEach(p=>row(peers,[p.node_id,p.site_id,p.role,p.https_url,p.cert_sha256,p.hmac_secret],true));base.edge_devices.forEach(d=>row(devices,[d.id,d.token_sha256,d.channels.join(',')]));base.edge_assets.forEach(a=>row(assets,[a.id,a.sha256,a.path]));
document.getElementById('sls-cluster-add-peer').onclick=()=>row(peers,['','','node','','',''],true);document.getElementById('sls-cluster-add-device').onclick=()=>row(devices,['','','desktop']);document.getElementById('sls-cluster-add-asset').onclick=()=>row(assets,['','','']);
async function call(action,input={}){
 if(requestBusy)throw new Error('Another cluster request is already in progress. Wait for its result before trying again.');
 requestBusy=true;const controls=[...panel.querySelectorAll('button,input,select,textarea')],prior=controls.map(control=>control.disabled);controls.forEach(control=>{control.disabled=true;});
 message.textContent='Working…';output.hidden=true;const body=new URLSearchParams({slsmassnotifyserver_csrf:csrf,payload:JSON.stringify({action,input})});const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),25000);
 try{
  const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body,signal:controller.signal});let result;
  try{result=await response.json();}catch(error){throw new Error('The PBX returned an unreadable cluster response. Reload saved cluster state before retrying.');}
  if(!result||typeof result!=='object'||Array.isArray(result))throw new Error('The PBX did not return a valid cluster response. Reload saved cluster state before retrying.');
  if(!response.ok||result.success===false||result.error)throw new Error(result.message||result.error||'Operation rejected.');message.textContent=result.message||({cluster_prerequisites:'Prerequisites measured. Save the matching contract before initializing this node.',cluster_status:'Local status loaded. No notification was sent.',cluster_mirror:'Mirror result received. Review the exact revision below.'}[action]||'Operation completed. Review the result below.');output.textContent=JSON.stringify(result,null,2);output.hidden=false;if(action==='cluster_prerequisites'&&(result.contract||(result.result&&result.result.contract)))form.elements.pbx_contract.value=result.contract||(result.result.contract);return result;
 }catch(e){if(controller.signal.aborted)e=new Error('The PBX did not confirm this request within 25 seconds. Review saved cluster state or the exact job receipt before retrying; the request was not retried.');message.textContent=e.message;throw e;
 }finally{clearTimeout(timer);controls.forEach((control,index)=>{if(control.isConnected!==false)control.disabled=prior[index];});requestBusy=false;}
}
form.onsubmit=async event=>{event.preventDefault();const data=new FormData(form),c={...base,auto_failover:false};['enabled','mirroring_enabled','remote_enabled','offline_cache_enabled'].forEach(k=>c[k]=data.has(k));['mode','role','cluster_id','node_id','site_id','witness_id','initial_owner','witness_epoch','pbx_contract','mirror_authority','mirror_epoch','tls_cert','tls_key','tls_ca','spend_currency'].forEach(k=>c[k]=String(data.get(k)||''));['lease_seconds','max_queue','spend_limit_cents','voice_reservation_cents'].forEach(k=>c[k]=Number(data.get(k)));['site_recipients','approved_cache'].forEach(k=>c[k]=lines(data.get(k)));['mirror_fields','local_device_channels'].forEach(k=>c[k]=data.getAll(k));c.peers=[...peers.rows].map(r=>{const v=[...r.querySelectorAll('input,select')].map(e=>e.value);return {node_id:v[0],site_id:v[1],role:v[2],https_url:v[3],cert_sha256:v[4],hmac_secret:v[5]};});c.edge_devices=[...devices.rows].map(r=>{const v=[...r.querySelectorAll('input')].map(e=>e.value);return{id:v[0],token_sha256:v[1],channels:lines(v[2])};});c.edge_assets=[...assets.rows].map(r=>{const v=[...r.querySelectorAll('input')].map(e=>e.value);return{id:v[0],sha256:v[1],path:v[2]};});if(c.enabled&&(c.mirroring_enabled||c.mode==='notification_ha'||c.role==='witness')){if(!window.SlsLabsSafety){message.textContent='The administrator danger acknowledgment is unavailable.';return;}if(!await window.SlsLabsSafety.acknowledge())return;}call('cluster_save',{config:c,revision}).then(r=>{if(typeof r.revision==='string')revision=r.revision;Object.assign(base,c);window.SlsLabsActivation?.('enterprise_cluster',c);window.SlsSaveState?.set(document.getElementById('sls-enterprise'),'saved');}).catch(()=>{});};
panel.querySelectorAll('[data-cluster-action]').forEach(b=>b.onclick=()=>call(b.dataset.clusterAction).catch(()=>{}));
document.getElementById('sls-cluster-control').onsubmit=e=>{e.preventDefault();const d=new FormData(e.target);call('cluster_control',{peer_id:String(d.get('peer_id')),stopped:d.has('stopped'),spend_limit_cents:Number(d.get('spend_limit_cents'))}).catch(()=>{});};
document.getElementById('sls-cluster-handoff').onsubmit=e=>{e.preventDefault();call('cluster_handoff',{node_id:String(new FormData(e.target).get('node_id'))}).catch(()=>{});};
document.getElementById('sls-cluster-reconcile').onsubmit=e=>{e.preventDefault();const d=new FormData(e.target);call('cluster_reconcile',{id:String(d.get('id')),evidence:String(d.get('evidence')),prior_node_fenced:d.has('prior_node_fenced')}).catch(()=>{});};
document.getElementById('sls-cluster-receipt').onsubmit=e=>{e.preventDefault();const d=new FormData(e.target);call('cluster_receipt',{peer_id:String(d.get('peer_id')),id:String(d.get('id'))}).catch(()=>{});};
    document.getElementById('sls-cluster-distribute').onsubmit=async e=>{e.preventDefault();const d=new FormData(e.target),intent={message:String(d.get('message')),title:String(d.get('title'))},channels=[],recipients=[];if(d.has('edge')){const ids=lines(d.get('edge_recipients'));recipients.push(...ids);channels.push(...d.getAll('edge_channels').map(String));const asset=String(d.get('audio_asset_id')||'').trim();if(asset){intent.audio_asset_id=asset;if(!channels.includes('local_audio'))channels.push('local_audio');}}else{const mapping={targets:'phones',desktop_clients:'desktop',email_recipient_ids:'email',sms_recipient_ids:'sms',voice_recipient_ids:'external_voice',webhook_ids:'webhook'};Object.entries(mapping).forEach(([k,ch])=>{const ids=lines(d.get(k));if(ids.length){intent[k]=ids;channels.push(ch);recipients.push(...ids);}});intent.audio_mode=String(d.get('audio_mode'));if(intent.audio_mode!=='none'&&intent.targets)channels.push('audio');}try{await call('cluster_distribute',{peer_id:String(d.get('peer_id')),site_id:String(d.get('site_id')),ttl:Number(d.get('ttl')),recipients:[...new Set(recipients)].sort(),channels:[...new Set(channels)].sort(),intent,offline_approved:d.has('offline_approved')});}catch(error){message.textContent=error.message;}};
})();
</script>
