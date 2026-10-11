<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/slsmassnotifyserver/VoicemailConfig.php';

use SLS\MassNotify\VoicemailConfig;

set_error_handler(static function ($severity, $message, $file, $line): void {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function load_view(string $path, array $variables = []): string { return ''; }

function voicemail_controls_check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

foreach ([true, 1, '1'] as $value) {
    voicemail_controls_check(VoicemailConfig::flag($value) === '1', 'Enabled preference did not normalize.');
}
foreach ([false, 0, '0'] as $value) {
    voicemail_controls_check(VoicemailConfig::flag($value) === '0', 'Disabled preference did not normalize.');
}
foreach ([null, '', 'false', 'true', 0.0, 1.0, 2, [], new stdClass()] as $value) {
    try { VoicemailConfig::flag($value); }
    catch (InvalidArgumentException $expected) { continue; }
    throw new RuntimeException('Malformed voicemail preference was accepted.');
}
voicemail_controls_check(VoicemailConfig::DEFAULT_ENABLED === '1', 'Voicemail no longer defaults on.');

$render = static function (string $view, array $overrides): string {
    $hero_image = '';
    $csrf_token = 'fixture-csrf';
    $settings = array_replace(['enabled' => '0', 'nws_zones' => [], 'quiet_critical_events' => []], $overrides);
    $paging = $overrides['live_paging'] ?? [];
    ob_start();
    try { require dirname(__DIR__) . '/slsmassnotifyserver/views/' . $view . '.php'; return (string)ob_get_clean(); }
    catch (Throwable $error) { ob_end_clean(); throw $error; }
};

$cases = [
    ['view' => 'settings', 'name' => 'nws_voicemail_enabled', 'id' => 'sls-weather-voicemail', 'field' => 'nws_voicemail_enabled', 'nested' => null],
    ['view' => 'lightning', 'name' => 'xweather[voicemail_enabled]', 'id' => 'sls-lightning-voicemail', 'field' => 'voicemail_enabled', 'nested' => 'xweather'],
    ['view' => 'paging', 'name' => 'live_paging[voicemail_enabled]', 'id' => 'sls-paging-voicemail', 'field' => 'voicemail_enabled', 'nested' => 'live_paging'],
];
foreach ($cases as $case) {
    foreach (['missing', '1', '0', false] as $value) {
        $config = $value === 'missing' ? [] : [$case['field'] => $value];
        $html = $render($case['view'], $case['nested'] === null ? $config : [$case['nested'] => $config]);
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        // libxml's HTML4 parser does not treat text/template scripts as HTML5
        // raw text. Remove those scripts before checking ordinary form nodes.
        $document->loadHTML((string)preg_replace('~<script\b[^>]*>.*?</script>~si', '', $html));
        libxml_clear_errors();
        $xpath = new DOMXPath($document);
        $checkboxes = $xpath->query('//input[@id="' . $case['id'] . '" and @type="checkbox"]');
        voicemail_controls_check($checkboxes->length === 1, 'Voicemail control is missing or duplicated.');
        $checkbox = $checkboxes->item(0);
        voicemail_controls_check($checkbox->getAttribute('name') === $case['name'] && $checkbox->getAttribute('value') === '1', 'Voicemail checkbox does not post its own preference.');
        voicemail_controls_check($checkbox->hasAttribute('checked') === in_array($value, ['missing', '1'], true), 'Voicemail saved-off state or default was lost.');
        $hidden = $xpath->query('//input[@type="hidden" and @name="' . $case['name'] . '" and @value="0"]');
        voicemail_controls_check($hidden->length === 1, 'Unchecked voicemail control cannot explicitly disable the preference.');
        voicemail_controls_check($checkbox->getAttribute('aria-describedby') !== '', 'Voicemail control lost its accessible explanation.');
        $description = $xpath->query('//*[@id="' . $checkbox->getAttribute('aria-describedby') . '"]');
        voicemail_controls_check($description->length === 1 && trim($description->item(0)->textContent) !== '', 'Voicemail explanation is not available.');
        voicemail_controls_check($checkbox->parentNode->tagName === 'label', 'Voicemail label is not associated with its control.');
        $form = $xpath->query('ancestor::form', $checkbox)->item(0);
        voicemail_controls_check($form !== null && $form->getAttribute('method') === 'post', $case['view'] . ': voicemail option is outside its saved settings form.');
        $hiddenBefore = $xpath->query('preceding::input[@type="hidden" and @name="' . $case['name'] . '" and @value="0"]', $checkbox);
        voicemail_controls_check($hiddenBefore->length === 1, 'The hidden false value can overwrite a checked voicemail value.');
    }
}

echo "Voicemail preferences reject malformed flags; Weather, Lightning and live Paging controls default on, preserve saved off values and post explicit unchecked values.\n";
