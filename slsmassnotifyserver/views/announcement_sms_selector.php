<?php
// IDs only; SMS numbers remain in the administrator's saved-recipient editor.
$smsSelectorField = (string)($sms_selector_field ?? '');
if (!in_array($smsSelectorField, ['announcement_sms_recipient_ids', 'group_sms_recipient_ids', 'schedule_sms_recipient_ids'], true)) { throw new DomainException('Unknown SMS recipient form.'); }
$smsSelectorId = 'sls-' . str_replace('_', '-', $smsSelectorField);
$smsSelectorRows = array_values(array_filter((array)($smsRecipients ?? []), 'is_array'));
$smsSelectorEscape = static function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
?>
<style>
.sls-sms-selector { border:1px solid #dfe5ec; border-radius:7px; margin-bottom:12px; background:#fff; }
.sls-sms-selector>summary { display:flex; gap:8px; align-items:center; cursor:pointer; padding:10px 12px; font-weight:600; }
.sls-sms-selector .sls-sms-selector-body { padding:0 12px 12px; }
.sls-sms-selector .sls-sms-selected { margin-left:auto; color:#475569; font-size:12px; font-weight:400; }
.sls-sms-selector .sls-sms-selector-list { max-height:220px; overflow:auto; margin-top:9px; }
.sls-sms-selector .checkbox { margin:7px 0; overflow-wrap:anywhere; }
.sls-sms-selector .sls-sms-unavailable { color:#92400e; }
.sls-sms-selector .help-block { font-size:12px; margin-bottom:0; }
</style>
<details class="sls-destination-panel sls-sms-selector" id="<?php echo $smsSelectorEscape($smsSelectorId); ?>">
    <summary><i class="fa fa-commenting" aria-hidden="true"></i><span><?php echo _('SMS'); ?></span><span class="sls-sms-selected" data-sms-selected aria-live="polite"></span><i class="fa fa-chevron-down" aria-hidden="true"></i></summary>
    <div class="sls-sms-selector-body">
        <label class="sr-only" for="<?php echo $smsSelectorEscape($smsSelectorId); ?>-search"><?php echo _('Search saved SMS recipients'); ?></label>
        <input class="form-control input-sm" type="search" id="<?php echo $smsSelectorEscape($smsSelectorId); ?>-search" data-sms-search placeholder="<?php echo $smsSelectorEscape(_('Search recipients')); ?>" autocomplete="off">
        <div class="sls-sms-selector-list" data-sms-selector-list>
        <?php foreach ($smsSelectorRows as $recipient) { ?><div class="checkbox" data-sms-selector-row><label><input type="checkbox" name="<?php echo $smsSelectorEscape($smsSelectorField); ?>[]" value="<?php echo $smsSelectorEscape($recipient['id'] ?? ''); ?>"> <?php echo $smsSelectorEscape($recipient['name'] ?? ''); ?></label></div><?php } ?>
        <div data-sms-unavailable></div></div>
        <p class="help-block" data-sms-none <?php echo $smsSelectorRows ? 'hidden' : ''; ?>><?php echo $smsSelectorRows ? _('No matching saved recipients.') : _('Enable announcement SMS and save recipients in General Settings.'); ?></p>
        <p class="help-block"><?php echo _('Sends the organization label, title and complete message by SMS. Audio may be None for SMS-only announcements. Use Preview SMS to review segments and budgeted cost; provider delivery does not confirm reading.'); ?></p>
    </div>
</details>
<script>
(function(){'use strict';var root=document.getElementById(<?php echo json_encode($smsSelectorId); ?>);if(!root)return;var field=<?php echo json_encode($smsSelectorField); ?>,search=root.querySelector('[data-sms-search]'),unavailable=root.querySelector('[data-sms-unavailable]');
    function refresh(){var selected=0,visible=0,query=search.value.toLowerCase().trim();root.querySelectorAll('input[type=checkbox]').forEach(function(input){if(input.checked)selected++;});root.querySelectorAll('[data-sms-selector-row]').forEach(function(row){row.hidden=query!==''&&row.textContent.toLowerCase().indexOf(query)===-1;if(!row.hidden)visible++;});root.querySelector('[data-sms-selected]').textContent=selected+' '+<?php echo json_encode(_('selected')); ?>;root.querySelector('[data-sms-none]').hidden=visible>0;}
    root.slsSmsSelection=function(ids){unavailable.textContent='';search.value='';var selected=new Set(Array.isArray(ids)?ids:[]),known=new Set();root.querySelectorAll('[data-sms-selector-row] input').forEach(function(input){known.add(input.value);input.checked=selected.has(input.value);});selected.forEach(function(id){if(known.has(id))return;var row=document.createElement('div'),label=document.createElement('label'),input=document.createElement('input');row.className='checkbox sls-sms-unavailable';row.setAttribute('data-sms-selector-row','');input.type='checkbox';input.name=field+'[]';input.value=id;input.checked=true;label.appendChild(input);label.appendChild(document.createTextNode(' '+<?php echo json_encode(_('Unavailable saved SMS recipient — remove this selection or re-enable the recipient in General Settings.')); ?>));row.appendChild(label);unavailable.appendChild(row);});refresh();};
    search.addEventListener('input',refresh);root.addEventListener('change',refresh);refresh();
})();
</script>
