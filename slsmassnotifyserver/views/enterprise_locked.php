<?php
$endpoint='config.php?display=slsmassnotifyserver_enterprise';$csrf=$csrf_token;
$safetyFeature='enterprise_labs';$safetyAutomatic=true;$safetySelectors='';$safetyReload=true;
?>
<div class="container-fluid" id="sls-enterprise-locked">
    <h1><i class="fa fa-flask" aria-hidden="true"></i> SLS Enterprise Labs</h1>
    <p><span class="label label-danger">Inactive</span></p>
    <p>The required risk warning opens automatically. After accepting it, choose and enable individual features. Acceptance alone leaves Labs inactive.</p>
    <noscript><p class="alert alert-warning">JavaScript is required to display and acknowledge the warning. Return to the <a href="config.php?display=index">PBX dashboard</a>.</p></noscript>
    <?php include __DIR__.'/labs_danger.php'; ?>
</div>
