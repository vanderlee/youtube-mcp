<?php

declare(strict_types=1);

namespace YouTubeMcp\Tests\Auth;

use DateTimeImmutable;
use YouTubeMcp\Auth\{Client, OAuthRepository, Scope};

covers(OAuthRepository::class);

test('repository validates confidential clients and returns only supported scopes', function (): void {
    $db = $this->database();
    $db->execute('INSERT INTO oauth_clients VALUES (?,?,?,?,?)', [
        'client', 'Fixture', '["https://example.test/callback"]', password_hash('dummy-secret', PASSWORD_DEFAULT), 1,
    ]);
    $repository = new OAuthRepository($db, 'https://example.test');

    expect($repository->getClientEntity('missing'))->toBeNull();
    expect($repository->validateClient('missing', 'dummy-secret', null))->toBeFalse();
    expect($repository->validateClient('client', null, null))->toBeFalse();
    expect($repository->validateClient('client', 'wrong', null))->toBeFalse();
    expect($repository->validateClient('client', 'dummy-secret', null))->toBeTrue();
    expect($repository->getClientEntity('client')->getRedirectUri())->toBe(['https://example.test/callback']);
    expect($repository->getClientEntity('client')->isConfidential())->toBeTrue();
    expect($repository->getScopeEntityByIdentifier('playlists.read')->getIdentifier())->toBe('playlists.read');
    expect($repository->getScopeEntityByIdentifier('unknown.write'))->toBeNull();

    $db->execute('UPDATE oauth_clients SET confidential=0 WHERE id=?', ['client']);
    expect($repository->validateClient('client', null, null))->toBeTrue();
    $scopes = [new Scope('playlists.read')];
    $client = new Client('client', 'Fixture', []);
    expect($repository->finalizeScopes($scopes, 'authorization_code', $client))->toBe($scopes);
});

test('repository hashes token identities and enforces type expiration and revocation', function (string $kind): void {
    $db = $this->database();
    $this->user($db);
    $repository = new OAuthRepository($db, 'https://example.test');
    $client = new Client('client', 'Fixture', []);
    $access = $repository->getNewToken($client, [new Scope('playlists.read')], 'u1');
    $access->setIdentifier('access-id');
    $access->setExpiryDateTime(new DateTimeImmutable('+1 hour'));
    $entity = match ($kind) {
        'AccessToken' => $access,
        'AuthCode' => $repository->getNewAuthCode(),
        'RefreshToken' => $repository->getNewRefreshToken(),
    };
    $entity->setIdentifier('raw-token-id');
    $entity->setExpiryDateTime(new DateTimeImmutable('+1 hour'));
    if ($kind === 'AuthCode') {
        $entity->setClient($client);
        $entity->setUserIdentifier('u1');
    } elseif ($kind === 'RefreshToken') {
        $entity->setAccessToken($access);
    }
    $persist = 'persistNew' . $kind;
    $revoked = 'is' . $kind . 'Revoked';
    $revoke = 'revoke' . $kind;
    expect($repository->$revoked('unknown'))->toBeTrue();
    $repository->$persist($entity);
    $row = $db->one('SELECT * FROM oauth_tokens');

    expect($row['id'])->toBe(hash('sha256', 'raw-token-id'));
    expect($row['user_id'])->toBe('u1');
    expect($row['client_id'])->toBe('client');
    expect($repository->$revoked('raw-token-id'))->toBeFalse();
    $wrongKind = $kind === 'AuthCode' ? 'isAccessTokenRevoked' : 'isAuthCodeRevoked';
    expect($repository->$wrongKind('raw-token-id'))->toBeTrue();
    $db->execute('UPDATE oauth_tokens SET expires_at=?', [time()]);
    expect($repository->$revoked('raw-token-id'))->toBeTrue();
    $db->execute('UPDATE oauth_tokens SET expires_at=?', [time() + 3600]);
    $repository->$revoke('raw-token-id');
    expect($repository->$revoked('raw-token-id'))->toBeTrue();
})->with(['AccessToken', 'AuthCode', 'RefreshToken']);
