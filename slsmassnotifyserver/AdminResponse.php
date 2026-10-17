<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Do not spin or submit an action behind an unremovable PBX response buffer. */
final class AdminResponse
{
    public static function clearBuffers(): bool
    {
        $buffers=ob_get_status(true);
        if (count($buffers)>64) { return false; }
        foreach ($buffers as $buffer) {
            if (!(($buffer['flags']??0)&PHP_OUTPUT_HANDLER_REMOVABLE)) { return false; }
        }
        while (ob_get_level()>0) {
            if (!@ob_end_clean()) { return false; }
        }
        return true;
    }

    public static function requireClearBuffers(): void
    {
        if (self::clearBuffers()) { return; }
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode(['success'=>false,'error'=>'response_buffer_unavailable',
            'message'=>'A PBX output handler prevents a clean response. Reload this page and review custom theme or module output buffering.']);
        exit;
    }
}
