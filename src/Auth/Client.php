<?php
declare(strict_types=1);
namespace YouTubeMcp\Auth;
final class Client implements \League\OAuth2\Server\Entities\ClientEntityInterface
{
    use \League\OAuth2\Server\Entities\Traits\EntityTrait;
    public function __construct(string $id, private string $name, private array $redirects, private bool $confidential = false) { $this->setIdentifier($id); }
    public function getName(): string { return $this->name; }
    public function getRedirectUri(): array { return $this->redirects; }
    public function isConfidential(): bool { return $this->confidential; }
}
