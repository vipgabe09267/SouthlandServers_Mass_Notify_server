<?php
require_once dirname(__DIR__) . '/AudiencePicker.php';
$sourcePickerChoices = $source_picker_choices ?? \SLS\MassNotify\AudiencePicker::choices($settings ?? [], ($source_picker_mode ?? 'weather') === 'weather');
?>
<style>
.sls-source-picker{border:1px solid #d5e2ef;border-radius:7px;background:#f6faff;margin:12px 0 16px;min-width:0}
.sls-source-picker>summary{padding:11px 14px;font-weight:600;cursor:pointer;font-size:13px;color:#315979}
.sls-source-picker>summary .fa{margin-right:7px}
.sls-source-picker-body{padding:0 14px 14px}.sls-source-picker-body select{max-width:100%;min-width:0}
.sls-source-picker-types{display:flex;flex-wrap:wrap;gap:12px;margin:12px 0}
.sls-source-picker-types label{display:flex;align-items:center;gap:7px;font-weight:400;margin:0}
.sls-source-picker-review{margin:12px 0;padding:10px;border-radius:5px;background:#fff;font-size:13px;overflow-wrap:anywhere}
.sls-source-picker-review p:last-child{margin-bottom:0}
</style>
<script type="application/json" id="sls-source-picker-data"><?php echo json_encode($sourcePickerChoices, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR); ?></script>
<script src="modules/slsmassnotifyserver/views/audience_source_picker.js?v=<?php echo substr(hash_file('sha256', __DIR__ . '/audience_source_picker.js'), 0, 16); ?>" defer></script>
