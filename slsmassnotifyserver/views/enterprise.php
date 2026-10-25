<?php
$e=static fn($value):string=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$scriptHash=@hash_file('sha256',__DIR__.'/enterprise.js');$styleHash=@hash_file('sha256',__DIR__.'/enterprise.css');
if (!is_string($scriptHash)||!is_string($styleHash)) { throw new RuntimeException('Enterprise Labs assets are unavailable. Repair the signed SLS module before opening this page.'); }
$revision=substr(hash('sha256',$scriptHash.$styleHash),0,12);
?>
<link rel="stylesheet" href="modules/slsmassnotifyserver/views/enterprise.css?v=<?php echo $revision; ?>">
<div id="sls-enterprise" class="sls-enterprise-workspace">
<header class="sls-ent-heading sls-enterprise-title"><div><span class="sls-ent-eyebrow">SLS MASS NOTIFY</span><h1>Enterprise Labs</h1><p>Site links, device integrations and incident response.</p></div><?php include __DIR__.'/labs.php'; ?></header>
<div class="sls-labs-activation-card">
<div><p id="sls-labs-activation" role="status"><span class="label label-danger">Inactive</span> <small>Enterprise Labs is disabled.</small></p><p class="sls-labs-activation-help">Use Feature manager to enable Labs and choose its tools.</p></div>
<div class="sls-ent-actions"><span class="sls-labs-risk-receipt"><i class="fa fa-check-circle" aria-hidden="true"></i> Risk warning acknowledged</span><button type="button" class="btn btn-default" id="sls-labs-configure"><i class="fa fa-sliders" aria-hidden="true"></i> Feature manager</button></div>
</div>
<div class="sls-ent-layout">
<nav class="sls-ent-tabs" aria-label="Enterprise Labs sections">
<span class="sls-ent-nav-label">SETTINGS</span>
<?php foreach(['continuity'=>['server','Continuity and remote sites'],'identity'=>['users','Directory and subscribers'],'integrations'=>['plug','Devices and providers'],'operations'=>['clipboard','Incident coordination']] as $key=>[$icon,$label]): ?>
<a href="#labs-<?php echo $key; ?>" data-labs-tab="<?php echo $key; ?>"><i class="fa fa-<?php echo $icon; ?>" aria-hidden="true"></i> <?php echo $label; ?></a>
<?php endforeach; ?></nav>
<div class="sls-ent-content">
<div id="sls-enterprise-status" class="sls-ent-notice" role="status" tabindex="-1" hidden></div>
<section data-labs-panel="continuity"><?php echo $module->renderClusterPage('config.php?display=slsmassnotifyserver_enterprise',$csrf_token); ?></section>
<section data-labs-panel="identity" hidden><?php echo $module->renderEnterpriseLabsPage('config.php?display=slsmassnotifyserver_enterprise',$csrf_token); ?></section>
<section data-labs-panel="integrations" hidden><?php echo $module->renderEnterpriseIntegrations(); ?></section>
<section data-labs-panel="operations" hidden>
<div class="sls-ent-heading"><div><h2>Incident coordination</h2><p>Floor plans, drills, on-duty recipients and approval policies.</p></div><button class="btn btn-primary" id="sls-operations-save" type="button"><i class="fa fa-save" aria-hidden="true"></i> Save coordination settings</button></div>
<div class="sls-coordination-intro"><div><i class="fa fa-sitemap" aria-hidden="true"></i><div><strong>Coordination tools</strong><p>Enable this section and the individual tools below.</p></div></div><label class="sls-ent-toggle"><input type="checkbox" id="sls-operations-enabled"> Enable incident coordination</label></div>
<?php if (isset($operations['floorplan_storage'])): $usage=$operations['floorplan_storage']; ?>
<p class="help-block"><i class="fa fa-hdd-o" aria-hidden="true"></i> Private floor plans: <?php echo (int)$usage['image_count']; ?> / 100 images, <?php echo $e(number_format(($usage['image_bytes']+$usage['pending_upload_bytes'])/1048576,1)); ?> / 32 MiB. Interrupted uploads older than one day are removed on the next upload.</p>
<?php endif; ?>
<details class="sls-ent-disclosure"><summary><i class="fa fa-picture-o" aria-hidden="true"></i> Stored floor-plan images <span>Upload a plan when adding a floor plan below</span></summary><p>Download an image before retiring it. An image can be retired only when saved configurations no longer reference it.</p><div id="sls-floorplan-storage"></div></details>
<div class="sls-ent-grid">
<?php foreach(['floorplans'=>['map-o','Private floor plans','Plans remain private. Place locations and devices on an uploaded PNG or JPEG.'],
    'drills'=>['calendar-check-o','Central drills','Assign owners, deadlines and review objectives. Every launch is explicitly marked as a drill.'],
    'shift_routing'=>['clock-o','Shift routing','Route incident templates to the audience on duty in a saved timezone. Uncovered or overlapping shifts stop submission.'],
    'dual_approval'=>['user-secret','Two-person approval','Review exact announcement and incident wording and recipients before sending. Weather, lightning, live paging and provider controls keep their separate authorization.']] as $section=>[$icon,$label,$description]): ?>
<section class="sls-ent-card sls-coordination-card"><div class="sls-ent-card-heading"><h3><i class="fa fa-<?php echo $icon; ?>" aria-hidden="true"></i> <?php echo $label; ?></h3><button class="btn btn-default btn-sm" data-add-operation="<?php echo $section; ?>" type="button"><i class="fa fa-plus" aria-hidden="true"></i> Add</button></div><p><?php echo $description; ?></p><label class="sls-ent-toggle"><input type="checkbox" data-enable-operation="<?php echo $section; ?>"> Enable <?php echo strtolower($label); ?></label><div data-operation-list="<?php echo $section; ?>"></div></section>
<?php endforeach; ?></div>
<section class="sls-ent-card"><h3><i class="fa fa-check-square-o" aria-hidden="true"></i> Approval queue</h3><p>Only reviews within your current audience and approval assignments appear here. Rejection applies to an unsubmitted request.</p><div id="sls-enterprise-reviews"></div></section>
<details class="sls-ent-disclosure"><summary><i class="fa fa-archive" aria-hidden="true"></i> Coordination history <span>Archive completed reviews and drills</span></summary><p>Archive completed approvals and reviewed drills while preserving reports and replay protection. Pending and uncertain work stays active.</p><div class="sls-ent-actions"><label>Keep completed entries active for (days)<input id="sls-operations-retention" class="form-control" type="number" min="1" max="365" value="30"></label><button class="btn btn-default" id="sls-operations-archive" type="button">Archive eligible history</button></div></details>
<section class="sls-ent-card"><h3><i class="fa fa-calendar" aria-hidden="true"></i> Drill report</h3><div id="sls-enterprise-drills"></div><button class="btn btn-default" id="sls-drill-export" type="button"><i class="fa fa-download" aria-hidden="true"></i> Export drill report</button></section>
</section>
</div>
</div>
<dialog id="sls-labs-feature-manager" class="sls-ent-dialog sls-feature-dialog" aria-labelledby="sls-labs-feature-title">
<form id="sls-labs-features-form">
<header><div><h2 id="sls-labs-feature-title"><i class="fa fa-flask" aria-hidden="true"></i> Feature manager</h2><p>Choose which Enterprise Labs tools are available.</p></div><button type="button" data-close-feature-manager aria-label="Close feature manager">×</button></header>
<div class="sls-ent-dialog-body sls-feature-content">
<p id="sls-labs-feature-error" class="sls-ent-notice" role="alert" tabindex="-1" hidden></p>
<label class="sls-feature-master"><input type="checkbox" id="sls-labs-master"><span><strong>Enable Enterprise Labs</strong><small>Required before enabling any feature below. Turning Labs off disables its tools and keeps their saved configuration.</small></span></label>
<div id="sls-labs-feature-choices"></div>
<p class="sls-feature-note">Feature changes take effect immediately. Review recipients, provider credentials and schedules before enabling a tool.</p>
</div>
<footer class="sls-feature-footer">
<div class="sls-feature-warning"><strong><?php echo $e(\SLS\MassNotify\LabsSafety::TITLE); ?></strong><p><?php echo $e(\SLS\MassNotify\LabsSafety::BODY); ?></p></div>
<label class="sls-feature-agreement"><input type="checkbox" id="sls-labs-feature-agree" required><span><?php echo $e(\SLS\MassNotify\LabsSafety::AGREEMENT); ?></span></label>
<div class="sls-ent-actions"><button type="button" class="btn btn-default" data-close-feature-manager>Cancel</button><button type="submit" class="btn btn-primary" id="sls-labs-feature-save" disabled><i class="fa fa-save" aria-hidden="true"></i> Save feature choices</button></div>
</footer>
</form>
</dialog>
<dialog id="sls-enterprise-editor" class="sls-ent-dialog" aria-labelledby="sls-enterprise-editor-title"><form id="sls-enterprise-editor-form"><header><h2 id="sls-enterprise-editor-title"></h2><button type="button" data-close-enterprise aria-label="Close editor">×</button></header><div id="sls-enterprise-editor-body" class="sls-ent-dialog-body"></div><footer><button type="button" class="btn btn-default" data-close-enterprise>Close</button><button type="submit" class="btn btn-primary" id="sls-enterprise-editor-submit">Save in editor</button></footer></form></dialog>
<?php $endpoint='config.php?display=slsmassnotifyserver_enterprise';$csrf=$csrf_token;$safetyFeature='enterprise_labs';$safetyAutomatic=true;$safetySelectors='[data-labs-panel], .sls-ent-tabs';include __DIR__.'/labs_danger.php'; ?>
<script type="application/json" id="sls-enterprise-data"><?php echo json_encode(['csrf'=>$csrf_token,'operations'=>$operations,'safety'=>$safety,'feature_manager'=>$feature_manager??null,'feature_settings'=>array_map(static function($config){ return ['enabled'=>!empty($config['enabled'])]; },$feature_settings??[]),'safety_revision'=>\SLS\MassNotify\LabsSafety::revision()],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR); ?></script>
<script src="modules/slsmassnotifyserver/views/enterprise.js?v=<?php echo $revision; ?>" defer></script>
</div>

<script><?php readfile(__DIR__.'/save_state.js'); ?>
window.SlsSaveState.attach(document.getElementById('sls-enterprise'),{mode:'immediate',initial:'saved',ignore:'form:not(#sls-cluster-config):not(#sls-integration-form):not(#sls-enterprise-editor-form),#sls-labs-danger,#sls-labs-features-form'});</script>
