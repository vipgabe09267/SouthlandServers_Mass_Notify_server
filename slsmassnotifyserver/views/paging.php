<?php
$paging = is_array($paging ?? null) ? $paging : [];
foreach (($paging['groups'] ?? []) as $index => $group) {
    $paging['groups'][$index]['has_pin'] = !empty($group['has_pin']) || !empty($group['pin_hash']);
    unset($paging['groups'][$index]['pin_hash'], $paging['groups'][$index]['pin']);
}
$extensions = array_values((array)($available_extensions ?? []));
$result = is_array($save_result ?? null) ? $save_result : null;
$escape = static function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$help = static function (string $id, string $text) use ($escape): void {
    echo '<button type="button" class="sls-paging-help" aria-label="' . $escape(_('Help')) . '" aria-describedby="' . $escape($id) . '"><i class="fa fa-question-circle" aria-hidden="true"></i><span class="sls-paging-help-text" id="' . $escape($id) . '" role="tooltip">' . $escape($text) . '</span></button>';
};
?>
<style>
#sls-live-paging {padding-bottom:24px;color:#1f2937}
#sls-live-paging [hidden] {display:none!important}
#sls-live-paging .sls-paging-heading {display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}
#sls-live-paging .sls-paging-heading h1 {margin:0 0 7px;font-size:30px;font-weight:700;line-height:1.2}
#sls-live-paging p {line-height:1.5}
#sls-live-paging .sls-paging-heading p {margin:0}
#sls-live-paging .sls-paging-badge {display:inline-flex;align-items:center;gap:7px;padding:7px 11px;border:1px solid #dfe5ec;border-radius:999px;background:#f8fafc;color:#475569;font-size:12px;font-weight:600;white-space:nowrap}
#sls-live-paging .sls-paging-enable {display:flex;align-items:center;flex-wrap:wrap;gap:10px 24px;padding:15px 18px;margin-bottom:20px;border:1px solid #dfe5ec;border-radius:8px;background:#f8fafc}
#sls-live-paging .sls-paging-enable label {display:flex;align-items:center;gap:9px;margin:0;font-weight:600}
#sls-live-paging .sls-paging-enable .text-muted {font-size:12px}
#sls-live-paging input[type=checkbox] {position:static;margin:0;width:16px;height:16px;flex:0 0 16px}
#sls-live-paging .sls-paging-card {margin-bottom:20px;border:1px solid #dfe5ec;border-radius:9px;background:#fff;box-shadow:0 2px 8px rgba(15,23,42,.04)}
#sls-live-paging .sls-paging-card-title {display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding:13px 18px;border-bottom:1px solid #e8edf2;border-radius:9px 9px 0 0;background:#f8fafc}
#sls-live-paging .sls-paging-card-title h2 {margin:0;font-size:17px;font-weight:700;color:#334155}
#sls-live-paging .sls-paging-card-title .fa {margin-right:6px}
#sls-live-paging .sls-paging-card-body {padding:18px}
#sls-live-paging .sls-paging-grid {display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:20px 26px}
#sls-live-paging .form-group {min-width:0;margin-bottom:0}
#sls-live-paging .sls-paging-label {display:flex;gap:7px;align-items:baseline;margin-bottom:7px}
#sls-live-paging .sls-paging-label label {margin:0;font-size:13px;font-weight:600}
#sls-live-paging .form-control {width:100%;min-width:0;box-sizing:border-box;font-size:14px}
#sls-live-paging input.form-control,#sls-live-paging select.form-control {height:38px}
#sls-live-paging .sls-paging-short {max-width:260px}
#sls-live-paging .sls-paging-unit {display:flex;align-items:center;gap:10px}
#sls-live-paging .sls-paging-unit input {max-width:170px}
#sls-live-paging .help-block {display:block;font-size:12px;line-height:1.5;margin:7px 0 0;color:#64748b}
#sls-live-paging textarea.form-control {resize:vertical;min-height:100px;line-height:1.5}
#sls-live-paging .sls-paging-help {position:relative;border:0;background:transparent;color:#337ab7;padding:2px;line-height:1;cursor:help;font-size:16px}
#sls-live-paging :focus-visible {outline:2px solid #337ab7;outline-offset:3px}
#sls-live-paging .sls-paging-help-text {display:none;position:fixed;z-index:2200;top:24px;left:16px;width:280px;max-width:65vw;border:1px solid #aab7c4;border-radius:5px;background:#fff;color:#1f2937;padding:10px 12px;box-shadow:0 3px 12px rgba(15,23,42,.15);font-size:12px;font-weight:400;line-height:1.5;text-align:left;white-space:normal}
#sls-live-paging .sls-paging-help:hover .sls-paging-help-text,#sls-live-paging .sls-paging-help:focus .sls-paging-help-text {display:block}
#sls-live-paging .sls-paging-submit {display:flex;align-items:center;flex-wrap:wrap;gap:12px;padding-top:2px}
#sls-live-paging .sls-paging-submit .text-muted {font-size:12px}
#sls-live-paging .sls-paging-group-grid {display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
#sls-live-paging .sls-paging-group {border:1px solid #dfe5ec;border-left:4px solid #337ab7;border-radius:8px;background:#fff;padding:16px;min-width:0}
#sls-live-paging .sls-paging-group-top {display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:12px}
#sls-live-paging .sls-paging-group h3 {font-size:16px;font-weight:700;margin:0 0 4px;overflow-wrap:anywhere}
#sls-live-paging .sls-paging-group .sls-paging-badge {padding:4px 8px;font-size:11px}
#sls-live-paging .sls-paging-meta {display:flex;flex-wrap:wrap;gap:8px 16px;color:#475569;font-size:12px;line-height:1.6}
#sls-live-paging .sls-paging-meta .fa {margin-right:4px;color:#64748b}
#sls-live-paging .sls-paging-group-footer {display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:14px;padding-top:12px;border-top:1px solid #edf1f5}
#sls-live-paging .sls-paging-empty {padding:30px 20px;border:1px dashed #cbd5e1;border-radius:8px;background:#f8fafc;text-align:center;color:#64748b}
#sls-live-paging .sls-paging-empty>i {display:block;margin-bottom:10px;font-size:30px;color:#94a3b8}
#sls-live-paging .sls-paging-empty strong {display:block;margin-bottom:5px;color:#334155}
#sls-live-paging .sls-paging-legacy {margin-top:18px;padding-top:18px;border-top:1px solid #e8edf2}
#sls-live-paging .sls-paging-legacy h3 {font-size:15px;margin:0 0 7px;font-weight:600}
#sls-live-paging .sls-paging-modal {position:fixed;inset:0;z-index:2100;background:rgba(15,23,42,.5);display:flex;align-items:center;justify-content:center;padding:24px}
#sls-live-paging .sls-paging-dialog {width:960px;max-width:100%;max-height:calc(100vh - 48px);display:flex;flex-direction:column;border:1px solid #dfe5ec;border-radius:10px;background:#fff;box-shadow:0 16px 48px rgba(15,23,42,.25);overflow:hidden}
#sls-live-paging .sls-paging-modal-header {display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:18px 22px;border-bottom:1px solid #e8edf2;background:#f8fafc}
#sls-live-paging .sls-paging-modal-header h2 {font-size:21px;margin:0 0 5px;font-weight:700}
#sls-live-paging .sls-paging-modal-header p {margin:0;color:#64748b;font-size:12px}
#sls-live-paging .sls-paging-close {border:0;background:transparent;color:#64748b;font-size:23px;line-height:1;padding:3px 5px}
#sls-live-paging .sls-paging-modal-body {padding:20px 22px;overflow-y:auto;min-height:0}
#sls-live-paging .sls-paging-modal-footer {display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:14px 22px;border-top:1px solid #e8edf2;background:#f8fafc}
#sls-live-paging .sls-paging-modal-footer p {margin:0;color:#64748b;font-size:12px}
#sls-live-paging .sls-paging-modal-actions {display:flex;gap:8px}
#sls-live-paging .sls-paging-editor-section {margin-top:20px;padding-top:18px;border-top:1px solid #e8edf2}
#sls-live-paging .sls-paging-editor-section h3 {font-size:16px;margin:0 0 12px;font-weight:700}
#sls-live-paging .sls-paging-selector {border:1px solid #dfe5ec;border-radius:7px;overflow:hidden;background:#fff}
#sls-live-paging .sls-paging-selector-toolbar {padding:10px;background:#f8fafc;border-bottom:1px solid #e8edf2}
#sls-live-paging .sls-paging-selection-actions {display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-top:8px;font-size:12px;color:#475569}
#sls-live-paging .sls-paging-selection-buttons {display:flex;gap:10px}
#sls-live-paging .sls-paging-selection-buttons button {border:0;background:transparent;color:#286090;font-size:12px;padding:2px 0;text-decoration:underline}
#sls-live-paging .sls-paging-options {max-height:174px;overflow-y:auto;min-height:48px}
#sls-live-paging .sls-paging-option {display:flex;align-items:center;gap:9px;padding:8px 11px;margin:0;border-bottom:1px solid #edf1f5;cursor:pointer;font-size:12px;font-weight:400;line-height:1.5;overflow-wrap:anywhere}
#sls-live-paging .sls-paging-option:last-child {border-bottom:0}
#sls-live-paging .sls-paging-option:hover,#sls-live-paging .sls-paging-option:focus-within {background:#f1f5f9}
#sls-live-paging .sls-paging-option span {min-width:0}
#sls-live-paging .sls-paging-selector-empty {padding:15px 12px;color:#64748b;font-size:12px}
#sls-live-paging .sls-paging-pin-toggle {display:flex;align-items:center;gap:9px;margin:0 0 12px;font-size:13px}
#sls-live-paging .sls-paging-pin-fields {display:grid;grid-template-columns:110px minmax(130px,1fr) auto;gap:12px;align-items:end;max-width:620px}
#sls-live-paging .sls-paging-pin-fields .btn {height:38px}
#sls-live-paging .sls-paging-pin-note {color:#7c530b;background:#fff8e7;border:1px solid #f0dfb8;border-radius:6px;padding:9px 11px;font-size:12px;line-height:1.5;margin-top:12px}
#sls-live-paging .sls-paging-legacy-notice {padding:12px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:7px;margin-bottom:18px;color:#334155}
#sls-live-paging .sls-paging-legacy-notice p {margin:0 0 9px;font-size:12px}
#sls-live-paging .sls-paging-alert {margin-bottom:16px}
@media(max-width:900px){#sls-live-paging .sls-paging-group-grid{grid-template-columns:1fr}#sls-live-paging .sls-paging-modal{padding:14px}#sls-live-paging .sls-paging-dialog{max-height:calc(100vh - 28px)}}
@media(max-width:650px){#sls-live-paging .sls-paging-grid{grid-template-columns:1fr;gap:18px}#sls-live-paging .sls-paging-heading{display:block}#sls-live-paging .sls-paging-heading>.sls-paging-badge{margin-top:10px}#sls-live-paging .sls-paging-heading h1{font-size:26px}#sls-live-paging .sls-paging-modal{padding:6px}#sls-live-paging .sls-paging-dialog{max-height:calc(100vh - 12px)}#sls-live-paging .sls-paging-modal-header,#sls-live-paging .sls-paging-modal-body,#sls-live-paging .sls-paging-modal-footer{padding:16px}#sls-live-paging .sls-paging-pin-fields{grid-template-columns:90px minmax(0,1fr)}#sls-live-paging .sls-paging-pin-fields>.btn{grid-column:1/-1;justify-self:start}#sls-live-paging .sls-paging-short{max-width:none}}
</style>
<div class="container-fluid" id="sls-live-paging"><div class="display full-border"><div class="fpbx-container">
    <?php echo load_view(__DIR__ . '/hero.php', ['hero_image' => $hero_image ?? '']); ?>
    <div id="sls-paging-page-content">
    <div class="sls-paging-heading">
        <div><h1><i class="fa fa-bullhorn text-primary" aria-hidden="true"></i> <?php echo _('Dial-in Paging'); ?> <?php include __DIR__ . '/labs.php'; ?></h1><p class="text-muted"><?php echo _('Choose a paging number, add a group, then Apply Config. Authorized callers dial the number and choose a group to speak live.'); ?></p></div>
        <span class="sls-paging-badge"><i class="fa fa-users" aria-hidden="true"></i> <span id="sls-paging-group-count" aria-live="polite"></span></span>
    </div>
    <div class="alert <?php echo !empty($active_paging_enabled) ? 'alert-info' : 'alert-warning'; ?>" role="status">
        <i class="fa fa-<?php echo !empty($active_paging_enabled) ? 'phone' : 'pause-circle'; ?>" aria-hidden="true"></i>
        <?php echo !empty($active_paging_enabled) ? $escape(sprintf(_('Active configuration: paging extension %s.'), $active_paging_extension ?? '')) : _('Paging is disabled in the active configuration. Save your groups, enable paging, then use Apply Config before dialing.'); ?>
        <?php if (!empty($has_pending_changes)): ?><strong><?php echo _('Saved changes are waiting for Apply Config.'); ?></strong><?php endif; ?>
    </div>
    <?php if ($result): ?>
    <div class="alert <?php echo !empty($result['success']) ? 'alert-success' : 'alert-danger'; ?>" role="status">
        <?php echo $escape($result['message'] ?? ''); ?>
        <?php foreach ((array)($result['errors'] ?? []) as $error): ?><div><?php echo $escape($error); ?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <noscript><div class="alert alert-warning"><?php echo _('JavaScript is required to edit and save paging groups. Your saved settings have not been changed.'); ?></div></noscript>
    <form method="post" action="config.php?display=slsmassnotifyserver_paging" id="sls-paging-form" autocomplete="off">
        <input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo $escape($csrf_token ?? ''); ?>">
        <input type="hidden" name="slsmassnotifyserver_action" value="save_live_paging">
        <input type="hidden" name="paging_revision" value="<?php echo $escape($paging_revision ?? ''); ?>">
        <input type="hidden" name="sls_paging_form_complete" id="sls-paging-form-complete" value="0">
        <input type="hidden" name="live_paging[groups]" id="sls-paging-groups-json" value="">
        <input type="hidden" name="live_paging[allowed_callers]" id="sls-paging-callers-json" value="<?php echo $escape(json_encode(array_values((array)($paging['allowed_callers'] ?? [])))); ?>">
        <input type="hidden" name="live_paging[enabled]" value="0">
        <div class="sls-paging-enable">
            <label for="sls-paging-enabled"><input type="checkbox" id="sls-paging-enabled" name="live_paging[enabled]" value="1" <?php echo ($paging['enabled'] ?? '0') === '1' ? 'checked' : ''; ?>> <?php echo _('Enable dial-in paging'); ?></label>
            <span class="text-muted"><?php echo _('Submit your settings, then use Apply Config to activate the paging menu.'); ?></span>
        </div>
        <div class="alert alert-danger sls-paging-alert" id="sls-paging-error" role="alert" tabindex="-1" hidden></div>
        <section class="sls-paging-card" aria-labelledby="sls-paging-access-title">
            <div class="sls-paging-card-title"><h2 id="sls-paging-access-title"><i class="fa fa-phone text-primary" aria-hidden="true"></i> <?php echo _('Paging access'); ?></h2></div>
            <div class="sls-paging-card-body sls-paging-grid">
                <div class="form-group">
                    <div class="sls-paging-label"><label for="sls-paging-extension"><?php echo _('Paging extension'); ?></label> <?php $help('sls-paging-extension-tip', _('Choose an unused internal number. SLS checks for conflicts with existing extensions and dialplan routes before activation.')); ?></div>
                    <input class="form-control sls-paging-short" id="sls-paging-extension" name="live_paging[extension]" value="<?php echo $escape($paging['extension'] ?? ''); ?>" inputmode="numeric" pattern="[0-9]{1,20}" maxlength="20" placeholder="<?php echo $escape(_('e.g. 799')); ?>" aria-describedby="sls-paging-extension-help">
                    <span class="help-block" id="sls-paging-extension-help"><?php echo _('Authorized phones dial this number to hear the group menu.'); ?></span>
                </div>
                <div class="form-group">
                    <div class="sls-paging-label"><label for="sls-paging-duration"><?php echo _('Maximum page duration'); ?></label> <?php $help('sls-paging-duration-tip', _('The caller hangs up to end a page. This limit also ends an abandoned call. Choose 30 to 1,800 seconds; the default is 300 seconds (5 minutes).')); ?></div>
                    <div class="sls-paging-unit sls-paging-short"><input class="form-control" type="number" id="sls-paging-duration" name="live_paging[max_duration_seconds]" min="30" max="1800" required value="<?php echo (int)($paging['max_duration_seconds'] ?? 300); ?>" aria-describedby="sls-paging-duration-unit"><span id="sls-paging-duration-unit"><?php echo _('seconds'); ?></span></div>
                    <span class="help-block"><?php echo _('Hang up to finish sooner.'); ?></span>
                </div>
            </div>
        </section>
        <section class="sls-paging-card" aria-labelledby="sls-paging-ivr-title"><div class="sls-paging-card-title"><h2 id="sls-paging-ivr-title"><i class="fa fa-globe text-primary" aria-hidden="true"></i> <?php echo _('External access through an IVR'); ?> <?php include __DIR__ . '/labs.php'; ?></h2></div><div class="sls-paging-card-body">
            <input type="hidden" name="live_paging[external_access]" value="0">
            <label class="sls-paging-pin-toggle" for="sls-paging-external-access"><input id="sls-paging-external-access" type="checkbox" name="live_paging[external_access]" value="1" <?php echo ($paging['external_access'] ?? '0') === '1' ? 'checked' : ''; ?>> <?php echo _('Enable paging from the selected IVRs'); ?></label>
            <p class="help-block" id="sls-paging-ivr-help"><?php echo _('Choose an existing PBX IVR below. Approved callers dial the paging extension while its menu is playing. No inbound phone number is needed here.'); ?></p>
            <div class="sls-paging-selector" id="sls-paging-ivrs" role="group" aria-labelledby="sls-paging-ivr-title" aria-describedby="sls-paging-ivr-help">
                <?php foreach ($available_ivrs ?? [] as $ivr) { ?><label class="sls-paging-option"><input type="checkbox" name="live_paging[external_ivr_ids][]" value="<?php echo $escape($ivr['id']); ?>" <?php echo in_array($ivr['id'], $paging['external_ivr_ids'] ?? [], true) ? 'checked' : ''; ?>><span><?php echo $escape($ivr['name']); ?></span></label><?php } ?>
            </div>
            <p class="help-block" id="sls-paging-external-status" role="status"><?php echo ($paging['external_access'] ?? '0') === '1' ? _('External paging is enabled for the selected IVRs after Apply Config.') : _('External paging is off. Group caller settings alone do not enable an IVR route.'); ?></p>
            <p class="help-block"><?php echo _('Callers in a selected IVR can dial the paging number after Apply Config. Each group also needs approved caller numbers and a saved PIN. Unlisted or withheld callers are rejected; an external caller always enters a PIN.'); ?></p>
            <?php if (empty($available_ivrs)) { ?><p class="text-warning"><?php echo _('No IVRs are available. Create one under Applications → IVR before enabling external paging.'); ?></p><?php } ?>
            <p class="help-block"><?php echo _('External callers share a limit of two active menu sessions and ten failed PIN attempts per minute. Caller ID can be spoofed, so the PIN is always required. Existing IVR and inbound routes are changed only when you select this destination.'); ?></p>
        </div></section>
        <section class="sls-paging-card" aria-labelledby="sls-paging-groups-title">
            <div class="sls-paging-card-title"><h2 id="sls-paging-groups-title"><i class="fa fa-users text-primary" aria-hidden="true"></i> <?php echo _('Paging groups'); ?></h2><button type="button" class="btn btn-primary btn-sm" id="sls-paging-add"><i class="fa fa-plus" aria-hidden="true"></i> <?php echo _('Add paging group'); ?></button></div>
            <div class="sls-paging-card-body">
                <p class="text-muted"><?php echo _('Choose each group’s audio recipients, phone text recipients, authorized callers, and PIN. A phone can belong to more than one group.'); ?></p>
                <div class="sls-paging-group-grid" id="sls-paging-rows"></div>
                <div class="sls-paging-empty" id="sls-paging-empty"><i class="fa fa-bullhorn" aria-hidden="true"></i><strong><?php echo _('Create your first paging group'); ?></strong><span><?php echo _('Add a name, choose the phones, and share its generated PIN with authorized callers.'); ?></span></div>
                <span class="help-block"><?php echo _('The calling phone is excluded from live audio. A busy group is declined so an existing page is not interrupted.'); ?></span>
                <div class="sls-paging-legacy" id="sls-paging-legacy-callers" hidden>
                    <div class="sls-paging-label"><h3 id="sls-paging-legacy-callers-title"><i class="fa fa-link text-primary" aria-hidden="true"></i> <?php echo _('Callers for linked legacy groups'); ?></h3><?php $help('sls-paging-legacy-tip', _('Existing linked groups retain their announcement-group phones and this shared caller list. Use Convert to independent group inside a group editor to give that group its own recipients, text message, and callers.')); ?></div>
                    <p class="help-block"><?php echo _('This shared list applies only to groups marked “Linked announcement group.”'); ?></p>
                    <div id="sls-paging-callers" style="margin-top:10px" aria-labelledby="sls-paging-legacy-callers-title"></div>
                </div>
            </div>
        </section>
        <section class="sls-paging-card" aria-labelledby="sls-paging-prompts-title">
            <div class="sls-paging-card-title"><h2 id="sls-paging-prompts-title"><i class="fa fa-volume-up text-primary" aria-hidden="true"></i> <?php echo _('Spoken prompts'); ?></h2></div>
            <div class="sls-paging-card-body">
                <p class="text-muted"><?php echo _('Prompts use the announcement TTS voice selected in General Settings.'); ?></p>
                <div class="sls-paging-grid">
                    <div class="form-group"><div class="sls-paging-label"><label for="sls-paging-menu"><?php echo _('Menu introduction'); ?></label><?php $help('sls-paging-menu-tip', _('This introduction plays before the menu numbers and group names. You do not need to type those choices into the prompt.')); ?></div><textarea class="form-control" id="sls-paging-menu" name="live_paging[menu_prompt]" rows="3" maxlength="1000" required aria-describedby="sls-paging-menu-help"><?php echo $escape($paging['menu_prompt'] ?? ''); ?></textarea><span class="help-block" id="sls-paging-menu-help"><?php echo _('Group names and menu numbers are spoken automatically.'); ?></span></div>
                    <div class="form-group"><div class="sls-paging-label"><label for="sls-paging-pin-prompt"><?php echo _('PIN prompt'); ?></label><?php $help('sls-paging-pin-prompt-tip', _('This prompt plays when the chosen group requires a PIN. Callers finish with the pound key. Three unsuccessful attempts end the call.')); ?></div><textarea class="form-control" id="sls-paging-pin-prompt" name="live_paging[pin_prompt]" rows="3" maxlength="1000" required aria-describedby="sls-paging-pin-prompt-help"><?php echo $escape($paging['pin_prompt'] ?? ''); ?></textarea><span class="help-block" id="sls-paging-pin-prompt-help"><?php echo _('Remind callers to finish their PIN with the pound (#) key.'); ?></span></div>
                </div>
            </div>
        </section>
        <div class="sls-paging-submit"><button class="btn btn-primary" type="submit" id="sls-paging-submit" disabled><i class="fa fa-check" aria-hidden="true"></i> <?php echo _('Submit'); ?></button><span class="text-muted"><?php echo _('Use Apply Config after submitting to activate your changes.'); ?></span></div>
    </form>
    </div>
    <div class="sls-paging-modal" id="sls-paging-editor" hidden>
        <section class="sls-paging-dialog" role="dialog" aria-modal="true" aria-labelledby="sls-paging-editor-title" aria-describedby="sls-paging-editor-intro">
            <div class="sls-paging-modal-header"><div><h2 id="sls-paging-editor-title"><i class="fa fa-users text-primary" aria-hidden="true"></i> <span></span></h2><p id="sls-paging-editor-intro"><?php echo _('Set the group’s menu choice, recipients, and access.'); ?></p></div><button type="button" class="sls-paging-close" data-paging-close aria-label="<?php echo $escape(_('Close group editor')); ?>"><i class="fa fa-times" aria-hidden="true"></i></button></div>
            <div class="sls-paging-modal-body">
                <div class="alert alert-danger" id="sls-paging-editor-error" role="alert" tabindex="-1" hidden></div>
                <div class="sls-paging-legacy-notice" id="sls-paging-legacy-notice" hidden><strong><?php echo _('Linked announcement group'); ?></strong><p id="sls-paging-legacy-summary"></p><button class="btn btn-default btn-sm" type="button" id="sls-paging-convert"><i class="fa fa-chain-broken" aria-hidden="true"></i> <?php echo _('Convert to independent group'); ?></button><span class="help-block"><?php echo _('Conversion copies the current phone members and shared callers. Future announcement-group edits will no longer change this paging group.'); ?></span></div>
                <div class="sls-paging-grid">
                    <div class="form-group"><div class="sls-paging-label"><label for="sls-paging-group-name"><?php echo _('Group name'); ?></label><?php $help('sls-paging-name-tip', _('Callers hear this name in the spoken menu. Choose a short, recognizable name, such as Front office or Warehouse. Up to 64 bytes.')); ?></div><input class="form-control" id="sls-paging-group-name" maxlength="64" placeholder="<?php echo $escape(_('e.g. Front office')); ?>"></div>
                    <div class="form-group"><div class="sls-paging-label"><label for="sls-paging-menu-number"><?php echo _('Menu number'); ?></label><?php $help('sls-paging-number-tip', _('Give every group a different number from 1 through 10. Callers enter that number to select the group.')); ?></div><select class="form-control sls-paging-short" id="sls-paging-menu-number"><?php for ($n = 1; $n <= 10; $n++): ?><option value="<?php echo $n; ?>"><?php echo $n; ?></option><?php endfor; ?></select></div>
                </div>
                <div id="sls-paging-independent-fields" data-sls-picker="paging">
                    <div class="sls-paging-editor-section"><h3><i class="fa fa-bullhorn text-primary" aria-hidden="true"></i> <?php echo _('Recipients and phone text'); ?></h3><div class="sls-paging-grid">
                        <div><div class="sls-paging-label"><label id="sls-paging-audio-label"><?php echo _('Live audio recipients'); ?></label><?php $help('sls-paging-audio-tip', _('Choose at least one internal phone to hear live audio. The caller’s own phone is excluded during a page. Search by extension number or name. Up to 1,000 recipients.')); ?></div><div id="sls-paging-audio-selector"></div><span class="help-block"><?php echo _('Phones that hear the caller’s voice. At least one required.'); ?></span></div>
                        <div><div class="sls-paging-label"><label id="sls-paging-notify-label"><?php echo _('Phone text recipients'); ?></label><?php $help('sls-paging-notify-tip', _('Optional: send this group’s saved message to selected phones using SIP NOTIFY when paging starts. These phones may differ from the audio recipients. Display support depends on the phone. Up to 1,000 recipients.')); ?></div><div id="sls-paging-notify-selector"></div><span class="help-block"><?php echo _('Optional phones that display the saved message.'); ?></span></div>
                    </div></div>
                    <div class="sls-paging-editor-section">
                        <div class="form-group"><div class="sls-paging-label"><label for="sls-paging-text-message"><?php echo _('Saved phone text message'); ?></label><?php $help('sls-paging-message-tip', _('This message is saved with the group and sent to its phone text recipients when a live page starts. It does not change the live audio or the spoken menu. Up to 1,000 bytes.')); ?></div><textarea class="form-control" id="sls-paging-text-message" rows="5" maxlength="1000" aria-describedby="sls-paging-text-help"></textarea><span class="help-block" id="sls-paging-text-help"><?php echo _('Used for SIP NOTIFY phone displays when this group is paged.'); ?></span></div>
                    </div>
                </div>
                <div class="sls-paging-editor-section">
                    <h3><i class="fa fa-lock text-primary" aria-hidden="true"></i> <?php echo _('Caller access and PIN'); ?></h3>
                        <div style="max-width:620px;margin-bottom:18px"><div class="sls-paging-label"><label id="sls-paging-authorized-label"><?php echo _('Authorized caller extensions'); ?></label><?php $help('sls-paging-authorized-tip', _('Only selected authenticated internal PBX phones may start a page to this group. Incoming trunks and caller-ID matches do not grant access. Select at least one caller, up to 1,000.')); ?></div><div id="sls-paging-authorized-selector"></div><span class="help-block"><?php echo _('Internal phones allowed to start a page to this group.'); ?></span></div>

                    <label class="sls-paging-pin-toggle" for="sls-paging-require-pin"><input type="checkbox" id="sls-paging-require-pin"> <?php echo _('Require a PIN for this group'); ?></label>
                    <div class="sls-paging-pin-fields" id="sls-paging-pin-fields">
                        <div class="form-group"><div class="sls-paging-label"><label for="sls-paging-pin-length"><?php echo _('PIN digits'); ?></label></div><select class="form-control" id="sls-paging-pin-length"><?php for ($n = 4; $n <= 8; $n++): ?><option value="<?php echo $n; ?>"><?php echo $n; ?></option><?php endfor; ?></select></div>
                        <div class="form-group"><div class="sls-paging-label"><label for="sls-paging-pin"><?php echo _('New PIN'); ?></label><?php $help('sls-paging-pin-tip', _('New groups start with a random four-digit PIN. Choose four through eight digits. Leave an existing PIN blank to keep it; saved PINs cannot be displayed. Record a new PIN before submitting.')); ?></div><input class="form-control" id="sls-paging-pin" type="text" inputmode="numeric" pattern="[0-9]{4,8}" maxlength="8" autocomplete="new-password" aria-describedby="sls-paging-pin-note"></div>
                        <button type="button" class="btn btn-default" id="sls-paging-randomize"><i class="fa fa-random" aria-hidden="true"></i> <?php echo _('Randomize PIN'); ?></button>
                    </div>
                    <div class="sls-paging-pin-note" id="sls-paging-pin-note" role="status"></div>
                </div>
                <div class="sls-paging-editor-section"><h3><i class="fa fa-globe text-primary" aria-hidden="true"></i> <?php echo _('External callers'); ?></h3>
                    <p class="help-block"><?php echo _('Select allowed IVRs above. Add calling phone numbers here to allow this group; leave the list empty for internal paging only. External callers always need this group’s PIN.'); ?></p>
                    <div class="form-group" id="sls-paging-external-callers-field" style="margin:14px 0 18px;max-width:620px">
                        <div class="sls-paging-label"><label for="sls-paging-external-callers"><?php echo _('Approved external caller numbers'); ?></label><?php $help('sls-paging-external-callers-tip', _('These are the caller phone numbers allowed to page this group, such as your cell number. They are not the PBX inbound numbers. An empty list allows nobody; each group needs its own approved callers.')); ?></div>
                        <textarea class="form-control" id="sls-paging-external-callers" rows="3" maxlength="4100" spellcheck="false" autocomplete="off" placeholder="+15125550123" aria-describedby="sls-paging-external-callers-help"></textarea>
                        <p class="help-block" id="sls-paging-external-callers-help"><?php echo _('One full number per line, including + and country code; up to 100. Spaces, parentheses and hyphens are accepted. Only callers listed here reach this group’s PIN prompt.'); ?></p>
                    </div>
                </div>
            </div>
            <div class="sls-paging-modal-footer"><p><?php echo _('Save stores this group and the page settings. Use Apply Config to activate them.'); ?></p><div class="sls-paging-modal-actions"><button type="button" class="btn btn-default" data-paging-close><?php echo _('Cancel'); ?></button><button type="button" class="btn btn-primary" id="sls-paging-save-group"><i class="fa fa-save" aria-hidden="true"></i> <?php echo _('Save group'); ?></button></div></div>
        </section>
    </div>
</div></div></div>
<script>
(function ($) {
    'use strict';
    var rows = <?php echo json_encode(array_values((array)($paging['groups'] ?? [])), $jsonFlags); ?>;
    var announcementGroups = <?php echo json_encode(array_values((array)($announcement_groups ?? [])), $jsonFlags); ?>;
    var phones = <?php echo json_encode($extensions, $jsonFlags); ?>;
    var legacyCallers = <?php echo json_encode(array_values((array)($paging['allowed_callers'] ?? [])), $jsonFlags); ?>;
    var labels = <?php echo json_encode([
        'edit' => _('Edit group'), 'remove' => _('Remove group'), 'add' => _('New paging group'), 'menu' => _('Menu'),
        'selected' => _('selected'), 'groups' => _('of 10 groups'), 'linked' => _('Linked announcement group'),
        'audio' => _('audio phones'), 'notify' => _('text phones'), 'callers' => _('authorized callers'),
        'pinOn' => _('PIN required'), 'pinOff' => _('No PIN'), 'pinSaved' => _('PIN saved — leave blank to keep'),
        'externalPin' => _('External access always requires a PIN'), 'externalCallers' => _('approved external callers'),
        'externalList' => _('Add 1–100 unique approved caller numbers with + and country code, one per line (for example +15125550123).'),
        'pinKeep' => _('A PIN is saved. Leave the field blank to keep it, or enter or randomize a replacement.'),
        'pinRecord' => _('Record this new PIN before submitting. Saved PINs cannot be shown again.'),
        'pinNone' => _('Selected authorized callers may page this group without a PIN.'),
        'pinNeeded' => _('Enter a PIN or use Randomize PIN before adding this group.'),
        'pinLength' => _('Enter a PIN matching the selected 4–8 digit length. Generate a new PIN when changing its length.'),
        'pinFailure' => _('Unable to generate a PIN. Try again or enter a PIN.'), 'pinPending' => _('Generating a PIN…'),
        'search' => _('Search extension or name'), 'all' => _('Select visible'), 'clear' => _('Clear selection'),
        'emptyPhones' => _('No internal phone extensions are available.'), 'noMatch' => _('No matching extensions.'),
        'unavailable' => _('unavailable extension'), 'tooMany' => _('Choose no more than 1,000 extensions in each list.'),
        'name' => _('Enter a group name of up to 64 bytes.'), 'number' => _('Choose a unique menu number from 1 through 10.'),
        'targets' => _('Select at least one live audio recipient and one authorized caller.'),
        'message' => _('Enter a saved phone text message of up to 1,000 bytes.'),
        'maximum' => _('You can save up to ten paging groups.'), 'tooLarge' => _('The paging selection is too large to save.'),
        'enabled' => _('Add at least one paging group and enter a paging extension before enabling paging.'),
        'legacy' => _('Audio phones and authorized callers follow the linked announcement group and shared caller list until you convert this group.'),
        'removeConfirm' => _('Remove this paging group? Submit the page to save the removal.'),
        'unsaved' => _('New PIN pending save'), 'defaultMessage' => _('Live page in progress.')
    ], $jsonFlags); ?>;
    var editor = $('#sls-paging-editor'), draft = null, editing = -1, opener = null, pinRequest = null;
    var selectors = {}, selectorSerial = 0;
    function copy(value) { return JSON.parse(JSON.stringify(value)); }
    function standalone(row) { return Object.prototype.hasOwnProperty.call(row, 'name'); }
    function announcement(row) { return announcementGroups.filter(function (group) { return String(group.id) === String(row.group_id); })[0] || {}; }
    function groupName(row) { return standalone(row) ? row.name : (announcement(row).name || row.group_id); }
    function byteLength(value) { return new Blob([value]).size; }
    function error(message, inEditor) {
        var target = $(inEditor ? '#sls-paging-editor-error' : '#sls-paging-error');
        target.text(message || '').prop('hidden', !message);
        if (message) { target[0].focus(); target[0].scrollIntoView({block:'nearest'}); }
    }
    function icon(name) { return $('<i>', {'class':'fa ' + name, 'aria-hidden':'true'}); }
    function createSelector(target, titleId, chosen) {
        var selected = new Set((chosen || []).map(String)), id = 'sls-paging-selector-' + (++selectorSerial);
        var map = {}, options = [];
        phones.forEach(function (phone) { var value = String(phone.extension); if (!map[value]) { map[value] = true; options.push({value:value, label:value + (phone.name ? ' · ' + phone.name : '')}); } });
        selected.forEach(function (value) { if (!map[value]) { options.push({value:value, label:value + ' · ' + labels.unavailable}); } });
        var wrap = $('<div>', {'class':'sls-paging-selector', role:'group', 'aria-labelledby':titleId});
        var toolbar = $('<div>', {'class':'sls-paging-selector-toolbar'});
        var search = $('<input>', {type:'search', 'class':'form-control', placeholder:labels.search, 'aria-label':labels.search, 'aria-describedby':titleId});
        var count = $('<span>', {'aria-live':'polite'}), all = $('<button>', {type:'button'}).text(labels.all), clear = $('<button>', {type:'button'}).text(labels.clear);
        var list = $('<div>', {'class':'sls-paging-options', id:id});
        var empty = $('<div>', {'class':'sls-paging-selector-empty'});
        var elements = [];
        function update() { count.text(selected.size + ' ' + labels.selected); clear.prop('disabled', !selected.size); }
        options.forEach(function (option, index) {
            var input = $('<input>', {type:'checkbox', id:id + '-' + index, value:option.value}).prop('checked', selected.has(option.value));
            var label = $('<label>', {'class':'sls-paging-option', 'for':id + '-' + index}).append(input, $('<span>').text(option.label));
            input.on('change', function () {
                if (this.checked && selected.size >= 1000) { this.checked = false; error(labels.tooMany, !editor.prop('hidden')); return; }
                if (this.checked) { selected.add(option.value); } else { selected.delete(option.value); } update();
            });
            list.append(label); elements.push({value:option.value, search:option.label.toLowerCase(), input:input, node:label});
        });
        function filter() {
            var query = search.val().toLowerCase().trim(), visible = 0;
            elements.forEach(function (item) { var show = item.search.indexOf(query) !== -1; item.node.prop('hidden', !show); if (show) { visible++; } });
            empty.text(options.length ? labels.noMatch : labels.emptyPhones).prop('hidden', visible > 0);
            all.prop('disabled', !visible);
        }
        search.on('input', filter);
        all.on('click', function () {
            var matches = elements.filter(function (item) { return !item.node.prop('hidden'); });
            var additions = matches.filter(function (item) { return !selected.has(item.value); }).length;
            if (selected.size + additions > 1000) { error(labels.tooMany, !editor.prop('hidden')); return; }
            matches.forEach(function (item) { selected.add(item.value); item.input.prop('checked', true); }); update();
        });
        clear.on('click', function () { selected.clear(); elements.forEach(function (item) { item.input.prop('checked', false); }); update(); });
        toolbar.append(search, $('<div>', {'class':'sls-paging-selection-actions'}).append(count, $('<div>', {'class':'sls-paging-selection-buttons'}).append(all, clear)));
        wrap.append(toolbar, list.append(empty)); $(target).empty().append(wrap); filter(); update();
        return {values:function () { return Array.from(selected); }};
    }
    var shared = createSelector('#sls-paging-callers', 'sls-paging-legacy-callers-title', legacyCallers);
    function refresh() {
        var list = $('#sls-paging-rows').empty(), legacyCount = 0;
        rows.forEach(function (row, index) {
            var independent = standalone(row), group = announcement(row);
            if (!independent) { legacyCount++; }
            var card = $('<article>', {'class':'sls-paging-group'});
            var identity = $('<div>').append($('<h3>').text(groupName(row)));
            if (!independent) { identity.append($('<span>', {'class':'help-block'}).append(icon('fa-link'), ' ' + labels.linked)); }
            card.append($('<div>', {'class':'sls-paging-group-top'}).append(identity, $('<span>', {'class':'sls-paging-badge'}).text(labels.menu + ' ' + row.menu_number)));
            var stats = $('<div>', {'class':'sls-paging-meta'});
            stats.append($('<span>').append(icon('fa-volume-up'), ' ' + (independent ? row.extensions : group.extensions || []).length + ' ' + labels.audio));
            if (independent) { stats.append($('<span>').append(icon('fa-comment-o'), ' ' + row.notify_extensions.length + ' ' + labels.notify)); }
            stats.append($('<span>').append(icon('fa-phone'), ' ' + (independent ? row.allowed_callers : shared.values()).length + ' ' + labels.callers));
            if (row.allow_external === '1') { stats.append($('<span>').append(icon('fa-globe'), ' ' + (row.external_callers || []).length + ' ' + labels.externalCallers)); }
            card.append(stats);
            var pinStatus = row.require_pin !== '0' ? labels.pinOn : labels.pinOff;
            if (row.pin) { pinStatus += ' · ' + labels.unsaved; }
            if (row.allow_external === '1') { pinStatus += ' · ' + labels.externalPin; }
            card.append($('<div>', {'class':'help-block'}).append(icon(row.require_pin !== '0' ? 'fa-lock' : 'fa-unlock-alt'), ' ' + pinStatus));
            var edit = $('<button>', {type:'button', 'class':'btn btn-default btn-sm', 'aria-label':labels.edit + ': ' + groupName(row)}).append(icon('fa-pencil'), ' ' + labels.edit).on('click', function () { openEditor(index, this); });
            var remove = $('<button>', {type:'button', 'class':'btn btn-link btn-sm text-danger', 'aria-label':labels.remove + ': ' + groupName(row)}).append(icon('fa-trash-o'), ' ' + labels.remove).on('click', function () {
                if (window.confirm(labels.removeConfirm)) { rows.splice(index, 1); refresh(); $('#sls-paging-add').trigger('focus'); }
            });
            card.append($('<div>', {'class':'sls-paging-group-footer'}).append(edit, remove)); list.append(card);
        });
        $('#sls-paging-group-count').text(rows.length + ' ' + labels.groups);
        $('#sls-paging-add').prop('disabled', rows.length >= 10);
        $('#sls-paging-empty').prop('hidden', rows.length !== 0);
        $('#sls-paging-legacy-callers').prop('hidden', legacyCount === 0);
        $('#sls-paging-form-complete').val('0');
    }
    function renderIndependent() {
        var independent = standalone(draft);
        $('#sls-paging-independent-fields').prop('hidden', !independent);
        $('#sls-paging-legacy-notice').prop('hidden', independent);
        $('#sls-paging-group-name').val(groupName(draft)).prop('disabled', !independent);
        $('#sls-paging-legacy-summary').text(labels.legacy);
        if (independent) {
            selectors.audio = createSelector('#sls-paging-audio-selector', 'sls-paging-audio-label', draft.extensions);
            selectors.notify = createSelector('#sls-paging-notify-selector', 'sls-paging-notify-label', draft.notify_extensions);
            selectors.callers = createSelector('#sls-paging-authorized-selector', 'sls-paging-authorized-label', draft.allowed_callers);
            $('#sls-paging-text-message').val(draft.text_message);
        }
    }
    function pinStatus() {
        var required = $('#sls-paging-require-pin').prop('checked') || $('#sls-paging-external-callers').val().trim() !== '', entered = $('#sls-paging-pin').val();
        $('#sls-paging-pin-fields').prop('hidden', !required);
        $('#sls-paging-pin-note').text(!required ? labels.pinNone : (entered ? labels.pinRecord : (draft.has_pin ? labels.pinKeep : labels.pinNeeded)));
    }
    function randomize() {
        if (!draft) { return; }
        if (pinRequest) { pinRequest.abort(); }
        error('', true); var activeDraft = draft;
        $('#sls-paging-randomize,#sls-paging-save-group').prop('disabled', true);
        $('#sls-paging-pin-note').text(labels.pinPending);
        pinRequest = $.ajax({url:'config.php?display=slsmassnotifyserver_paging', type:'POST', dataType:'json', cache:false,
            data:{slsmassnotifyserver_action:'generate_paging_pin', slsmassnotifyserver_csrf:$('#sls-paging-form input[name="slsmassnotifyserver_csrf"]').val(), pin_length:$('#sls-paging-pin-length').val()}})
            .done(function (result) {
                if (draft !== activeDraft) { return; }
                if (!result || !result.success || !/^[0-9]{4,8}$/.test(result.pin) || result.pin.length !== Number($('#sls-paging-pin-length').val())) { error((result && result.message) || labels.pinFailure, true); return; }
                $('#sls-paging-pin').val(result.pin);
            }).fail(function (_, status) { if (status !== 'abort' && draft === activeDraft) { error(labels.pinFailure, true); } })
            .always(function () { if (draft === activeDraft) { pinRequest = null; $('#sls-paging-randomize,#sls-paging-save-group').prop('disabled', false); pinStatus(); } });
    }
    function openEditor(index, source) {
        if (index < 0 && rows.length >= 10) { error(labels.maximum); return; }
        editing = index; opener = source; error('', true);
        var number = 1; while (rows.some(function (row) { return Number(row.menu_number) === number; }) && number < 10) { number++; }
        draft = index < 0 ? {group_id:'', name:'', menu_number:number, extensions:[], notify_extensions:[], allowed_callers:[], text_message:labels.defaultMessage, require_pin:'1', pin_length:4, has_pin:false, pin:''} : copy(rows[index]);
        $('#sls-paging-editor-title span').text(index < 0 ? labels.add : labels.edit);
        $('#sls-paging-menu-number').val(draft.menu_number);
        $('#sls-paging-require-pin').prop('checked', draft.require_pin !== '0');
        $('#sls-paging-external-callers').val((draft.external_callers || []).join('\n'));
        $('#sls-paging-pin-length').val(draft.pin_length || 4);
        $('#sls-paging-pin').val(draft.pin || '').attr('placeholder', draft.has_pin ? labels.pinSaved : '');
        $('#sls-paging-randomize,#sls-paging-save-group').prop('disabled', false);
        renderIndependent(); pinStatus(); editor.prop('hidden', false);
        $('#sls-paging-page-content').attr('inert', '').attr('aria-hidden', 'true');
        editor.find('.sls-paging-modal-body').scrollTop(0);
        (standalone(draft) ? $('#sls-paging-group-name') : $('#sls-paging-menu-number')).trigger('focus');
        if (index < 0) { randomize(); }
    }
    function closeEditor() {
        var previousOpener = opener; draft = null;
        if (pinRequest) { pinRequest.abort(); pinRequest = null; }
        editor.prop('hidden', true); $('#sls-paging-page-content').removeAttr('inert aria-hidden');
        $('#sls-paging-pin').val(''); if (previousOpener) { previousOpener.focus(); }
    }
    function rowError(row, index) {
        if (!Number.isInteger(Number(row.menu_number)) || Number(row.menu_number) < 1 || Number(row.menu_number) > 10 || rows.some(function (other, otherIndex) { return otherIndex !== index && Number(other.menu_number) === Number(row.menu_number); })) { return labels.number; }
        if (standalone(row)) {
            if (!row.name.trim() || byteLength(row.name) > 64) { return labels.name; }
            if (!row.extensions.length || !row.allowed_callers.length) { return labels.targets; }
            if ([row.extensions, row.notify_extensions, row.allowed_callers].some(function (values) { return values.length > 1000; })) { return labels.tooMany; }
            if (!row.text_message.trim() || byteLength(row.text_message) > 1000) { return labels.message; }
        }
        if (row.pin && (!/^[0-9]{4,8}$/.test(row.pin) || row.pin.length !== Number(row.pin_length))) { return labels.pinLength; }
        if (!row.pin && row.has_pin && Number(row.pin_length) !== Number(row._saved_pin_length || row.pin_length)) { return labels.pinLength; }
        if ((row.require_pin !== '0' || row.allow_external === '1') && !row.pin && !row.has_pin) { return labels.pinNeeded; }
        var externalNumbers = row.external_callers || [];
        if (!Array.isArray(externalNumbers) || externalNumbers.length > 100 || (row.allow_external === '1' && !externalNumbers.length)
            || externalNumbers.some(function (number) { return !/^\+[1-9][0-9]{6,14}$/.test(number); })
            || new Set(externalNumbers).size !== externalNumbers.length) { return labels.externalList; }
        return '';
    }
    $('.sls-paging-help').on('mouseenter focus', function () {
        var button = this.getBoundingClientRect(), tip = $(this).find('.sls-paging-help-text');
        tip.css({left:Math.max(16, Math.min(button.left - 12, window.innerWidth - tip.outerWidth() - 16)), top:button.bottom + 8});
        var bounds = tip[0].getBoundingClientRect();
        if (bounds.bottom > window.innerHeight - 12) { tip.css('top', Math.max(12, button.top - bounds.height - 8)); }
    });
    $('#sls-paging-add').on('click', function () { openEditor(-1, this); });
    $('[data-paging-close]').on('click', closeEditor);
    $('#sls-paging-randomize').on('click', randomize);
    $('#sls-paging-pin-length').on('change', randomize);
    $('#sls-paging-require-pin').on('change', pinStatus);
    $('#sls-paging-external-callers').on('input', pinStatus);
    $('#sls-paging-pin').on('input', pinStatus);
    $('#sls-paging-convert').on('click', function () {
        var group = announcement(draft);
        draft.name = String(group.name || draft.group_id); draft.extensions = copy(group.extensions || []);
        draft.notify_extensions = []; draft.allowed_callers = shared.values(); draft.text_message = labels.defaultMessage;
        renderIndependent(); $('#sls-paging-group-name').trigger('focus');
    });
    $('#sls-paging-save-group').on('click', function () {
        if (!draft || pinRequest) { return; }
        draft.menu_number = Number($('#sls-paging-menu-number').val());
        draft.require_pin = $('#sls-paging-require-pin').prop('checked') ? '1' : '0';
        draft.external_callers = $('#sls-paging-external-callers').val().split(/\r?\n/).map(function (number) { return number.trim().replace(/[ ().-]/g, ''); }).filter(Boolean);
        draft.allow_external = draft.external_callers.length ? '1' : '0';
        draft._saved_pin_length = draft._saved_pin_length || draft.pin_length;
        draft.pin_length = Number($('#sls-paging-pin-length').val()); draft.pin = $('#sls-paging-pin').val().trim();
        if (standalone(draft)) {
            draft.name = $('#sls-paging-group-name').val().trim(); draft.text_message = $('#sls-paging-text-message').val().trim();
            draft.extensions = selectors.audio.values(); draft.notify_extensions = selectors.notify.values(); draft.allowed_callers = selectors.callers.values();
        }
        var problem = rowError(draft, editing); if (problem) { error(problem, true); return; }
        if (editing < 0) { rows.push(draft); } else { rows[editing] = draft; }
        closeEditor(); refresh(); error('');
        $('#sls-paging-form').trigger('submit');
    });
    editor.on('keydown', function (event) {
        if (event.key === 'Escape') { event.preventDefault(); closeEditor(); return; }
        if (event.key !== 'Tab') { return; }
        var focusable = editor.find('button,input,select,textarea,[tabindex="0"]').filter(':visible:not(:disabled)');
        var first = focusable[0], last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    $('#sls-paging-external-access, #sls-paging-ivrs input').on('change', function () {
        var enabled = $('#sls-paging-external-access').prop('checked');
        var selected = $('#sls-paging-ivrs input:checked').length;
        $('#sls-paging-external-status').text(enabled ? (selected ? 'External paging will be available through ' + selected + ' selected IVR(s) after Apply Config. A group also needs approved caller numbers and a PIN.' : 'Select at least one IVR before enabling external paging.') : 'External paging is off. Group caller settings alone do not enable an IVR route.');
    });
    $('#sls-paging-form').on('submit', function (event) {
        event.preventDefault();
        $('#sls-paging-form-complete').val('0'); error('');
        try {
            if (!editor.prop('hidden')) { throw new Error(labels.edit); }
            if (rows.length > 10) { throw new Error(labels.maximum); }
            if ($('#sls-paging-enabled').prop('checked') && (!rows.length || !$('#sls-paging-extension').val())) { throw new Error(labels.enabled); }
            if ($('#sls-paging-external-access').prop('checked') && !$('#sls-paging-ivrs input:checked').length) { throw new Error('Select at least one existing IVR for external paging.'); }
            var payload = rows.map(function (row, index) {
                var problem = rowError(row, index); if (problem) { throw new Error(groupName(row) + ': ' + problem); }
                var output = {group_id:row.group_id, menu_number:row.menu_number, require_pin:row.require_pin, allow_external:row.allow_external || '0', external_callers:row.external_callers || [], pin_length:row.pin_length, pin:row.pin || ''};
                if (standalone(row)) { ['name','extensions','notify_extensions','allowed_callers','text_message'].forEach(function (key) { output[key] = row[key]; }); }
                return output;
            });
            var encoded = JSON.stringify(payload), callers = JSON.stringify(shared.values());
            if (byteLength(encoded) > 800000 || byteLength(callers) > 25000) { throw new Error(labels.tooLarge); }
            $('#sls-paging-groups-json').val(encoded); $('#sls-paging-callers-json').val(callers);
            $('#sls-paging-form-complete').val('1');
            var form = $(this), controls = $('#sls-live-paging button'), body = form.serialize() + '&ajax=1';
            if (form.attr('aria-busy') === 'true') { return; }
            form.attr('aria-busy', 'true'); controls.prop('disabled', true);
            $.ajax({url:form.attr('action'), type:'POST', dataType:'json', data:body, timeout:30000})
                .done(function (result) {
                    if (result.success) { window.location.assign('config.php?display=slsmassnotifyserver_paging'); }
                    else { error([result.message].concat(result.errors || []).filter(Boolean).join(' ')); }
                }).fail(function () { error('The save response was interrupted. Reload Paging to check whether your changes were saved before trying again.'); })
                .always(function () { form.removeAttr('aria-busy'); controls.prop('disabled', false); refresh(); });
        } catch (failure) { event.preventDefault(); error(failure.message || labels.tooLarge); }
    });
    $('#sls-paging-callers').on('change click', function () { refresh(); });
    refresh(); $('#sls-paging-submit').prop('disabled', false);
})(jQuery);
</script>

<?php $source_picker_mode = 'paging'; include __DIR__ . '/audience_source_picker.php'; ?>
