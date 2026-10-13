<?php
declare(strict_types=1);
namespace YouTubeMcp\Storage;
use YouTubeMcp\ServiceError;

final class Lock
{
    public function __construct(private string $directory) { if (!is_dir($directory)) { mkdir($directory, 0700, true); } }
    public function run(string $name, callable $fn): mixed
    {
        $handle = fopen($this->directory . '/' . hash('sha256', $name) . '.lock', 'c');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) { if ($handle) { fclose($handle); } throw new ServiceError('OPERATION_BUSY', 'This account or playlist is being updated. Retry shortly.', [], 409); }
        try { return $fn(); } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
}
