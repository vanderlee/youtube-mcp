<?php

declare(strict_types=1);

namespace YouTubeMusicMcp\Music;

use YouTubeMusicMcp\{Config, ServiceError};
use YouTubeMusicMcp\Auth\Google;
use YouTubeMusicMcp\Operations\Receipts;
use YouTubeMusicMcp\Storage\Lock;

final class Service
{
    public function __construct(
        private Google $google,
        private Api $api,
        private Receipts $receipts,
        private Lock $locks,
        private Config $config,
        private ?\YouTubeMusicMcp\Auth\MusicOAuth $musicOAuth = null,
        private ?\YouTubeMusicMcp\Auth\MusicBrowser $musicBrowser = null
    ) {
    }

    public function execute(string $user, array $scopes, string $tool, array $args): array
    {
        $spec = Tools::specs()[$tool] ?? throw new ServiceError('UNKNOWN_TOOL', 'Unknown Music tool.');
        if (!in_array(Tools::scope($tool), $scopes, true)) {
            throw new ServiceError('INSUFFICIENT_SERVICE_SCOPE', 'Authorize ' . Tools::scope($tool) . '.', [], 403);
        }
        $this->validate($args, $spec[1], $spec[2]);
        if ($tool === 'get_music_library' && ($args['kind'] ?? '') === 'playlists' && isset($args['order'])) {
            throw new ServiceError('INVALID_ARGUMENT', 'Music playlist libraries do not support order.');
        }
        // Use the same account lock for reads and writes, so reconnect cannot swap identity mid-call.
        return $this->locks->run('account:' . $user, function () use ($user, $tool, $args) {
            $connection = $this->google->connection($user);
            $browserConnection = $this->musicBrowser?->connection($user, $connection);
            $musicConnection = !$browserConnection && $this->musicOAuth?->configured() ? $this->musicOAuth->connection($user, $connection) : null;
            $generation = $connection['generation'] . ($browserConnection ? ':browser:' . $browserConnection['generation'] : ($musicConnection ? ':music:' . $musicConnection['generation'] : ''));
            if (in_array($tool, Tools::WRITES, true)) {
                return $this->receipts->run(
                    $user,
                    $generation,
                    $tool,
                    $args,
                    function ($step) use ($user, $generation, $tool, $args, $connection, $musicConnection, $browserConnection) {
                        // Verify action tokens only inside the receipt callback: completed replay stays local.
                        $arguments = $args;
                        unset($arguments['operation_key'], $arguments['resume']);
                        if ($tool === 'set_music_song_library_status') {
                            $arguments['feedback_token'] = $this->decodeToken(
                                $arguments['feedback_token'],
                                $user,
                                $generation,
                                'library'
                            );
                        } elseif ($tool === 'remove_music_history_items') {
                            $arguments['feedback_tokens'] = array_map(
                                fn($t) => $this->decodeToken($t, $user, $generation, 'history'),
                                $arguments['feedback_tokens']
                            );
                        }
                        $api = $browserConnection ? $this->musicBrowser->client($browserConnection) : $this->api;
                        $access = $browserConnection ? null : ($musicConnection ? $this->musicOAuth->accessToken($user, $musicConnection) : $this->musicAccessToken($user, $connection));
                        $result = $step('music-change', fn() => $api->call($tool, $arguments, $access));
                        return ['changed' => true, 'upstream' => $result];
                    }
                );
            }
            $api = $browserConnection ? $this->musicBrowser->client($browserConnection) : $this->api;
            $result = $api->call($tool, $args, $browserConnection ? null :
                ($musicConnection ? $this->musicOAuth->accessToken($user, $musicConnection) : $this->musicAccessToken($user, $connection)));
            $truncated = false;
            $result = $this->protect($result, $user, $generation, $truncated, $tool === 'get_music_history');
            return [
                'source' => 'youtube_music_unofficial',
                'data' => $result,
                'nested_lists_limited_to' => 50,
                'nested_lists_may_be_truncated' => $truncated,
                'quota_accounting' => 'Music requests are not included in official Data API usage estimates.'
            ];
        });
    }

    private function musicAccessToken(string $user, array $connection): string
    {
        if (!in_array(Google::MUSIC_SCOPE, preg_split('/\s+/', trim($connection['scopes'])), true)) {
            throw new ServiceError('GOOGLE_MUSIC_SCOPE_REQUIRED',
                'This Google connection lacks Music authorization. Reconnect Google in the dashboard and grant Manage your YouTube account, then reconnect ChatGPT.',
                ['required_google_scope' => Google::MUSIC_SCOPE], 403);
        }
        return $this->google->accessToken($user);
    }

    private function validate(array $args, array $props, array $required): void
    {
        foreach ($required as $key) {
            if (!array_key_exists($key, $args)) {
                throw new ServiceError('INVALID_ARGUMENT', 'Missing ' . $key . '.');
            }
        }
        foreach ($args as $key => $value) {
            if (!isset($props[$key]) || !$this->validValue($value, $props[$key])) {
                throw new ServiceError('INVALID_ARGUMENT', 'Invalid Music argument: ' . $key . '.');
            }
        }
    }

    private function validValue(mixed $value, array $schema): bool
    {
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            return false;
        }
        return match ($schema['type']) {
            'string' => is_string($value) && strlen($value) >= ($schema['minLength'] ?? 0)
                && strlen($value) <= ($schema['maxLength'] ?? 65536)
                && (!isset($schema['pattern']) || preg_match('~' . $schema['pattern'] . '~D', $value) === 1),
            'integer' => is_int($value) && $value >= $schema['minimum'] && $value <= $schema['maximum'],
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value) && count($value) >= $schema['minItems']
                && count($value) <= $schema['maxItems'] && count(array_unique($value, SORT_REGULAR)) === count($value)
                && array_reduce(
                    $value,
                    fn($valid, $item) => $valid && $this->validValue($item, $schema['items']),
                    true
                ),
            default => false,
        };
    }

    private function decodeToken(string $signed, string $user, string $generation, string $purpose): string
    {
        $parts = explode('.', $signed);
        if (count($parts) === 2) {
            $expected = $this->base64(
                hash_hmac(
                    'sha256',
                    'music-action:' . $parts[0],
                    base64_decode($this->config->get('APP_KEY'), true),
                    true
                )
            );
            $decoded = json_decode(base64_decode(strtr($parts[0], '-_', '+/'), true) ?: '', true);
            if (hash_equals($expected, $parts[1]) && is_array($decoded) && ($decoded['user'] ?? '') === $user
                && ($decoded['generation'] ?? '') === $generation && ($decoded['purpose'] ?? '') === $purpose
                && ($decoded['expires'] ?? 0) > time() && is_string($decoded['token'] ?? null)) {
                return $decoded['token'];
            }
        }
        throw new ServiceError(
            'INVALID_MUSIC_TOKEN',
            'Refresh Music data and use its action token for this account and action. Tokens expire after one hour.',
            [],
            409
        );
    }

    private function base64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function protect(mixed $value, string $user, string $generation, bool &$truncated, bool $history): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            if (count($value) >= 50) {
                $truncated = true;
            }
            $items = [];
            foreach (array_slice($value, 0, 50) as $entry) {
                $items[] = $this->protect($entry, $user, $generation, $truncated, $history);
            }
            return $items;
        }
        foreach ($value as $key => &$entry) {
            if ($key === 'feedbackTokens' && is_array($entry)) {
                $tokens = [];
                foreach (['add', 'remove'] as $action) {
                    if (isset($entry[$action]) && is_string($entry[$action])) {
                        $tokens[$action] = $this->encodeToken($entry[$action], $user, $generation, 'library');
                    }
                }
                $entry = $tokens;
            } elseif ($key === 'feedbackToken') {
                $entry = $history && is_string($entry) ? $this->encodeToken(
                    $entry,
                    $user,
                    $generation,
                    'history'
                ) : null;
            } else {
                $entry = $this->protect($entry, $user, $generation, $truncated, $history);
            }
        }
        unset($entry);
        return $value;
    }

    private function encodeToken(string $token, string $user, string $generation, string $purpose): string
    {
        $data = $this->base64(json_encode([
            'token' => $token,
            'user' => $user,
            'generation' => $generation,
            'purpose' => $purpose,
            'expires' => time() + 3600
        ], JSON_THROW_ON_ERROR));
        return $data . '.' . $this->base64(
                hash_hmac('sha256', 'music-action:' . $data, base64_decode($this->config->get('APP_KEY'), true), true)
            );
    }
}
