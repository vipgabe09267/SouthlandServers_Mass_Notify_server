<?php
$e=static fn($value):string=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$revision=substr(hash_file('sha256',__DIR__.'/enterprise.js'),0,12);
?>
<link rel="stylesheet" href="modules/slsmassnotifyserver/views/enterprise.css?v=<?php echo $revision; ?>">
<div id="sls-enterprise" class="container-fluid"><div class="display full-border"><div class="fpbx-container">
<header class="sls-ent-heading"><div><h1><i class="fa fa-flask" aria-hidden="true"></i> SLS Enterprise Labs</h1><p>Optional tools for coordinated delivery, identity and incident response.</p></div><?php include __DIR__.'/labs.php'; ?></header>
<div class="sls-ent-notice"><i class="fa fa-info-circle" aria-hidden="true"></i><div>All new features start disabled. Automated protocol checks do not qualify real PBXs, providers or physical devices. Validate your complete deployment before relying on it.</div></div>
<nav class="sls-ent-tabs" aria-label="Enterprise Labs sections">
<?php foreach(['continuity'=>['server','Continuity and remote sites'],'identity'=>['key','Identity and subscribers'],'integrations'=>['plug','Devices and providers'],'operations'=>['clipboard','Incident coordination']] as $key=>[$icon,$label]): ?>
<a href="#labs-<?php echo $key; ?>" data-labs-tab="<?php echo $key; ?>"><i class="fa fa-<?php echo $icon; ?>" aria-hidden="true"></i> <?php echo $label; ?></a>
<?php endforeach; ?></nav>
<div id="sls-enterprise-status" class="sls-ent-notice" role="status" tabindex="-1" hidden></div>
<section data-labs-panel="continuity"><?php echo $module->renderClusterPage('config.php?display=slsmassnotifyserver_enterprise',$csrf_token); ?></section>
<section data-labs-panel="identity" hidden><?php echo $module->renderEnterpriseLabsPage('config.php?display=slsmassnotifyserver_enterprise',$csrf_token); ?></section>
<section data-labs-panel="integrations" hidden><?php echo $module->renderEnterpriseIntegrations(); ?></section>
<section data-labs-panel="operations" hidden>
<div class="sls-ent-heading"><div><h2>Incident coordination <?php include __DIR__.'/labs.php'; ?></h2><p>Private floor plans, drill assignments, on-duty audiences and two-person review.</p></div><button class="btn btn-primary" id="sls-operations-save" type="button"><i class="fa fa-save" aria-hidden="true"></i> Save coordination settings</button></div>
<label class="sls-ent-toggle"><input type="checkbox" id="sls-operations-enabled"> Enable incident coordination</label>
<div class="sls-ent-grid">
<?php foreach(['floorplans'=>['map-o','Private floor plans','Plans remain private. Place locations and devices on an uploaded PNG or JPEG.'],
    'drills'=>['calendar-check-o','Central drills','Assign owners, deadlines and review objectives. Every launch is explicitly marked as a drill.'],
    'shift_routing'=>['clock-o','Shift routing','Route incident templates to the audience on duty in a saved timezone. Uncovered or overlapping shifts stop submission.'],
    'dual_approval'=>['user-secret','Two-person approval','Review exact announcement and incident wording and recipients before sending. Weather, lightning, live paging and provider controls keep their separate authorization.']] as $section=>[$icon,$label,$description]): ?>
<section class="sls-ent-card"><div class="sls-ent-card-heading"><h3><i class="fa fa-<?php echo $icon; ?>" aria-hidden="true"></i> <?php echo $label; ?></h3><button class="btn btn-default btn-sm" data-add-operation="<?php echo $section; ?>" type="button"><i class="fa fa-plus" aria-hidden="true"></i> Add</button></div><p><?php echo $description; ?></p><label class="sls-ent-toggle"><input type="checkbox" data-enable-operation="<?php echo $section; ?>"> Enable <?php echo strtolower($label); ?></label><div data-operation-list="<?php echo $section; ?>"></div></section>
<?php endforeach; ?></div>
<section class="sls-ent-card"><h3><i class="fa fa-check-square-o" aria-hidden="true"></i> Approval queue</h3><p>Only reviews within your current audience and approval assignments appear here. Rejection applies to an unsubmitted request.</p><div id="sls-enterprise-reviews"></div></section>
<section class="sls-ent-card"><h3><i class="fa fa-calendar" aria-hidden="true"></i> Drill report</h3><div id="sls-enterprise-drills"></div><button class="btn btn-default" id="sls-drill-export" type="button"><i class="fa fa-download" aria-hidden="true"></i> Export drill report</button></section>
</section>
<dialog id="sls-enterprise-editor" class="sls-ent-dialog"><form id="sls-enterprise-editor-form"><header><h2 id="sls-enterprise-editor-title"></h2><button type="button" data-close-enterprise aria-label="Close editor">×</button></header><div id="sls-enterprise-editor-body" class="sls-ent-dialog-body"></div><footer><button type="button" class="btn btn-default" data-close-enterprise>Close</button><button type="submit" class="btn btn-primary" id="sls-enterprise-editor-submit">Save in editor</button></footer></form></dialog>
<dialog id="sls-labs-danger" class="sls-ent-dialog sls-ent-danger" aria-labelledby="sls-labs-danger-title"><header><h2 id="sls-labs-danger-title"></h2></header><div class="sls-ent-dialog-body"><p id="sls-labs-danger-body"></p><label><input type="checkbox" id="sls-labs-danger-agree" disabled> <span id="sls-labs-danger-agreement"></span> <span id="sls-labs-danger-countdown">(5s)</span></label><p id="sls-labs-danger-error" role="alert" hidden></p></div><footer><button type="button" id="sls-labs-danger-cancel" class="btn btn-default">Cancel</button><button type="button" id="sls-labs-danger-accept" class="btn btn-danger" disabled>Accept risk</button></footer></dialog>
<script type="application/json" id="sls-enterprise-data"><?php echo json_encode(['csrf'=>$csrf_token,'operations'=>$operations,'safety'=>$safety,'safety_revision'=>\SLS\MassNotify\LabsSafety::revision()],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR); ?></script>
<script src="modules/slsmassnotifyserver/views/enterprise.js?v=<?php echo $revision; ?>" defer></script>
</div></div></div>
