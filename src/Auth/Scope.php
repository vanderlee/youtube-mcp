<?php

declare(strict_types=1);

namespace YouTubeMcp\Auth;

use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;

/**
 * Represents a supported service permission for the League OAuth server.
 *
 * Supplies scope identifiers used by OAuthRepository when granting client access.
 */
final class Scope implements ScopeEntityInterface
{
    use EntityTrait;
    use ScopeTrait;

    public const SUPPORTED = [
        'playlists.read',
        'playlists.write',
        'likes.read',
        'likes.write',
        'subscriptions.read',
        'subscriptions.write',
        'videos.read',
        'videos.write',
        'videos.upload',
        'channels.read',
        'channels.write',
        'comments.read',
        'comments.write',
        'comments.moderate',
        'captions.read',
        'captions.write',
        'media.read',
        'media.write',
    ];

    public function __construct(string $id)
    {
        if ($id === '') {
            throw new \InvalidArgumentException('Identifier must not be empty.');
        }

        $this->setIdentifier($id);
    }
}
