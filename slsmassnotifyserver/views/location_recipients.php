<?php
require_once dirname(__DIR__) . '/api/sls-mass-notify/sms/Config.php';
require_once dirname(__DIR__) . '/api/sls-mass-notify/sms/Service.php';
$settings = is_array($settings ?? null) ? $settings : [];
?>
<section id="sls-location-recipient-panel" role="tabpanel" aria-labelledby="sls-directory-tab-recipients" hidden>
    <div class="sls-location-card-heading"><div><h2><i class="fa fa-address-book-o" aria-hidden="true"></i> <?php echo _('Saved recipients'); ?></h2><p class="help-block"><?php echo _('Manage contact details here, then assign recipients to locations or collect them in saved audiences.'); ?></p></div><a class="btn btn-default btn-sm" href="config.php?display=slsmassnotifyserver_other#settings-channels"><i class="fa fa-cog" aria-hidden="true"></i> <?php echo _('Delivery providers'); ?></a></div>
    <form id="sls-location-recipients-form">
        <p class="help-block"><?php echo _('Provider credentials, route choices and sending limits remain in General Settings. Desktop credentials are in its Desktop clients category; extensions are managed by FreePBX.'); ?></p>
        <?php $slsRecipientEditorOnly = true; $slsRecipientDirectoryManaged = false; ?>
        <?php include __DIR__ . '/phone_contacts.php'; ?>
        <?php include __DIR__ . '/announcement_email.php'; ?>
        <footer class="sls-location-card-footer"><button type="submit" class="btn btn-primary"><i class="fa fa-save" aria-hidden="true"></i> <?php echo _('Save recipients'); ?></button><p class="help-block"><?php echo _('Apply Config after saving. No notifications are sent by this form.'); ?></p></footer>
    </form>
</section>
