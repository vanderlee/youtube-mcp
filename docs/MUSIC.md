# YouTube Music tools

Catalog, Music search, discovery, personal library and listening history use a native PHP adapter to Music's unofficial web endpoints. Existing playlist, likes and subscription tools retain the official YouTube Data API v3 integration. No subprocess or additional runtime is required.

## Runtime and authorization

Use the existing PHP/cURL runtime with outbound HTTPS access. Optional `MUSIC_LOCATION` is a two-letter country code such as `NL`. Optional `MUSIC_CLIENT_VERSION` overrides the default WEB_REMIX client version derived from the current UTC date.

The adapter uses the calling user's server-managed Google OAuth access token; PHP continues to encrypt and refresh credentials. Music requires the `https://www.googleapis.com/auth/youtube` grant used by the reference Music OAuth client. Earlier connections with only `youtube.force-ssl` return `GOOGLE_MUSIC_SCOPE_REQUIRED` before dispatching a Music request. Reconnect Google after deployment, grant Manage your YouTube account, confirm the selected channel and reconnect ChatGPT. Existing official API operations continue to work with the older grant.

There is no shared Music account, browser-cookie import, separate credential file or device OAuth flow. The existing Google Web application client remains configured. The reference Music setup uses a TVs and Limited Input devices OAuth client; matching its scope does not prove that this deployment's Web-client tokens will be accepted. Verify live Music access after fresh consent; do not repeatedly reconnect if safe diagnostics show a client/request rejection.

Live Music access with this deployment's Google grant is **not established by local tests**. Verify `get_music_account` against the intended Music account/channel, especially for brand accounts. `MUSIC_RECONNECT_REQUIRED` denotes upstream 401; `MUSIC_ACCESS_DENIED` denotes 403 and does not promise that reconnecting will resolve it. There is no fallback to another account. Google reconnect revokes old service tokens, so reconnect ChatGPT afterward. Unofficial endpoint and response formats can change independently of the Data API.

## Permissions and tools

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

- Native requests have a 20-second timeout and a 5-second connection timeout. History addition uses a player preflight and a tracking request.
- MCP input remains capped at 64 KiB. Raw Music responses are capped at 4 MB; parsed nested lists at 50 items. Lists/searches return first-response prefixes, not complete exports. Full albums and discographies may exceed these bounds.
- No automatic write retries, followed redirects, arbitrary upstream methods, user-provided tracking URLs or shared private caches.
- Raw commands, streaming URLs and private upstream errors are not returned or logged. Only normalized data fields and signed action tokens are exposed.
- Music traffic is outside local official Data API quota estimates; `get_usage_status` does not claim to count Music requests or know their remaining quota.
- Throttling returns `MUSIC_RATE_LIMITED`. Unrecognized response layouts return `MUSIC_FORMAT_CHANGED`; transport failures return `MUSIC_UNAVAILABLE` for reads. Ambiguous writes return `UPSTREAM_UNCERTAIN` and stay protected by receipts.
- Website interaction gates return `MUSIC_INTERACTION_REQUIRED`. Feedback writes require an explicit processed acknowledgment for every token, avoiding false completed receipts.

## Verification

```sh
composer test
php bin/console.php music:check
```

Tests verify native request/response handling, parsers, permissions, account isolation and replay protection offline. `music:check` validates PHP/cURL and configuration without network calls. Neither proves live authorization or playback. Complete the [Plesk live checks](PLESK.md#youtube-music-tools) with the deployed PHP-FPM runtime and two accounts before depending on private library/history changes.
