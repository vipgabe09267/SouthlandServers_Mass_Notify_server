<style>
.sls-device-tests { margin-top:20px; padding-top:18px; border-top:1px solid #e2e8f0; }
.sls-device-tests-heading { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px; }
.sls-device-tests-heading h4 { margin:0; font-size:18px; }
.sls-device-tests-actions { display:flex; flex-wrap:wrap; gap:8px; }
.sls-device-tests-description, .sls-device-tests-retention { color:#475569; line-height:1.6; margin:12px 0; }
.sls-device-tests-summary { display:flex; flex-wrap:wrap; gap:8px; margin:12px 0; }
.sls-device-tests-chip { padding:5px 10px; border-radius:16px; background:#f1f5f9; color:#334155; font-size:12px; font-weight:600; }
.sls-device-tests-history { max-height:480px; overflow:auto; border:1px solid #dce2e8; border-radius:8px; }
.sls-device-test { border-bottom:1px solid #e2e8f0; padding:12px 14px; overflow-wrap:anywhere; }
.sls-device-test:last-child { border-bottom:0; }
.sls-device-test summary { cursor:pointer; line-height:1.6; }
.sls-device-test summary:focus-visible { outline:2px solid #93c5fd; outline-offset:2px; }
.sls-device-test p { margin:8px 0 0; line-height:1.6; white-space:pre-wrap; }
.sls-device-test small { color:#64748b; }
.sls-device-test-dialog { width:min(760px, calc(100% - 24px)); max-height:calc(100vh - 32px); padding:0; overflow:hidden; border:1px solid #cbd5e1; border-radius:12px; box-shadow:0 18px 60px rgba(15,23,42,.2); color:#334155; }
.sls-device-test-dialog[open] { display:flex; flex-direction:column; }
.sls-device-test-dialog::backdrop { background:rgba(15,23,42,.45); }
.sls-device-test-dialog-heading { padding:18px 22px; flex-shrink:0; background:#f8fafc; border-bottom:1px solid #e2e8f0; }
.sls-device-test-dialog-heading h3 { margin:0 0 8px; font-size:22px; }
.sls-device-test-dialog-heading p { margin:0; line-height:1.6; }
.sls-device-test-dialog-body { padding:20px 22px; min-height:0; overflow:auto; }
.sls-device-test-fields { display:grid; grid-template-columns:1fr 1fr; gap:14px 18px; }
.sls-device-test-field { min-width:0; }
.sls-device-test-field label, .sls-device-test-dialog legend { font-size:13px; font-weight:600; }
.sls-device-test-field .form-control { width:100%; min-height:36px; }
.sls-device-test-field small { display:block; color:#64748b; margin-top:5px; line-height:1.5; }
.sls-device-test-wide { grid-column:1 / -1; }
.sls-device-test-dialog fieldset { margin:18px 0; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; }
.sls-device-test-dialog legend { width:auto; border:0; margin:0; padding:0 5px; }
.sls-device-test-checks { display:grid; grid-template-columns:1fr 1fr; gap:10px 16px; }
.sls-device-test-checks label, .sls-device-test-confirm { display:flex; align-items:flex-start; gap:9px; line-height:1.5; font-weight:400; margin:0; }
.sls-device-test-checks input, .sls-device-test-confirm input { margin:4px 0 0; flex-shrink:0; }
.sls-device-test-dialog-footer { padding:14px 22px; flex-shrink:0; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; flex-wrap:wrap; gap:8px; }
.sls-device-test-dialog [role=status] { margin:12px 0 0; line-height:1.6; overflow-wrap:anywhere; }
@media(max-width:600px) { .sls-device-test-fields, .sls-device-test-checks { grid-template-columns:1fr; } .sls-device-test-dialog-body, .sls-device-test-dialog-heading { padding:16px; } }
</style>
<div class="sls-device-tests" id="sls-device-tests" data-csrf="<?php echo htmlspecialchars((string)($csrfToken ?? $csrf_token ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <div class="sls-device-tests-heading">
        <h4><i class="fa fa-check-square-o text-primary" aria-hidden="true"></i> <?php echo _('Device acceptance'); ?> <?php include __DIR__ . '/labs.php'; ?></h4>
        <div class="sls-device-tests-actions">
            <button type="button" class="btn btn-default" id="sls-device-tests-refresh"><i class="fa fa-refresh" aria-hidden="true"></i> <?php echo _('Refresh observations'); ?></button>
            <button type="button" class="btn btn-primary" id="sls-device-tests-add"><i class="fa fa-plus" aria-hidden="true"></i> <?php echo _('Record a device test'); ?></button>
        </div>
    </div>
    <p class="sls-device-tests-description"><?php echo _('Record what an administrator actually saw or heard on a configured device. These observations are separate from software receipts and local health checks. Saving an observation sends nothing.'); ?></p>
    <p id="sls-device-tests-status" role="status" aria-live="polite"><?php echo _('Check readiness or refresh observations to review saved tests.'); ?></p>
    <div id="sls-device-tests-summary" class="sls-device-tests-summary"></div>
    <div id="sls-device-tests-history" class="sls-device-tests-history" hidden></div>
    <p class="sls-device-tests-retention"><?php echo _('The latest 200 observations are kept in the protected .config. Download the readiness report to retain older evidence. Test the current installation after software, routing, firmware or PBX restore changes.'); ?></p>
</div>
<dialog id="sls-device-test-dialog" class="sls-device-test-dialog" aria-labelledby="sls-device-test-title">
    <div class="sls-device-test-dialog-heading">
        <h3 id="sls-device-test-title"><i class="fa fa-check-square-o text-primary" aria-hidden="true"></i> <?php echo _('Record a device test'); ?></h3>
        <p><?php echo _('Describe a test you already performed on approved targets. This form records your observation; it does not run a test or send a notification.'); ?></p>
    </div>
    <div class="sls-device-test-dialog-body">
        <div class="sls-device-test-fields">
            <div class="sls-device-test-field"><label for="sls-device-test-type"><?php echo _('Device or channel'); ?></label><select id="sls-device-test-type" class="form-control" required></select></div>
            <div class="sls-device-test-field"><label for="sls-device-test-target"><?php echo _('Configured destination'); ?></label><select id="sls-device-test-target" class="form-control" required></select></div>
            <div class="sls-device-test-field"><label for="sls-device-test-model"><?php echo _('Device model or provider'); ?></label><input id="sls-device-test-model" class="form-control" maxlength="100"><small><?php echo _('Required for phones; use the actual model, such as Yealink T48G.'); ?></small></div>
            <div class="sls-device-test-field"><label for="sls-device-test-firmware"><?php echo _('Firmware or app version'); ?></label><input id="sls-device-test-firmware" class="form-control" maxlength="100"><small><?php echo _('Leave blank if you did not verify the version.'); ?></small></div>
            <div class="sls-device-test-field"><label for="sls-device-test-time"><?php echo _('When you tested'); ?></label><input id="sls-device-test-time" class="form-control" type="datetime-local" step="1" min="2000-01-01T00:00:00" required><small><?php echo _('Your browser local time; stored in UTC.'); ?></small></div>
            <div class="sls-device-test-field"><label for="sls-device-test-result"><?php echo _('Observed result'); ?></label><select id="sls-device-test-result" class="form-control" required><option value=""><?php echo _('Choose a result'); ?></option><option value="passed"><?php echo _('Passed'); ?></option><option value="failed"><?php echo _('Failed'); ?></option><option value="incomplete"><?php echo _('Incomplete'); ?></option></select></div>
            <div class="sls-device-test-field sls-device-test-wide"><label for="sls-device-test-notes"><?php echo _('What you observed'); ?></label><textarea id="sls-device-test-notes" class="form-control" rows="3" maxlength="600" required></textarea><small><?php echo _('Describe the verified behavior or failure. Keep credentials and message contents out of these notes.'); ?></small></div>
        </div>
        <fieldset><legend><?php echo _('Behavior checked'); ?></legend><div id="sls-device-test-checks" class="sls-device-test-checks"></div></fieldset>
        <label class="sls-device-test-confirm"><input id="sls-device-test-confirm" type="checkbox" required><span><?php echo _('I am recording my own test observation. A queued call or submitted message alone does not establish device receipt or playback.'); ?></span></label>
        <p id="sls-device-test-form-status" role="status" aria-live="polite"></p>
    </div>
    <div class="sls-device-test-dialog-footer">
        <button type="button" class="btn btn-default" id="sls-device-test-reload"><i class="fa fa-refresh" aria-hidden="true"></i> <?php echo _('Reload destinations'); ?></button>
        <button type="button" class="btn btn-default" id="sls-device-test-cancel"><?php echo _('Cancel'); ?></button>
        <button type="button" class="btn btn-primary" id="sls-device-test-save"><i class="fa fa-check" aria-hidden="true"></i> <?php echo _('Record observation'); ?></button>
    </div>
</dialog>
<script src="modules/slsmassnotifyserver/views/device_acceptance.js?v=<?php echo substr(hash_file('sha256', __DIR__ . '/device_acceptance.js'), 0, 12); ?>"></script>
