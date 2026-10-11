<?php
declare(strict_types=1);

namespace SLS\MassNotify;

/** Strict checkbox values shared by saved voicemail delivery preferences. */
final class VoicemailConfig
{
    public const DEFAULT_ENABLED = '1';

    public static function flag($value, string $label = 'Voicemail option'): string
    {
        if (!in_array($value, [true, false, 0, 1, '0', '1'], true)) {
            throw new \InvalidArgumentException($label . ' must be enabled or disabled.');
        }
        return in_array($value, [true, 1, '1'], true) ? '1' : '0';
    }
}
