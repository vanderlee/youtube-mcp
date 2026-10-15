<?php

declare(strict_types=1);

namespace YouTubeMcp\Auth;

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;

/**
 * Provides the refresh-token entity required by the League OAuth server.
 *
 * OAuthRepository tracks its expiry and revocation to support refresh rotation.
 */
final class RefreshToken implements RefreshTokenEntityInterface
{
    use EntityTrait;
    use RefreshTokenTrait;
}
