# Google and ChatGPT setup

## Before connecting

Finish [Plesk setup](PLESK.md) first. Confirm HTTPS health and metadata work and PHP-FPM can write private storage. A passing CLI initialization does not prove the web worker has the same permissions or extensions.

These are two independent OAuth connections:

| Connection | Credentials | Callback |
|---|---|---|
| Service to Google | Google Cloud Web application client, stored in `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` | Your service's `/google/callback` |
| ChatGPT to service | Client registered with this service through DCR or `client:create` | The exact callback supplied by ChatGPT |

Do not enter Google client credentials into ChatGPT's service OAuth fields or register ChatGPT's callback as Google's redirect URI. Use your own HTTPS origin in place of example domains.

## Google Cloud

1. Create one Google Cloud project for this service and enable YouTube Data API v3.
2. Configure the OAuth consent screen with a clear app name and the production HTTPS domain. Configure the audience and test users as appropriate.
3. Create OAuth credentials of type Web application.
4. Register exactly `https://YOUR_DOMAIN/google/callback` as the authorized redirect URI.
5. Set `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` in private `.env` configuration.
6. Review actual project quotas in Cloud Console. YouTube enforces limits; local counters are informational.

The app requests openid, email and profile for sign-in. Connecting YouTube additionally requests https://www.googleapis.com/auth/youtube.force-ssl with offline access for official Data API operations. Existing broader youtube grants remain accepted; new connections no longer request that extra scope. TV/Devices OAuth, browser cookies and unofficial Music endpoints are no longer supported. get_connection_status reports only the standard account and Google/service scope names.

Review current Google publishing and verification requirements for this scope and audience. Testing-mode authorizations can have restrictions, including token lifetime; do not assume a test consent configuration is suitable for ongoing public operation. Quota extensions are a separate process from OAuth app verification.

References: [Google web-server OAuth](https://developers.google.com/identity/protocols/oauth2/web-server), [YouTube quota](https://developers.google.com/youtube/v3/determine_quota_cost), [quota extensions](https://developers.google.com/youtube/v3/guides/quota_and_compliance_audits).

## User onboarding

1. Add each user's verified email to `ALLOWED_EMAILS`, or explicitly enable `ALLOW_REGISTRATION=true` when ready for broader access.
2. Open the service dashboard and sign in with Google.
3. Select Connect YouTube account and grant permission using the same Google identity.
4. Choose the intended YouTube channel in Google's account/channel selection when offered.
5. Confirm the displayed channel name and ID match the user's YouTube Music channel.
6. Create a disposable private playlist and confirm Music visibility/playback before managing existing playlists.

Each connected identity has separate encrypted tokens. The Google account's identity is verified by Google's userinfo endpoint; emails are used for invitations, not as the permanent identity key.

Google's test-user list and this service's invitation list are separate controls: configure both when using Testing mode. Testing-mode grants with YouTube permissions have seven-day refresh-token expiry; review Google's audience requirements before ongoing use. Publishing and verification are separate steps. [Google app audience](https://support.google.com/cloud/answer/15549945?hl=en)

Complete one authorization flow at a time in one browser session. Pending Google and service authorization state expires after ten minutes; starting another flow in a second tab replaces pending state. After an error or header change, reload the dashboard and start again rather than replaying an old callback or approval form.

Browser sign-in expires after 30 minutes of inactivity or 12 hours in total by default. Configure positive `SESSION_IDLE_SECONDS` and `SESSION_ABSOLUTE_SECONDS` values to change these bounds. Account deletion invalidates all browser sessions; recreating the same Google identity does not restore those sessions. Browser expiry is independent of MCP access/refresh token expiry.

Reconnect replaces the active connection and revokes existing ChatGPT service tokens. Connect ChatGPT again afterward. Disconnect removes local Google credentials and attempts Google revocation; if that call fails, the dashboard explains that access should also be removed in Google account settings.

Google token refresh failures preserve stored Google credentials and do not revoke ChatGPT access or refresh tokens. An `invalid_grant` response requires Google reconnection; other Google rejections report `GOOGLE_UNAVAILABLE` so operators can repair OAuth configuration or wait for recovery without disconnecting users. Normal MCP token expiry and rotation still apply. Explicit Google reconnection continues to invalidate previous service authorization and connection-bound data.

## ChatGPT connection

The expanded official tools use separate `videos.read/write/upload`, `channels.read/write`, `comments.read/write/moderate`, `captions.read/write` and `media.read/write` service permissions. Existing playlist, like and subscription grants remain independent. Reconnect ChatGPT to request desired new permissions; merely deploying does not expand a grant. No TV/device client, cookies or new Google scope are required. See [tool-permission mapping](DATA_API_TOOLS.md).


Use the current custom MCP connection interface on a ChatGPT account/workspace with permission to add custom integrations. The service URL is `https://YOUR_DOMAIN/mcp`.

Choose **OAuth**, rather than No authentication or mixed authentication. This deployment requires authentication for all MCP transport requests. Discovery endpoints for OAuth metadata are public, but MCP initialization/tool listing require service authorization.

The service supports public dynamic client registration at `/oauth/register` with exactly one operator-allowed callback and `token_endpoint_auth_method: "none"` (the default). Repeated registration reuses one public client per callback; its ID is public and each grant still requires consent and PKCE S256. Arbitrary client names do not create more public clients. If ChatGPT's setup supports this DCR mode, leave static credentials unset and choose that registration route. Confidential clients using `client_secret_post` or `client_secret_basic` must be created through the operator CLI, rather than the anonymous endpoint. It does not advertise CIMD support.

For static credentials, first add the exact callback URL shown by the ChatGPT setup interface to `OAUTH_REDIRECT_URIS`, then run:

```sh
php bin/console.php client:create https://EXACT_CALLBACK_URI ChatGPT
```

Enter the returned client ID and secret in ChatGPT's connection setup. The output contains a secret: keep it private and do not paste it into chat or logs. The example callback in `.env.example` is a starting value, not a guarantee for every current workspace or client.

### Discover the actual callback

ChatGPT can use a connection-specific callback. A guessed URI can allow metadata discovery but fail during registration or authorization.

1. Start one ChatGPT connection attempt using the intended MCP URL and OAuth mode.
2. Copy the exact callback displayed by that setup, if available.
3. If DCR is rejected, inspect the private application log for `oauth_registration_callback_rejected`; it records the requested URI.
4. Verify the URI belongs to the intended ChatGPT flow, then add it verbatim to `OAUTH_REDIRECT_URIS`. Multiple exact URIs can be comma separated.
5. Retry the same setup. If you create a different connection, check whether its callback changed.

Keep exact matching; do not introduce a wildcard to bypass rejection. A static client's callback list is stored in the database, so changing `.env` does not update an existing registration. Create a new client with the correct callback and use its new credentials when needed.

The post-deployment script's `--create-client` saves `chatgpt-client.json` in private storage and preserves it on rerun. Check that file's callback privately before reusing it. If the callback changed, use `client:create` for a new registration rather than assuming the script rewrites old credentials.

The authorization request must use PKCE S256 and `resource=https://YOUR_DOMAIN/mcp`. Approve requested read/write permissions in the browser. The default scope is read-only; playlist writes require `playlists.write`. Google connection must already exist before service consent succeeds.

After setup, install/enable the integration according to the current ChatGPT UI. Refresh its tools after changing schemas. Test usage status first, then one disposable playlist workflow. No standard `search`/`fetch` pair is required for these custom playlist tools.

Confirm the consent page shows both `playlists.read` and `playlists.write` for full management. Sign-in and tool discovery do not prove write permission. If the connection is read-only, restart authorization requesting write permission; retain the server's permission checks.

Reference: [OpenAI custom MCP connection guide](https://developers.openai.com/api/docs/guides/custom-mcp-server) and [authentication guidance](https://developers.openai.com/plugins/build/auth).

## Troubleshoot the failed stage

Use browser developer tools and private Plesk access/error logs together. Record endpoint, status, timestamp, and request ID where available. Do not share raw callback query strings, authorization codes, cookies, bearer tokens, client secrets, or token responses.

| Observation | Next action |
|---|---|
| Initial unauthenticated `/mcp` returns 401 | Expected challenge; continue OAuth discovery |
| Metadata returns 200 JSON | Discovery works; browser consent and token exchange still need verification |
| `/oauth/register` returns 201 | DCR succeeded; continue authorization |
| Registration callback rejected | Verify and allowlist the exact callback, then retry the same setup |
| Google redirect URI mismatch | Match the service's Google callback exactly in Cloud Console |
| Google callback gets HTML 403 | Check WAF audit logs; follow the targeted procedure in PLESK.md |
| Browser blocks a form redirect | Inspect effective CSP headers, including host-added policies |
| State expired or approval failed | Restart one flow; check session cookies/storage and browser policy |
| `/oauth/token` returns 200 | Token exchange succeeded; verify authenticated MCP next |
| Authenticated MCP returns 403 | Inspect Origin/transport rejection logs and Host forwarding |
| Reads work but writes fail for scope | Complete fresh consent with `playlists.write` |
| MCP returns 401 after Google reconnect | Reconnect ChatGPT; old service tokens were revoked |

The application sends `Referrer-Policy: same-origin` and permits form redirects to Google Accounts and ChatGPT in its CSP. An additional restrictive CSP configured by the host is enforced alongside the app's policy and can still block redirects. Reload the dashboard after header changes and restart authorization. Do not remove Origin validation or disable the WAF globally to conceal a policy/routing problem.

## Usage expectations

`get_usage_status` reports observed request-cost estimates with `quota_enforced_by: youtube` and `remaining_quota_known: false`. It cannot report actual remaining Cloud quota. YouTube enforces limits; legacy local budget settings are ignored by the current implementation. Compare usage with Cloud Console and inspect partial receipts before retrying interrupted work. Users normally share the same project's quota.

## Suggested first request

Refresh tools after deploying the compact-result and create-with-tracks changes. Read results default to compact; use `response_detail: "full"` only when descriptions, raw fields, or full receipts are needed. Search enrichment is separately controlled by `include_details: true`. Ask ChatGPT to reuse returned IDs and combine creation with initial `video_ids` rather than issuing create, add, and read-back calls by habit.

> Use the YouTube playlist integration to show my connected channel and observed usage. Create a private disposable playlist named MCP Test, add this known music video, list its entries, and show me the YouTube Music link. Use a stable operation key for each change.

Then manually inspect Music playback, move/remove an entry, and delete only the disposable playlist. ChatGPT's own write confirmations are handled by ChatGPT; tool annotations do not replace permission checks on the server.

