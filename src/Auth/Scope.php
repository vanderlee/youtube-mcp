<?php
declare(strict_types=1);
namespace YouTubeMcp\Auth;
final class Scope implements \League\OAuth2\Server\Entities\ScopeEntityInterface
{
    use \League\OAuth2\Server\Entities\Traits\EntityTrait;
    use \League\OAuth2\Server\Entities\Traits\ScopeTrait;
    public function __construct(string $id) { $this->setIdentifier($id); }
}
