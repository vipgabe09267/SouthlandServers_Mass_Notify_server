<?php
// Shared compact badge. Automated checks do not replace site acceptance.
if (empty($GLOBALS['sls_labs_style_loaded'])) {
    $GLOBALS['sls_labs_style_loaded'] = true;
?>
<style>.sls-labs{display:inline-flex;align-items:center;gap:5px;vertical-align:middle;margin-left:8px;padding:3px 8px;border:1px solid #d5c6ef;border-radius:12px;background:#f5f0fc;color:#5b397c;font:600 11px/1.4 sans-serif;white-space:nowrap;cursor:help}.sls-labs:focus{outline:2px solid #5b397c;outline-offset:2px}</style>
<?php } ?>
<span class="sls-labs" tabindex="0" title="<?php echo htmlspecialchars(_('Labs: site acceptance is still required. Validate this feature with your devices and providers before relying on it.'), ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-flask" aria-hidden="true"></i> <?php echo _('Labs'); ?></span>
