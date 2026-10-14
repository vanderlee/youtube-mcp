# Official Data API tools and workflows

The catalog contains 57 tools: the original 18 plus 39 additions below. All upstream operations use the official YouTube Data API and the user's normal Google OAuth connection. No TV/device client, browser cookies, subprocess or background worker is used. Local tests do not establish live Google or Plesk compatibility.

## Browsing and comments

| Tools | Permission | Behavior |
|---|---|---|
| `get_video_details` | `videos.read` | Up to 50 video IDs; snippet, statistics, duration, status and returned region restrictions. Omitted IDs are reported, not assumed deleted. |
| `get_channel` | `channels.read` | Look up a channel ID or @handle; omit both for the confirmed channel. Does not switch accounts. |
| `list_channel_uploads` | `channels.read` | One page from the returned uploads playlist. Optional channel ID, page token and page size up to 50. |
| `search_channels` | `channels.read` | One search page for channels. |
| `search_playlists` | `videos.read` | One search page for playlists. |
| `list_comments`, `list_comment_replies` | `comments.read` | One page up to 50 items; top-level threads can contain an incomplete subset of replies. Fetch replies separately when needed. |
| `post_comment`, `reply_to_comment`, `update_comment`, `delete_comment` | `comments.write` | Create comments/replies or change comments authored by the confirmed channel. Writes use receipts. |
| `moderate_comments` | `comments.moderate` | Publish, hold or reject up to 20 comments after checking every associated video's owner against the confirmed channel. |

`search_tracks` retains its existing `playlists.read` permission. It additionally accepts `channel_id`, `published_after`, `published_before`, `order`, `duration` and `category_id`. Dates are RFC 3339, with the lower bound before the upper bound. Orders are date/rating/relevance/title/viewCount; duration is any/short/medium/long. Channel/playlist search supports the common channel/date/order filters, not video duration/category. Search ordering and filters do not guarantee an exhaustive catalog or immediate visibility of new uploads. Use uploads playlists for channel uploads.

## Playlist workflows

| Tool | Permission | Behavior |
|---|---|---|
| `copy_playlist` | `playlists.write` | Copy an accessible source into a new playlist, private by default; optional privacy and duplicate policy. |
| `merge_playlists` | `playlists.write` | Append up to five accessible sources to an owned destination; duplicates skipped by default. |
| `sort_playlist` | `playlists.write` | Sort owned occurrences by title ascending/descending or playlist-added date oldest/newest. Added date is not video publication date. |
| `remove_playlist_duplicates` | `playlists.write` | Remove repeated video occurrences while retaining the first or last occurrence. |
| `reorder_playlist` | `playlists.write` | Supply a complete permutation of current occurrence IDs, preserving all entries. |
| `transfer_tracks` | `playlists.write` | Copy or move up to 20 selected occurrence IDs between different playlists. Move requires ownership of both. Additions precede source removals. |
| `compare_playlists` | `playlists.read` | Complete bounded snapshots, video-ID differences and occurrence counts. |
| `export_playlist` | `playlists.read` | One page in JSON, CSV or watch-URL format; includes next page token and complete flag. CSV neutralizes formula-prefixed titles. |
| `audit_playlist` | `playlists.read` | One page of returned video metadata/privacy/region restrictions. Omitted metadata does not distinguish deletion from lack of access; no automatic removal. |

Write workflows persist a fixed local plan before any upstream write. Full scans are limited to 1000 entries per playlist, 1000 combined source entries and 45 seconds per scan phase. A destination exceeding 1000 entries is rejected before mutation. A scan limit returns an error, never a silently truncated mutation plan.

Each invocation dispatches at most 20 writes within a 45-second write window; an already-dispatched HTTP request can take up to another 20 seconds. A larger job returns a partial receipt. Inspect it and invoke the same tool/arguments/operation key with `resume: true` to continue pending steps. Completed jobs replay locally. Interrupted/uncertain writes require reconciliation and cannot automatically resume. Source snapshots remain fixed across resume; changed destination membership/order blocks further writes. Local account locks coordinate this service, but cannot prevent simultaneous edits directly in YouTube.

## Creator tools

| Tools | Permission | Behavior |
|---|---|---|
| `list_video_categories` | `videos.read` | Category metadata for a region (default US). |
| `list_media_assets`, `delete_media_asset` | `media.read`, `media.write` respectively | List or remove privately staged assets belonging to the current user/channel/connection generation. |
| `upload_video` | `videos.upload` | Upload a staged video, private by default; title required, description/category/tags/privacy optional. |
| `update_video`, `delete_video`, `set_video_thumbnail` | `videos.write` | Update supplied metadata/privacy, delete an owned video or replace its thumbnail. Unspecified metadata is preserved. |
| `list_captions`, `download_caption` | `captions.read` | Owned-video caption metadata or SRT/VTT content, bounded to 1 MiB. Not a public transcript/lyrics service. |
| `upload_caption`, `update_caption`, `delete_caption` | `captions.write` | Manage timed caption files/name/draft status after exact owned-video/track checks. Deprecated automatic synchronization is not used. |
| `update_channel_branding`, `set_channel_banner` | `channels.write` | Update supplied description/keywords/country/trailer, or upload/apply a banner to the confirmed channel. |
| `list_channel_sections` | `channels.read` | Current channel presentation sections. |
| `create_channel_section`, `update_channel_section`, `delete_channel_section` | `channels.write` | Manage owned single-playlist, multiple-playlist or multiple-channel sections. Type/title/reference consistency is checked before writing. |

The existing Google `youtube.force-ssl` scope supports these official operations; no separate Google client is required. Existing MCP grants do not acquire new service scopes automatically: reconnect ChatGPT and grant only the desired capabilities. Uploads may remain private due to Google's restrictions on unverified API projects. Google also controls thumbnail eligibility, caption permissions and banner dimensions. Channel title/handle changes, arbitrary public caption downloading and channel-avatar uploading are not exposed by these tools.

### Stage files safely

1. Apply the `006_media_assets.sql` section of `migrations/001_initial.sql` through the existing initialization.
2. Sign into the HTTPS dashboard and choose **Stage media** (`/media`). The form requires the browser session and CSRF token.
3. Choose a file: video MP4/WebM/MOV/AVI up to 64 MiB; PNG/JPEG image up to 2 MiB; UTF-8 timed SRT/VTT up to 1 MiB. MIME/content checks apply. The original filename is ignored.
4. Use the returned `asset_id` in a creator tool. Alternatively grant `media.read` and use `list_media_assets`. Staging itself does not upload or publish anything to YouTube.

Linux MIME databases may identify SRT files as `application/x-subrip`; staging normalizes this to `text/plain` and still enforces the UTF-8, timed-caption, and 1 MiB checks. Client-supplied MIME labels do not bypass content validation.

Files live outside `public/` under private `STORAGE_PATH/media`, use random IDs and owner/channel/generation-bound database records, and expire after 24 hours. At most 50 active assets per user are allowed. SHA-256 checks reject changed files. No arbitrary server paths, remote URL downloads or binary MCP arguments are accepted. Reconnecting Google invalidates old assets. Account deletion removes owned files; the existing cleanup command removes expired files and old orphaned files. Preserve private ownership and backup controls for media too.

Media uploads use Google's resumable-session protocol with one streamed content request per operation. Session URLs are encrypted in receipts and never printed. Individual calls time out after 20 seconds. Interrupted/HTTP 308 uploads are marked uncertain; no automatic chunk retransmission is attempted. Do not submit a new operation key to bypass an uncertain upload without checking whether the video or caption was created.

Configure the domain PHP `upload_max_filesize=64M` and `post_max_size=65M` if full-size assets are needed, and permit about 65 MiB only for the browser media endpoint. Keep MCP/OAuth requests bounded to 64 KiB. See [Plesk configuration](PLESK.md#creator-media-and-expanded-tools). No new `.env` setting is required.

## Live acceptance

`get_operation` reads receipts only for the current Google connection generation. It accepts the originating tool's permission or its corresponding service read permission (for example, `comments.write` or `comments.read` for a comment receipt, and `videos.upload` or `videos.read` for an upload receipt). Receipt retrieval does not require an unrelated `playlists.read` grant. Receipts remain stored for their normal retention period, but reconnect/disconnect removes MCP access to the old generation; reconcile uncertain operations before replacing a connection. See [security upgrade steps](PLESK.md#security-upgrade).

Use disposable private playlists/video uploads and explicitly authorized comment targets. Verify scoped reads, public-channel lookup, search filters and continuation tokens. Copy/sort/dedupe/transfer disposable playlists, inspect partial receipts, explicitly resume and replay completed operations. Validate two users cannot use each other's asset IDs or change each other's videos/comments/sections. Verify uploads default private, a replaced caption belongs to the selected video and interrupted writes are not replayed. Restore test comment/branding changes and remove only disposable content. Automated tests use fixtures and isolated storage, not live accounts.

Official references: [API resources](https://developers.google.com/youtube/v3/docs), [search](https://developers.google.com/youtube/v3/docs/search/list), [uploads](https://developers.google.com/youtube/v3/guides/using_resumable_upload_protocol), [captions](https://developers.google.com/youtube/v3/docs/captions), [channel sections](https://developers.google.com/youtube/v3/docs/channelSections).
