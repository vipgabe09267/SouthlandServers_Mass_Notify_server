<?php
// Project destination names and IDs only; webhook URLs and credentials stay out of this selector.
$webhookSelectorField = (string)($webhook_selector_field ?? '');
if (!in_array($webhookSelectorField, ['group_webhook_ids', 'schedule_webhook_ids'], true)) { throw new DomainException('Unknown Webhook recipient form.'); }
$webhookSelectorId = 'sls-' . str_replace('_', '-', $webhookSelectorField);
$webhookSelectorRows = array_values(array_filter((array)($announcementWebhooks ?? []), 'is_array'));
$webhookSelectorEscape = static function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
?>
<style>
.sls-webhook-selector { border:1px solid #dfe5ec; border-radius:7px; margin-bottom:12px; background:#fff; }
.sls-webhook-selector>summary { display:flex; gap:8px; align-items:center; cursor:pointer; padding:10px 12px; font-weight:600; }
.sls-webhook-selector .sls-webhook-selector-body { padding:0 12px 12px; }
.sls-webhook-selector .sls-webhook-selected { margin-left:auto; color:#475569; font-size:12px; font-weight:400; }
.sls-webhook-selector .sls-webhook-selector-list { max-height:220px; overflow:auto; margin-top:9px; }
.sls-webhook-selector .checkbox { margin:7px 0; overflow-wrap:anywhere; }
.sls-webhook-selector .sls-webhook-unavailable { color:#92400e; }
.sls-webhook-selector .help-block { font-size:12px; margin-bottom:0; }
</style>
<details class="sls-destination-panel sls-webhook-selector" id="<?php echo $webhookSelectorEscape($webhookSelectorId); ?>">
    <summary><i class="fa fa-link" aria-hidden="true"></i><span><?php echo _('Webhook'); ?></span><span class="sls-webhook-selected" data-webhook-selected aria-live="polite"></span><i class="fa fa-chevron-down" aria-hidden="true"></i></summary>
    <div class="sls-webhook-selector-body">
        <label class="sr-only" for="<?php echo $webhookSelectorEscape($webhookSelectorId); ?>-search"><?php echo _('Search saved Webhook recipients'); ?></label>
        <input class="form-control input-sm" type="search" id="<?php echo $webhookSelectorEscape($webhookSelectorId); ?>-search" data-webhook-search placeholder="<?php echo $webhookSelectorEscape(_('Search recipients')); ?>" autocomplete="off">
        <div class="sls-webhook-selector-list" data-webhook-selector-list>
        <?php foreach ($webhookSelectorRows as $recipient) { ?><div class="checkbox" data-webhook-selector-row><label><input type="checkbox" name="<?php echo $webhookSelectorEscape($webhookSelectorField); ?>[]" value="<?php echo $webhookSelectorEscape($recipient['id'] ?? ''); ?>"> <?php echo $webhookSelectorEscape($recipient['name'] ?? ''); ?></label></div><?php } ?>
        <div data-webhook-unavailable></div></div>
        <p class="help-block" data-webhook-none <?php echo $webhookSelectorRows ? 'hidden' : ''; ?>><?php echo $webhookSelectorRows ? _('No matching saved recipients.') : _('Add a webhook in Delivery providers. Only enabled destinations can receive an alert.'); ?></p>
        <p class="help-block"><?php echo _('Select saved webhook destinations. A shared webhook can belong to several audiences; each alert submits to it once.'); ?></p>
    </div>
</details>
<script>
(function(){'use strict';var root=document.getElementById(<?php echo json_encode($webhookSelectorId); ?>);if(!root)return;var field=<?php echo json_encode($webhookSelectorField); ?>,search=root.querySelector('[data-webhook-search]'),unavailable=root.querySelector('[data-webhook-unavailable]');
    function refresh(){var selected=0,visible=0,query=search.value.toLowerCase().trim();root.querySelectorAll('input[type=checkbox]').forEach(function(input){if(input.checked)selected++;});root.querySelectorAll('[data-webhook-selector-row]').forEach(function(row){row.hidden=query!==''&&row.textContent.toLowerCase().indexOf(query)===-1;if(!row.hidden)visible++;});root.querySelector('[data-webhook-selected]').textContent=selected+' '+<?php echo json_encode(_('selected')); ?>;root.querySelector('[data-webhook-none]').hidden=visible>0;}
    root.slsWebhookSelection=function(ids){unavailable.textContent='';search.value='';var selected=new Set(Array.isArray(ids)?ids:[]),known=new Set();root.querySelectorAll('[data-webhook-selector-row] input').forEach(function(input){known.add(input.value);input.checked=selected.has(input.value);});selected.forEach(function(id){if(known.has(id))return;var row=document.createElement('div'),label=document.createElement('label'),input=document.createElement('input');row.className='checkbox sls-webhook-unavailable';row.setAttribute('data-webhook-selector-row','');input.type='checkbox';input.name=field+'[]';input.value=id;input.checked=true;label.appendChild(input);label.appendChild(document.createTextNode(' '+<?php echo json_encode(_('Unavailable saved Webhook recipient — remove this selection or re-enable the recipient in General Settings.')); ?>));row.appendChild(label);unavailable.appendChild(row);});refresh();};
    search.addEventListener('input',refresh);root.addEventListener('change',refresh);refresh();
})();
</script>
