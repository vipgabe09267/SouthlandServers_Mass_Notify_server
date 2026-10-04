<?php
$sms=\SLS\MassNotify\Sms\Config::normalize($settings['announcement_sms']??[]);
$smsEscape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$smsAmount=static function($value): string {
    $parts=explode('.',number_format($value/1000000,6,'.',''));
    return $parts[0].'.'.str_pad(rtrim($parts[1],'0'),2,'0');
};
$smsUsage=is_array($announcement_sms_usage??null)?$announcement_sms_usage:['available'=>true,'periods'=>[],'blocked_recipients'=>[]];
try { $smsCallback=\SLS\MassNotify\Sms\Service::callbackUrl($settings); } catch (\Throwable $error) { $smsCallback=''; }
?>
<style>
.sls-sms-editor{padding:18px;border:1px solid var(--sls-border,#dfe5ec);border-radius:8px;background:var(--sls-surface,#fbfcfe);color:var(--sls-text,#334155);margin:18px 0}
.sls-sms-editor .text-warning{color:var(--sls-yellow,#92400e)}.sls-sms-editor h3{margin-top:0}.sls-sms-editor h4{margin:20px 0 12px;font-size:16px}
.sls-sms-editor .text-muted,.sls-sms-editor .help-block{color:var(--sls-muted,#64748b)}
.sls-sms-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px 18px}
.sls-sms-grid .form-group{margin-bottom:0}.sls-sms-grid label{display:block}.sls-sms-grid .form-control{width:100%;min-width:0}
.sls-sms-secrets{padding:14px;background:var(--sls-raised,#fff);border:1px solid var(--sls-border,#e0e5eb);border-radius:6px;margin:14px 0}
.sls-sms-secrets[hidden]{display:none}.sls-sms-editor [data-sls-provider-fields] :disabled{background:var(--sls-bg,#eef1f5);color:var(--sls-muted,#7b8796);cursor:not-allowed;opacity:1}.sls-sms-editor code{overflow-wrap:anywhere;white-space:normal}
.sls-sms-rows{max-height:500px;overflow:auto;margin:14px 0}.sls-sms-row{background:var(--sls-raised,#fff);border:1px solid var(--sls-border,#dfe5ec);border-radius:6px;padding:14px;margin-bottom:10px}
.sls-sms-row-top{display:grid;grid-template-columns:75px minmax(130px,1fr) minmax(160px,1fr) 38px;gap:12px;align-items:end}
.sls-sms-row label{display:block;font-size:13px;margin:0}.sls-sms-row input.form-control{margin-top:5px}
.sls-sms-consent{display:grid;grid-template-columns:minmax(170px,1fr) minmax(190px,1.6fr);gap:10px 16px;margin-top:14px;align-items:center}
.sls-sms-renew{margin-top:8px!important;color:var(--sls-muted,#586577)}.sls-sms-footer{display:flex;gap:12px;align-items:center;justify-content:space-between}
.sls-sms-error{color:var(--sls-red,#991b1b);margin:8px 0}.sls-sms-editor .help-block{font-size:13px;margin-top:7px}
@media(max-width:767px){.sls-sms-grid{grid-template-columns:1fr}.sls-sms-row-top{grid-template-columns:1fr 38px}.sls-sms-name,.sls-sms-number{grid-column:1/-1}.sls-sms-remove{grid-column:2;grid-row:1}.sls-sms-consent{grid-template-columns:1fr}}
</style>
<section id="sls-announcement-sms" class="sls-sms-editor" aria-labelledby="sls-sms-heading">
    <h3 id="sls-sms-heading" class="sls-settings-heading"><i class="fa fa-commenting text-primary" aria-hidden="true"></i> <?php echo _('SMS and MMS alerts'); ?> <?php include __DIR__ . '/labs.php'; ?></h3>
    <p class="text-muted"><?php echo _('Send announcements to saved recipients through Twilio, Telnyx or BulkVS. SMS is disabled until a provider, sender, consent and limits are configured. Saving settings does not send a message.'); ?></p>
    <input type="hidden" name="announcement_sms_present" value="1"><input type="hidden" name="announcement_sms_complete" data-sms-complete value="0"><input type="hidden" name="announcement_sms_recipients_json" data-sms-json value="">
<div data-sls-provider-fields <?php echo !empty($slsRecipientEditorOnly) ? 'hidden' : ''; ?>>
    <div class="checkbox"><label><input type="checkbox" name="announcement_sms_enabled" value="1" <?php echo $sms['enabled']?'checked':''; ?>> <?php echo _('Enable SMS announcements'); ?></label></div>
    <p class="help-block" id="sls-sms-provider-note" role="status"><?php echo _('Choose a provider first to configure sending, credentials and limits.'); ?></p>
    <div class="sls-sms-grid">
        <div class="form-group"><label for="sls-sms-provider"><?php echo _('Provider'); ?></label><select id="sls-sms-provider" name="sms_provider" class="form-control"><option value=""><?php echo _('Choose a provider'); ?></option><?php foreach (['twilio'=>'Twilio','telnyx'=>'Telnyx','bulkvs'=>'BulkVS'] as $value=>$label) { ?><option value="<?php echo $value; ?>" <?php echo $sms['provider']===$value?'selected':''; ?>><?php echo $label; ?></option><?php } ?></select></div>
        <div class="form-group"><label for="sls-sms-from"><?php echo _('Sending number'); ?></label><input id="sls-sms-from" class="form-control" name="sms_from" maxlength="16" value="<?php echo $smsEscape($sms['from']); ?>" placeholder="+15555550100"><p class="help-block"><?php echo _('Use an SMS-enabled number assigned to this provider account, including + and country code.'); ?></p></div>
        <div class="form-group"><label for="sls-sms-organization"><?php echo _('Organization label'); ?></label><input id="sls-sms-organization" class="form-control" name="sms_organization" maxlength="40" value="<?php echo $smsEscape($sms['organization']); ?>"><p class="help-block"><?php echo _('Included before the title and message. Preview the complete SMS on the dashboard.'); ?></p></div>
    </div>
    <?php foreach (['twilio'=>['twilio_account_sid'=>['Account SID','Account identifier beginning with AC.'],'twilio_key_sid'=>['API key SID','Sending key beginning with SK.'],'twilio_key_secret'=>['API key secret','Used only to authenticate provider requests.'],'twilio_auth_token'=>['Account auth token','Used to verify Twilio callback signatures.']],
        'telnyx'=>['telnyx_api_key'=>['API key','Used only to authenticate provider requests.'],'telnyx_public_key'=>['Webhook public key','Base64 Ed25519 key from the Telnyx account.'],'telnyx_profile_id'=>['Messaging profile ID','The SMS-enabled profile assigned to the sending number.']],
        'bulkvs'=>['bulkvs_api_username'=>['API username','Use the REST API username from API → API Credentials in the BulkVS portal.'],'bulkvs_api_password'=>['API password / token','Use the REST API password or token, not your portal login password.']]] as $provider=>$fields) { ?>
    <fieldset class="sls-sms-secrets" data-sms-provider="<?php echo $provider; ?>" <?php echo $sms['provider']===$provider?'':'hidden'; ?>>
        <legend class="sr-only"><?php echo ucfirst($provider).' '._('credentials'); ?></legend><div class="sls-sms-grid">
        <?php foreach ($fields as $key=>[$label,$hint]) { $secret=in_array($key,['twilio_key_secret','twilio_auth_token','telnyx_api_key','bulkvs_api_password'],true); ?>
        <div class="form-group"><label for="sls-<?php echo $key; ?>"><?php if ($secret) { ?><i class="fa fa-lock" aria-hidden="true"></i> <?php } echo _($label); ?></label>
            <input class="form-control" id="sls-<?php echo $key; ?>" name="sms_<?php echo $key; ?>" type="<?php echo $secret?'password':'text'; ?>" autocomplete="<?php echo $secret?'new-password':'off'; ?>" maxlength="512" value="<?php echo $secret?'':$smsEscape($sms[$key]); ?>" <?php if ($secret && $sms[$key]!=='') { ?>placeholder="<?php echo $smsEscape(_('Saved — leave blank to keep')); ?>"<?php } ?>>
            <p class="help-block"><?php echo _($hint); ?></p>
            <?php if ($secret) { ?><label class="text-muted"><input type="checkbox" name="sms_clear_<?php echo $key; ?>" value="1"> <?php echo _('Clear saved credential'); ?></label><?php } ?>
        </div><?php } ?></div>
    </fieldset><?php } ?>
    <p class="help-block" data-sms-callback-help><i class="fa fa-link" aria-hidden="true"></i> <strong><?php echo _('Provider callback address'); ?>:</strong> <?php if ($smsCallback!=='') { ?><code><?php echo $smsEscape($smsCallback); ?></code><?php } else { echo _('Set the advertised HTTPS Control API address in General Settings first.'); } ?><br><?php echo _('Configure inbound SMS webhooks at this address so STOP replies reach SLS. Outbound delivery callbacks are attached automatically. This address follows the saved hostname and advertised Control API port.'); ?></p>
    <p class="help-block" data-sms-bulkvs-help hidden><i class="fa fa-info-circle" aria-hidden="true"></i> <?php echo _('BulkVS reports API acceptance only. Delivery receipts and inbound STOP updates are not connected to SLS. Manage opt-outs in BulkVS and disable affected recipients here before sending another alert. Verify the provider account before live use.'); ?></p>
    <div data-sms-bulkvs-check hidden><button type="button" class="btn btn-default" data-sms-check-sender><i class="fa fa-check-circle" aria-hidden="true"></i> <?php echo _('Check saved BulkVS sender'); ?></button><p class="help-block"><?php echo _('Checks the saved sending number in BulkVS without sending SMS or changing the provider account. SMS must be enabled on the number; A2P long-code numbers also need an approved messaging campaign.'); ?></p><p data-sms-check-result role="status" aria-live="polite"></p></div>
    <p class="help-block"><?php echo _('Twilio and Telnyx messages include “Reply STOP to opt out.” Configure and verify their inbound webhook so replies update SLS. BulkVS inbound STOP handling is not yet supported; its opt-outs must be managed in the provider account.'); ?></p>
    <div class="sls-sms-grid">
        <div class="form-group"><label for="sls-sms-format"><?php echo _('Message format'); ?></label><select id="sls-sms-format" name="sms_message_format" class="form-control"><option value="sms" <?php echo $sms['message_format']==='sms'?'selected':''; ?>><?php echo _('SMS · text'); ?></option><option value="mms" <?php echo $sms['message_format']==='mms'?'selected':''; ?>><?php echo _('MMS · alert image and text'); ?></option></select></div>
        <div class="form-group" data-sms-mms-cost><label for="sls-sms-mms-cost"><?php echo _('Budgeted cost per MMS message').' ('.$smsEscape($sms['currency']).')'; ?></label><input id="sls-sms-mms-cost" class="form-control" name="sms_mms_cost" type="number" min="0" max="100" step="0.000001" data-sms-money value="<?php echo $smsAmount($sms['mms_cost_micros']); ?>"></div>
    </div>
    <p class="help-block"><?php echo _('SMS is the default. MMS attaches a summary image and retains the complete alert in its text. MMS requires an MMS-enabled sending number and an externally reachable HTTPS image URL. Provider image downloads follow your generated-media network and expiry settings. MMS is not necessarily cheaper; compare one MMS price with the SMS segment count, including carrier fees.'); ?></p>
    <h4><i class="fa fa-tachometer text-primary" aria-hidden="true"></i> <?php echo _('Sending limits'); ?></h4>
    <div class="sls-sms-grid">
        <?php foreach (['max_segments'=>['Maximum segments per message',10],'daily_segments'=>['Daily segment limit',1000000],'monthly_segments'=>['Monthly segment limit',10000000]] as $key=>[$label,$max]) { ?><div class="form-group"><label for="sls-sms-<?php echo $key; ?>"><?php echo _($label); ?></label><input id="sls-sms-<?php echo $key; ?>" class="form-control" type="number" name="sms_<?php echo $key; ?>" min="1" max="<?php echo $max; ?>" value="<?php echo (int)$sms[$key]; ?>"></div><?php } ?>
        <?php foreach (['segment_cost'=>'Budgeted cost per segment','daily_budget'=>'Daily cost limit','monthly_budget'=>'Monthly cost limit'] as $key=>$label) { ?><div class="form-group"><label for="sls-sms-<?php echo $key; ?>"><?php echo _($label).' ('.$smsEscape($sms['currency']).')'; ?></label><input id="sls-sms-<?php echo $key; ?>" class="form-control" name="sms_<?php echo $key; ?>" data-sms-money type="number" min="0" max="<?php echo $key==='segment_cost'?'100':'999999.999999'; ?>" step="0.000001" value="<?php echo $smsAmount($sms[$key.'_micros']); ?>"></div><?php } ?>
        <div class="form-group"><label for="sls-sms-currency"><?php echo _('Account currency'); ?></label><input id="sls-sms-currency" class="form-control" name="sms_currency" maxlength="3" pattern="[A-Z]{3}" value="<?php echo $smsEscape($sms['currency']); ?>"></div>
    </div>
    <p class="help-block"><?php echo _('Sending units are SMS segments or individual MMS messages. Limits are reserved before each provider request and reset at UTC day/month boundaries. Set a cost per segment that includes your provider and carrier fees. SLS limits its reserved cost; the provider determines the final bill. Failed and uncertain submissions retain their reservation. Also configure spending and queue limits in the provider account.'); ?></p>
    <?php if (empty($smsUsage['available'])) { ?><p class="alert alert-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> <?php echo _('SMS usage and opt-out storage could not be read. Sending will stop if its durable reservation cannot be verified. Check permissions and free space in the protected sms directory; preserve deliveries.sqlite and its initialized marker.'); ?></p>
    <?php } elseif (!empty($smsUsage['periods'])) { ?><div class="table-responsive"><table class="table table-condensed"><caption><?php echo _('Current reservations · UTC'); ?></caption><thead><tr><th><?php echo _('Period'); ?></th><th><?php echo _('Segments reserved'); ?></th><th><?php echo _('Cost reserved'); ?></th></tr></thead><tbody><?php foreach ($smsUsage['periods'] as $period) { ?><tr><th><?php echo $smsEscape(substr($period['period'],1)); ?></th><td><?php echo (int)$period['segments']; ?></td><td><?php echo $smsEscape($smsAmount($period['amount']).' '.$period['currency']); ?></td></tr><?php } ?></tbody></table></div><?php } ?>
</div>
<?php if (!empty($slsRecipientDirectoryManaged)) { ?><p class="help-block"><i class="fa fa-address-book-o" aria-hidden="true"></i> <?php echo _('Manage saved recipients in'); ?> <a href="config.php?display=slsmassnotifyserver_locations#recipients"><?php echo _('Locations and Audiences → Recipients'); ?></a>.</p><?php } ?>
<div data-sls-recipient-fields <?php echo !empty($slsRecipientDirectoryManaged) ? 'hidden' : ''; ?>>
    <h4><i class="fa fa-address-book-o text-primary" aria-hidden="true"></i> <?php echo _('Saved recipients and consent'); ?></h4>
    <p class="text-muted"><?php echo _('Only enabled recipients with recorded consent can be selected. Verified Twilio/Telnyx STOP callbacks block future SMS. For BulkVS, manage opt-outs in the provider account and disable affected recipients here. To re-enroll someone, record renewed consent with an updated note; they may also need to reply START to the provider.'); ?></p>
    <div class="sls-sms-rows" data-sms-rows></div><p class="text-muted" data-sms-empty><?php echo _('No SMS recipients have been saved.'); ?></p><p class="sls-sms-error" data-sms-error role="alert"></p>
    <div class="sls-sms-footer"><button type="button" class="btn btn-default" data-sms-add><i class="fa fa-plus" aria-hidden="true"></i> <?php echo _('Add SMS recipient'); ?></button><span class="text-muted" data-sms-count aria-live="polite"></span></div>
    <noscript><p class="alert alert-warning"><?php echo _('Enable JavaScript and reload before editing SMS recipients.'); ?></p></noscript>
    <template data-sms-template><div class="sls-sms-row" data-sms-row><input type="hidden" data-sms-field="id"><div class="sls-sms-row-top">
        <label><input type="checkbox" data-sms-field="enabled" checked> <?php echo _('Enabled'); ?></label>
        <label class="sls-sms-name"><?php echo _('Recipient name'); ?><input class="form-control input-sm" data-sms-field="name" maxlength="80" required></label>
        <label class="sls-sms-number"><?php echo _('Mobile number'); ?><input class="form-control input-sm" data-sms-field="number" type="tel" maxlength="16" pattern="\+[1-9][0-9]{7,14}" placeholder="+15555550101" required></label>
        <button type="button" class="btn btn-default sls-sms-remove" data-sms-remove title="<?php echo $smsEscape(_('Remove recipient')); ?>" aria-label="<?php echo $smsEscape(_('Remove recipient')); ?>"><i class="fa fa-trash-o" aria-hidden="true"></i></button>
        </div><div class="sls-sms-consent"><div><label><input type="checkbox" data-sms-field="consent"> <?php echo _('Agreed to receive SMS alerts'); ?></label><label class="sls-sms-renew"><input type="checkbox" data-sms-field="renew_consent"> <?php echo _('Record renewed consent'); ?></label></div>
        <label><?php echo _('Consent note'); ?><input class="form-control input-sm" data-sms-field="consent_note" maxlength="160" placeholder="<?php echo $smsEscape(_('When and how the recipient agreed')); ?>"></label></div><p class="text-warning" data-sms-blocked hidden><i class="fa fa-ban" aria-hidden="true"></i> <?php echo _('Opted out — sending is blocked until renewed consent is recorded and applied.'); ?></p></div></template>
</div>
</section>
<script>
(function(){'use strict';var root=document.getElementById('sls-announcement-sms');if(!root)return;
var list=root.querySelector('[data-sms-rows]'),template=root.querySelector('[data-sms-template]'),error=root.querySelector('[data-sms-error]'),complete=root.querySelector('[data-sms-complete]'),hidden=root.querySelector('[data-sms-json]'),add=root.querySelector('[data-sms-add]');
var seed=<?php echo json_encode($sms['recipients'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR); ?>;
var blocked=new Set(<?php echo json_encode($smsUsage['blocked_recipients'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR); ?>);
function sync(){complete.value='0';hidden.value='';var values=[],seen={},problem='';list.querySelectorAll('[data-sms-row]').forEach(function(row){var v={};['id','name','number','consent_note'].forEach(function(k){var input=row.querySelector('[data-sms-field="'+k+'"]');input.setCustomValidity('');v[k]=input.value;});['enabled','consent','renew_consent'].forEach(function(k){v[k]=row.querySelector('[data-sms-field="'+k+'"]').checked;});var n=row.querySelector('[data-sms-field="number"]'),name=row.querySelector('[data-sms-field="name"]'),note=row.querySelector('[data-sms-field="consent_note"]');if(!v.name.trim()||/[\x00-\x1f\x7f]/.test(v.name))name.setCustomValidity('Enter a recipient name without control characters.');if(!/^\+[1-9][0-9]{7,14}$/.test(v.number))n.setCustomValidity('Enter one number including + and country code.');if(seen[v.number])n.setCustomValidity('This number is already in the recipient list.');seen[v.number]=true;note.required=v.consent;if(v.consent&&!v.consent_note.trim())note.setCustomValidity('Record how this recipient agreed to receive SMS.');[name,n,note].forEach(function(input){if(!input.checkValidity())problem=problem||input.validationMessage;});values.push(v);});
if(values.length>50)problem='Save at most 50 SMS recipients.';var json=JSON.stringify(values);if(new Blob([json]).size>65536)problem='The SMS recipient editor exceeds its size limit.';error.textContent=problem;root.querySelector('[data-sms-empty]').hidden=values.length!==0;root.querySelector('[data-sms-count]').textContent=values.length+' / 50 recipients';add.disabled=values.length>=50;if(!problem){hidden.value=json;complete.value='1';}return !problem;}
function append(row){var node=template.content.firstElementChild.cloneNode(true);['id','name','number','consent_note'].forEach(function(k){node.querySelector('[data-sms-field="'+k+'"]').value=row[k]||'';});['enabled','consent'].forEach(function(k){node.querySelector('[data-sms-field="'+k+'"]').checked=row[k]===true;});node.querySelector('[data-sms-blocked]').hidden=!blocked.has(row.id);list.appendChild(node);}
function provider(){
    var selector=root.querySelector('#sls-sms-provider'),selected=selector.value;
    var fields=root.querySelector('[data-sls-provider-fields]');
    fields.querySelectorAll('input,select,button').forEach(function(input){if(input!==selector)input.disabled=!selected;});
    root.querySelectorAll('[data-sms-provider]').forEach(function(pane){pane.hidden=pane.dataset.smsProvider!==selected;});
    root.querySelector('#sls-sms-provider-note').hidden=!!selected;
    root.querySelector('[data-sms-callback-help]').hidden=selected==='bulkvs';
    root.querySelector('[data-sms-bulkvs-help]').hidden=selected!=='bulkvs';
    root.querySelector('[data-sms-bulkvs-check]').hidden=selected!=='bulkvs';
    selector.required=fields.querySelector('[name=announcement_sms_enabled]').checked;
    root.querySelector('[data-sms-mms-cost]').hidden=root.querySelector('#sls-sms-format').value!=='mms';
}
root.querySelector('[data-sms-check-sender]').addEventListener('click',async function(){
    var result=root.querySelector('[data-sms-check-result]'),button=this,form=root.closest('form');
    button.disabled=true;result.textContent='Checking the saved sending number…';
    try {
        var response=await fetch('config.php?display=slsmassnotifyserver_other',{method:'POST',credentials:'same-origin',cache:'no-store',
            body:new URLSearchParams({slsmassnotifyserver_action:'check_bulkvs_sender',slsmassnotifyserver_csrf:form.querySelector('[name="slsmassnotifyserver_csrf"]').value})});
        var value=await response.json();
        result.textContent=value.message||'The sender check did not return a result. Reload General Settings.';
        result.className=response.ok&&value.success&&!value.issues.length?'text-success':'text-warning';
    } catch(error) {result.className='text-warning';result.textContent='The sender check was interrupted. Reload General Settings and try again. No SMS was sent.';}
    finally {button.disabled=false;}
});
root.querySelectorAll('[data-sms-money]').forEach(function(input){input.addEventListener('blur',function(){
    if(!/^(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,6})?$/.test(input.value))return;
    var parts=input.value.split('.'),decimals=(parts[1]||'').replace(/0+$/,'');
    input.value=parts[0]+'.'+decimals.padEnd(2,'0');
});});
seed.forEach(append);sync();provider();root.querySelector('#sls-sms-provider').addEventListener('change',provider);root.addEventListener('input',sync);root.addEventListener('change',function(){sync();provider();});add.addEventListener('click',function(){if(list.children.length>=50)return;append({enabled:true});sync();list.lastElementChild.querySelector('[data-sms-field="name"]').focus();});list.addEventListener('click',function(event){var button=event.target.closest('[data-sms-remove]');if(button){button.closest('[data-sms-row]').remove();sync();}});var form=root.closest('form');if(form)form.addEventListener('submit',function(event){if(!sync()){event.preventDefault();form.reportValidity();}});
})();
</script>
