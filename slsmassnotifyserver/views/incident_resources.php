<?php
// Only reviewed links from the frozen incident template are displayed. This
// view never embeds, downloads or proxies linked documents.
$resources = [];
try { $resources = \SLS\MassNotify\IncidentConfig::resources($incident['template']['resources'] ?? []); }
catch (\InvalidArgumentException $error) { ?>
<div class="sls-inc-alert">Saved incident resources could not be validated. Preserve the incident record and review the original template.</div>
<?php }
if ($resources): ?>
<div class="sls-inc-card">
    <h2><i class="fa fa-map-o" aria-hidden="true"></i> Incident resources <?php include __DIR__ . '/labs.php'; ?></h2>
    <p class="sls-inc-muted">Links and review notes were saved when this incident started. Linked documents stay on their original service; access may require a separate sign-in.</p>
    <div class="sls-inc-resource-grid">
    <?php foreach ($resources as $resource): ?>
        <div class="sls-inc-resource-card">
            <span class="sls-inc-muted"><?php echo $e(ucfirst($resource['kind'])); ?></span>
            <h3><a href="<?php echo $e($resource['url']); ?>" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer"><?php echo $e($resource['label']); ?> <i class="fa fa-external-link" aria-hidden="true"></i><span class="sr-only"> (opens in a new tab)</span></a></h3>
            <p class="sls-inc-muted"><?php echo $e($resource['revision']); ?></p>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
