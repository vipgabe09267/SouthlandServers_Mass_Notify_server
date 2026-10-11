<?php
require_once dirname(__DIR__) . '/ScheduleProgrammes.php';
$programmeSettings = $schedule_programmes ?? \SLS\MassNotify\ScheduleProgrammes::defaults();
$programmeEscape = static fn($value)=>htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<style><?php readfile(__DIR__.'/schedule_programmes.css'); ?></style>
<details class="sls-programmes" id="sls-shared-calendars">
    <summary><span><i class="fa fa-calendar-check-o" aria-hidden="true"></i> <?php echo _('Shared calendars and day programmes'); ?> <?php include __DIR__.'/labs.php'; ?></span><span class="sls-programme-count"><?php echo count($programmeSettings['calendars']); ?> / 25</span></summary>
    <div class="sls-programmes-body">
        <p class="help-block"><?php echo _('Reuse a calendar across bell schedules. A/B cycles advance on included school days, or once each week. A whole-day substitution replaces every schedule using that date’s day type. Saved schedules keep their reviewed dates until you reopen and save them.'); ?></p>
        <form method="post" action="config.php?display=slsmassnotifyserver_scheduling" id="sls-programmes-form">
            <input type="hidden" name="slsmassnotifyserver_action" value="save_schedule_programmes">
            <input type="hidden" name="slsmassnotifyserver_csrf" value="<?php echo $programmeEscape($csrf_token); ?>">
            <input type="hidden" name="programmes_revision" value="<?php echo hash('sha256', json_encode($programmeSettings, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); ?>">
            <input type="hidden" name="programmes_json" id="sls-programmes-json">
            <label class="sls-programme-switch"><input type="checkbox" id="sls-programmes-enabled" <?php echo $programmeSettings['enabled'] ? 'checked' : ''; ?>> <?php echo _('Allow shared calendars for new or revised schedules'); ?></label>
            <div id="sls-programme-list"></div>
            <p id="sls-programme-empty" class="sls-programme-empty"><?php echo _('No shared calendars configured.'); ?></p>
            <div class="sls-programme-actions"><button type="button" class="btn btn-default" id="sls-programme-add"><i class="fa fa-plus" aria-hidden="true"></i> <?php echo _('Add calendar'); ?></button><button type="submit" class="btn btn-primary"><i class="fa fa-save" aria-hidden="true"></i> <?php echo _('Save shared calendars'); ?></button></div>
            <p class="help-block" id="sls-programme-status" role="status" aria-live="polite"></p>
        </form>
    </div>
</details>
<script type="application/json" id="sls-programme-data"><?php echo json_encode(['settings'=>$programmeSettings, 'timezone'=>$timezoneName], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR); ?></script>
<script><?php readfile(__DIR__.'/schedule_programmes.js'); ?></script>
