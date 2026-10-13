<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Auth;

use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\UserEntityInterface;

final class User implements UserEntityInterface
{
    use EntityTrait;

    public function __construct(string $id)
    {
        $this->setIdentifier($id);
    }
}
