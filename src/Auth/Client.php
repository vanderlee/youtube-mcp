<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Auth;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

final class Client implements ClientEntityInterface
{
    use EntityTrait;

    public function __construct(
        string $id,
        private string $name,
        private array $redirects,
        private bool $confidential = false
    ) {
        $this->setIdentifier($id);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRedirectUri(): array
    {
        return $this->redirects;
    }

    public function isConfidential(): bool
    {
        return $this->confidential;
    }
}
