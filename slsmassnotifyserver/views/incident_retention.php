<?php $retention = $retention ?? \SLS\MassNotify\IncidentConfig::retention([]); ?>
<details class="sls-inc-card">
    <summary style="cursor:pointer;font-weight:600"><i class="fa fa-archive text-primary" aria-hidden="true"></i> Incident archival <?php include __DIR__ . '/labs.php'; ?></summary>
    <p style="margin-top:14px">Move closed incidents and missed drills out of the working list after a saved age. Archived reports remain readable, and their request identities permanently prevent replay. Open incidents and uncertain submissions are preserved.</p>
    <div class="sls-inc-actions">
        <span class="sls-inc-badge"><?php echo $retention['enabled'] ? 'Enabled' : 'Off'; ?></span>
        <span class="sls-inc-muted"><?php echo $e($retention['after_days']); ?> days · <?php echo $e($retention['max_archive_mib']); ?> MiB allocation · up to 20,000 archived reports</span>
        <button type="button" class="btn btn-default btn-sm" id="sls-inc-retention"><i class="fa fa-sliders" aria-hidden="true"></i> Configure archival</button>
    </div>
    <p class="sls-inc-help">Saving requires Apply Config. Archival runs in bounded batches through the existing worker. No reports are automatically deleted; a full archive preserves active records and reports an error. Allow additional free workspace for the database journal and backup. Native recovery retains historical evidence without activating old incident workflows.</p>
</details>
