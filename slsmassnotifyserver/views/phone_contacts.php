<?php
$contactsEscape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$contactSeed=['voice'=>$settings['outbound_voice']['recipients']??[], 'sms'=>$settings['announcement_sms']['recipients']??[],
    'blocked'=>$announcement_sms_usage['blocked_recipients']??[]];
?>
<style>
.sls-phone-contacts{margin:18px 0;padding:18px;border:1px solid #dfe5ec;border-radius:8px;background:#fbfcfe}
.sls-phone-contacts h3{margin:0 0 10px}.sls-phone-contacts .contact-row{border:1px solid #dfe5ec;border-radius:7px;background:white;padding:14px;margin:12px 0}
.sls-phone-contacts .contact-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px}
.sls-phone-contacts .contact-channels{display:flex;align-items:center;gap:20px;flex-wrap:wrap;margin-top:12px}
.sls-phone-contacts .contact-channels label{display:flex;gap:7px;align-items:center;margin:0;font-weight:600}
.sls-phone-contacts .contact-channels button{margin-left:auto}.sls-phone-contacts .contact-consent{margin-top:14px;padding-top:12px;border-top:1px solid #e8edf2}
.sls-phone-contacts .contact-consent label{display:block;margin-bottom:8px}.sls-phone-contacts label .form-control{margin-top:6px}
.sls-phone-contacts .contact-footer{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.sls-phone-contacts [hidden]{display:none!important}
@media(max-width:650px){.sls-phone-contacts .contact-grid{grid-template-columns:1fr}.sls-phone-contacts{padding:14px}}
</style>
<section id="sls-phone-contacts" class="sls-phone-contacts" aria-labelledby="sls-phone-contacts-title">
    <h3 id="sls-phone-contacts-title"><i class="fa fa-phone text-primary" aria-hidden="true"></i> <?php echo _('Phone and SMS contacts'); ?> <?php include __DIR__.'/labs.php'; ?></h3>
    <p class="help-block"><?php echo _('Save one contact per number. Enable Calls, SMS or both, then select the channels when composing an announcement. SMS requires recorded consent. Provider credentials and routing stay in General Settings.'); ?></p>
    <?php if (isset($announcement_sms_usage['available']) && !$announcement_sms_usage['available']) { ?><p class="alert alert-warning"><?php echo _('SMS opt-out storage could not be read. Sending remains blocked until its permissions and free space are repaired. Preserve the SMS ledger and initialized marker.'); ?></p><?php } ?>
    <input type="hidden" name="outbound_voice_complete" value="0"><input type="hidden" name="outbound_voice_recipients_json" value="">
    <input type="hidden" name="announcement_sms_complete" value="0"><input type="hidden" name="announcement_sms_recipients_json" value="">
    <div data-contact-rows></div><p data-contact-empty class="text-muted"><?php echo _('No phone or SMS contacts have been saved.'); ?></p>
    <p data-contact-error class="text-danger" role="alert"></p>
    <div class="contact-footer"><button type="button" class="btn btn-default" data-contact-add><i class="fa fa-plus" aria-hidden="true"></i> <?php echo _('Add phone contact'); ?></button><span data-contact-count class="text-muted" aria-live="polite"></span></div>
    <noscript><p class="alert alert-warning"><?php echo _('Enable JavaScript and reload before editing contacts.'); ?></p></noscript>
    <template data-contact-template><div class="contact-row" data-contact-row>
        <div class="contact-grid"><label><?php echo _('Name'); ?><input class="form-control" data-contact-field="name" maxlength="240" required></label><label><?php echo _('Phone number'); ?><input class="form-control" data-contact-field="number" type="tel" maxlength="16" pattern="\+[1-9][0-9]{1,14}" placeholder="+15551234567" required></label></div>
        <div class="contact-channels"><label><input type="checkbox" data-contact-field="voice_enabled"> <i class="fa fa-phone" aria-hidden="true"></i> <?php echo _('Calls'); ?></label><label><input type="checkbox" data-contact-field="sms_enabled"> <i class="fa fa-commenting" aria-hidden="true"></i> <?php echo _('SMS'); ?></label><button type="button" class="btn btn-default btn-sm" data-contact-remove aria-label="<?php echo $contactsEscape(_('Remove contact')); ?>"><i class="fa fa-trash-o" aria-hidden="true"></i> <?php echo _('Remove'); ?></button></div>
        <div class="contact-consent" data-contact-consent hidden><label><input type="checkbox" data-contact-field="consent"> <?php echo _('Agreed to receive SMS alerts'); ?></label><label><?php echo _('SMS consent note'); ?><input class="form-control" data-contact-field="consent_note" maxlength="160" placeholder="<?php echo $contactsEscape(_('When and how the recipient agreed')); ?>"></label><label><input type="checkbox" data-contact-field="renew_consent"> <?php echo _('Record renewed consent (update the note)'); ?></label><p data-contact-blocked class="text-warning" hidden><i class="fa fa-ban" aria-hidden="true"></i> <?php echo _('SMS opted out. Sending remains blocked until renewed consent is recorded; the provider must also accept the opt-in.'); ?></p></div>
    </div></template>
</section>
<script type="application/json" id="sls-phone-contact-data"><?php echo json_encode($contactSeed,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR); ?></script>
<script><?php readfile(__DIR__.'/phone_contacts.js'); ?></script>
