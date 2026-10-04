<style>
.sls-test-receipts { margin:12px 0; padding:14px 16px; border:1px solid #dfe5ec; border-radius:8px; background:transparent; }
.sls-test-receipt-summary { margin:0 0 10px; font-weight:600; }
.sls-test-receipts details { margin-bottom:10px; }
.sls-test-receipts summary { cursor:pointer; }
.sls-test-receipts ul { max-height:240px; overflow:auto; padding:8px 0; list-style:none; }
.sls-test-receipts li { padding:5px 0; overflow-wrap:anywhere; }
.sls-test-receipts .sls-receipt-confirmed .fa { color:#15803d; }
.sls-test-receipts .help-block { margin-top:10px; font-size:12px; }
</style>
<script><?php readfile(__DIR__.'/test_delivery.js'); ?></script>
<?php if (!empty($testResult['delivery_ticket'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  var lightning = document.getElementById('sls-lightning-test-form');
  window.SlsTestDeliveryReports.start(<?php echo json_encode($testResult, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR); ?>,
    lightning || document.getElementById('sls-test-form'), lightning ? 'sls-lightning-test-result' : 'sls-test-result');
});
</script>
<?php endif; ?>
