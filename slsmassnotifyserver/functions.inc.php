<?php
// Expose the dial-in extension to FreePBX's standard conflict registry.
function slsmassnotifyserver_check_extensions($extensions = true)
{
    return \FreePBX::Slsmassnotifyserver()->getLivePagingExtensionUsage($extensions);
}

function slsmassnotifyserver_destinations()
{
    return \FreePBX::Slsmassnotifyserver()->getLivePagingExternalDestinations();
}

function slsmassnotifyserver_getdestinfo($dest)
{
    if ($dest !== 'sls-live-paging-external,s,1') { return false; }
    $destinations = slsmassnotifyserver_destinations();
    return $destinations ? ['description' => $destinations[0]['description'],
        'edit_url' => 'config.php?display=slsmassnotifyserver_paging'] : [];
}
