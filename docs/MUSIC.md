# YouTube Music tools

Catalog, Music search, discovery, personal library and listening history use a native PHP adapter to Music's unofficial web endpoints. Existing playlist, likes and subscription tools retain the official YouTube Data API v3 integration. No subprocess or additional runtime is required.

## Runtime and authorization

### Separate Music device OAuth for rejected Web-client tokens

The same minimal anonymous home request was accepted while authenticated Music reads returned HTTP 400 `INVALID_ARGUMENT`/`badRequest`. A matching public web profile therefore does not establish compatibility of the Web OAuth token. The application supports a separate native PHP Music device flow matching the [reference OAuth setup](https://ytmusicapi.readthedocs.io/en/stable/setup/oauth.html). It requests device codes from `https://www.youtube.com/o/oauth2/device/code`, uses the reference Cobalt user agent, and exchanges `code` at Google's token endpoint with grant type `http://oauth.net/grant_type/device/1.0`, matching the [reference implementation](https://github.com/sigma67/ytmusicapi/blob/main/ytmusicapi/auth/oauth/credentials.py).

Earlier releases requested codes through the generic Google device endpoint. After upgrading, start a new Music connection from the dashboard, complete consent, and reconnect ChatGPT. Existing tokens are not converted automatically; pending codes from the earlier protocol are rejected with `MUSIC_AUTHORIZATION_EXPIRED`. This corrects a protocol difference but does not by itself establish that it caused an upstream HTTP 400; verify Music reads live after new consent.

1. In the same Google Cloud project with YouTube Data API enabled, create an additional OAuth client of type **TVs and Limited Input devices**. Keep the existing Web application client for sign-in and official YouTube tools.
2. Store the new values privately as `MUSIC_OAUTH_CLIENT_ID` and `MUSIC_OAUTH_CLIENT_SECRET`. Do not paste them into chat or log output. Configure both or neither.
3. Deploy and run the existing initialization so migration `003_music_connections.sql` is applied.
4. Sign in at the dashboard and select **Connect YouTube Music**. Open the Google verification link, enter the displayed code and authorize the exact channel already confirmed in the dashboard. Select **Check authorization** after Google's polling interval. Each check sends at most one request; there is no worker or automatic polling loop.
5. After the Music connection succeeds, reconnect ChatGPT with the needed service permissions. Saving a new Music connection revokes old service tokens. Verify `get_music_account` and then `get_music_history` live.

When these client settings are configured, Music tools require this separate connection and never silently fall back to Web-client tokens. Official playlist/likes/subscription tools retain their existing credentials. Device tokens and pending device codes are encrypted with the existing `APP_KEY`; tokens are scoped to the user, exact selected channel, Google connection generation, Music connection generation and OAuth client. Token refresh uses the Music client credentials only. Music reconnect changes receipt/action-token generations, preventing replay under a replacement connection. Google reconnect/disconnect deletes the local Music connection and requires new Music consent.

`get_connection_status` reports `music_authentication` (`device_oauth` or the legacy `google_web_oauth` mode) and `music_device_connected`. These fields confirm stored authorization/channel binding, not a successful Music endpoint request; `music_access_checked` remains false because the status tool does not perform that check. `MUSIC_CONNECT_REQUIRED` directs the user to separate Music consent. `MUSIC_CHANNEL_MISMATCH` rejects another channel without storing its credentials.

Disconnect Music removes its local credentials and revokes service access; it does not revoke the separate Google grant remotely. Remove that grant in Google account settings if needed. User deletion cascades local Music credentials. Backups must preserve the database and `APP_KEY` together. Poll intervals and slow-down handling follow [Google's device authorization documentation](https://developers.google.com/identity/protocols/oauth2/limited-input-device); Music-specific code issuance and exchange follow the reference implementation linked above. Live behavior with the newly configured client remains to be verified; this is an unofficial Music integration rather than a guarantee of upstream support.

Use the existing PHP/cURL runtime with outbound HTTPS access. Optional `MUSIC_LOCATION` is a two-letter country code such as `NL`. By default the adapter loads the public Music homepage to discover the actual WEB_REMIX client version and visitor context; it no longer invents a client version from the current date. `MUSIC_CLIENT_VERSION` is an explicit troubleshooting override, not normally needed. Leave it empty unless deliberately pinning a known compatible version.

The metadata request is anonymous: no Google access token is sent to the homepage, and its public profile is reused only within the current PHP API instance. Both the account request body and client headers use the same version and visitor context. If metadata is incomplete or unreachable, `MUSIC_BOOTSTRAP_UNAVAILABLE` stops the operation before dispatching an account request. This preflight failure does not imply an uncertain write. Music redirects are not followed automatically.

In legacy mode the adapter uses the calling user's server-managed Web OAuth access token; PHP continues to encrypt and refresh credentials. Music uses the `https://www.googleapis.com/auth/youtube` grant requested by the reference Music OAuth client. Earlier legacy connections with only `youtube.force-ssl` return `GOOGLE_MUSIC_SCOPE_REQUIRED` before dispatching a Music request. In device mode the Music token supplies its own grant, so a new Web grant is not needed. Existing official API operations continue to work with the older Web grant.

With the optional Music client settings empty, the legacy Web-token integration remains available. There is no shared Music account or browser-cookie import. Matching the Music scope alone does not prove Web-client compatibility; prefer the separate Music route for persistent Web-token rejections. Verify endpoint access after authorization rather than repeatedly adding service scopes.

Live Music access with this deployment's Google grant is **not established by local tests**. Verify `get_music_account` against the intended Music account/channel, especially for brand accounts. `MUSIC_RECONNECT_REQUIRED` denotes upstream 401; `MUSIC_ACCESS_DENIED` denotes 403 and does not promise that reconnecting will resolve it. There is no fallback to another account. Google reconnect revokes old service tokens, so reconnect ChatGPT afterward. Unofficial endpoint and response formats can change independently of the Data API.

## Permissions and tools

Music device authorization requires storage migration `003_music_connections.sql`. A missing Music table returns `DEPLOYMENT_MIGRATION_REQUIRED` before contacting Google; the operator must run `php bin/console.php init` from the deployed project root as the subscription user and restart authorization. See [Plesk database troubleshooting](PLESK.md#database-failure-during-music-device-authorization) for safe diagnostics and repair.

Read tools accept `response_detail: "compact"` (default) or `"full"`. Compact Music results omit thumbnails while preserving account-bound action tokens, requested text, and explicit prefix/truncation indicators. This option is handled at the MCP presentation boundary; it does not broaden permissions or fetch additional upstream pages. MCP text contains a short summary and the actual payload is in `structuredContent`.

| Permission | Tools |
| --- | --- |
| `catalog.read` | `get_music_artist`, `get_music_artist_albums`, `get_music_album`, `get_music_song`, `get_music_lyrics`, `get_music_song_credits`, `search_music`, `get_music_search_suggestions` |
| `discovery.read` | `get_music_home`, `get_music_related`, `get_music_radio`, `get_music_charts`, `get_music_mood_categories`, `get_music_mood_playlists` |
| `library.read` | `get_music_account`, `get_music_library` |
| `library.write` | `set_music_song_library_status`, `set_music_playlist_library_status` |
| `history.read` | `get_music_history` |
| `history.write` | `add_music_history_item`, `remove_music_history_items` |

Existing playlist/likes/subscription permissions do not grant these tools. Reauthorize ChatGPT with the desired new permissions after upgrading. Operation receipts remain readable with `get_operation` and `playlists.read`.

## Catalog, search and discovery

`search_music` accepts `query`, optional `filter` (`songs`, `videos`, `albums`, `artists`, `playlists`), `limit` (1–50, default 25), and `ignore_spelling`. Search suggestions accept `query` and `limit`. Results are a bounded prefix of the first upstream response, without continuation/export support.

Use Music result IDs: artist/album/lyrics/credits tools accept `browse_id`; song and radio accept an 11-character `video_id`. Artist albums require the `browseId` and `params` in the corresponding artist section's `navigation` field. Album track `creditsBrowseId` values feed the credits tool. Radio returns `lyrics` and `related` browse IDs. These IDs are not interchangeable with playlist IDs.

Home accepts `sections` (1–10, default 5). Radio accepts `limit`. Charts accept `country` (default `ZZ`, global). Mood categories return `params` for mood playlists. Artist/album metadata and discovery responses return normalized `sections` with titles and contents. Song results include metadata and playability only. No tool streams audio or starts playback.

## Personal library

`get_music_library` accepts `kind`: `songs`, `albums`, `artists`, `playlists`, or `subscriptions`, and `limit`. All kinds except playlists accept `order`: `a_to_z`, `z_to_a`, or `recently_added`. Saved Music playlists can include playlists owned by others; the existing `list_playlists` lists owned YouTube playlists.

To save a song, retrieve its album, radio queue or library data and pass `feedbackTokens.add` to `set_music_song_library_status` as `feedback_token`. To remove it, use a fresh `feedbackTokens.remove`. Keep the token verbatim. Tokens are signed for the user, Google connection generation and library action and expire after one hour. History removal tokens cannot be used for library changes. Pin/unpin is not exposed.

To save or unsave an album/playlist, use `set_music_playlist_library_status` with `playlist_id`, `in_library` (true/false) and `operation_key`. For an album use its `audioPlaylistId`. This changes the saved library rather than the playlist's ownership or contents.

## Listening history

`get_music_history` accepts `limit` and returns recent history supplied by Music, with account-bound `feedbackToken` values for specific occurrences. `remove_music_history_items` accepts up to 20 as `feedback_tokens`, plus `operation_key`. Tokens expire after one hour. Brand-account history removal may be unsupported; verify it before relying on it.

`add_music_history_item` accepts `video_id` and `operation_key`. It records history through a validated playback tracking endpoint returned by Music without playing audio. Only use it when the user explicitly wants to change history. History changes can affect recommendations.

All writes use account locks and existing operation receipts. Reuse the same operation key and arguments on retry; completed operations replay locally. Resume partial receipts only with `resume: true`; uncertain writes cannot automatically resume. Inspect Music and the receipt first. After a Google reconnect, inspect prior writes before submitting them under the new connection namespace.

## Limits and failure handling

- Native requests have a 20-second timeout and a 5-second connection timeout. A Music call first retrieves public client metadata, then makes its account request. History addition also makes a tracking request, giving at most three sequential requests (up to 60 seconds of request time).
- MCP input remains capped at 64 KiB. Raw Music responses are capped at 4 MB; parsed nested lists at 50 items. Lists/searches return first-response prefixes, not complete exports. Full albums and discographies may exceed these bounds.
- No automatic write retries, followed redirects, arbitrary upstream methods, user-provided tracking URLs or shared private caches.
- Raw commands, streaming URLs and private upstream errors are not returned or logged. Only normalized data fields and signed action tokens are exposed.
- Music traffic is outside local official Data API quota estimates; `get_usage_status` does not claim to count Music requests or know their remaining quota.
- Throttling returns `MUSIC_RATE_LIMITED`. Unrecognized response layouts return `MUSIC_FORMAT_CHANGED`; transport failures return `MUSIC_UNAVAILABLE` for reads. Ambiguous writes return `UPSTREAM_UNCERTAIN` and stay protected by receipts.
- Website interaction gates return `MUSIC_INTERACTION_REQUIRED`. Feedback writes require an explicit processed acknowledgment for every token, avoiding false completed receipts.

## Verification

Music upstream errors include safe diagnostics in both the MCP text error and `structuredContent`, so clients that display only the text do not hide the HTTP status. PHP's error log receives a `music_upstream_error` JSON event with the endpoint, HTTP status, allowlisted status/reasons and public client-version metadata. Credentials, raw upstream messages/bodies and visitor identifiers are excluded. Legacy Google `error.errors[].reason` values such as `badRequest` are supported alongside `error.details[].reason`.

Successful `get_connection_status` identifies stored connections only; it does not establish Music endpoint access. Use `get_music_account` for the latter. If account-menu and history requests both return HTTP 400 `INVALID_ARGUMENT` with actual client metadata, more MCP permissions and repeated consent are not established remedies. The generic response does not prove whether Google rejects the OAuth client/token or another request field. Device mode uses separately issued Music tokens; legacy mode uses Web tokens. Do not claim the history integration works on the basis of official account status alone.

```sh
composer test
php bin/console.php music:check
```

Tests verify native request/response handling, parsers, permissions, account isolation and replay protection offline. `music:check` validates PHP/cURL and configuration without network calls. Neither proves live authorization or playback. Complete the [Plesk live checks](PLESK.md#youtube-music-tools) with the deployed PHP-FPM runtime and two accounts before depending on private library/history changes.
