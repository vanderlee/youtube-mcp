<?php
declare(strict_types=1);
namespace YouTubeMcp\Auth;
final class User implements \League\OAuth2\Server\Entities\UserEntityInterface
{
    use \League\OAuth2\Server\Entities\Traits\EntityTrait;
    public function __construct(string $id) { $this->setIdentifier($id); }
}
