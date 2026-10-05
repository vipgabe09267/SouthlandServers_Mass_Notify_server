<?php
$phoneFormatLabels = ['yealink'=>'Yealink', 'yealink_text'=>'Yealink (text)', 'cisco'=>'Cisco', 'poly'=>'Poly',
    'polycom'=>'Polycom', 'grandstream'=>'Grandstream', 'fanvil'=>'Fanvil', 'snom'=>'Snom', 'aastra'=>'Aastra / Mitel',
    'mitel'=>'Mitel', 'sangoma'=>'Sangoma', 'avaya'=>'Avaya', 'vtech'=>'VTech', 'ale'=>'ALE', 'alcatel'=>'Alcatel', 'panasonic'=>'Panasonic'];
?>
<thead><tr><th><?php echo _('Extension'); ?></th><th><?php echo _('Phone format'); ?></th><th><?php echo _('Transport'); ?></th><th><?php echo _('User Agent'); ?></th></tr></thead>
<tbody>
<?php foreach ($endpointDiagnostics as $endpoint) {
    $devices = array_values(array_filter((array)($endpoint['devices'] ?? []), 'is_array'));
    if (!$devices) { $devices = [$endpoint]; }
    foreach ($devices as $index=>$device) {
        $format = (string)($device['format'] ?? 'unknown');
        $known = isset($phoneFormatLabels[$format]);
        $transport = strtolower((string)($device['transport'] ?? ''));
?>
<tr>
    <td><?php echo htmlspecialchars((string)($endpoint['extension'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
        <?php if ($index === 0 && count($devices) > 1) { ?><small class="text-muted">(<?php echo count($devices); ?>)</small><?php } ?>
    </td>
    <td><span class="label <?php echo $known ? 'label-info' : 'label-warning'; ?>"><?php echo $known ? htmlspecialchars($phoneFormatLabels[$format], ENT_QUOTES, 'UTF-8') : _('Unknown'); ?></span>
        <?php if (!empty($device['override'])) { ?><span class="label label-default"><?php echo _('override'); ?></span><?php } ?>
    </td>
    <td><?php echo in_array($transport, ['udp','tcp','tls','ws','wss'], true) ? strtoupper($transport) : _('Unresolved'); ?></td>
    <td><?php echo htmlspecialchars((string)($device['user_agent'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
</tr>
<?php } } ?>
</tbody>
