<?php
declare(strict_types=1);
use SLS\MassNotify\BetaNotice;
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$revision = substr(hash_file('sha256', __DIR__ . '/beta_notice.js'), 0, 16);
$styleRevision = substr(hash_file('sha256', __DIR__ . '/beta_notice.css'), 0, 16);
?>
<link rel="stylesheet" href="modules/slsmassnotifyserver/views/beta_notice.css?v=<?= $escape($styleRevision) ?>">
<template id="sls-beta-notice-template" data-csrf="<?= $escape((string)$csrf_token) ?>">
    <dialog class="sls-beta-notice" aria-labelledby="sls-beta-notice-title" aria-describedby="sls-beta-notice-body">
        <div class="sls-beta-notice-brand">SLS Mass Notifications · Beta</div>
        <h2 id="sls-beta-notice-title"><?= $escape(BetaNotice::TITLE) ?></h2>
        <p id="sls-beta-notice-body"><strong><?= $escape(BetaNotice::BODY) ?></strong></p>
        <p class="sls-beta-notice-status" role="status" aria-live="polite">Preparing the five-second confirmation…</p>
        <button type="button" disabled>Agree and continue (5s)</button>
    </dialog>
</template>
<script src="modules/slsmassnotifyserver/views/beta_notice.js?v=<?= $escape($revision) ?>" defer></script>
