<?php $readinessEscape = static function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }; ?>
<style>
.sls-readiness { margin:24px 0; max-width:960px; }
.sls-readiness-heading { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px; }
.sls-readiness-heading h3 { margin:0; font-size:20px; }
.sls-readiness-actions { display:flex; flex-wrap:wrap; gap:8px; }
.sls-readiness-description { margin:12px 0; color:#475569; line-height:1.5; }
.sls-readiness-status { margin:12px 0 0; font-weight:600; }
.sls-readiness-results { margin-top:14px; }
.sls-readiness-checks { list-style:none; margin:0; padding:0; max-height:480px; overflow:auto; border:1px solid #dce2e8; border-radius:8px; }
.sls-readiness-check { display:grid; grid-template-columns:22px minmax(140px,1fr) 2fr; gap:10px; padding:12px; border-bottom:1px solid #e2e8f0; line-height:1.5; overflow-wrap:anywhere; }
.sls-readiness-check:last-child { border-bottom:0; }
.sls-readiness-check i { margin-top:3px; }
.sls-readiness-check p { margin:0; color:#475569; }
.sls-readiness-check .sls-check-ok { color:#15803d; }
.sls-readiness-check .sls-check-warning { color:#92400e; }
.sls-readiness-check .sls-check-unknown { color:#475569; }
.sls-readiness-scope { margin:12px 0 0; color:#475569; font-size:13px; line-height:1.5; }
@media(max-width:600px) { .sls-readiness-check { grid-template-columns:22px 1fr; } .sls-readiness-check p { grid-column:2; } }
</style>
<section class="sls-manager-card sls-readiness" id="sls-readiness" aria-labelledby="sls-readiness-title">
    <div class="sls-readiness-heading">
        <h3 id="sls-readiness-title"><i class="fa fa-stethoscope text-primary" aria-hidden="true"></i> <?php echo _('Deployment readiness'); ?> <?php include __DIR__ . '/labs.php'; ?></h3>
        <div class="sls-readiness-actions">
            <button type="button" class="btn btn-primary" id="sls-readiness-check" aria-controls="sls-readiness-results"><i class="fa fa-refresh" aria-hidden="true"></i> <?php echo _('Check now'); ?></button>
            <button type="button" class="btn btn-default" id="sls-readiness-download" disabled><i class="fa fa-download" aria-hidden="true"></i> <?php echo _('Download report'); ?></button>
        </div>
    </div>
    <p class="sls-readiness-description"><?php echo _('Check local runtime health, queue diagnostics, maintenance activity, clock synchronization and the HTTPS certificate currently served by the PBX. These checks read current state and send no notifications.'); ?></p>
    <p class="sls-readiness-status" id="sls-readiness-status" role="status" aria-live="polite"></p>
    <div class="sls-readiness-results" id="sls-readiness-results" hidden>
        <ul class="sls-readiness-checks" id="sls-readiness-checks" aria-label="<?php echo $readinessEscape(_('Readiness check results')); ?>"></ul>
        <p class="sls-readiness-scope" id="sls-readiness-scope"></p>
    </div>
    <?php include __DIR__ . '/device_acceptance.php'; ?>
</section>
<script>
(function(){
    'use strict';
    var root=document.getElementById('sls-readiness'),button=document.getElementById('sls-readiness-check');
    if(!root||!button)return;
    var download=document.getElementById('sls-readiness-download'),status=document.getElementById('sls-readiness-status');
    var results=document.getElementById('sls-readiness-results'),list=document.getElementById('sls-readiness-checks'),report=null;
    button.addEventListener('click',function(){
        if(button.disabled)return;
        button.disabled=true;download.disabled=true;root.setAttribute('aria-busy','true');
        status.textContent=<?php echo json_encode(_('Checking the PBX…')); ?>;
        var data=new FormData();data.set('slsmassnotifyserver_action','deployment_readiness');
        data.set('slsmassnotifyserver_csrf',<?php echo json_encode((string)($csrfToken ?? $csrf_token ?? '')); ?>);
        fetch('config.php?display=slsmassnotifyserver',{method:'POST',credentials:'same-origin',body:data,cache:'no-store'})
        .then(function(response){return response.json().catch(function(){throw new Error(<?php echo json_encode(_('The PBX returned an unreadable readiness response. Reload the page and check the PHP error log. HTTP status:')); ?>+' '+response.status);}).then(function(body){
            if(!response.ok||!body.success)throw new Error(body.message||<?php echo json_encode(_('The PBX rejected the readiness check. Reload the page and retry.')); ?>);
            return body.report;
        });}).then(function(value){
            if(!value||value.schema!=='sls-deployment-readiness-v1'||!Array.isArray(value.checks)||value.checks.length>69)throw new Error(<?php echo json_encode(_('The PBX returned an invalid readiness report. Check module diagnostics.')); ?>);
            report=value;list.textContent='';
            value.checks.forEach(function(check){
                var row=document.createElement('li'),icon=document.createElement('i'),label=document.createElement('strong'),detail=document.createElement('p');
                row.className='sls-readiness-check';
                var state=check.state==='ok'?'ok':check.state==='warning'?'warning':'unknown';
                icon.className='fa '+(state==='ok'?'fa-check-circle':state==='warning'?'fa-exclamation-triangle':'fa-question-circle')+' sls-check-'+state;
                icon.setAttribute('aria-hidden','true');label.textContent=String(check.label||'');detail.textContent=String(check.detail||'');
                row.appendChild(icon);row.appendChild(label);row.appendChild(detail);list.appendChild(row);
            });
            var time=new Date(value.generated_at);var checked=isNaN(time.getTime())?String(value.generated_at):time.toLocaleString();
            status.textContent=(value.operational_ready?<?php echo json_encode(_('Local operational checks passed.')); ?>:<?php echo json_encode(_('Review the highlighted or unverified checks.')); ?>)+' '+checked;
            document.getElementById('sls-readiness-scope').textContent=String(value.verification_scope||'');
            results.hidden=false;download.disabled=false;
            root.dispatchEvent(new CustomEvent('sls:readiness-checked',{detail:value}));
        }).catch(function(error){report=null;results.hidden=true;status.textContent=error.message||<?php echo json_encode(_('The readiness check could not complete. Check the PBX connection and PHP error log, then retry.')); ?>;})
        .finally(function(){button.disabled=false;root.setAttribute('aria-busy','false');});
    });
    download.addEventListener('click',function(){
        if(!report)return;
        var url=URL.createObjectURL(new Blob([JSON.stringify(report,null,2)+'\n'],{type:'application/json'}));
        var link=document.createElement('a');link.href=url;link.download='sls-deployment-readiness.json';link.click();
        setTimeout(function(){URL.revokeObjectURL(url);},1000);
    });
})();
</script>
