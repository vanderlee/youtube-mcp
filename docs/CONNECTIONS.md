# Google and ChatGPT setup

## Google Cloud

1. Create one Google Cloud project for this service and enable YouTube Data API v3.
2. Configure the OAuth consent screen with a clear app name and the production HTTPS domain. Configure the audience and test users as appropriate.
3. Create OAuth credentials of type Web application.
4. Register exactly `https://YOUR_DOMAIN/google/callback` as the authorized redirect URI.
5. Set `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` in private `.env` configuration.
6. Review actual project quotas in Cloud Console. YouTube enforces limits; local counters are informational.

The app requests `openid email profile` for sign-in. Connecting YouTube additionally requests `https://www.googleapis.com/auth/youtube.force-ssl` with offline access. This is broader than playlist-only permission: Google has no separate playlist-only scope in this implementation. Explain this scope honestly in consent/setup materials.

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

Reconnect replaces the active connection and revokes existing ChatGPT service tokens. Connect ChatGPT again afterward. Disconnect removes local Google credentials and attempts Google revocation; if that call fails, the dashboard explains that access should also be removed in Google account settings.

## ChatGPT connection

Use the current custom MCP connection interface on a ChatGPT account/workspace with permission to add custom integrations. The service URL is `https://YOUR_DOMAIN/mcp`.

Choose **OAuth**, rather than No authentication or mixed authentication. This deployment requires authentication for all MCP transport requests. Discovery endpoints for OAuth metadata are public, but MCP initialization/tool listing require service authorization.

The service supports dynamic client registration at `/oauth/register` for operator-allowed callbacks. If ChatGPT's setup supports DCR, leave static credentials unset and choose that registration route. It does not advertise CIMD support.

For static credentials, first add the exact callback URL shown by the ChatGPT setup interface to `OAUTH_REDIRECT_URIS`, then run:

```sh
php bin/console.php client:create https://EXACT_CALLBACK_URI ChatGPT
```

Enter the returned client ID and secret in ChatGPT's connection setup. The output contains a secret: keep it private and do not paste it into chat or logs. The example callback in `.env.example` is a starting value, not a guarantee for every current workspace or client.

The authorization request must use PKCE S256 and `resource=https://YOUR_DOMAIN/mcp`. Approve requested read/write permissions in the browser. The default scope is read-only; playlist writes require `playlists.write`. Google connection must already exist before service consent succeeds.

After setup, install/enable the integration according to the current ChatGPT UI. Refresh its tools after changing schemas. Test usage status first, then one disposable playlist workflow. No standard `search`/`fetch` pair is required for these custom playlist tools.

Reference: [OpenAI custom MCP connection guide](https://developers.openai.com/api/docs/guides/custom-mcp-server) and [authentication guidance](https://developers.openai.com/plugins/build/auth).

## Suggested first request

> Use the YouTube playlist integration to show my connected channel and remaining usage. Create a private disposable playlist named MCP Test, add this known music video, list its entries, and show me the YouTube Music link. Use a stable operation key for each change.

Then manually inspect Music playback, move/remove an entry, and delete only the disposable playlist. ChatGPT's own write confirmations are handled by ChatGPT; tool annotations do not replace permission checks on the server.

