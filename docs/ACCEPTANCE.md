# Live acceptance checklist

This is a manual checklist, not a record of completed verification. Local tests use mocked Google responses and isolated storage. Record the date, application revision, PHP/FPM version, hosting setup, client/version, result and evidence for each applicable check. Do not include tokens, secrets or private media in the record. Mark untested features explicitly.

Use dedicated test identities and disposable private resources. Live writes, revocations and deletion require the operator's explicit authorization; do not test against personal playlists by default. Keep the account/channel identity visible throughout and record IDs for cleanup.

## Deployment and OAuth

- [ ] Confirm HTTPS, the configured public origin, routing and private-file protection. Requests for `.env`, storage and signing keys must not expose content.
- [ ] Check `/health/live`, `/health/ready` and both OAuth metadata documents. Confirm an unauthenticated MCP request receives 401 with discovery information.
- [ ] Sign in as an invited identity; verify an uninvited identity is rejected when registration is disabled.
- [ ] Connect Google with offline permission, select the intended channel and confirm its displayed ID. Confirm independent read permissions do not grant writes.
- [ ] Connect each supported client using its exact permitted redirect, PKCE S256 and this installation's MCP resource. Initialize, list tools and perform a scoped read.
- [ ] Test refresh and access revocation. Confirm revoked/expired service authorization cannot dispatch a tool. Use disposable connections for disconnect/reconnect checks.

## Playlist behavior and isolation

- [ ] Create a private playlist with known test video IDs. Verify its privacy and initial entries directly in YouTube.
- [ ] Read paginated entries, edit metadata, add duplicate occurrences, move an occurrence and remove it by playlist-item ID. Confirm omitted metadata is preserved.
- [ ] Replay a completed operation with identical key/arguments and verify no duplicate upstream effect. Confirm changed arguments with the same key are rejected.
- [ ] Exercise a bounded workflow requiring explicit partial resume on disposable data; verify completed steps are preserved. Do not simulate ambiguous failures by interrupting a real personal-account write.
- [ ] Verify uncertain-operation handling using local fixtures and review the [recovery procedure](OPERATIONS.md#recovering-mutations). Live uncertainty must be reconciled manually, never automatically replayed.
- [ ] Connect a second test identity/channel. Confirm private data, media assets, receipts and write permissions remain isolated, including after reconnect.
- [ ] Inspect relevant Music playback/library behavior separately if it matters to the installation. Official Data API success does not guarantee Music availability.
- [ ] Delete only the disposable playlists after recording results.

## Optional tool families

- [ ] Likes/subscriptions: read with the separate scopes; record original state, exercise authorized changes on test content, replay completed keys and restore original state.
- [ ] Comments/moderation: use owned test content; verify author/owner restrictions and that unrelated scopes cannot read operation receipts.
- [ ] Creator/media: stage supported disposable files, upload a private test video, and exercise only the thumbnail/caption/branding/section tools intended for this deployment. Verify ownership, staged-file expiry, completed replay and actual upstream results. Record unsupported or untested features.
- [ ] Remove staged assets and disposable content; restore any changed channel presentation.

## Operations

- [ ] Run hourly cleanup under the deployed CLI user and verify configuration, permissions and exit status. Confirm busy-account media deferral does not prevent other housekeeping.
- [ ] Verify errors include usable request IDs and logs contain no credentials. Confirm upstream errors are not automatically retried.
- [ ] Compare informational usage estimates with observed project traffic without treating them as remaining quota.
- [ ] Complete an isolated backup/restore exercise with matching configuration and keys before relying on the deployment.

Keep failed checks and remaining compatibility limits visible in the deployment record. See [connections](CONNECTIONS.md), [Plesk](PLESK.md), [Docker](DOCKER.md) and [operations](OPERATIONS.md) for setup and recovery.
