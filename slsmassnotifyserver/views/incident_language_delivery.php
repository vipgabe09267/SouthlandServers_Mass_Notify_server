<?php if (!empty($operation['language_jobs'])): ?>
<div class="sls-inc-table-wrap"><table><thead><tr><th>Message language</th><th>Recipients</th><th>Submission</th><th>Job or review</th></tr></thead><tbody>
<?php foreach ($operation['language_jobs'] as $batch): ?>
<tr><td><?php echo $e($batch['locale'] === '' ? 'Fallback wording' : $batch['locale']); ?></td><td><?php
    $counts=[];
    foreach (\SLS\MassNotify\IncidentLanguages::CHANNELS as $channel) {
        $count=count($batch['delivery'][$channel] ?? []);
        if ($count) { $counts[]=str_replace('_',' ',$channel).': '.$count; }
    }
    echo $e(implode(' · ',$counts));
?></td><td><?php echo $e(str_replace('_',' ',$batch['state'])); ?><?php if (!empty($batch['error'])): ?><p class="sls-inc-muted"><?php echo $e($batch['error']); ?></p><?php endif; ?></td><td><?php echo $e($batch['job_id'] ?: ($batch['review_id'] ?? '—')); ?></td></tr>
<?php if (isset($batch['delivery_result'])): ?>
<tr><td colspan="4"><table><thead><tr><th>Channel</th><th>Destination</th><th>Result</th><th>Evidence</th></tr></thead><tbody>
<?php foreach ($batch['delivery_result']['receipts'] ?? [] as $receipt): ?><tr><td><?php echo $e($receipt['channel'] ?? ''); ?></td><td><?php echo $e($receipt['target'] ?? ''); ?></td><td><?php echo $e($receipt['state'] ?? 'unknown'); ?></td><td><?php echo $e($receipt['detail'] ?? ''); ?></td></tr><?php endforeach; ?>
</tbody></table></td></tr>
<?php endif; endforeach; ?>
</tbody></table></div>
<?php endif; ?>
