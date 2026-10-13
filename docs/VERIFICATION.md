# Local verification record

Date: 2026-10-13.

## Passed

- PHP syntax checks across application, CLI, entry points, and tests.
- PHPUnit: **33 tests, 86 assertions**, no failures or warnings on the final run.
- `composer validate --strict`: valid manifest and lockfile.
- Dependency resolution/audit after updating Firebase JWT: no reported security advisories.
- Actual loopback HTTP checks: readiness and OAuth metadata returned 200; unauthenticated MCP returned 401 with protected-resource metadata challenge.
- Temporary HTTP server stopped; its test database and signing keys removed.

Tested runtime: Windows PHP 8.2.10 with project-command extensions enabled for fileinfo, PDO SQLite, and sodium. The local OpenSSL configuration path was provided for RSA generation. Production should use a maintained PHP patch version.

Locked main packages: `mcp/sdk` 0.8.1, `league/oauth2-server` 9.4.1, `firebase/php-jwt` 7.2.1. Other exact versions are in `composer.lock`.

## Automated evidence

- SDK handshake, tool discovery, and a local usage tool call through the application.
- PKCE exchange/mismatch, code replay rejection, refresh rotation/replay rejection, JWT authentication, revocation, and callback allowlisting.
- Private token encryption and wrong-key rejection.
- Invite-only onboarding, stable quota identity after account recreation, and refresh isolation between users.
- Owned-playlist checks, write scopes, metadata preservation, duplicate handling, occurrence removal, batch budget rejection, and completed-operation replay.
- Separate search/general budgets, per-user isolation, circuit breakers, reservation expiry, Pacific daylight-saving reset, and cache hits.
- Two independent PHP processes cannot reserve beyond the same project quota balance.
- Post-deployment Bash syntax/help checks; configuration helper preserves encryption keys on rerun, correctly escapes special characters, rejects public storage, and refuses replacement keys when existing state has no configuration.
- Partial receipt resume skips completed steps; uncertain changes cannot automatically resume; cross-user receipts are inaccessible.

## Pending external evidence

No Google OAuth client credentials or public deployment were provided. No live API call was made and no existing playlist was changed. YouTube Music compatibility, real channel selection, Google consent requirements, actual project quotas, ChatGPT OAuth interoperability, and Plesk runtime/proxy behavior remain to be verified through [ACCEPTANCE.md](ACCEPTANCE.md).

This record describes local implementation evidence, not completion of the live compatibility gate or production acceptance.
