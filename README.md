# YouTube playlist MCP service

A multi-user PHP service for managing YouTube playlists from ChatGPT. Each user signs in with Google, authorizes their own YouTube channel, and grants ChatGPT separate service permissions. Google credentials stay on the server.

This is an initial implementation with automated local verification. It has **not yet been validated against a live Google account, YouTube Music, ChatGPT, or a Plesk installation**. Complete the [live acceptance checklist](docs/ACCEPTANCE.md) before treating the deployment as ready for personal playlists.

## Features

- Official PHP MCP SDK with Streamable HTTP at `/mcp`.
- Google sign-in, invite-only onboarding, explicit channel confirmation, offline token refresh, and encrypted credentials.
- OAuth authorization code with PKCE S256, expiring service tokens, refresh rotation, revocation, and audience validation.
- Playlist listing, inspection, search, creation, metadata updates, track addition/removal/movement, and deletion.
- Informational, cache-aware daily usage estimates; YouTube enforces quotas and rate limits.
- Mutation receipts, explicit partial resume, and protection against replay of completed operations.
- PHP-FPM hosting in Plesk, SQLite storage, no Docker or background worker.

## Requirements

PHP 8.2 or newer compatible with the locked dependencies, Composer 2, HTTPS, and extensions `curl`, `fileinfo`, `pdo`, `pdo_sqlite`, `sodium`, `openssl`, `mbstring`, `session`, and `json`. The test suite also requires `dom` and `xml`. Use a maintained PHP patch version in production.

The implemented database backend is SQLite on local storage. MariaDB remains a future enhancement. Run one application instance with local filesystem locks and shared PHP-FPM storage; multiple hosts or network filesystems are unsupported.

## Setup

For automated Plesk post-deployment setup, run `bash bin/post-deploy.sh --php /opt/plesk/php/8.3/bin/php` as the subscription user. It prompts for configuration, initializes storage, and checks HTTPS; see [script options](docs/PLESK.md#automated-server-side-setup). Google Cloud registration and browser authorizations remain manual.

1. Install dependencies: `composer install --no-dev --prefer-dist --optimize-autoloader`.
2. Copy `.env.example` to `.env` outside the web root.
3. Set `APP_URL` to the exact HTTPS origin, your Google client credentials, and invited email addresses.
4. Run `php bin/console.php key:generate`, then place the generated value in `APP_KEY`. Keep it private and back it up.
5. Run `php bin/console.php init` to create signing keys and migrate storage.
6. Point the domain's document root to `public/` and configure front-controller routing.
7. Follow [Plesk installation](docs/PLESK.md) and [Google/ChatGPT connection](docs/CONNECTIONS.md).
8. Run `php bin/console.php cleanup` hourly through a scheduled task.

No OpenAI API key is needed: the service exposes tools to ChatGPT and does not call OpenAI's model API itself.

## Tools

`get_connection_status`, `get_usage_status`, `get_operation`, `list_playlists`, `get_playlist`, `search_tracks`, `create_playlist`, `update_playlist`, `add_tracks`, `remove_tracks`, `move_track`, and `delete_playlist`.

Writes require `playlists.write`; reads require `playlists.read`. New playlists default to private. Entries are removed or moved by **playlist-item ID**, identifying an occurrence; video IDs identify the video and can occur more than once.

Example mutation arguments:

```json
{
  "playlist_id": "YOUR_PLAYLIST_ID",
  "video_ids": ["VIDEO_ID_01", "https://music.youtube.com/watch?v=VIDEO_ID_02"],
  "duplicate_policy": "skip",
  "operation_key": "add-my-tracks-20261013"
}
```

Use actual 11-character video IDs. Keep the same operation key and arguments when retrying. A completed receipt returns without repeating API writes. A partial receipt requires `resume: true` to attempt pending steps. An uncertain or interrupted in-flight mutation cannot automatically resume; inspect YouTube and resolve the ambiguity first. Receipt retention defaults to seven days, so do not replay old operation keys after their receipts have been removed.

Keys are scoped to the active connection generation. Reconnecting Google starts a new namespace: inspect previous receipts before submitting the same requested change again after reconnecting.

## Usage reporting

YouTube enforces project quotas and rate limits. The app does not impose project/user budgets, headroom, reservation admission limits, local circuit breakers, or tool-call throttling. Legacy QUOTA_*, USER_QUOTA_*, and MCP_CALLS_PER_MINUTE settings are ignored.

Usage status reports estimates for requests observed by this installation, grouped by Pacific day. It cannot determine remaining YouTube quota or see other project traffic. Actual YouTube quota/rate-limit errors are returned to the caller without automatic retries. Operation receipts, ownership checks, bounded batches, and time limits remain active.
## Development and tests

```sh
composer install
composer test
composer audit
```

On the current Windows development machine, required DLLs exist but are not enabled in the default PHP configuration. Run tests with:

```powershell
$env:OPENSSL_CONF = 'C:/tools/php82/extras/ssl/openssl.cnf'
php -d extension=fileinfo -d extension=pdo_sqlite -d extension=sodium vendor/phpunit/phpunit/phpunit
```

For a local HTTP smoke test only, run `php -S 127.0.0.1:8080 -t public public/router.php` with a temporary configuration, disabled secure cookies, and the configured Host header. Production configuration intentionally requires an HTTPS origin. Google callbacks and ChatGPT require a reachable HTTPS deployment; the PHP development server is not a production runtime.

## Current limitations

- Search returns YouTube video candidates; Music catalog membership and playback are not guaranteed.
- One Google identity and one selected channel per user. Connect using the same verified identity used for sign-in. Channel selection is delegated to Google's consent flow and then confirmed.
- Entirely authenticated MCP endpoint: select OAuth in ChatGPT, not mixed/no-auth mode.
- Client registration supports an operator allowlist of exact redirects, or static credentials; no CIMD or public marketplace submission.
- Playlist duplicate/member scans are capped at 1,000 entries and 45 seconds. Larger playlists may be readable through pagination but cannot use operations needing a full scan.
- Bulk writes are capped at 20 entries and stop after a 45-second write window, allowing the final in-flight request to finish. Long preflight scans can add another bounded window.
- Local locks serialize account mutations. Edits made directly on YouTube cannot be locked and may change positions between preflight and write.
- Uncertain mutations require manual reconciliation. Exactly-once changes cannot be guaranteed by the upstream API.

See [PLAN.md](PLAN.md) for the design and [operations guide](docs/OPERATIONS.md) for recovery and maintenance.

