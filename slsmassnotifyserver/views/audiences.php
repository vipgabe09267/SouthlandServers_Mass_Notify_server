<?php require_once __DIR__.'/../AudienceLimits.php'; require_once __DIR__.'/../api/sls-mass-notify/person-profiles.php'; ?>
<section id="sls-audiences" role="tabpanel" aria-labelledby="sls-directory-tab-audiences" hidden>
    <div class="sls-location-card-heading"><div><h2><i class="fa fa-users" aria-hidden="true"></i> <?php echo _('People and saved audiences'); ?></h2><p class="help-block"><?php echo _('Collect a person’s devices or a team’s recipients in one audience. A shared webhook can belong to several audiences.'); ?></p></div><button type="button" class="btn btn-primary" id="sls-audience-add"><i class="fa fa-plus" aria-hidden="true"></i> <?php echo _('Add audience'); ?></button></div>
    <div class="sls-location-card-body">
      <label for="sls-audience-search"><?php echo _('Find an audience'); ?></label>
      <input id="sls-audience-search" class="form-control" type="search" maxlength="100" placeholder="<?php echo $escape(_('Search by name')); ?>">
      <p id="sls-audience-count" class="help-block" role="status" aria-live="polite" data-maximum="<?php echo \SLS\MassNotify\AudienceLimits::SAVED; ?>"></p>
      <p id="sls-audience-budget" class="help-block" role="status" aria-live="polite"></p>
    </div>
    <div class="sls-audience-grid" id="sls-audience-list"></div>
    <button type="button" class="btn btn-default" id="sls-audience-more" hidden><?php echo _('Show more audiences'); ?></button>
    <form class="sls-location-card" id="sls-audience-form" hidden>
        <div class="sls-location-card-heading"><h2 id="sls-audience-title"></h2><span class="sls-location-badge" id="sls-audience-total" aria-live="polite"></span></div>
        <div class="sls-location-card-body">
            <div class="form-group"><label for="sls-audience-name"><?php echo _('Audience name'); ?></label><input type="text" class="form-control" id="sls-audience-name" required maxlength="64" placeholder="<?php echo $escape(_('For example, Gabe Williams or Everyone')); ?>"><p class="help-block"><?php echo _('Choose devices below. Membership is saved explicitly; future alerts use the audience active when they are submitted.'); ?></p></div>
            <details class="form-group"><summary><?php echo _('Person profile'); ?> <?php include __DIR__.'/labs.php'; ?></summary>
                <div class="checkbox"><label><input type="checkbox" id="sls-audience-person-enabled"> <?php echo _('This audience contains one person’s devices'); ?></label></div>
                <div id="sls-audience-person-fields" hidden>
                    <label for="sls-audience-person"><?php echo _('Linked directory person'); ?></label><select class="form-control" id="sls-audience-person"></select>
                    <p id="sls-audience-person-candidates" class="help-block"></p>
                    <label for="sls-audience-language"><?php echo _('Preferred language'); ?></label><select class="form-control" id="sls-audience-language"><?php foreach (\SLS\MassNotify\PersonProfiles::LANGUAGES as $code=>$label): ?><option value="<?php echo $escape($code); ?>"><?php echo $escape($label); ?></option><?php endforeach; ?></select>
                    <p class="help-block"><?php echo _('A stored preference for reviewed templates and integrations. Messages are not translated automatically.'); ?></p>
                    <div class="checkbox"><label><input type="checkbox" id="sls-audience-ownership"> <?php echo _('I verified that the selected devices and contact methods belong to this person'); ?></label></div>
                    <p class="help-block"><?php echo _('Imported numbers are suggestions. Add approved recipients under Delivery first; SMS still requires its own recorded consent. Deactivating a linked directory person blocks this saved audience until reviewed.'); ?></p>
                </div>
            </details>
            <div id="sls-audience-members"></div>
        </div>
        <footer class="sls-location-card-footer"><div class="sls-location-actions"><button type="submit" class="btn btn-primary"><i class="fa fa-save" aria-hidden="true"></i> <?php echo _('Save audience'); ?></button><button type="button" class="btn btn-default" id="sls-audience-close"><?php echo _('Cancel'); ?></button></div><p class="help-block"><?php echo _('Saved changes require Apply Config.'); ?></p></footer>
    </form>
</section>
