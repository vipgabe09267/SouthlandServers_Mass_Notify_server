<?php
$safetyEscape=static fn(string $value):string=>htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$safetySettings=['labs_safety'=>\SLS\MassNotify\LabsSafety::normalize($safety)];
$safetyData=['endpoint'=>$endpoint,'csrf'=>$csrf,'feature'=>$safetyFeature,'automatic'=>$safetyAutomatic,
    'selectors'=>$safetySelectors,'acknowledged'=>\SLS\MassNotify\LabsSafety::hasReceipt($safetySettings,$safetyFeature)];
?>
<link rel="stylesheet" href="modules/slsmassnotifyserver/views/labs_danger.css?v=<?= $safetyEscape(substr(hash_file('sha256',__DIR__.'/labs_danger.css'),0,16)) ?>">
<dialog id="sls-labs-danger" class="sls-ent-dialog sls-ent-danger" aria-labelledby="sls-labs-danger-title" aria-describedby="sls-labs-danger-body">
    <header><h2 id="sls-labs-danger-title"></h2></header>
    <div class="sls-ent-dialog-body">
        <p id="sls-labs-danger-body"></p>
        <p><em>This is a one time nag!</em></p>
        <label><input type="checkbox" id="sls-labs-danger-agree" disabled> <span id="sls-labs-danger-agreement"></span> <span id="sls-labs-danger-countdown">(5s)</span></label>
        <p id="sls-labs-danger-error" role="alert" hidden></p>
    </div>
    <footer><button type="button" id="sls-labs-danger-cancel" class="btn btn-default">Cancel</button><button type="button" id="sls-labs-danger-accept" class="btn btn-danger" disabled>Accept risk</button></footer>
</dialog>
<script type="application/json" id="sls-labs-danger-data"><?=json_encode($safetyData,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR)?></script>
<script src="modules/slsmassnotifyserver/views/labs_danger.js?v=<?= $safetyEscape(substr(hash_file('sha256',__DIR__.'/labs_danger.js'),0,16)) ?>" defer></script>
