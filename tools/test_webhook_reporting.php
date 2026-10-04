<?php
declare(strict_types=1);
require __DIR__.'/../slsmassnotifyserver/bin/sls_mass_notify/sls_announcement_jobs.php';
function webhookCheck(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$old = ['state'=>'failed','failure_category'=>'channel_submission_failed','success'=>false,
    'receipts'=>[['channel'=>'webhook','target'=>'hook_one','state'=>'uncertain','retryable'=>false,'detail'=>'Webhook: delivery_unconfirmed']]];
$before = json_encode($old);
$projection = SlsAnnouncementJobStore::deliveryProjection($old);
webhookCheck($projection['state']==='complete' && $projection['success'] && !$projection['receipts'][0]['needs_attention'], 'One-way response uncertainty still raised a delivery error.');
webhookCheck(str_contains($projection['receipts'][0]['detail'],'not confirmed') && !str_contains($projection['receipts'][0]['detail'],'Accepted'), 'Unconfirmed response was presented as accepted.');
webhookCheck(json_encode($old)===$before, 'Projection rewrote historical receipts.');
webhookCheck(SlsAnnouncementJobStore::failureChannels($old['receipts'])===[], 'Webhook uncertainty remains in the fault summary.');
foreach ([408,429,500,503] as $status) {
    $row = $old['receipts'][0] + ['http_status'=>$status,'failure_code'=>'http_failure'];
    $result = SlsAnnouncementJobStore::deliveryProjection(array_replace($old,['receipts'=>[$row]]));
    webhookCheck($result['state']==='failed' && $result['receipts'][0]['needs_attention'], 'An HTTP failure was hidden.');
    webhookCheck(count(SlsAnnouncementJobStore::failureChannels([$row]))===1, 'HTTP failure disappeared from diagnostics.');
}
foreach (['tls_failure','dns_failure','network_failure','destination_unavailable'] as $code) {
    $row = array_replace($old['receipts'][0],['state'=>'failed','failure_code'=>$code]);
    webhookCheck(SlsAnnouncementJobStore::receiptNeedsAttention($row), 'A definite transmission failure was hidden.');
}
foreach (['desktop','audio','external_voice','sip_notify','sms','email'] as $channel) {
    $row = array_replace($old['receipts'][0],['channel'=>$channel]);
    $result = SlsAnnouncementJobStore::deliveryProjection(array_replace($old,['receipts'=>[$old['receipts'][0],$row]]));
    webhookCheck($result['state']==='failed' && $result['receipts'][1]['needs_attention'], 'Another channel failure was hidden.');
}
$workerFailure = array_replace($old,['failure_category'=>'worker_runtime_failed']);
webhookCheck(SlsAnnouncementJobStore::deliveryProjection($workerFailure)['state']==='failed', 'Worker failure was treated as an unconfirmed webhook.');
$accepted = ['channel'=>'webhook','state'=>'accepted','http_status'=>204];
webhookCheck(!SlsAnnouncementJobStore::receiptNeedsAttention($accepted), 'Accepted webhook required a human receipt.');
echo "Webhook accepted/unconfirmed/HTTP failure projections, channel isolation and immutable history passed.\n";
