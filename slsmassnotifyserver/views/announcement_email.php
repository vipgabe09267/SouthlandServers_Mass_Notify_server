<?php
$announcementEmail = is_array($settings['announcement_email'] ?? null) ? $settings['announcement_email'] : [];
$emailRows = is_array($announcementEmail['recipients'] ?? null) ? $announcementEmail['recipients'] : [];
$emailEscape = static function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
?>
<style>
.sls-email-editor { padding:16px; border:1px solid #dfe5ec; border-radius:8px; background:#fbfcfe; margin:18px 0; }
.sls-email-editor h3 { margin-top:0; }
.sls-email-editor-list { max-height:420px; overflow:auto; margin:12px 0; }
.sls-email-editor-row { display:grid; grid-template-columns:75px minmax(140px,1fr) minmax(190px,1.4fr) 40px; gap:12px; align-items:end; padding:12px; margin-bottom:8px; border:1px solid #dfe5ec; border-radius:6px; background:#fff; }
.sls-email-editor-row label { display:block; margin:0; font-size:13px; }
.sls-email-editor-row input.form-control { margin-top:5px; }
.sls-email-editor-row .sls-email-row-enabled { align-self:center; }
.sls-email-editor-row button { margin-bottom:2px; }
.sls-email-editor-footer { display:flex; align-items:center; justify-content:space-between; gap:12px; }
.sls-email-editor .sls-email-error { color:#991b1b; margin:8px 0; }
@media(max-width:767px) { .sls-email-editor-row { grid-template-columns:1fr 40px; } .sls-email-editor-row .sls-email-row-name,.sls-email-editor-row .sls-email-row-address { grid-column:1 / -1; } .sls-email-editor-row .sls-email-remove { grid-column:2; grid-row:1; } }
</style>
<section id="sls-announcement-email" class="sls-email-editor" aria-labelledby="sls-announcement-email-heading">
    <h3 class="sls-settings-heading" id="sls-announcement-email-heading"><i class="fa fa-envelope text-primary" aria-hidden="true"></i> <?php echo _('Announcement email recipients'); ?> <?php include __DIR__ . '/labs.php'; ?></h3>
    <p class="text-muted"><?php echo _('Save up to 50 email recipients for announcements. Each selected address receives a separate message through the PBX mail service, using the email sender identity configured in General Settings.'); ?></p>
    <input type="hidden" name="announcement_email_present" value="1">
    <input type="hidden" name="announcement_email_complete" id="sls-announcement-email-complete" value="0">
    <input type="hidden" name="announcement_email_recipients_json" id="sls-announcement-email-json" value="">
<div data-sls-provider-fields <?php echo !empty($slsRecipientEditorOnly) ? 'hidden' : ''; ?>>
    <div class="checkbox"><label><input type="checkbox" name="announcement_email_enabled" value="1" <?php echo !empty($announcementEmail['enabled']) ? 'checked' : ''; ?>> <?php echo _('Enable announcement email'); ?></label></div>
    <p class="help-block"><i class="fa fa-info-circle" aria-hidden="true"></i> <?php echo _('Disabled by default. Save and Apply Config before selecting recipients. Saving this list does not send email. “Accepted by PBX mail service” does not confirm inbox delivery or that a person read the message.'); ?></p>
</div>
<?php if (!empty($slsRecipientDirectoryManaged)) { ?><p class="help-block"><i class="fa fa-address-book-o" aria-hidden="true"></i> <?php echo _('Manage saved recipients in'); ?> <a href="config.php?display=slsmassnotifyserver_locations#recipients"><?php echo _('Locations and Audiences → Recipients'); ?></a>.</p><?php } ?>
<div data-sls-recipient-fields <?php echo !empty($slsRecipientDirectoryManaged) ? 'hidden' : ''; ?>>
    <div class="sls-email-editor-list" data-email-rows></div>
    <p class="text-muted" data-email-empty><?php echo _('No saved email recipients. Add a recipient to configure this channel.'); ?></p>
    <p class="sls-email-error" role="alert" data-email-error></p>
    <div class="sls-email-editor-footer"><button class="btn btn-default" type="button" data-email-add><i class="fa fa-plus" aria-hidden="true"></i> <?php echo _('Add recipient'); ?></button><span class="text-muted" data-email-count aria-live="polite"></span></div>
    <noscript><p class="alert alert-warning"><?php echo _('Enable JavaScript and reload to edit or save announcement email recipients.'); ?></p></noscript>
    <template data-email-template><div class="sls-email-editor-row" data-email-row>
        <input type="hidden" data-email-field="id">
        <label class="sls-email-row-enabled"><input type="checkbox" data-email-field="enabled" checked> <?php echo _('Enabled'); ?></label>
        <label class="sls-email-row-name"><?php echo _('Recipient name'); ?><input class="form-control input-sm" data-email-field="name" maxlength="240" required placeholder="<?php echo $emailEscape(_('Front office')); ?>"></label>
        <label class="sls-email-row-address"><?php echo _('Email address'); ?><input class="form-control input-sm" data-email-field="address" type="email" maxlength="254" required placeholder="office@example.com" autocomplete="off"></label>
        <button class="btn btn-default sls-email-remove" type="button" data-email-remove aria-label="<?php echo $emailEscape(_('Remove recipient')); ?>" title="<?php echo $emailEscape(_('Remove recipient')); ?>"><i class="fa fa-trash-o" aria-hidden="true"></i></button>
    </div></template>
</div>
</section>
<script>
(function(){'use strict';
    var root=document.getElementById('sls-announcement-email');if(!root)return;
    var rows=root.querySelector('[data-email-rows]'),template=root.querySelector('[data-email-template]'),add=root.querySelector('[data-email-add]'),error=root.querySelector('[data-email-error]'),hidden=root.querySelector('#sls-announcement-email-json'),complete=root.querySelector('#sls-announcement-email-complete');
    var seed=<?php echo json_encode($emailRows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR); ?>;
    var messages={limit:<?php echo json_encode(_('At most 50 email recipients can be saved.')); ?>,name:<?php echo json_encode(_('Enter a recipient name without control characters, at most 240 UTF-8 bytes.')); ?>,address:<?php echo json_encode(_('Enter one email address without spaces or a display name.')); ?>,duplicate:<?php echo json_encode(_('This email address is already in the list.')); ?>,size:<?php echo json_encode(_('The email recipient editor exceeds its size limit.')); ?>,count:<?php echo json_encode(_('saved recipients')); ?>};
    function bytes(value){return new Blob([value]).size;}
    function sync(){complete.value='0';hidden.value='';var data=[],seen={},problem='';var list=rows.querySelectorAll('[data-email-row]');
        list.forEach(function(row){var n=row.querySelector('[data-email-field="name"]'),a=row.querySelector('[data-email-field="address"]');n.setCustomValidity('');a.setCustomValidity('');
            if(!n.value.trim()||bytes(n.value)>240||/[\x00-\x1f\x7f]/.test(n.value))n.setCustomValidity(messages.name);
            if(!a.value||a.value!==a.value.trim()||/[^\x21-\x7e]/.test(a.value)||!a.checkValidity())a.setCustomValidity(messages.address);
            var key=a.value.toLowerCase();if(seen[key])a.setCustomValidity(messages.duplicate);seen[key]=true;
            if(!n.checkValidity()||!a.checkValidity())problem=problem||n.validationMessage||a.validationMessage;
            data.push({id:row.querySelector('[data-email-field="id"]').value,name:n.value,address:a.value,enabled:row.querySelector('[data-email-field="enabled"]').checked?'1':'0'});
        });
        if(data.length>50)problem=messages.limit;
        var json=JSON.stringify(data);if(bytes(json)>65536)problem=messages.size;
        error.textContent=problem;add.disabled=data.length>=50;root.querySelector('[data-email-empty]').hidden=data.length!==0;root.querySelector('[data-email-count]').textContent=data.length+' / 50 '+messages.count;
        if(!problem){hidden.value=json;complete.value='1';}return !problem;
    }
    function append(row){var node=template.content.firstElementChild.cloneNode(true);['id','name','address'].forEach(function(field){node.querySelector('[data-email-field="'+field+'"]').value=typeof row[field]==='string'?row[field]:'';});node.querySelector('[data-email-field="enabled"]').checked=String(row.enabled===undefined?'1':row.enabled)==='1';rows.appendChild(node);}
    if(!Array.isArray(seed)||seed.length>50){error.textContent=messages.limit;add.disabled=true;return;}seed.forEach(append);sync();
    add.addEventListener('click',function(){if(rows.children.length>=50)return;append({});sync();rows.lastElementChild.querySelector('[data-email-field="name"]').focus();});
    root.addEventListener('input',sync);root.addEventListener('change',sync);
    rows.addEventListener('click',function(event){var button=event.target.closest('[data-email-remove]');if(button){button.closest('[data-email-row]').remove();sync();}});
    var form=root.closest('form');if(form)form.addEventListener('submit',function(event){if(!sync()){event.preventDefault();form.reportValidity();}});
})();
</script>
