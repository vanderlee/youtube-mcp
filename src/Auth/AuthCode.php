<?php
declare(strict_types=1);
namespace YouTubeMcp\Auth;
final class AuthCode implements \League\OAuth2\Server\Entities\AuthCodeEntityInterface
{
    use \League\OAuth2\Server\Entities\Traits\EntityTrait;
    use \League\OAuth2\Server\Entities\Traits\TokenEntityTrait;
    use \League\OAuth2\Server\Entities\Traits\AuthCodeTrait;
}
