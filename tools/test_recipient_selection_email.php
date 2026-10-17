<?php
declare(strict_types=1);
require dirname(__DIR__) . '/slsmassnotifyserver/RecipientSelection.php';

use SLS\MassNotify\RecipientSelection;

$count = 0;
$check = static function (bool $ok, string $message) use (&$count): void {
    ++$count;
    if (!$ok) {
        throw new RuntimeException($message);
    }
};
$encode = static fn(array $value): string => json_encode($value, JSON_THROW_ON_ERROR);
$actions = [
    'send_announcement' => 'announcement_email_recipient_ids',
    'save_announcement_group' => 'group_email_recipient_ids',
    'save_scheduled_announcement' => 'schedule_email_recipient_ids',
];
$ids = [];
for ($index = 0; $index < 51; ++$index) {
    $ids[] = 'email_' . str_pad(dechex($index + 1), 24, '0', STR_PAD_LEFT);
}

foreach ($actions as $action => $field) {
    $check(
        in_array($field, RecipientSelection::FIELDS[$action], true),
        'Email field missing from recipient envelope.'
    );
    $envelope = array_fill_keys(RecipientSelection::FIELDS[$action], []);
    $input = [
        'slsmassnotifyserver_action' => $action,
        'sls_recipient_selection_present' => '1',
        'sls_recipient_selection_complete' => '1',
        'unrelated' => 'preserve',
        $field => ['arbitrary@example.com'],
    ];
    foreach ([[], [$ids[0]], array_slice($ids, 0, 50)] as $selected) {
        $envelope[$field] = $selected;
        $input['sls_recipient_selection_json'] = $encode($envelope);
        $decoded = RecipientSelection::decode($input);
        $check(
            $decoded[$field] === $selected && $decoded['unrelated'] === 'preserve',
            'Envelope lost email selection or accepted conflicting raw fields.'
        );
    }
    $invalidSelections = [
        $ids,
        ['raw@example.com'],
        ['email_short'],
        [strtoupper($ids[0])],
        [$ids[0] . "\n"],
        [123],
        [[$ids[0]]],
        ['id' => $ids[0]],
        null,
        'all',
    ];
    foreach ($invalidSelections as $bad) {
        $envelope[$field] = $bad;
        $input['sls_recipient_selection_json'] = $encode($envelope);
        $rejected = false;
        try {
            RecipientSelection::decode($input);
        } catch (DomainException $error) {
            $rejected = $error->getMessage() !== '';
        }
        $check($rejected, 'Malformed/oversized email recipient envelope accepted.');
    }
    unset($envelope[$field]);
    $input['sls_recipient_selection_json'] = $encode($envelope);
    $rejected = false;
    try {
        RecipientSelection::decode($input);
    } catch (DomainException $error) {
        $rejected = $error->getMessage() !== '';
    }
    $check($rejected, 'Incomplete modern recipient envelope silently lost email field.');
    $legacy = ['slsmassnotifyserver_action' => $action, $field => [$ids[0]]];
    $check(
        RecipientSelection::decode($legacy) === $legacy,
        'Non-envelope compatibility path changed.'
    );
}

$action = 'save_scheduled_announcement';
$browserField = 'schedule_browser_recipient_ids';
$connectorField = 'schedule_connector_ids';
$check(
    in_array($browserField, RecipientSelection::FIELDS[$action], true)
        && in_array($connectorField, RecipientSelection::FIELDS[$action], true),
    'Scheduled Labs recipients bypass the bounded form envelope.'
);
$envelope = array_fill_keys(RecipientSelection::FIELDS[$action], []);
for ($index = 0; $index < 1000; ++$index) {
    $envelope[$browserField][] = 'sub_' . str_pad(dechex($index + 1), 24, '0', STR_PAD_LEFT);
}
$envelope[$connectorField] = ['conn_' . str_repeat('b', 24)];
$input = [
    'slsmassnotifyserver_action' => $action,
    'sls_recipient_selection_present' => '1',
    'sls_recipient_selection_complete' => '1',
    'sls_recipient_selection_json' => $encode($envelope),
    $browserField => ['sub_' . str_repeat('f', 24)],
];
$decoded = RecipientSelection::decode($input);
$check(
    $decoded[$browserField] === $envelope[$browserField]
        && $decoded[$connectorField] === $envelope[$connectorField],
    'The form lost part of its 1000-browser selection or preferred conflicting raw fields.'
);
foreach ([$browserField, $connectorField] as $field) {
    $bad = $envelope;
    $bad[$field] = ['wrong_' . str_repeat('a', 24)];
    $input['sls_recipient_selection_json'] = $encode($bad);
    $rejected = false;
    try {
        RecipientSelection::decode($input);
    } catch (DomainException $error) {
        $rejected = $error->getMessage() !== '';
    }
    $check($rejected, 'A Labs field accepted an invalid saved-recipient ID.');
}
$old = $envelope;
unset($old[$browserField], $old[$connectorField]);
$input['sls_recipient_selection_json'] = $encode($old);
$input[$browserField] = $envelope[$browserField];
$input[$connectorField] = $envelope[$connectorField];
$decoded = RecipientSelection::decode($input);
$check(
    $decoded[$browserField] === $envelope[$browserField]
        && $decoded[$connectorField] === $envelope[$connectorField],
    'The compatibility form discarded existing separate Labs selections.'
);
echo "Email recipient form envelopes: $count checks passed.\n";
