# YouTube Music MCP service

A multi-user PHP service for managing YouTube playlists, video ratings and channel subscriptions from ChatGPT, Claude, and Gemini CLI through the official YouTube Data API. Each user signs in with Google, authorizes their own YouTube channel, and grants each AI client separate service permissions. Google credentials stay on the server.

This is an initial implementation with automated local verification. It has **not yet been validated against a live Google account, YouTube Music, ChatGPT, or a Plesk installation**. Complete the [live acceptance checklist](docs/ACCEPTANCE.md) before treating the deployment as ready for personal playlists.

TV/Devices OAuth, browser-cookie login, unofficial Music tools and Music diagnostics have been removed. The service now provides 57 official Data API and local-service tools. Migration 005 retires Music credentials; migration 006 adds private creator media assets. Refresh ChatGPT's tools and authorize the new service permissions after deployment. See [expanded tools, workflows and limits](docs/DATA_API_TOOLS.md).

## Features

- Official PHP MCP SDK with Streamable HTTP at `/mcp`.
- Google sign-in, invite-only onboarding, explicit channel confirmation, offline token refresh, and encrypted credentials.
- OAuth authorization code with PKCE S256, expiring service tokens, refresh rotation, revocation, and audience validation.
- Google refresh failures preserve stored credentials and independent ChatGPT authorization; only an invalid Google grant requests Google reconnection.
- Playlist listing, inspection, search, creation, metadata updates, track addition/removal/movement, and deletion.
- Video/channel details, uploads listing, channel/playlist search, enhanced video search and scoped comment management.
- Copy/merge/sort/deduplicate/reorder/transfer workflows, playlist comparison, paged export and read-only availability audits.
- Owned-video uploads/metadata/thumbnails/captions and channel branding/banner/sections, using privately staged media IDs.
- Informational, cache-aware daily usage estimates; YouTube enforces quotas and rate limits.
- Mutation receipts, explicit partial resume, and protection against replay of completed operations.
- PHP-FPM hosting in Plesk or Docker Compose, SQLite storage, no background worker.

## Requirements

PHP 8.2 or newer compatible with the locked dependencies, Composer 2, HTTPS, and extensions `curl`, `fileinfo`, `pdo`, `pdo_sqlite`, `sodium`, `openssl`, `mbstring`, `session`, and `json`. The test suite also requires `dom` and `xml`. Use a maintained PHP patch version in production.

The implemented database backend is SQLite on local storage. MariaDB remains a future enhancement. Run one application instance with local filesystem locks and shared PHP-FPM storage; multiple hosts or network filesystems are unsupported.

## Setup

For containers, follow [Docker deployment](docs/DOCKER.md). The included Compose stack builds the PHP-FPM and Nginx images, provides HTTPS through Caddy, and preserves private state in a local volume. After configuring `.env`, start it with `docker compose up -d --build`. Use one application instance per installation.

Plesk's `deploy.sh` hook delegates dependency installation, initialization, and checks to `bin/post-deploy.sh --non-interactive` on every deployment. It defaults to `/opt/plesk/php/8.5/bin/php` and `/usr/lib/plesk-9.0/composer.phar`; override these with `PHP_BIN` and `COMPOSER_BIN` as needed. Configure the private `.env` before unattended deployment. A failed setup or HTTPS check fails the hook; details are in the private project-root `deploy.log`.

For automated Plesk post-deployment setup, run `bash bin/post-deploy.sh --php /opt/plesk/php/8.3/bin/php` as the subscription user. It prompts for configuration, initializes storage, and checks HTTPS; see [script options](docs/PLESK.md#automated-server-side-setup). Google Cloud registration and browser authorizations remain manual.

1. Install dependencies: `composer install --no-dev --prefer-dist --optimize-autoloader`.
2. Copy `.env.example` to `.env` outside the web root.
3. Set `APP_URL` to the exact HTTPS origin, your Google client credentials, and invited email addresses.
   Optionally set `BRAND_NAME` and `BRAND_TAGLINE` for the browser title, header, and footer. They default to `Toyls` and `tools, toys & toils`; values are rendered as escaped text.
   Set `BRAND_URL` to the header brand name link destination; it defaults to `https://toyls.com`. The `/` separator is unlinked, and `youtube` links to `APP_URL`.
4. Run `php bin/console.php key:generate`, then place the generated value in `APP_KEY`. Keep it private and back it up.
5. Run `php bin/console.php init` to create signing keys and migrate storage.
6. Point the domain's document root to `public/` and configure front-controller routing.
7. Follow [Plesk installation](docs/PLESK.md) and [Google/ChatGPT connection](docs/CONNECTIONS.md).
8. Run `php bin/console.php cleanup` hourly through a scheduled task. Busy accounts defer expired media until the next run; other housekeeping continues. See [operations and recovery](docs/OPERATIONS.md).

Migrations 001–006 are bundled in `migrations/001_initial.sql`. The `-- Migration: <original filename>` sections retain their existing database identities, so initialization applies only unfinished steps on upgrades and is safe to repeat. Keep these sections unchanged; add future schema changes as new ordered SQL files starting at `007`.

Migration `007_browser_sessions.sql` separates browser authentication generations from the stable usage identity. Apply it before serving this release; existing browser sessions must sign in again. See [security upgrade steps](docs/PLESK.md#security-upgrade) for worker draining required by the bounded lock format.

No OpenAI API key is needed: the service exposes tools to ChatGPT and does not call OpenAI's model API itself.

## Tools

### Read caching and connection reuse

Equivalent cached reads share an entry regardless of query parameter order. Channel and playlist searches return compact IDs, titles, channel information and pagination by default, reducing model input size; use `response_detail: "full"` when descriptions or upstream metadata are needed. No items are truncated from the returned page.

`API_READ_CACHE_TTL` controls additional caching for read-only playlist metadata, video/channel details, channel uploads, and comments. The default is 30 seconds; values from 0 to 300 are accepted, with 0 disabling these additional caches. Existing search and listing tools retain their explicit cache lifetimes. Ownership checks, duplicate scans, mutation planning, and receipt verification continue to request fresh upstream data.

Responses remain partitioned by user, selected channel, and connection generation. Writes invalidate dependent resources instead of deleting unrelated searches, playlists, ratings, and subscriptions. Invalidations happen around dispatch, including uncertain writes. Version checks prevent an in-flight read from restoring an invalidated cache entry. Deleting an occurrence without a known playlist conservatively invalidates playlist data; full reconnect/disconnect still clears the account's caches.

The JSON HTTP client reuses a bounded pool of connections within one PHP request, partitioned by upstream origin and authorization headers. Request options are reset between calls, so methods, bodies, and headers cannot carry over. Connections are not persisted between PHP-FPM requests; streaming media transport is unchanged. Cache hits avoid API calls, while connection reuse reduces setup overhead without reducing quota costs.

After deploying this change, run `php bin/console.php migrate` before serving traffic. Migration `008_response_cache_dependencies.sql` creates dependency/version tables and clears only old untagged cache entries. Keys, connections, receipts, and usage history are preserved. Changes made directly in YouTube may appear after the applicable cache lifetime; shorten the configured TTL when fresher read-only results are needed.

### Fewer API calls and smaller ChatGPT results

Read tools return compact data by default: essential IDs, titles, positions, privacy/counts where applicable, and pagination. Request `response_detail: "full"` for available raw details. Mutation results summarize receipts, including completed-step IDs, errors, and any uncertain pending step; use `get_operation` with `response_detail: "full"` to inspect the full persisted receipt without a YouTube request. Receipt access requires the current connection generation and the originating tool's permission or the corresponding service read permission. A playlist-only grant cannot read comment, video, caption, media or channel receipts. Compact presentation does not change stored receipts.

MCP `content` contains a short summary, while the payload appears once in `structuredContent`. Refresh ChatGPT's tool definitions after deployment to pick up the changed schemas.

`create_playlist` accepts optional `video_ids` (up to 20 IDs or HTTPS video URLs) and `duplicate_policy` (`skip`, the default, or `allow`). It creates and populates the playlist in one tool call, without a new-playlist scan or separate ownership read. Every video is validated before creation; all creation/insertion steps share one receipt. Partial resume reuses the created playlist and skips completed insertions; ambiguous writes remain blocked from automatic resume. This combines ChatGPT tool calls, not Google's per-entry write requests. Duplicate skipping here applies to repeated IDs in the initial input list.

`search_tracks` returns IDs, titles, and channels using one uncached search call. Set `include_details: true` to fetch durations with an additional metadata call. `response_detail: "full"` changes presentation only; it does not implicitly enable enrichment. Prefer known video IDs and reuse returned IDs to avoid repeat search and read-back calls.

Remove/move preflight fetches only the requested playlist-item IDs and verifies their playlist membership. Move bounds use fresh playlist metadata. Duplicate checks when adding to an existing playlist still require the bounded full scan; unrelated caching and transport behavior are unchanged.

`get_connection_status`, `get_usage_status`, `get_operation`, `list_playlists`, `get_playlist`, `search_tracks`, `create_playlist`, `update_playlist`, `add_tracks`, `remove_tracks`, `move_track`, and `delete_playlist`.

Likes and subscriptions: `list_liked_tracks`, `get_track_ratings`, `rate_track`, `list_subscriptions`, `subscribe_artist`, and `unsubscribe_artist`.

These likes/subscription tools use the official YouTube Data API with the existing Google `youtube.force-ssl` authorization. Liked tracks are YouTube liked videos and may include non-music content; subscriptions are YouTube channels, including non-artists. YouTube Music catalog membership, playback, and exact correspondence with its library are not guaranteed.

Grant `likes.read` for liked videos/ratings, `likes.write` for rating changes, `subscriptions.read` for subscriptions, and `subscriptions.write` for subscribing/unsubscribing. Existing playlist permissions do not grant these tools. Reauthorize the ChatGPT connection with the desired new permissions after deployment.

`rate_track` accepts `video_id` (an 11-character ID or HTTPS YouTube watch URL), `rating` (`like`, `dislike`, or `none` to remove the rating), and `operation_key`. `get_track_ratings` accepts up to 20 `video_ids`. Subscription writes accept a `channel_id` in the form `UC` followed by 22 characters and an `operation_key`. Use artist channel IDs rather than Music browse IDs. Listing tools use `page_size` and `page_token`. Writes share playlist mutation receipts and account locks; subscribing to an existing subscription or removing an absent one completes without a write.

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

Usage status reports estimates for requests observed by this installation, grouped by Pacific day. It cannot determine remaining YouTube quota or see other project traffic. All upstream errors, including read server errors and quota/rate-limit errors, are returned to the caller without automatic retries. Operation receipts, ownership checks, bounded batches, and time limits remain active.
## Development and tests

MCP dispatch lives in `src/Mcp/Dispatcher.php`, wired in `App.php`; tool routing and permission resolution live in `src/Mcp/ToolCatalog.php`. Domain services own validation and effects. `src/Quota/Usage.php` records informational estimates and operation attribution without quota reservation or admission checks. See the [architecture guide](docs/ARCHITECTURE.md).

HTTP route handlers live in `src/Routes/`. `src/App.php` wires their dependencies, maps paths to handlers, and applies shared request validation, browser authentication, account locks, error handling, and response headers. The two protected-resource discovery paths share one handler.

Browser markup lives in plain PHP templates under `views/`, outside the public web root. `src/Http/View.php` renders page templates inside `layout.php` and provides a shared `form.php` partial. Templates receive handler data, an `$escape()` helper for text and attributes, and the current CSRF token. Escape dynamic values; only rendered template output should be emitted as trusted HTML. Include `views/` when deploying the application (the Docker image does this automatically).

The suite uses Pest 3 for PHP 8.2 compatibility. `composer test` runs all tests; use `vendor/bin/pest tests/Auth/OAuthTest.php` or `--filter` for focused runs. Shared fixtures are registered in `tests/Pest.php`; `phpunit.xml` remains the runner configuration.

Tests mirror the `YouTubeMcp` namespace: root classes have root test files, and `Auth`, `Http`, `Mcp`, `Operations`, `Playlist`, `Quota`, `Storage`, and `YouTube` each have a matching directory under `tests/`. Each application class has a dedicated behavior test file. Cross-component security regressions are in `tests/Integration`; private deployment-helper checks are in `tests/Deployment`. Tests use dummy identities, mocked upstream calls, in-memory SQLite, temporary private storage, and a local TLS fixture rather than live Google accounts. Class coverage declarations identify test ownership; they do not imply full line or branch coverage.

```sh
composer install
composer test
composer audit
```

GNU Make provides the same commands through `make install`, `make test`, and `make audit`.
Run `make phpstan` for PHPStan level 6, `make cs` for PHP_CodeSniffer, and
`make cs-check` to preview PHP-CS-Fixer changes. `make cs-fix` applies formatting;
review its diff before committing. `make qa` runs tests and all QA checks.
Every QA target also has a matching Composer script, so Make is optional.

PHPStan checks `src/`, `bin/`, and `public/` against PHP 8.2 without a baseline.
Both style tools use the non-risky PER Coding Style 3.0 preset in
`.php-cs-fixer.dist.php`, with four-space indentation and LF line endings.
The installed fixer does not yet provide a dedicated 3.1 preset.
PHP_CodeSniffer has no bundled PER standard, so a local read-only sniff runs the
same fixer rules in memory. It reports files needing changes and the applicable
fixer names; `composer cs-check` shows the diff and `composer cs-fix` writes it.
Use PHP-CS-Fixer for repairs; this sniff does not provide PHPCBF fixes.
Both style tools check PHP files in `src/`, `tests/`, `bin/`, and `public/`, plus
the fixer configuration, and exclude `HUMANS.md`.

[GitHub Actions](.github/workflows/ci.yml) runs on pushes, pull requests, and
manual dispatch. It installs locked development dependencies and runs tests on
PHP 8.2 and 8.5, plus PHPStan on PHP 8.2. CI also gates the PHP-CS-Fixer formatting check on PHP 8.2. Run style checks locally through
`make cs`, `make cs-check`, or `make qa`.
CI uses test fixtures and needs no Google credentials or production configuration.

On the current Windows development machine, required DLLs exist but are not enabled in the default PHP configuration. Run tests with:

```powershell
$env:OPENSSL_CONF = 'C:/tools/php82/extras/ssl/openssl.cnf'
php -d extension=fileinfo -d extension=pdo_sqlite -d extension=sodium vendor/bin/pest
```

Make is optional on Windows. With those extensions enabled, use `composer phpstan`,
`composer cs`, `composer cs-check`, or `composer cs-fix` directly.

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

See [architecture guide](docs/ARCHITECTURE.md) for the design and [operations guide](docs/OPERATIONS.md) for recovery and maintenance.


## Claude and Gemini CLI

Use the same HTTPS MCP endpoint with OAuth. Configure exact callbacks in `OAUTH_REDIRECT_URIS`; `MCP_CLIENT_ORIGINS` controls browser origins independently. See [Claude and Gemini CLI setup](docs/CONNECTIONS.md#claude-and-gemini-cli) for examples and Gemini's fixed localhost callback port. Real client acceptance testing is still required. Gemini CLI support does not establish compatibility with the Gemini chat website.
