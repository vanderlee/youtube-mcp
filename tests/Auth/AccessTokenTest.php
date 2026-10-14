<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Auth;

use DateTimeImmutable;
use Firebase\JWT\{JWT, Key};
use League\OAuth2\Server\CryptKey;
use YouTubeMcp\Auth\{AccessToken, Client, Scope};

covers(AccessToken::class);

test('signed access tokens bind issuer audience client user expiry and granted scopes', function (): void {
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $private);
    $expiry = new DateTimeImmutable('+5 minutes');
    $token = new AccessToken('https://example.test');
    $token->setPrivateKey(new CryptKey($private, null, false));
    $token->setIdentifier('token-id');
    $token->setClient(new Client('client-id', 'Application', []));
    $token->setUserIdentifier('user-id');
    $token->setExpiryDateTime($expiry);
    $token->addScope(new Scope('playlists.read'));
    $claims = JWT::decode($token->toString(), new Key(openssl_pkey_get_details($key)['key'], 'RS256'));

    expect($claims->iss)->toBe('https://example.test');
    expect($claims->aud)->toBe('https://example.test/mcp');
    expect($claims->sub)->toBe('user-id');
    expect($claims->client_id)->toBe('client-id');
    expect($claims->jti)->toBe('token-id');
    expect($claims->exp)->toBe($expiry->getTimestamp());
    expect($claims->scopes)->toBe(['playlists.read']);
    expect($claims->iat)->toBeLessThanOrEqual(time());
    expect($claims->nbf)->toBe($claims->iat);
});
