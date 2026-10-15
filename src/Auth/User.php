<?php

declare(strict_types=1);

namespace YouTubeMcp\Auth;

use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\UserEntityInterface;

/**
 * Wraps a local user identifier for the League OAuth authorization flow.
 *
 * Lets AuthorizeClientRoute associate browser consent with the server's user entity contract.
 */
final class User implements UserEntityInterface
{
    use EntityTrait;

    public function __construct(string $id)
    {
        if ($id === '') {
            throw new \InvalidArgumentException('Identifier must not be empty.');
        }

        $this->setIdentifier($id);
    }
}
