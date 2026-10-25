<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Admission policy; receipt handling and administration remain available. */
final class RuntimeState
{
    public static function labsEnabled(array $settings): bool
    {
        $value=$settings['enterprise_labs']??[];
        return is_array($value) && !array_diff(array_keys($value),['enabled']) && ($value['enabled']??false)===true;
    }

    public static function requireLabsEnabled(array $settings): void
    {
        if (!self::labsEnabled($settings)) {
            throw new \DomainException('Enterprise Labs is disabled. Enable it in Feature manager before running this operation.');
        }
    }

    public static function running(array $settings): bool
    {
        $value = $settings['runtime_enabled'] ?? true;
        if (array_key_exists('runtime_enabled', $settings) && !is_bool($settings['runtime_enabled'])) {
            throw new \DomainException('SLS runtime_enabled must be a boolean; notification admission is blocked.');
        }
        return $value;
    }

    public static function requireRunning(array $settings): void
    {
        if (!self::running($settings)) { throw new \DomainException('SLS notifications are stopped. Run sudo slsconsole start to resume. No new notification was submitted.'); }
    }
}
