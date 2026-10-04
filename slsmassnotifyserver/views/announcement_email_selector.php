<?php
// IDs only; email addresses remain in the administrator's saved-recipient editor.
$emailSelectorField = (string)($email_selector_field ?? '');
if (!in_array($emailSelectorField, ['announcement_email_recipient_ids', 'group_email_recipient_ids', 'schedule_email_recipient_ids'], true)) { throw new DomainException('Unknown email recipient form.'); }
$emailSelectorId = 'sls-' . str_replace('_', '-', $emailSelectorField);
$emailSelectorRows = array_values(array_filter((array)($emailRecipients ?? []), 'is_array'));
$emailSelectorEscape = static function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
?>
<style>
.sls-email-selector { border:1px solid #dfe5ec; border-radius:7px; margin-bottom:12px; background:#fff; }
.sls-email-selector>summary { display:flex; gap:8px; align-items:center; cursor:pointer; padding:10px 12px; font-weight:600; }
.sls-email-selector .sls-email-selector-body { padding:0 12px 12px; }
.sls-email-selector .sls-email-selected { margin-left:auto; color:#475569; font-size:12px; font-weight:400; }
.sls-email-selector .sls-email-selector-list { max-height:220px; overflow:auto; margin-top:9px; }
.sls-email-selector .checkbox { margin:7px 0; overflow-wrap:anywhere; }
.sls-email-selector .sls-email-unavailable { color:#92400e; }
.sls-email-selector .help-block { font-size:12px; margin-bottom:0; }
</style>
<details class="sls-destination-panel sls-email-selector" id="<?php echo $emailSelectorEscape($emailSelectorId); ?>">
    <summary><i class="fa fa-envelope" aria-hidden="true"></i><span><?php echo _('Email'); ?></span><span class="sls-email-selected" data-email-selected aria-live="polite"></span><i class="fa fa-chevron-down" aria-hidden="true"></i></summary>
    <div class="sls-email-selector-body">
        <label class="sr-only" for="<?php echo $emailSelectorEscape($emailSelectorId); ?>-search"><?php echo _('Search saved email recipients'); ?></label>
        <input class="form-control input-sm" type="search" id="<?php echo $emailSelectorEscape($emailSelectorId); ?>-search" data-email-search placeholder="<?php echo $emailSelectorEscape(_('Search recipients')); ?>" autocomplete="off">
        <div class="sls-email-selector-list" data-email-selector-list>
        <?php foreach ($emailSelectorRows as $recipient) { ?><div class="checkbox" data-email-selector-row><label><input type="checkbox" name="<?php echo $emailSelectorEscape($emailSelectorField); ?>[]" value="<?php echo $emailSelectorEscape($recipient['id'] ?? ''); ?>"> <?php echo $emailSelectorEscape($recipient['name'] ?? ''); ?></label></div><?php } ?>
        <div data-email-unavailable></div></div>
        <p class="help-block" data-email-none <?php echo $emailSelectorRows ? 'hidden' : ''; ?>><?php echo $emailSelectorRows ? _('No matching saved recipients.') : _('Enable announcement email and save recipients in General Settings.'); ?></p>
        <p class="help-block"><?php echo _('Sends the complete message as text and branded email. Audio may be None for email-only announcements. Mail service acceptance is not proof of inbox delivery or reading.'); ?></p>
    </div>
</details>
<script>
(function(){'use strict';var root=document.getElementById(<?php echo json_encode($emailSelectorId); ?>);if(!root)return;var field=<?php echo json_encode($emailSelectorField); ?>,search=root.querySelector('[data-email-search]'),unavailable=root.querySelector('[data-email-unavailable]');
    function refresh(){var selected=0,visible=0,query=search.value.toLowerCase().trim();root.querySelectorAll('input[type=checkbox]').forEach(function(input){if(input.checked)selected++;});root.querySelectorAll('[data-email-selector-row]').forEach(function(row){row.hidden=query!==''&&row.textContent.toLowerCase().indexOf(query)===-1;if(!row.hidden)visible++;});root.querySelector('[data-email-selected]').textContent=selected+' '+<?php echo json_encode(_('selected')); ?>;root.querySelector('[data-email-none]').hidden=visible>0;}
    root.slsEmailSelection=function(ids){unavailable.textContent='';search.value='';var selected=new Set(Array.isArray(ids)?ids:[]),known=new Set();root.querySelectorAll('[data-email-selector-row] input').forEach(function(input){known.add(input.value);input.checked=selected.has(input.value);});selected.forEach(function(id){if(known.has(id))return;var row=document.createElement('div'),label=document.createElement('label'),input=document.createElement('input');row.className='checkbox sls-email-unavailable';row.setAttribute('data-email-selector-row','');input.type='checkbox';input.name=field+'[]';input.value=id;input.checked=true;label.appendChild(input);label.appendChild(document.createTextNode(' '+<?php echo json_encode(_('Unavailable saved email recipient — remove this selection or re-enable the recipient in General Settings.')); ?>));row.appendChild(label);unavailable.appendChild(row);});refresh();};
    search.addEventListener('input',refresh);root.addEventListener('change',refresh);refresh();
})();
</script>
