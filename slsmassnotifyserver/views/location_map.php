<details class="sls-location-card sls-geo-panel" id="sls-geo-panel">
    <summary><i class="fa fa-globe" aria-hidden="true"></i> <?php echo _('Geographic audience'); ?> <?php include __DIR__ . '/labs.php'; ?><span class="sls-geo-summary-note"><?php echo _('Select an area and review its saved locations'); ?></span></summary>
    <div class="sls-location-card-body">
        <p><?php echo _('Draw an area or enter its boundaries to create a fixed audience for an incident template, schedule, or announcement. Locations use their own reviewed coordinates or inherit the nearest parent’s position.'); ?></p>
        <div class="sls-geo-workspace">
            <div class="sls-geo-map-column">
                <div class="sls-geo-toolbar" role="group" aria-label="<?php echo $escape(_('Map controls')); ?>">
                    <div class="btn-group"><button type="button" class="btn btn-default btn-sm" id="sls-geo-pan" aria-pressed="true"><i class="fa fa-hand-paper-o" aria-hidden="true"></i> <?php echo _('Pan'); ?></button><button type="button" class="btn btn-default btn-sm" id="sls-geo-draw" aria-pressed="false"><i class="fa fa-object-group" aria-hidden="true"></i> <?php echo _('Draw area'); ?></button></div>
                    <div class="btn-group"><button type="button" class="btn btn-default btn-sm" id="sls-geo-zoom-in" aria-label="<?php echo $escape(_('Zoom in')); ?>"><i class="fa fa-plus" aria-hidden="true"></i></button><button type="button" class="btn btn-default btn-sm" id="sls-geo-zoom-out" aria-label="<?php echo $escape(_('Zoom out')); ?>"><i class="fa fa-minus" aria-hidden="true"></i></button></div>
                    <button type="button" class="btn btn-default btn-sm" id="sls-geo-fit"><i class="fa fa-crosshairs" aria-hidden="true"></i> <?php echo _('Fit locations'); ?></button>
                    <button type="button" class="btn btn-default btn-sm" id="sls-geo-fit-area" disabled><i class="fa fa-search-plus" aria-hidden="true"></i> <?php echo _('Fit area'); ?></button>
                </div>
                <svg id="sls-geo-map" viewBox="0 0 360 180" preserveAspectRatio="none" tabindex="0" role="group" aria-label="<?php echo $escape(_('Saved location map')); ?>" aria-describedby="sls-geo-map-help">
                    <image href="modules/slsmassnotifyserver/assets/location-basemap.svg" x="0" y="0" width="360" height="180"/>
                    <g id="sls-geo-grid" aria-hidden="true"></g><g id="sls-geo-area" aria-hidden="true"></g><g id="sls-geo-markers"></g>
                </svg>
                <p class="help-block" id="sls-geo-map-help"><?php echo _('Pan by dragging or with arrow keys; + and − zoom. Draw area selects a rectangle. Enter exact boundaries for keyboard access or an area crossing the date line. Escape cancels a drag.'); ?></p>
                <div class="sls-geo-legend"><span><i class="fa fa-circle sls-geo-current" aria-hidden="true"></i> <?php echo _('Reviewed location'); ?></span><span><i class="fa fa-circle sls-geo-selected" aria-hidden="true"></i> <?php echo _('Inside area'); ?></span><span><i class="fa fa-circle sls-geo-stale" aria-hidden="true"></i> <?php echo _('Review needed'); ?></span></div>
                <p class="sls-geo-attribution"><?php echo _('Offline land overview:'); ?> <a href="https://www.naturalearthdata.com/about/terms-of-use/" target="_blank" rel="noopener noreferrer">Natural Earth</a>. <?php echo _('No street detail. Coordinates stay on this PBX; no map service or device tracking is used.'); ?></p>
            </div>
            <div class="sls-geo-selection">
                <h3><i class="fa fa-crop" aria-hidden="true"></i> <?php echo _('Area boundaries'); ?></h3>
                <div class="sls-geo-bounds">
                    <?php foreach (['north' => ['North latitude', 90], 'south' => ['South latitude', 90], 'west' => ['West longitude', 180], 'east' => ['East longitude', 180]] as $key => $definition): ?>
                    <div class="form-group"><label for="sls-geo-<?php echo $key; ?>"><?php echo _($definition[0]); ?></label><input class="form-control" id="sls-geo-<?php echo $key; ?>" type="number" min="-<?php echo $definition[1]; ?>" max="<?php echo $definition[1]; ?>" step="any" inputmode="decimal" placeholder="<?php echo $definition[1] === 90 ? '-90 … 90' : '-180 … 180'; ?>"></div>
                    <?php endforeach; ?>
                </div>
                <p class="help-block"><?php echo _('West greater than east selects across the date line. Boundary points are included.'); ?></p>
                <div class="form-group sls-geo-age"><label for="sls-geo-age"><?php echo _('Coordinates reviewed within'); ?></label><div class="input-group"><input class="form-control" id="sls-geo-age" type="number" min="1" max="3650" step="1" value="365"><span class="input-group-addon"><?php echo _('days'); ?></span></div><span class="help-block"><?php echo _('Choose the review age appropriate for this audience. Older, future-dated, or missing positions are excluded and listed for review.'); ?></span></div>
                <p id="sls-geo-map-status" class="sls-geo-map-status" role="status"></p>
            </div>
        </div>
        <fieldset class="sls-location-channel-options"><legend><?php echo _('Recipient types'); ?></legend><div id="sls-geo-channels"></div></fieldset>
        <button type="button" class="btn btn-default" id="sls-geo-preview"><i class="fa fa-search" aria-hidden="true"></i> <?php echo _('Review geographic audience'); ?></button>
        <div id="sls-geo-result" hidden>
            <h3 id="sls-geo-result-heading"></h3>
            <div id="sls-geo-review-locations"></div>
            <div id="sls-geo-review-members"></div>
            <div class="alert alert-warning" id="sls-geo-unavailable" hidden></div>
            <label class="sls-location-check sls-geo-confirm"><input type="checkbox" id="sls-geo-confirm"> <?php echo _('I reviewed the included and excluded locations and the exact recipients below.'); ?></label>
            <div class="sls-location-create"><div class="form-group"><label for="sls-geo-name"><?php echo _('Audience group name'); ?></label><input class="form-control" id="sls-geo-name" maxlength="64" autocomplete="off"><span class="help-block"><?php echo _('Creates a new static group. Later coordinate changes do not update it. Creating a group sends no notifications.'); ?></span></div><button type="button" class="btn btn-primary" id="sls-geo-create" disabled><i class="fa fa-plus-circle" aria-hidden="true"></i> <?php echo _('Create group'); ?></button></div>
        </div>
    </div>
</details>
