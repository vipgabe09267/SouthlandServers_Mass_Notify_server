<?php
require_once dirname(__DIR__).'/LocationDirectory.php';
$weatherSiteGroup = $weather_site_group ?? [];
$weatherSitePrefix = $weather_site_prefix ?? 'zone';
$weatherSiteDirectory = \SLS\MassNotify\LocationDirectory::effective($settings['location_directory'] ?? []);
?>
<div class="form-group">
    <label><i class="fa fa-map-marker" aria-hidden="true"></i> <?php echo _('Site'); ?> <span class="label label-info"><i class="fa fa-flask" aria-hidden="true"></i> Labs</span></label>
    <select class="form-control" data-<?php echo $weatherSitePrefix; ?>-field="site_id">
        <option value=""><?php echo _('Independent weather route'); ?></option>
        <?php $weatherSiteIds = []; foreach ($weatherSiteDirectory['nodes'] as $weatherSite) { if ($weatherSite['type'] !== 'site') { continue; } $weatherSiteIds[] = $weatherSite['id']; ?>
            <option value="<?php echo htmlspecialchars($weatherSite['id']); ?>" <?php echo ($weatherSiteGroup['site_id'] ?? '') === $weatherSite['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($weatherSite['name']); ?></option>
        <?php } if (!empty($weatherSiteGroup['site_id']) && !in_array($weatherSiteGroup['site_id'], $weatherSiteIds, true)) { ?>
            <option value="<?php echo htmlspecialchars($weatherSiteGroup['site_id']); ?>" selected><?php echo _('Removed site — choose a saved site or an independent route'); ?></option>
        <?php } ?>
    </select>
    <p class="help-block"><?php echo _('Use a location or audience below to copy its devices and optional weather coverage. Review the resulting selections before saving. Later site changes do not alter this route automatically.'); ?></p>
</div>
