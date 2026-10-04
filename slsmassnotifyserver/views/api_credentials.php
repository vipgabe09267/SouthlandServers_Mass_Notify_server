<?php
// Include inside the General Settings form. Controls submit through its existing
// authenticated, CSRF-protected controller; no nested forms or stored secrets.
$credentialEscape = static function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
$credentialRows = is_array($api_credentials ?? null) ? $api_credentials : [];
foreach ($credentialRows as &$row) { unset($row['secret_hash']); } unset($row);
$credentialOptions = ['extensions' => [], 'desktop_client_ids' => [], 'announcement_group_ids' => [], 'voice_recipient_ids' => [], 'webhook_ids' => [], 'nws_zone_ids' => [], 'email_recipient_ids' => [], 'sms_recipient_ids' => []];
foreach ((array)($available_extensions ?? []) as $row) {
    if (is_array($row) && isset($row['extension'])) { $credentialOptions['extensions'][] = [(string)$row['extension'], (string)$row['extension'] . ' · ' . ($row['name'] ?? '')]; }
}
foreach (['desktop_client_ids' => ['desktop_clients', 'client_id'], 'announcement_group_ids' => ['announcement_groups', 'id'],
          'webhook_ids' => ['announcement_webhooks', 'id'], 'nws_zone_ids' => ['nws_zones', 'id']] as $field => [$source, $id]) {
    foreach ((array)($settings[$source] ?? []) as $row) { if (is_array($row) && isset($row[$id])) { $credentialOptions[$field][] = [(string)$row[$id], (string)($row['name'] ?? $row['label'] ?? $row['username'] ?? $row[$id])]; } }
}
foreach ((array)($settings['outbound_voice']['recipients'] ?? []) as $row) {
    if (is_array($row) && isset($row['id'])) { $credentialOptions['voice_recipient_ids'][] = [(string)$row['id'], (string)($row['name'] ?? $row['id'])]; }
}
$credentialEmailIds = array_fill_keys(\SLS\MassNotify\ApiSecurity::enabledEmailRecipientIds($settings ?? []), true);
foreach ((array)($settings['announcement_email']['recipients'] ?? []) as $row) {
    if (is_array($row) && isset($credentialEmailIds[$row['id'] ?? ''])) {
        // Show the saved label, never project addresses or private mail settings.
        $credentialOptions['email_recipient_ids'][] = [(string)$row['id'], (string)($row['name'] ?? $row['id'])];
    }
}
$credentialSmsIds = array_fill_keys(\SLS\MassNotify\ApiSecurity::enabledSmsRecipientIds($settings ?? []), true);
foreach ((array)($settings['announcement_sms']['recipients'] ?? []) as $row) {
    if (is_array($row) && isset($credentialSmsIds[$row['id'] ?? ''])) {
        // Show the saved label, never project addresses or private mail settings.
        $credentialOptions['sms_recipient_ids'][] = [(string)$row['id'], (string)($row['name'] ?? $row['id'])];
    }
}
$credentialLabels = ['extensions' => 'Phones', 'desktop_client_ids' => 'Desktop clients', 'announcement_group_ids' => 'Saved announcement groups',
    'voice_recipient_ids' => 'External voice recipients', 'webhook_ids' => 'Announcement integrations', 'nws_zone_ids' => 'Weather zones', 'email_recipient_ids' => 'Announcement email recipients', 'sms_recipient_ids' => 'SMS recipients'];
?>
<section class="sls-manager-card sls-credential-card" aria-labelledby="sls-credential-heading">
  <h3 id="sls-credential-heading"><i class="fa fa-key" aria-hidden="true"></i> Named API credentials <?php include __DIR__ . '/labs.php'; ?></h3>
  <p class="help-block">Give each integration its own permissions and revoke it independently. The existing legacy key remains compatible. Changes here take effect immediately.</p>
  <div class="sls-credential-status alert" role="status" aria-live="polite" hidden></div>
  <div class="sls-credential-secret alert alert-success" hidden>
    <label for="sls-credential-secret-value">One-time secret</label>
    <div class="sls-credential-secret-controls"><input id="sls-credential-secret-value" class="form-control" readonly autocomplete="off" spellcheck="false"><button type="button" class="btn btn-default" data-credential-copy><i class="fa fa-copy" aria-hidden="true"></i> Copy</button><button type="button" class="btn btn-default" data-credential-hide>Hide</button></div>
    <p class="help-block">Save this secret in the integration's credential store. SLS stores only its hash.</p>
  </div>
  <div class="table-responsive" tabindex="0" aria-label="API credentials; scroll horizontally on small screens"><table class="table table-striped"><thead><tr><th>Name</th><th>Permissions</th><th>Audience</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody data-credential-rows></tbody></table></div>
  <details><summary><i class="fa fa-plus-circle" aria-hidden="true"></i> Create a credential</summary>
    <div class="sls-credential-create">
      <label for="sls-credential-name">Integration name</label><input id="sls-credential-name" class="form-control" maxlength="80" placeholder="For example, facilities automation" autocomplete="off">
      <fieldset><legend>Permissions</legend><div class="sls-credential-scopes">
        <?php foreach (['read' => 'Read delivery status', 'send' => 'Send announcements', 'test' => 'Run weather tests', 'config' => 'Read and change configuration'] as $scope => $label): ?>
          <label><input type="checkbox" data-credential-scope="<?php echo $scope; ?>"<?php echo $scope === 'read' ? ' checked' : ''; ?>> <?php echo $credentialEscape($label); ?></label>
        <?php endforeach; ?>
      </div><p class="help-block">Restricted credentials can read only their own announcement jobs. Configuration access requires an unrestricted audience and cannot create credentials or change proxy trust.</p></fieldset>
      <label class="sls-credential-unrestricted"><input type="checkbox" data-credential-unrestricted> Allow every current and future recipient</label>
      <p class="help-block"><i class="fa fa-info-circle" aria-hidden="true"></i> Leave unchecked to select permitted recipients below. Saved-group permission includes that group's current members. Weather-test permission applies to the selected zones and their configured recipients.</p>
      <div class="sls-credential-audiences">
      <?php foreach ($credentialLabels as $field => $label): ?>
        <details class="sls-credential-picker" data-credential-audience="<?php echo $field; ?>"<?php echo $field === 'extensions' && $credentialOptions[$field] ? ' open' : ''; ?>>
          <summary><?php echo $credentialEscape($label); ?> <span class="badge" data-credential-count>0 selected</span></summary>
          <?php if (!$credentialOptions[$field]): ?>
            <p class="help-block">No saved entries are available. Configure these recipients before assigning permission.</p>
          <?php else: ?>
            <input class="form-control" type="search" data-credential-search placeholder="Search <?php echo $credentialEscape(strtolower($label)); ?>" aria-label="Search <?php echo $credentialEscape(strtolower($label)); ?>">
            <div class="sls-credential-picker-actions"><button type="button" class="btn btn-default btn-sm" data-credential-select-visible>Select matches</button><button type="button" class="btn btn-default btn-sm" data-credential-clear>Clear</button></div>
            <div class="sls-credential-picker-list">
            <?php foreach ($credentialOptions[$field] as [$id, $name]): ?>
              <label><input type="checkbox" value="<?php echo $credentialEscape($id); ?>" data-credential-recipient> <span><?php echo $credentialEscape($name); ?></span></label>
            <?php endforeach; ?>
            </div>
            <p class="help-block" data-credential-no-matches hidden>No matching entries.</p>
          <?php endif; ?>
        </details>
      <?php endforeach; ?>
      </div>
      <p class="help-block" id="sls-credential-select-help">Expand a recipient type to search and select entries. Empty selections grant no permission for that recipient type.</p>
      <button type="button" class="btn btn-primary" data-credential-create><i class="fa fa-key" aria-hidden="true"></i> Create credential</button>
    </div>
  </details>
</section>
<style>
.sls-credential-card{margin-top:20px}.sls-credential-card .table-responsive{position:relative;overflow-x:auto;max-width:100%}.sls-credential-card table{min-width:620px}.sls-credential-card [hidden]{display:none!important}.sls-credential-create{padding:16px 0}.sls-credential-create>input{max-width:520px;margin-bottom:16px}.sls-credential-create fieldset{margin:14px 0;border:0;padding:0}.sls-credential-create legend{font-size:15px;font-weight:600;margin-bottom:8px}.sls-credential-scopes{display:flex;flex-wrap:wrap;gap:12px 24px}.sls-credential-scopes label,.sls-credential-unrestricted{font-weight:400}.sls-credential-audiences{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.sls-credential-picker{border:1px solid #d7dfe7;border-radius:5px;padding:0 10px;align-self:start;background:#fff}.sls-credential-picker summary{font-size:14px}.sls-credential-picker .badge{float:right;font-weight:400;background:#e7edf4;color:#334155;margin-left:6px}.sls-credential-picker-actions{display:flex;gap:6px;padding:8px 0}.sls-credential-picker-list{max-height:190px;overflow-y:auto;margin-bottom:10px}.sls-credential-picker-list label{display:flex;gap:8px;font-weight:400;padding:6px 2px;margin:0;overflow-wrap:anywhere;cursor:pointer}.sls-credential-picker-list input{flex:0 0 auto}.sls-credential-picker[aria-disabled="true"]{opacity:.6}.sls-credential-secret-controls{display:flex;gap:8px;flex-wrap:wrap}.sls-credential-secret-controls input{flex:1;min-width:180px;font-family:monospace}.sls-credential-card summary{cursor:pointer;font-weight:600;padding:10px 0}.sls-credential-card td{vertical-align:middle!important;overflow-wrap:anywhere}.sls-credential-card small{display:block;color:#596579}@media(max-width:900px){.sls-credential-audiences{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:560px){.sls-credential-audiences{grid-template-columns:1fr}.sls-credential-scopes{display:grid;gap:10px}}
</style>
<script>
(function(){
 'use strict';
 var card=document.querySelector('.sls-credential-card');if(!card)return;
 var rows=<?php echo json_encode($credentialRows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
 var status=card.querySelector('.sls-credential-status'),secretPanel=card.querySelector('.sls-credential-secret'),secret=card.querySelector('#sls-credential-secret-value'),busy=false;
 function message(text,ok){status.textContent=text;status.className='sls-credential-status alert '+(ok?'alert-success':'alert-danger');status.hidden=false;}
 function render(){var body=card.querySelector('[data-credential-rows]');body.textContent='';if(!rows.length){var empty=document.createElement('tr'),cell=document.createElement('td');cell.colSpan=5;cell.textContent='No named credentials yet.';empty.appendChild(cell);body.appendChild(empty);return;}
 rows.forEach(function(row){var tr=document.createElement('tr');var audience=row.audience||{},count=Object.keys(audience).reduce(function(total,key){return total+(Array.isArray(audience[key])?audience[key].length:0);},0);
 [row.name,(row.scopes||[]).join(', '),audience.unrestricted?'All recipients':count+' permitted selections',row.revoked_at?'Revoked':'Active'].forEach(function(value){var td=document.createElement('td');td.textContent=value;tr.appendChild(td);});
 var id=document.createElement('small');id.textContent=row.id;tr.firstChild.appendChild(id);var action=document.createElement('td');if(!row.revoked_at){var button=document.createElement('button');button.type='button';button.className='btn btn-sm btn-danger';button.textContent='Revoke';button.addEventListener('click',function(){if(window.confirm('Revoke '+row.name+' immediately? Requests using this credential will be rejected.'))submit({credential_action:'revoke',credential_id:row.id});});action.appendChild(button);}tr.appendChild(action);body.appendChild(tr);});}
 function submit(values){if(busy)return;var csrf=document.querySelector('[name="slsmassnotifyserver_csrf"]');if(!csrf){message('Security token is missing. Reload the page.',false);return;}busy=true;card.querySelectorAll('button').forEach(function(button){button.disabled=true;});secret.value='';secretPanel.hidden=true;var body=new URLSearchParams();body.set('slsmassnotifyserver_action','manage_api_credential');body.set('slsmassnotifyserver_csrf',csrf.value);Object.keys(values).forEach(function(key){body.set(key,values[key]);});
 var controller=new AbortController(),timer=window.setTimeout(function(){controller.abort();},20000);
 fetch(window.location.href,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString(),signal:controller.signal}).then(function(response){if(!response.ok)throw new Error('The PBX returned HTTP '+response.status+'. Reload the list before retrying.');return response.json();}).then(function(result){message(result.message||'The PBX did not confirm the change.',result.success===true);if(Array.isArray(result.credentials)){rows=result.credentials;render();}if(result.success===true&&typeof result.secret==='string'){secret.value=result.secret;secretPanel.hidden=false;secret.focus();secret.select();}}).catch(function(error){message(error.name==='AbortError'?'The PBX did not confirm the credential change in time. Reload the list before retrying; do not assume the operation failed.':error.message,false);}).finally(function(){window.clearTimeout(timer);busy=false;card.querySelectorAll('button').forEach(function(button){button.disabled=Boolean(button.closest('[data-credential-audience][aria-disabled="true"]'));});});}
 card.querySelector('[data-credential-create]').addEventListener('click',function(){var name=card.querySelector('#sls-credential-name').value.trim(),scopes=Array.from(card.querySelectorAll('[data-credential-scope]:checked')).map(function(input){return input.dataset.credentialScope;}),audience={unrestricted:card.querySelector('[data-credential-unrestricted]').checked};if(!name||!scopes.length){message('Enter an integration name and select at least one permission.',false);return;}if(scopes.indexOf('config')>=0&&!audience.unrestricted){message('Configuration permission requires an unrestricted audience.',false);return;}card.querySelectorAll('[data-credential-audience]').forEach(function(picker){audience[picker.dataset.credentialAudience]=Array.from(picker.querySelectorAll('[data-credential-recipient]:checked')).map(function(input){return input.value;});});submit({credential_action:'create',credential_json:JSON.stringify({name:name,scopes:scopes,audience:audience})});});
 card.querySelector('[data-credential-unrestricted]').addEventListener('change',function(){var checked=this.checked;card.querySelectorAll('[data-credential-audience]').forEach(function(picker){picker.setAttribute('aria-disabled',String(checked));picker.querySelectorAll('input,button').forEach(function(input){input.disabled=checked;});});});
 card.querySelectorAll('[data-credential-audience]').forEach(function(picker){
  var search=picker.querySelector('[data-credential-search]'),count=picker.querySelector('[data-credential-count]');
  function update(){count.textContent=picker.querySelectorAll('[data-credential-recipient]:checked').length+' selected';}
  picker.addEventListener('change',update);
  if(!search)return;
  search.addEventListener('input',function(){var term=this.value.trim().toLocaleLowerCase(),matches=0;picker.querySelectorAll('.sls-credential-picker-list label').forEach(function(label){label.hidden=label.textContent.toLocaleLowerCase().indexOf(term)<0;if(!label.hidden)matches++;});picker.querySelector('[data-credential-no-matches]').hidden=matches!==0;});
  picker.querySelector('[data-credential-select-visible]').addEventListener('click',function(){picker.querySelectorAll('.sls-credential-picker-list label').forEach(function(label){if(!label.hidden)label.querySelector('input').checked=true;});update();});
  picker.querySelector('[data-credential-clear]').addEventListener('click',function(){picker.querySelectorAll('[data-credential-recipient]').forEach(function(input){input.checked=false;});update();});
 });
 card.querySelector('[data-credential-hide]').addEventListener('click',function(){secret.value='';secretPanel.hidden=true;});
 card.querySelector('[data-credential-copy]').addEventListener('click',function(){secret.focus();secret.select();if(navigator.clipboard){navigator.clipboard.writeText(secret.value).then(function(){message('Secret copied. Store it securely.',true);},function(){message('Clipboard access was denied. Copy the selected secret manually.',false);});}else{message('Copy the selected secret manually.',true);}});
 window.addEventListener('pagehide',function(){secret.value='';secretPanel.hidden=true;});render();
})();
</script>
