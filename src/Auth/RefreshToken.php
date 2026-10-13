<?php
declare(strict_types=1);
namespace YouTubeMcp\Auth;
final class RefreshToken implements \League\OAuth2\Server\Entities\RefreshTokenEntityInterface
{
    use \League\OAuth2\Server\Entities\Traits\EntityTrait;
    use \League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;
}
