<?php

declare(strict_types=1);

namespace YouTubeMcp\Storage;

use YouTubeMcp\ServiceError;

final class Lock
{
    /**
     * Reentrant only for the same logical name within this request's lock owner.
     *
     * @var array<string, true>
     */
    private array $held = [];

    public function __construct(private string $directory)
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
    }

    public function run(string $name, callable $fn): mixed
    {
        if (isset($this->held[$name])) {
            return $fn();
        }
        // Separate nested lock kinds; hash collisions fail busy rather than weakening exclusion.
        $kind = explode(':', $name, 2)[0];
        $kind = in_array($kind, ['account', 'google', 'playlist', 'operation'], true) ? $kind : 'global';
        $slot = hexdec(substr(hash('sha256', $name), 0, 2));
        $handle = fopen($this->directory . '/slot-' . $kind . '-' . $slot . '.lock', 'c');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
            if ($handle) {
                fclose($handle);
            }
            throw new ServiceError(
                'OPERATION_BUSY',
                'This account or playlist is being updated. Retry shortly.',
                [],
                409,
            );
        }
        $this->held[$name] = true;
        try {
            return $fn();
        } finally {
            unset($this->held[$name]);
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
