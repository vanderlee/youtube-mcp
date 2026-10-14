# YouTube Music MCP service

A multi-user PHP service for managing YouTube playlists, video ratings and channel subscriptions from ChatGPT through the official YouTube Data API. Each user signs in with Google, authorizes their own YouTube channel, and grants ChatGPT separate service permissions. Google credentials stay on the server.

This is an initial implementation with automated local verification. It has **not yet been validated against a live Google account, YouTube Music, ChatGPT, or a Plesk installation**. Complete the [live acceptance checklist](docs/ACCEPTANCE.md) before treating the deployment as ready for personal playlists.

TV/Devices OAuth, browser-cookie login, unofficial Music tools and Music diagnostics have been removed. The service now provides 57 official Data API and local-service tools. Migration 005 retires Music credentials; migration 006 adds private creator media assets. Refresh ChatGPT's tools and authorize the new service permissions after deployment. See [expanded tools, workflows and limits](docs/DATA_API_TOOLS.md).

## Features

- Official PHP MCP SDK with Streamable HTTP at `/mcp`.
- Google sign-in, invite-only onboarding, explicit channel confirmation, offline token refresh, and encrypted credentials.
- OAuth authorization code with PKCE S256, expiring service tokens, refresh rotation, revocation, and audience validation.
- Playlist listing, inspection, search, creation, metadata updates, track addition/removal/movement, and deletion.
- Video/channel details, uploads listing, channel/playlist search, enhanced video search and scoped comment management.
- Copy/merge/sort/deduplicate/reorder/transfer workflows, playlist comparison, paged export and read-only availability audits.
- Owned-video uploads/metadata/thumbnails/captions and channel branding/banner/sections, using privately staged media IDs.
- Informational, cache-aware daily usage estimates; YouTube enforces quotas and rate limits.
- Mutation receipts, explicit partial resume, and protection against replay of completed operations.
- PHP-FPM hosting in Plesk, SQLite storage, no Docker or background worker.

## Requirements

PHP 8.2 or newer compatible with the locked dependencies, Composer 2, HTTPS, and extensions `curl`, `fileinfo`, `pdo`, `pdo_sqlite`, `sodium`, `openssl`, `mbstring`, `session`, and `json`. The test suite also requires `dom` and `xml`. Use a maintained PHP patch version in production.

The implemented database backend is SQLite on local storage. MariaDB remains a future enhancement. Run one application instance with local filesystem locks and shared PHP-FPM storage; multiple hosts or network filesystems are unsupported.

## Setup

Plesk's `deploy.sh` hook delegates dependency installation, initialization, and checks to `bin/post-deploy.sh --non-interactive` on every deployment. It defaults to `/opt/plesk/php/8.5/bin/php` and `/usr/lib/plesk-9.0/composer.phar`; override these with `PHP_BIN` and `COMPOSER_BIN` as needed. Configure the private `.env` before unattended deployment. A failed setup or HTTPS check fails the hook; details are in the private project-root `deploy.log`.

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

### Fewer API calls and smaller ChatGPT results

Read tools return compact data by default: essential IDs, titles, positions, privacy/counts where applicable, and pagination. Request `response_detail: "full"` for available raw details. Mutation results summarize receipts, including completed-step IDs, errors, and any uncertain pending step; use `get_operation` with `response_detail: "full"` to inspect the full persisted receipt without a YouTube request. Compact presentation does not change stored receipts or permissions.

MCP `content` contains a short summary, while the payload appears once in `structuredContent`. Music compact results omit thumbnails but retain action tokens, requested text, and truncation/bounds indicators. Refresh ChatGPT's tool definitions after deployment to pick up the changed schemas.

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

Usage status reports estimates for requests observed by this installation, grouped by Pacific day. It cannot determine remaining YouTube quota or see other project traffic. Actual YouTube quota/rate-limit errors are returned to the caller without automatic retries. Operation receipts, ownership checks, bounded batches, and time limits remain active.
## Development and tests

The suite uses Pest 3 for PHP 8.2 compatibility. `composer test` runs all tests; use `vendor/bin/pest tests/OAuthTest.php` or `--filter` for focused runs. Shared fixtures are registered in `tests/Pest.php`; `phpunit.xml` remains the runner configuration.

```sh
composer install
composer test
composer audit
```

On the current Windows development machine, required DLLs exist but are not enabled in the default PHP configuration. Run tests with:

```powershell
$env:OPENSSL_CONF = 'C:/tools/php82/extras/ssl/openssl.cnf'
php -d extension=fileinfo -d extension=pdo_sqlite -d extension=sodium vendor/bin/pest
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

