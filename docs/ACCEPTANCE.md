# Live acceptance checklist

Automated tests cover local protocol, OAuth, isolation, accounting, and mocked upstream responses. These live checks are pending because no Google credentials, public deployment, or ChatGPT account connection has been configured in this workspace.

## Compatibility gate

- [ ] Confirm actual Google quota buckets/costs and relevant playlist/channel limits in the configured project.
- [ ] Complete Google sign-in, offline YouTube consent, and channel confirmation.
- [ ] Create a disposable private playlist through the official API and add a known music video.
- [ ] Confirm the playlist and track appear and play in YouTube Music.
- [ ] Read and edit a disposable playlist originally created in YouTube Music.
- [ ] Connect ChatGPT using OAuth, including PKCE/resource and the exact callback.
- [ ] Verify tool discovery and one read/write sequence from ChatGPT.
- [ ] Record actual PHP, SDK, protocol, Google consent configuration, and ChatGPT workspace behavior.

Do not expand to production users if this gate fails. Record the case and adjust the implementation before release.

## Two-account trial

- [ ] Connect two different Google accounts, verify each channel, and list each user's private playlists.
- [ ] Confirm a user's write to the other user's playlist is rejected.
- [ ] Confirm another user's operation receipt cannot be read.
- [ ] Expire/revoke a service token and reconnect.
- [ ] Revoke Google access and confirm further operations fail until reconnect.
- [ ] Disconnect one account and confirm the other remains connected.

## Disposable mutation trial

- [ ] Create private playlist; change title while preserving omitted description/privacy.
- [ ] Add repeated videos under both duplicate policies.
- [ ] Remove exactly one duplicate occurrence using its playlist-item ID.
- [ ] Move one entry and verify actual order.
- [ ] Replay a completed operation key and confirm no duplicate mutation.
- [ ] Reject an over-budget batch before new writes.
- [ ] Verify a controlled partial failure/resume, using mocks or a dedicated test project rather than exhausting shared production quota.
- [ ] Delete the disposable playlist.

## Plesk trial

- [ ] Verify HTTPS, correct Host routing, front-controller paths, and Authorization forwarding.
- [ ] Confirm private files are inaccessible over HTTP.
- [ ] Confirm sessions persist across requests/workers and PHP-FPM restart.
- [ ] Verify cleanup, logs, threshold events, and configured execution timeouts.
- [ ] Restore a test backup and verify encrypted-token recovery without exposing secrets.
- [ ] Confirm no scheduled task replays pending writes after quota reset.

## Release record

Record date, operator, test account/channel IDs (privately), tested deployment/runtime, observed costs, Music availability exceptions, and unresolved issues. This file should remain explicit about pending checks until evidence exists.
