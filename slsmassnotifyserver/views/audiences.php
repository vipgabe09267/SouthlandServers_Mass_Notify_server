<section id="sls-audiences" role="tabpanel" aria-labelledby="sls-directory-tab-audiences" hidden>
    <div class="sls-location-card-heading"><div><h2><i class="fa fa-users" aria-hidden="true"></i> <?php echo _('People and saved audiences'); ?></h2><p class="help-block"><?php echo _('Collect a person’s devices or a team’s recipients in one audience. A shared webhook can belong to several audiences.'); ?></p></div><button type="button" class="btn btn-primary" id="sls-audience-add"><i class="fa fa-plus" aria-hidden="true"></i> <?php echo _('Add audience'); ?></button></div>
    <div class="sls-audience-grid" id="sls-audience-list"></div>
    <form class="sls-location-card" id="sls-audience-form" hidden>
        <div class="sls-location-card-heading"><h2 id="sls-audience-title"></h2><span class="sls-location-badge" id="sls-audience-total" aria-live="polite"></span></div>
        <div class="sls-location-card-body">
            <div class="form-group"><label for="sls-audience-name"><?php echo _('Audience name'); ?></label><input type="text" class="form-control" id="sls-audience-name" required maxlength="64" placeholder="<?php echo $escape(_('For example, Gabe Williams or Everyone')); ?>"><p class="help-block"><?php echo _('Choose devices below. Membership is saved explicitly; future alerts use the audience active when they are submitted.'); ?></p></div>
            <div id="sls-audience-members"></div>
        </div>
        <footer class="sls-location-card-footer"><div class="sls-location-actions"><button type="submit" class="btn btn-primary"><i class="fa fa-save" aria-hidden="true"></i> <?php echo _('Save audience'); ?></button><button type="button" class="btn btn-default" id="sls-audience-close"><?php echo _('Cancel'); ?></button></div><p class="help-block"><?php echo _('Saved changes require Apply Config.'); ?></p></footer>
    </form>
</section>
