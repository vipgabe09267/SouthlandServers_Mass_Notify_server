<?php
$phoneFormatLabels = ['yealink'=>'Yealink', 'yealink_text'=>'Yealink (text)', 'yealink_image'=>'Yealink (always image)', 'cisco'=>'Cisco', 'poly'=>'Poly',
    'polycom'=>'Polycom', 'grandstream'=>'Grandstream', 'fanvil'=>'Fanvil', 'snom'=>'Snom', 'aastra'=>'Aastra / Mitel',
    'mitel'=>'Mitel', 'sangoma'=>'Sangoma', 'avaya'=>'Avaya', 'vtech'=>'VTech', 'ale'=>'ALE', 'alcatel'=>'Alcatel', 'panasonic'=>'Panasonic'];
$phoneEscape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<thead><tr><th><?php echo _('Extension'); ?></th><th><?php echo _('Phone formats'); ?></th><th><?php echo _('Transports'); ?></th><th><?php echo _('User Agents'); ?></th></tr></thead>
<tbody>
<?php foreach ($endpointDiagnostics as $endpoint) {
    $devices = array_values(array_filter((array)($endpoint['devices'] ?? []), 'is_array'));
    if (!$devices) { $devices = [$endpoint]; }
    $formats = []; $transports = []; $agents = [];
    foreach ($devices as $index => $device) {
        $format = (string)($device['format'] ?? 'unknown');
        $agent = (string)($device['user_agent'] ?? '');
        $known = isset($phoneFormatLabels[$format]);
        $label = $phoneFormatLabels[$format] ?? (stripos($agent, 'acrobits') !== false ? 'Acrobits (unknown)' : _('Unknown'));
        if (!empty($device['override'])) { $label .= ' (' . _('override') . ')'; }
        $formats[] = ['label'=>$label, 'known'=>$known];
        $transport = strtolower((string)($device['transport'] ?? ''));
        $transports[] = in_array($transport, ['udp','tcp','tls','ws','wss'], true) ? strtoupper($transport) : _('Unresolved');
        $agents[] = $agent !== '' ? $agent : _('Not reported');
    }
?>
<tr>
    <td><?php echo $phoneEscape($endpoint['extension'] ?? ''); ?><?php if (count($devices) > 1) { ?> <small class="text-muted">(<?php echo count($devices); ?>)</small><?php } ?></td>
    <td><?php foreach ($formats as $index => $format) { ?><span class="sls-phone-contact"><span class="label <?php echo $format['known'] ? 'label-info' : 'label-warning'; ?>"><?php echo $phoneEscape($format['label']); ?></span><sub><?php echo $index + 1; ?></sub></span><?php } ?></td>
    <td><?php foreach ($transports as $index => $transport) { ?><span class="sls-phone-contact"><?php echo $phoneEscape($transport); ?><sub><?php echo $index + 1; ?></sub></span><?php } ?></td>
    <td><?php foreach ($agents as $index => $agent) { ?><span class="sls-phone-contact"><?php echo $phoneEscape($agent); ?><sub><?php echo $index + 1; ?></sub></span><?php } ?></td>
</tr>
<?php } ?>
</tbody>
