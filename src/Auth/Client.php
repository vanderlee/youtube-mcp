<?php

declare(strict_types=1);

namespace YouTubeMcp\Auth;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

/**
 * Adapts a registered MCP client to the League OAuth client contract.
 *
 * Exposes its name, permitted redirects and confidentiality to the authorization server.
 */
final class Client implements ClientEntityInterface
{
    use EntityTrait;

    /**
     * @param list<string> $redirects
     */
    public function __construct(
        string $id,
        private string $name,
        private array $redirects,
        private bool $confidential = false,
    ) {
        if ($id === '') {
            throw new \InvalidArgumentException('Identifier must not be empty.');
        }

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
