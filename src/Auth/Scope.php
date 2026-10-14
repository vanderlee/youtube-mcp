<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Auth;

use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;

final class Scope implements ScopeEntityInterface
{
    public const SUPPORTED = [
        'playlists.read',
        'playlists.write',
        'likes.read',
        'likes.write',
        'subscriptions.read',
        'subscriptions.write',
    ];
    use EntityTrait;
    use ScopeTrait;

    public function __construct(string $id)
    {
        $this->setIdentifier($id);
    }
}
