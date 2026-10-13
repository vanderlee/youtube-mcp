<?php

declare(strict_types=1);

namespace YouTubeMusicMcp;

use RuntimeException;

final class ServiceError extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $details = [],
        public readonly int $httpStatus = 400
    ) {
        parent::__construct($message);
    }

    public function payload(): array
    {
        return ['code' => $this->reason, 'message' => $this->getMessage()] + $this->details;
    }
}
