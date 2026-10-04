<?php
// Render only: no PBX bootstrap, active settings reads, sends or providers.
set_error_handler(static function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
function load_view($path, $values) { return ""; }
$render = static function (array $policy): string {
    $hero_image = '';
    $settings = ['xweather' => $policy + ['enabled' => '1', 'groups' => [['id' => 'area', 'name' => 'Area', 'enabled' => '1', 'location' => '40,-100']]]];
    $area_coverage = ['area' => ['last_xweather_poll_status' => 'fallback_exhausted', 'last_xweather_poll_message' => '<script>unsafe</script>', 'xweather_gate_health' => 'unavailable', 'xweather_gate_outage_started_at' => 10000, 'xweather_fallback_deadline' => 11800, 'xweather_fallback_attempts' => 6, 'xweather_fallback_query_limit' => 6]];
    ob_start(); try { require __DIR__ . '/../slsmassnotifyserver/views/lightning.php'; return ob_get_clean(); } catch (Throwable $e) { ob_end_clean(); throw $e; }
};
$default = $render([]);
$bounded = $render(['adaptive_gate_failure_policy' => 'bounded_poll', 'adaptive_fallback_minutes' => 45]);
foreach ([$default, $bounded] as $html) {
    foreach (['name="xweather[adaptive_gate_failure_policy]"', 'name="xweather[adaptive_fallback_minutes]"', 'Fallback exhausted', 'Fallback attempts: 6 / 6.', 'Allowance ends', '&lt;script&gt;unsafe&lt;/script&gt;', 'An outage never generates an all-clear.'] as $required) {
        if (strpos($html, $required) === false) throw new RuntimeException('Missing rendered policy/status: ' . $required);
    }
    if (strpos($html, '<script>unsafe</script>') !== false) throw new RuntimeException('Coverage message not escaped');
}
if (!preg_match('/value="standby" selected/', $default) || !preg_match('/value="bounded_poll" selected/', $bounded)) throw new RuntimeException('Wrong policy selection');
echo "Lightning outage policy default/configured rendering and escaped actionable coverage passed.\n";
