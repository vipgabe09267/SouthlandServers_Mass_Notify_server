<?php
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$state = is_array($state ?? null) ? $state : [];
$assetRevision = substr(hash('sha256', hash_file('sha256', __DIR__ . '/locations.js') . hash_file('sha256', __DIR__ . '/locations.css') . hash_file('sha256', __DIR__ . '/geographic.js') . hash_file('sha256', __DIR__ . '/audiences.js')), 0, 16);
?>
<link rel="stylesheet" href="modules/slsmassnotifyserver/views/locations.css?v=<?php echo $assetRevision; ?>">
<div class="container-fluid" id="sls-locations"><div class="display full-border"><div class="fpbx-container">
<?php echo load_view(__DIR__ . '/hero.php', ['hero_image' => $hero_image ?? '']); ?>
<header class="sls-location-heading">
    <div><h1><i class="fa fa-map-marker text-primary" aria-hidden="true"></i> <?php echo _('Locations and Audiences'); ?> <?php include __DIR__ . '/labs.php'; ?></h1>
    <p class="text-muted"><?php echo _('Organize recipients by site, building, floor, and room. Review a location’s audience before saving a group.'); ?></p></div>
    <span class="sls-location-badge"><i class="fa fa-sitemap" aria-hidden="true"></i> <span id="sls-location-count" aria-live="polite"></span></span>
</header>
<div class="alert alert-info" id="sls-location-pending" <?php echo empty($has_pending_changes) ? 'hidden' : ''; ?>><i class="fa fa-info-circle" aria-hidden="true"></i> <?php echo _('You are viewing pending settings. Apply Config to activate saved locations and newly created groups.'); ?></div>
<div class="alert" id="sls-location-status" role="status" tabindex="-1" hidden></div>
<noscript><div class="alert alert-warning"><?php echo _('JavaScript is required to manage locations. Saved settings have not been changed.'); ?></div></noscript>
<nav class="sls-directory-tabs" role="tablist" aria-label="<?php echo $escape(_('Locations and audiences')); ?>">
    <button type="button" role="tab" id="sls-directory-tab-directory" aria-controls="sls-directory-panel" aria-selected="true" data-directory-tab="directory"><i class="fa fa-map-marker" aria-hidden="true"></i> <?php echo _('Directory'); ?></button>
    <button type="button" role="tab" id="sls-directory-tab-audiences" aria-controls="sls-audiences" aria-selected="false" tabindex="-1" data-directory-tab="audiences"><i class="fa fa-users" aria-hidden="true"></i> <?php echo _('Saved audiences'); ?></button>
<button type="button" role="tab" id="sls-directory-tab-recipients" aria-controls="sls-location-recipient-panel" aria-selected="false" tabindex="-1" data-directory-tab="recipients"><i class="fa fa-address-book-o" aria-hidden="true"></i> <?php echo _('Recipients'); ?></button>
</nav>
<div id="sls-directory-panel" role="tabpanel" aria-labelledby="sls-directory-tab-directory">
<?php include __DIR__ . '/location_map.php'; ?>
<div class="sls-location-layout">
    <aside class="sls-location-card sls-location-navigation">
        <div class="sls-location-card-heading"><h2><i class="fa fa-sitemap" aria-hidden="true"></i> <?php echo _('Directory'); ?></h2><button type="button" class="btn btn-primary btn-sm" id="sls-location-add-site"><i class="fa fa-plus" aria-hidden="true"></i> <?php echo _('Add site'); ?></button></div>
        <div class="sls-location-search"><label class="sr-only" for="sls-location-search"><?php echo _('Find a location'); ?></label><input class="form-control" id="sls-location-search" type="search" placeholder="<?php echo $escape(_('Find a location…')); ?>"></div>
        <nav aria-label="<?php echo $escape(_('Location directory')); ?>" id="sls-location-tree"></nav>
        <p class="sls-location-footnote"><?php echo _('Assign each recipient to one location. A parent audience can include all of its child locations.'); ?></p>
    </aside>
    <div class="sls-location-workspace">
        <section class="sls-location-empty" id="sls-location-empty"><i class="fa fa-map-marker" aria-hidden="true"></i><h2><?php echo _('Start with a site'); ?></h2><p><?php echo _('Add a site, then its buildings, floors, and rooms. Select a saved location to manage recipients or review an audience.'); ?></p><button type="button" class="btn btn-primary" id="sls-location-empty-add"><i class="fa fa-plus" aria-hidden="true"></i> <?php echo _('Add your first site'); ?></button></section>
        <form class="sls-location-card" id="sls-location-form" hidden>
            <div class="sls-location-card-heading"><div><p id="sls-location-path" class="sls-location-breadcrumb"></p><h2 id="sls-location-editor-title"></h2></div><span class="sls-location-badge" id="sls-location-edit-state"></span></div>
            <div class="sls-location-card-body">
                <div class="sls-location-fields">
                    <div class="form-group"><label for="sls-location-name"><?php echo _('Location name'); ?></label><input class="form-control" id="sls-location-name" maxlength="160" required autocomplete="off" aria-describedby="sls-location-name-help"><span class="help-block" id="sls-location-name-help"><?php echo _('Use a clear local name, such as North Campus, Science Building, or Room 204.'); ?></span></div>
                    <div class="form-group"><label for="sls-location-type"><?php echo _('Location type'); ?></label><select class="form-control" id="sls-location-type"><option value="site"><?php echo _('Site'); ?></option><option value="building"><?php echo _('Building'); ?></option><option value="floor"><?php echo _('Floor'); ?></option><option value="room"><?php echo _('Room'); ?></option></select></div>
                    <div class="form-group"><label for="sls-location-parent"><?php echo _('Parent location'); ?></label><select class="form-control" id="sls-location-parent" aria-describedby="sls-location-parent-help"></select><span class="help-block" id="sls-location-parent-help"><?php echo _('Buildings belong to sites, floors to buildings, and rooms to floors.'); ?></span></div>
                    <div class="form-group"><label for="sls-location-description"><?php echo _('Description'); ?> <span class="text-muted"><?php echo _('(optional)'); ?></span></label><input class="form-control" id="sls-location-description" maxlength="400" placeholder="<?php echo $escape(_('Entrance, wing, or other useful context')); ?>"></div>
                </div>
                <details class="sls-location-position-panel"><summary><i class="fa fa-cloud" aria-hidden="true"></i> <?php echo _('Weather coverage and routes'); ?> <span class="label label-info"><i class="fa fa-flask" aria-hidden="true"></i> Labs</span></summary><div class="sls-location-card-body">
                    <label for="sls-location-weather-zone"><?php echo _('Weather.gov county or forecast zone'); ?></label><input class="form-control" id="sls-location-weather-zone" maxlength="6" placeholder="TXC491" autocomplete="off">
                    <p class="help-block"><?php echo _('Optional. Children inherit coverage when copying a weather route. Lightning uses the map position below. Recipient changes require an explicit copy and review in the alert editor.'); ?></p>
                    <div id="sls-location-weather-routes"></div>
                    <a class="btn btn-default btn-sm" href="config.php?display=slsmassnotifyserver_nws"><?php echo _('Weather Alerts'); ?></a> <a class="btn btn-default btn-sm" href="config.php?display=slsmassnotifyserver_lightning"><?php echo _('Lightning Alerts'); ?></a>
                </div></details>
                <details id="sls-location-position-panel" class="sls-location-position-panel"><summary><i class="fa fa-map-marker" aria-hidden="true"></i> <?php echo _('Map position'); ?> <span class="text-muted"><?php echo _('(optional)'); ?></span></summary><div class="sls-location-card-body">
                    <label class="sls-location-check"><input type="checkbox" id="sls-location-own-position"> <?php echo _('Set coordinates for this location'); ?></label>
                    <p class="help-block" id="sls-location-position-status"></p>
                    <div id="sls-location-position-fields" hidden><div class="sls-location-fields">
                        <div class="form-group"><label for="sls-location-latitude"><?php echo _('Latitude'); ?></label><input type="number" class="form-control" id="sls-location-latitude" min="-90" max="90" step="any" inputmode="decimal" placeholder="-90 … 90"></div>
                        <div class="form-group"><label for="sls-location-longitude"><?php echo _('Longitude'); ?></label><input type="number" class="form-control" id="sls-location-longitude" min="-180" max="180" step="any" inputmode="decimal" placeholder="-180 … 180"></div>
                    </div><label class="sls-location-check sls-geo-confirm"><input type="checkbox" id="sls-location-position-reviewed"> <?php echo _('I reviewed these coordinates today'); ?></label><p class="help-block"><?php echo _('Required for new or changed coordinates. Checking it refreshes the review date when you save. Children inherit this position unless they have their own.'); ?></p></div>
                </div></details>
                <div class="sls-location-section-heading"><h3><i class="fa fa-users" aria-hidden="true"></i> <?php echo _('Assigned recipients'); ?></h3><span class="text-muted" id="sls-location-member-count" aria-live="polite"></span></div>
                <p class="help-block"><?php echo _('These assignments describe configured locations, not live device tracking. Disabled or removed recipients remain visible for review. Manage recipient accounts and channels in General Settings.'); ?></p>
                <div id="sls-location-members"></div>
            </div>
            <footer class="sls-location-card-footer"><div class="sls-location-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-save" aria-hidden="true"></i> <?php echo _('Save location'); ?></button><button class="btn btn-default" type="button" id="sls-location-discard"><?php echo _('Discard edits'); ?></button></div><div class="sls-location-actions"><button class="btn btn-default" type="button" id="sls-location-add-child"><i class="fa fa-plus" aria-hidden="true"></i> <span></span></button><button class="btn btn-default" type="button" id="sls-location-set-default"><i class="fa fa-star-o" aria-hidden="true"></i> <?php echo _('Use as default'); ?></button><button class="btn btn-link text-danger" type="button" id="sls-location-delete"><i class="fa fa-trash" aria-hidden="true"></i> <?php echo _('Remove'); ?></button></div></footer>
        </form>
        <section class="sls-location-card" id="sls-location-audience" hidden aria-labelledby="sls-location-audience-title">
            <div class="sls-location-card-heading"><h2 id="sls-location-audience-title"><i class="fa fa-users" aria-hidden="true"></i> <?php echo _('Create an audience group'); ?></h2><span class="sls-location-badge"><?php echo _('Review before saving'); ?></span></div>
            <div class="sls-location-card-body">
                <p><?php echo _('Save a snapshot of this location’s recipients as an announcement group for the Dashboard, schedules, and incident templates. Future location edits will not change that group. Creating a group sends no notifications.'); ?></p>
                <label class="sls-location-check"><input type="checkbox" id="sls-location-descendants" checked> <?php echo _('Include child locations'); ?></label>
                <fieldset class="sls-location-channel-options"><legend><?php echo _('Recipient types'); ?></legend><div id="sls-location-channels"></div></fieldset>
                <button type="button" class="btn btn-default" id="sls-location-preview"><i class="fa fa-search" aria-hidden="true"></i> <?php echo _('Review audience'); ?></button>
                <div id="sls-location-preview-result" hidden><h3 id="sls-location-preview-heading"></h3><div class="alert alert-warning" id="sls-location-preview-issues" hidden></div><div id="sls-location-preview-members"></div>
                    <div class="sls-location-create"><div class="form-group"><label for="sls-location-group-name"><?php echo _('Audience group name'); ?></label><input class="form-control" id="sls-location-group-name" maxlength="64" autocomplete="off"><span class="help-block"><?php echo _('Up to 20 saved announcement groups. A new name keeps the previous group and its schedules intact.'); ?></span></div><button type="button" class="btn btn-primary" id="sls-location-create-group"><i class="fa fa-plus-circle" aria-hidden="true"></i> <?php echo _('Create group'); ?></button></div>
                </div>
            </div>
        </section>
    </div>
</div>
</div>
<?php include __DIR__ . '/audiences.php'; ?>
<?php include __DIR__ . '/location_recipients.php'; ?>
<script type="application/json" id="sls-location-data"><?php echo json_encode(['state' => $state, 'csrf' => $csrf_token ?? ''], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR); ?></script>
<script src="modules/slsmassnotifyserver/views/geographic.js?v=<?php echo $assetRevision; ?>" defer></script>
<script src="modules/slsmassnotifyserver/views/audiences.js?v=<?php echo $assetRevision; ?>" defer></script>
<script src="modules/slsmassnotifyserver/views/locations.js?v=<?php echo $assetRevision; ?>" defer></script>
</div></div></div>
