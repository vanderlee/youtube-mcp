# Direct PHP installation in Plesk

## Domain and runtime

Create a dedicated subdomain with a valid TLS certificate. Enable HTTPS redirection and use PHP-FPM with a maintained PHP 8.2+ version compatible with `composer.lock`. Enable the extensions listed in the README, including PDO SQLite and sodium. The CLI used for Composer, initialization, and scheduled tasks must use the same compatible PHP version/extensions.

Keep the application under a private project directory within the subscription, and set the domain's document root to that project's `public/` directory. Do not point it at the project root: that would expose configuration, credentials, source, and storage.

Install production dependencies with `composer install --no-dev --prefer-dist --optimize-autoloader`. If Composer is unavailable on the server, build a release including `vendor/` elsewhere with a compatible runtime. Do not upload a developer `.env` or temporary test database.

The domain's PHP handler and shell's default `php` can differ. Select the installed handler explicitly for Composer, initialization, and maintenance. For example:

```sh
/opt/plesk/php/8.3/bin/php -v
/opt/plesk/php/8.3/bin/php -m
/opt/plesk/php/8.3/bin/php /usr/lib/plesk-9.0/composer.phar install --no-dev --prefer-dist --optimize-autoloader
```

These version/path values are examples; verify them on your host. A failed Composer run is a failed deployment. A Git deployment hook installing dependencies does not replace initialization, permissions checks, or browser acceptance.

## YouTube Music tools

Music catalog, Music search, discovery, personal library and listening history run directly in PHP through Music's unofficial web endpoints. The existing PHP/cURL runtime is sufficient; no subprocess, additional runtime, daemon or cron task is required. Allow outbound HTTPS to music.youtube.com and YouTube playback tracking endpoints.

Optional private configuration: `MUSIC_LOCATION=NL` (two-letter country code) and `MUSIC_CLIENT_VERSION` (an explicit web client version if upstream compatibility requires it). Leave the version empty to discover the actual version and visitor context from the public Music homepage. Do not use a guessed date-based version. Outbound HTTPS access must allow both the homepage GET and Music API POST requests. Post-deployment setup accepts `DEPLOY_MUSIC_LOCATION` and `DEPLOY_MUSIC_CLIENT_VERSION` and runs `php bin/console.php music:check`, which checks local configuration only.

After the client-context fix, deploy and retry `get_music_account` and `get_music_history`; a new OAuth grant is not needed if `music_google_scope_granted` and `history.read` were already confirmed. Errors include safe `music_client_version` and `music_visitor_context_present` diagnostics without exposing the visitor identifier or credentials. `MUSIC_BOOTSTRAP_UNAVAILABLE` means no private account request was dispatched. Further `INVALID_ARGUMENT` failures can still indicate OAuth client incompatibility and need separate investigation.

The Music adapter reuses the user's server-managed Google OAuth token with the additional `https://www.googleapis.com/auth/youtube` grant. For connections made before this grant was requested: deploy the update, reconnect Google in the dashboard, grant Manage your YouTube account, confirm the intended channel, then reconnect ChatGPT. `deploy.sh` cannot upgrade stored OAuth grants; this requires browser consent.

Authorize ChatGPT with `catalog.read`, `discovery.read`, `library.read`, `library.write`, `history.read`, and/or `history.write` as desired. Existing tokens do not gain these permissions automatically. No additional Google API is enabled by this implementation. `get_connection_status` reports both Google and service scope names; it does not verify live Music access. If fresh consent still returns a rejection, use the safe upstream diagnostic codes instead of repeatedly reconnecting.

Verify `get_music_account` against the intended Music account/channel, then test catalog/search, home/radio/charts, each library kind and recent history. Confirm two accounts remain isolated. On disposable content, test save/unsave and history add/remove, replay completed operation keys and restore the original state. Brand-account history removal may be unsupported. Health/configuration checks do not establish live Music authorization. See [Music tools and limitations](MUSIC.md).

## Routing

For Apache-backed hosting, `public/.htaccess` routes non-file requests to `index.php` and forwards Authorization. Confirm that `.htaccess` overrides and rewrite rules are enabled.

For nginx serving PHP directly, use a front-controller rule appropriate to the Plesk-generated vhost:

```nginx
location / {
    try_files $uri /index.php$is_args$args;
}
```

Plesk may already generate a `location /` block. Do not paste a duplicate blindly; use the vhost's supported configuration mechanism or Apache routing. Preserve the generated PHP-FPM handling and ensure Authorization reaches PHP. Test `/mcp`, `/oauth/authorize`, `/google/callback`, and `/.well-known/oauth-authorization-server`; well-known metadata must not be swallowed by an unrelated static-file rule.

The app validates the requested Host against `APP_URL` and builds all authorization URLs from configuration. It ignores forwarded URL headers, so an attacker cannot choose the callback origin. If the server proxies to PHP, preserve the public Host. Use TLS at Plesk; no separate internal HTTPS configuration is required.

Deployment fixes normalize duplicate Host values before passing a validated request to the MCP SDK. Deploy the current entry point and application together. If metadata works but MCP transport returns 403, inspect its rejection logs and proxy headers instead of removing Host/Origin protection.

## Private storage and initialization

Set `STORAGE_PATH` to a local absolute directory outside `public/`, writable only by the subscription owner and PHP-FPM user. The app uses SQLite WAL, local lock files, browser sessions, and persistent MCP sessions there. Avoid NFS/network shares and deployments across multiple hosts.

Generate `APP_KEY`, then run:

```sh
php bin/console.php init
```

Initialization creates OAuth RSA signing keys if missing and applies migrations. Re-running it preserves existing keys. Protect `oauth-private.pem` and `.env` with restrictive permissions. Keep `APP_KEY` and signing keys stable across upgrades.

Root-owned files can pass CLI initialization yet fail under PHP-FPM. SQLite needs directory write access to create WAL/shared-memory files, and session/lock directories need write access too. If root was used accidentally, identify the subscription owner/group and repair only application-owned private files with administrator assistance. Preserve the keys/database; do not regenerate keys or use world-writable permissions to fix ownership. Retest through HTTPS.

Set PHP `display_errors=Off`, `log_errors=On`, and a memory limit such as 128 MiB. Allow approximately 180 seconds for PHP requests and the corresponding FPM/proxy timeout to accommodate bounded preflight and write windows; tune down bulk sizes if hosting policy permits shorter requests. Individual upstream requests have a 20-second timeout. Disable proxy buffering only if actual negotiated streaming behavior requires it, and verify rather than assuming.

Limit request bodies to 64 KiB at the web server and set a matching PHP `post_max_size`; the application also performs a bounded read. Configure PHP session garbage collection or a subscription-scoped cleanup for private session files. Session data includes transient authorization state; Google tokens pending confirmation are encrypted.

## Scheduled maintenance

Configure an hourly Plesk scheduled task using the absolute PHP CLI and script paths:

```sh
/path/to/php /path/to/project/bin/console.php cleanup
```

Run as the same subscription account with storage access. This cleans expired caches, old rate windows, expired tokens, expired unused reservations, and retained completed/partial receipts. It never dispatches YouTube writes. Monitor task failures and PHP error logs.

## Smoke checks

### Bootstrap failure after a namespace or release change

If `/mcp` and all OAuth metadata endpoints return 503 with a bootstrap `Error` at the entry point's application-construction line, first check application autoloading. The project namespace is `YouTubeMusicMcp`; an older generated Composer map may still reference the previous namespace. Missing or mixed-release source files can cause the same symptom. This failure precedes OAuth, so changing callback allowlists or renewing Google consent will not repair it.

From the deployed project root, as the subscription user, select the installed domain PHP version and run:

```sh
/opt/plesk/php/8.3/bin/php bin/console.php bootstrap:check
/opt/plesk/php/8.3/bin/php /usr/lib/plesk-9.0/composer.phar dump-autoload --no-dev --optimize
/opt/plesk/php/8.3/bin/php bin/console.php bootstrap:check
/opt/plesk/php/8.3/bin/php bin/console.php mcp:check
```

Paths/versions are examples. `dump-autoload` regenerates maps from the current manifest without updating dependencies or touching keys/storage. Confirm the current `composer.json`, `src/Config.php`, `src/App.php`, and other application files were deployed together; regeneration cannot repair missing source files. If dependencies are missing, run the locked production `composer install` instead. The entry point now logs an explicit `application_class_missing` reason with the known class name, without logging secrets.

If CLI checks pass but HTTPS still fails, verify PHP-FPM is serving that same project/release and reload its worker/OPcache through the appropriate Plesk/admin controls. Then verify health and metadata return 200 and unauthenticated MCP returns 401 before retrying ChatGPT refresh. Do not regenerate encryption keys or reset the database to fix autoloading.

If ChatGPT cannot refresh the tools, run this read-only check on the deployed release as the subscription user, using the same PHP version as PHP-FPM:

```sh
/opt/plesk/php/8.3/bin/php bin/console.php mcp:check
```

It compiles the tool schemas, checks catalog consistency and the compact presentation class, and prints a non-secret catalog fingerprint. Compare fingerprints between the intended release and server to detect incomplete deployment. It does not read credentials, call YouTube, or verify the web worker. If CLI passes but the web catalog remains old, check whether PHP-FPM OPcache still serves an earlier release and reload that domain's PHP worker through the available Plesk/admin controls when appropriate.

Reproduce one refresh and correlate its timestamp with `/mcp` and `/oauth/token` access/error logs. A 401 points to authorization/refresh, 403 to policy/WAF/transport, 404 to routing or session lookup, and 500/503 to application/bootstrap failure. These are starting points, not a diagnosis from status alone. Capture the exact error and request ID without authorization headers, cookies, or token/callback payloads. Do not delete keys, reset storage, or recreate OAuth clients merely because refresh failed.

After deploying tool-schema changes, refresh the ChatGPT connection's tools. Verify compact `structuredContent` and short text summaries in a real ChatGPT conversation. Local payload-size tests demonstrate smaller serialized results; actual model-token savings depend on the client and are not inferred from PHP timings. No new environment settings or database migrations are required for these result-shaping and playlist workflow changes.

- `/health/live` and `/health/ready` return JSON without credentials.
- Root dashboard allows Google sign-in over HTTPS.
- Metadata endpoints return the exact public URLs.
- A tool call to `/mcp` without a bearer token returns 401 with a protected-resource challenge.
- MCP initialization/listing succeeds with a valid token, and sessions persist across PHP workers.
- Requests using an unexpected Host or Origin fail.
- Verify `.env`, `var`, `src`, `vendor`, and signing keys cannot be fetched from the domain.

Finish the live acceptance checklist before granting access to important playlists.

## Post-deployment actions

### Automated server-side setup

The root `deploy.sh` Plesk hook delegates all dependency installation, initialization, and checks to this script with `--non-interactive`. It supplies the Plesk PHP CLI (default `/opt/plesk/php/8.5/bin/php`, overridable with `PHP_BIN`) and Composer PHAR (default `/usr/lib/plesk-9.0/composer.phar`, overridable with `COMPOSER_BIN`). Prepare the private `.env` before unattended deployment and run the hook as the subscription user. Output goes to project-root `deploy.log`; failures return a nonzero status without rolling back already deployed files or applied migrations. Cron installation and static-client creation remain opt-in manual steps.

This is suitable for every deployment with intact configuration and private storage. Existing keys and credentials are preserved; only unapplied migrations run. Cleanup removes expired data and completed/partial receipts beyond the configured retention period, without replaying upstream writes. Any exported `DEPLOY_*` overrides still update configuration, and HTTPS probe failures fail the hook even after local setup succeeds. Keep backups before deploying new migrations.

Run the included Bash script as the Plesk subscription user from the deployed project. Do not run it as root. Select the same PHP CLI version as the domain's PHP-FPM runtime:

The script requires Bash and `flock`; Composer can be skipped for a prebuilt release. It uses PHP cURL for HTTPS probes. Concurrent script runs are blocked by a private deployment lock.

```sh
bash bin/post-deploy.sh --php /opt/plesk/php/8.3/bin/php
```

The script checks required extensions, installs locked production dependencies, prompts for missing configuration, generates an encryption key only for a new installation, initializes private storage/signing keys/migrations, runs cleanup, and probes public HTTPS endpoints. Credentials are entered through hidden prompts and are not printed. Existing encryption/signing keys are preserved. Changed existing configuration is backed up privately before replacement.

Inspect planned actions without changing files:

```sh
bash bin/post-deploy.sh --php /opt/plesk/php/8.3/bin/php --dry-run
```

If Composer is a PHAR, use `--composer /path/to/composer.phar`. If the uploaded release already includes production dependencies, use `--skip-composer`. For unattended setup, first create a private `.env` with the required values and add `--non-interactive`; optional `DEPLOY_*` environment overrides are listed by `--help`. Avoid putting secrets directly into shell history or command-line arguments.

Optional automation:

- `--install-cron` installs or updates only this project's marked hourly entry in the current user's crontab, preserving other entries. Omit it to print the command for Plesk Scheduled Tasks instead; do not configure both methods.
- `--create-client` creates static ChatGPT client credentials once, saving them as `chatgpt-client.json` in private storage without printing the secret. This requires exactly one verified callback in `OAUTH_REDIRECT_URIS`.
- `--skip-http` allows setup before DNS/routing is ready. Run again without it once HTTPS is available; skipping probes does not establish deployment readiness.

Rerunning the script preserves credentials, keys, and initialized state, and migrations are versioned. It does not change domain routing, certificates, PHP-FPM configuration, ownership, Google Cloud resources, or browser authorizations. Follow the remaining checklist below and complete live acceptance. Protect private configuration backups and the saved static client secret.

Complete these steps after uploading the application and setting the document root to `public/`. Replace all example domains and paths with the deployment's actual values. Use the subscription's selected PHP CLI version for every command.

### 1. Configure the installation

- Copy `.env.example` to `.env` in the project root, outside `public/`.
- Set `APP_URL` to the exact public HTTPS origin, for example `https://music.example.com`, without a path or trailing slash.
- Set `STORAGE_PATH` to private local storage writable by PHP-FPM and the scheduled-task user.
- Set `ALLOWED_EMAILS` to the Google email addresses permitted to sign in. Leave `ALLOW_REGISTRATION=false` initially.
- Keep `COOKIE_SECURE=true`.
- Generate a key with `php bin/console.php key:generate` and put it in `APP_KEY`. Do not regenerate this key during upgrades.
- Confirm the PHP-FPM and CLI extensions match the README requirements.

### 2. Register Google OAuth

- Create a Google Cloud project and enable YouTube Data API v3.
- Configure the Google OAuth consent screen and add the required test users if using Testing mode.
- Create a Web application OAuth client.
- Register exactly `https://YOUR_DOMAIN/google/callback` as its redirect URI.
- Put its client ID and secret into `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` in `.env`.
- Check actual project quotas in Google Cloud Console. YouTube enforces limits; the app reports observed usage only. Legacy quota settings are ignored.
- Decide whether Testing mode is sufficient. YouTube authorizations in Testing mode have refresh-token expiry restrictions; consult [CONNECTIONS.md](CONNECTIONS.md) before ongoing use or broader rollout.

### 3. Initialize storage and protect files

```sh
php bin/console.php init
```

- Confirm migrations complete and the OAuth signing-key pair is created in private storage.
- Restrict access to `.env`, SQLite files, session directories, and `oauth-private.pem`.
- Back up the database, `APP_KEY`, and signing-key pair securely. See [OPERATIONS.md](OPERATIONS.md) for consistent SQLite backups.
- Confirm the web server cannot serve private files or project directories outside `public/`.

### 4. Verify routing and service health

- Visit `https://YOUR_DOMAIN/health/live` and `/health/ready`; both should return HTTP 200 JSON.
- Visit `/.well-known/oauth-authorization-server` and `/.well-known/oauth-protected-resource/mcp`; confirm all advertised URLs use the production domain.
- Send an unauthenticated POST to `/mcp`; expect HTTP 401 and a `WWW-Authenticate` header referencing the protected-resource metadata.
- Confirm the dashboard loads over HTTPS and no PHP warnings or stack traces appear in responses.
- Check Plesk's domain PHP/error logs for setup failures. Verify Authorization forwarding and public Host preservation if authentication fails.

### 5. Connect the first Google account

- Sign in through the dashboard using an invited Google identity.
- Select Connect YouTube account and grant offline YouTube access.
- Select the intended channel in Google's consent flow and confirm its displayed name/ID in the dashboard.
- Verify the usage panel shows observed usage and `quota_enforced_by: youtube`. Remaining quota is unknown.
- Keep the first tests restricted to disposable private playlists.

### 6. Connect ChatGPT

- Obtain the exact OAuth callback URL used by ChatGPT's custom MCP setup and add it to `OAUTH_REDIRECT_URIS`.
- Use the MCP URL `https://YOUR_DOMAIN/mcp` and choose OAuth authentication.
- Use DCR if supported by the setup, or create static credentials:

```sh
php bin/console.php client:create https://EXACT_CALLBACK_URI ChatGPT
```

- Enter the generated credentials only into the connection setup; do not publish the secret or put it in chat.
- Complete service consent with `playlists.read` and `playlists.write` for full management.
- For likes and subscriptions, also request the desired `likes.read`, `likes.write`, `subscriptions.read`, and `subscriptions.write` permissions. After upgrading, reauthorize ChatGPT to grant these; existing playlist tokens do not acquire them automatically. The current Google `youtube.force-ssl` grant covers these official API methods, so no additional runtime or Google API is required.
- Install/enable the connection and confirm tools appear. Follow [CONNECTIONS.md](CONNECTIONS.md) for PKCE/resource requirements and troubleshooting.

### 7. Run the live acceptance workflow

- Ask ChatGPT to show the connected channel and observed usage; actual remaining quota is unknown.
- Create a disposable private playlist and add a known music video.
- Confirm it appears and plays in YouTube Music.
- Test listing entries, editing metadata, moving an entry, removing an occurrence, and replaying a completed operation key.
- Delete only the disposable playlist.
- Test `list_liked_tracks`, `get_track_ratings`, and `list_subscriptions` with the new read permissions. Liked videos and subscribed channels can include non-music content.
- On a disposable video/channel, record the initial rating/subscription, test `rate_track` and `subscribe_artist`/`unsubscribe_artist`, replay a completed operation key, and restore the initial state. Confirm playlist-only tokens cannot call these tools. Verify the resulting state in YouTube and YouTube Music separately; Music correspondence has not been established by local tests.
- Connect a second Google account and confirm private playlists, receipts, and permissions remain isolated.
- Test service access revocation/disconnection without affecting the other user.
- Complete and record the remaining checks in [ACCEPTANCE.md](ACCEPTANCE.md). Live compatibility is not established by health checks alone.

### 8. Enable maintenance and monitoring

- Add the hourly Plesk scheduled cleanup task described above and run it once manually.
- Confirm the task's CLI version, environment/configuration, permissions, and absolute paths are correct.
- Monitor PHP error logs for bootstrap errors and request IDs associated with failures.
- Compare estimated usage with Google Cloud Console after the first workflows.
- Confirm no scheduled task replays pending writes; obsolete quota reservations are removed by cleanup.
- Confirm PHP/proxy timeouts accommodate the bounded operations; reduce batch size if the hosting limits are shorter.
- Set up consistent private-state backups and perform a test restore before depending on the service.

### 9. Open access gradually

- Add users to the service invitation list and, where applicable, Google's test-user list.
- Review project capacity before increasing the user count; each account shares the same project quotas.
- Complete the appropriate Google consent publishing/verification process before broader distribution.
- Record the deployed PHP version, dependency versions, actual quota values, and successful acceptance checks.

The initial implementation supports a single Plesk host with local SQLite and filesystem locks. Docker and multi-host deployment remain deferred.

## Troubleshooting browser authorization

### Identify the failing layer

Use Plesk's domain Logs view or HTTPS access/error logs. Common filenames are `access_ssl_log`, `proxy_access_ssl_log`, and `error_log`; locations vary by installation. Correlate endpoint, time, HTTP status, and application request ID. Keep authentication payloads and raw callback query strings private.

| Stage | Expected evidence |
|---|---|
| Unauthenticated MCP | 401 with protected-resource metadata challenge |
| OAuth metadata | 200 JSON with the correct public origin |
| DCR, when selected | POST `/oauth/register` returns 201 |
| Browser consent | Login/consent and redirects without browser-policy or WAF errors |
| Token exchange | POST `/oauth/token` returns 200 |
| Authenticated MCP | Initialization/tool discovery and a successful local usage query |

Request order can vary; static clients skip DCR. An HTML WAF 403, JSON `INVALID_ORIGIN`, and an SDK transport 403 are different failures. Use `request_origin_rejected`, `oauth_registration_callback_rejected`, and transport diagnostics before changing configuration. Bootstrap 503 errors require runtime/configuration/storage checks; errors carrying `X-Request-Id` can be correlated with app logs.

### Browser policy and recovery

Check effective response headers in browser developer tools. A second CSP set by Plesk/proxy is enforced in addition to the application's policy. A self-only `form-action` can block an otherwise valid redirect after submitting Google/ChatGPT authorization forms. Resolve the conflicting policy, reload the dashboard, and restart the flow; keep unrelated protections enabled.

The following operational checks summarize the deployment lessons:

- Run initialization and maintenance as the subscription user. Root-owned signing keys or SQLite files can make PHP-FPM fail even when CLI checks succeed. Preserve existing keys and repair ownership; do not regenerate keys to fix permissions.
- The browser policy must use `Referrer-Policy: same-origin` and allow form redirects to `https://accounts.google.com` and `https://chatgpt.com`. After deploying header changes, reload the dashboard and restart authorization; do not replay an old callback or approval form.
- A deployment encountered a Comodo/ModSecurity false positive in rule `210580` for Google's callback `scope` containing `userinfo.profile`. This is a rule-set-dependent example, not a required exception for every deployment. First correlate the failure with the WAF audit/error log. If that exact rule is responsible, an administrator can add the following narrowly scoped exception to this subdomain's Additional Apache directives for HTTPS:

  ```apache
  <LocationMatch "^/google/callback$">
      SecRuleRemoveById 210580
  </LocationMatch>
  ```

  Validate the Apache configuration through Plesk, then retry a fresh flow. Keep the WAF enabled. Inspect any other rejection's rule ID before adding an exception. Fail2Ban trusted IPs prevent bans but do not bypass WAF rejections.
- ChatGPT can use a connection-specific callback rather than the example callback in `.env.example`. A rejected registration logs `oauth_registration_callback_rejected` with the requested URI. Add that exact trusted callback to `OAUTH_REDIRECT_URIS` and retry the same setup. Multiple exact callbacks can be comma separated.
- Watch HTTPS access logs (`access_ssl_log` and, where present, `proxy_access_ssl_log`) along with `error_log`. An initial `/mcp` 401 is expected; discovery should return 200, registration 201, and the token exchange 200.
- A reconnect revokes existing service tokens. Reconnect ChatGPT afterward. Without `playlists.write` consent the connection is read-only.
- Google and service authorization state is stored in the browser session and expires after ten minutes. Complete one authorization flow at a time in a browser; starting another flow in a second tab replaces the pending state.

An existing static client has its own callback list in the database. Changing `OAUTH_REDIRECT_URIS` alone does not change that registration, and `--create-client` preserves an existing private credentials file on rerun. Register a new client when its callback changes. See [callback recovery](CONNECTIONS.md#discover-the-actual-callback).

After an upgrade, verify usage expectations against the current implementation: YouTube enforces quotas, while the app reports observed estimates and explicitly marks remaining quota unknown. Legacy local budget settings are ignored. Use Cloud Console for actual limits/consumption and inspect receipts after upstream errors. Scheduled cleanup must not replay pending writes.
