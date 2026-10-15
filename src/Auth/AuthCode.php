<?php

declare(strict_types=1);

namespace YouTubeMcp\Auth;

use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\Traits\AuthCodeTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

/**
 * Provides the authorization-code entity required by the League OAuth server.
 *
 * OAuthRepository persists its identity and expiry for exchange and revocation checks.
 */
final class AuthCode implements AuthCodeEntityInterface
{
    use EntityTrait;
    use TokenEntityTrait;
    use AuthCodeTrait;
}
