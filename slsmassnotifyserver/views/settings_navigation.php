<style>
#sls-settings-navigation{display:flex;flex-wrap:wrap;gap:8px;padding:12px;margin:12px 0 20px;background:#f8fafc;border:1px solid #dfe5ec;border-radius:9px}
#sls-settings-navigation button{display:flex;align-items:center;gap:8px;padding:10px 14px;border:1px solid transparent;border-radius:6px;background:transparent;color:#475569;font-size:13px;font-weight:600}
#sls-settings-navigation button:hover{background:#e9eff6}
#sls-settings-navigation button[aria-selected=true]{color:#1f4e79;border-color:#b9cce2;background:#eaf2fb}
#sls-settings-navigation button:focus-visible{outline:2px solid #337ab7;outline-offset:2px}
.sls-settings-section[hidden],[data-settings-related][hidden]{display:none!important}
.sls-settings-section{padding:4px 18px 20px;border:1px solid #dfe5ec;border-radius:9px;background:#fff;min-width:0}
.sls-settings-section>.sls-settings-heading:first-child{margin-top:18px}
.sls-capacity-preview{padding:16px;margin:12px 0 22px;border:1px solid #dfe5ec;border-radius:8px;background:#f8fafc}
.sls-capacity-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:16px 0}
.sls-capacity-metrics[hidden]{display:none}.sls-capacity-metrics dt{font-size:12px;color:#64748b;font-weight:500}.sls-capacity-metrics dd{margin-top:5px;font-size:19px;font-weight:700;color:#334155}
.sls-capacity-preview ul{color:#9a3412;padding-left:20px;line-height:1.6}
.sls-capacity-result{display:flex;align-items:center;gap:5px;font-size:12px;font-weight:600;margin-top:6px;line-height:1.5}
.sls-capacity-pass{color:#16803c}.sls-capacity-fail{color:#dc2626}.sls-capacity-unknown{color:#64748b}
@media(max-width:850px){.sls-capacity-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
#sls-other-settings-form .sls-save-actions{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-top:16px}
@media(max-width:600px){#sls-settings-navigation{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:4px;padding:7px}#sls-settings-navigation button{padding:10px 8px;font-size:12px}.sls-settings-section{padding:2px 12px 16px}}
</style>
<nav id="sls-settings-navigation" role="tablist" aria-label="<?php echo htmlspecialchars(_('General Settings categories')); ?>" hidden>
<?php foreach (['phones'=>['phone',_('Phones and address')], 'channels'=>['paper-plane',_('Delivery providers')],
    'audio'=>['volume-up',_('Speech and display')], 'desktops'=>['desktop',_('Desktop clients')],
    'security'=>['shield',_('API and security')], 'maintenance'=>['wrench',_('Updates and storage')]] as $key=>$tab): ?>
<button type="button" role="tab" id="sls-settings-tab-<?php echo $key; ?>" aria-controls="sls-settings-<?php echo $key; ?>" aria-selected="false" tabindex="-1" data-settings-tab="<?php echo $key; ?>"><i class="fa fa-<?php echo $tab[0]; ?>" aria-hidden="true"></i> <?php echo $tab[1]; ?></button>
<?php endforeach; ?>
</nav>
<script>
(function () {
    function initialize() {
        var nav=document.getElementById('sls-settings-navigation'), form=document.getElementById('sls-other-settings-form');
        if (!nav || !form || nav.dataset.ready) return;
        var tabs=Array.from(nav.querySelectorAll('[data-settings-tab]')), panels=Array.from(form.querySelectorAll('[data-settings-section]'));
        if (tabs.length!==panels.length) return;
        nav.dataset.ready='1'; nav.hidden=false;
        function select(key,focus,remember) {
            if (!tabs.some(function(t){return t.dataset.settingsTab===key;})) key='phones';
            panels.forEach(function(p){p.hidden=p.dataset.settingsSection!==key;p.setAttribute('role','tabpanel');});
            document.querySelectorAll('[data-settings-related]').forEach(function(p){p.hidden=p.dataset.settingsRelated!==key;});
            tabs.forEach(function(t){var active=t.dataset.settingsTab===key;t.setAttribute('aria-selected',String(active));t.tabIndex=active?0:-1;if(active&&focus)t.focus();});
            if (remember) history.replaceState(null,'','#settings-'+key);
        }
        tabs.forEach(function(t,i){
            t.addEventListener('click',function(){select(t.dataset.settingsTab,false,true);});
            t.addEventListener('keydown',function(e){
                var next=e.key==='ArrowRight'?(i+1)%tabs.length:e.key==='ArrowLeft'?(i+tabs.length-1)%tabs.length:e.key==='Home'?0:e.key==='End'?tabs.length-1:-1;
                if(next>=0){e.preventDefault();select(tabs[next].dataset.settingsTab,true,true);}
            });
        });
        // Hidden panels keep their enabled inputs in the one settings form.
        // Open the relevant category before browser validation focuses a field.
        form.addEventListener('invalid',function(e){var panel=e.target.closest('[data-settings-section]');if(panel)select(panel.dataset.settingsSection,false,true);},true);
        select(location.hash.replace(/^#settings-/,''),false,false);
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initialize);else initialize();
})();
</script>
