<details class="sls-help-section">
    <summary><i class="fa fa-stethoscope" aria-hidden="true"></i> <?php echo _('Installation compatibility'); ?></summary>
    <div class="sls-help-section-body">
        <p><?php echo _('Check the served PHP runtime, Asterisk, authenticated phone collector, resource allocation and installation stage. This report does not change configuration or contact notification providers.'); ?></p>
        <p><code>sudo slsconsole doctor</code> · <code>sudo slsconsole doctor --json</code></p>
        <p class="help-block"><?php echo _('The supported installer profile is Debian 12 with FreePBX 17 on amd64 using standard paths. Run the CLI doctor as root to verify publisher signatures, installed inventories and protected recovery evidence.'); ?></p>
        <?php if (!empty($can_export_installation_profile)) { ?>
        <div class="btn-toolbar" role="group" aria-label="<?php echo htmlspecialchars(_('Installation report actions')); ?>">
            <button type="button" id="sls-install-profile-check" class="btn btn-default"><i class="fa fa-search" aria-hidden="true"></i> <?php echo _('Check compatibility'); ?></button>
            <button type="button" id="sls-install-profile-download" class="btn btn-default" disabled><i class="fa fa-download" aria-hidden="true"></i> <?php echo _('Download support report'); ?></button>
        </div>
        <p id="sls-install-profile-status" class="help-block" role="status" aria-live="polite"></p>
        <pre id="sls-install-profile-result" hidden style="max-height:360px;overflow:auto;white-space:pre-wrap"></pre>
        <script>
        (function(){
            var check=document.getElementById('sls-install-profile-check'),download=document.getElementById('sls-install-profile-download');
            var status=document.getElementById('sls-install-profile-status'),result=document.getElementById('sls-install-profile-result'),report=null;
            check.addEventListener('click',async function(){
                check.disabled=true;download.disabled=true;status.textContent='Checking the local installation…';
                var controller=new AbortController(),timer=setTimeout(function(){controller.abort();},15000);
                try{
                    var body=new URLSearchParams({slsmassnotifyserver_action:'installation_profile',slsmassnotifyserver_csrf:<?php echo json_encode($csrf_token ?? '', JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>});
                    var response=await fetch('config.php?display=slsmassnotifyserver_help',{method:'POST',credentials:'same-origin',cache:'no-store',body:body,signal:controller.signal});
                    var value=await response.json();if(!response.ok||!value.success)throw new Error(value.message||'The compatibility report is unavailable.');
                    report=JSON.stringify(value,null,2)+'\n';result.textContent=report;result.hidden=false;download.disabled=false;
                    status.textContent='Report ready. Review warnings before upgrading. The report excludes configuration values and credentials; review system paths before sharing it.';
                }catch(error){status.textContent=error.name==='AbortError'?'The check timed out. Run slsconsole doctor from the CLI.':error.message;}
                finally{clearTimeout(timer);check.disabled=false;}
            });
            download.addEventListener('click',function(){if(!report)return;var url=URL.createObjectURL(new Blob([report],{type:'application/json'})),link=document.createElement('a');link.href=url;link.download='sls-installation-profile.json';document.body.appendChild(link);link.click();link.remove();setTimeout(function(){URL.revokeObjectURL(url);},1000);});
        }());
        </script>
        <?php } ?>
    </div>
</details>
