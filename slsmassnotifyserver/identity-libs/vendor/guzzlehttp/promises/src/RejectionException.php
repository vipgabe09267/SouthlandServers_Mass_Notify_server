<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise;

/**
 * A special exception that is thrown when waiting on a rejected promise.
 *
 * The reason value is available via the getReason() method.
 */
class RejectionException extends \RuntimeException
{
    /** @var mixed Rejection reason. */
    private $reason;

    /**
     * @param mixed       $reason      Rejection reason.
     * @param string|null $description Optional description.
     */
    public function __construct($reason, ?string $description = null)
    {
        $this->reason = $reason;

        $message = 'The promise was rejected';

        if ($description !== null && $description !== '') {
            $message .= ' with reason: '.self::diagnostic($description);
        } elseif (is_string($reason)
            || (is_object($reason) && method_exists($reason, '__toString'))
        ) {
            try {
                $message .= ' with reason: '.self::diagnostic((string) $this->reason);
            } catch (\Throwable $e) {
                $message .= ' with an unprintable reason';
            }
        } elseif ($reason instanceof \JsonSerializable) {
            try {
                $encoded = json_encode($this->reason, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
                $message .= ' with reason: '.self::diagnostic($encoded);
            } catch (\Throwable $e) {
                $message .= ' with an unprintable reason';
            }
        }

        parent::__construct($message);
    }

    private static function diagnostic(string $value): string
    {
        $length = strlen($value);
        $limit = min($length, 1024);
        $escaped = '';
        for ($index = 0; $index < $limit; ++$index) {
            $byte = ord($value[$index]);
            $escaped .= $byte >= 0x20 && $byte <= 0x7e
                ? $value[$index]
                : sprintf('\\x%02X', $byte);
        }

        return $escaped.($length > $limit ? '...' : '');
    }

    /**
     * Returns the rejection reason.
     *
     * @return mixed
     */
    public function getReason()
    {
        return $this->reason;
    }
}
