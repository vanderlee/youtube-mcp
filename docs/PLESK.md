# Direct PHP installation in Plesk

## Domain and runtime

Create a dedicated subdomain with a valid TLS certificate. Enable HTTPS redirection and use PHP-FPM with a maintained PHP 8.2+ version compatible with `composer.lock`. Enable the extensions listed in the README, including PDO SQLite and sodium. The CLI used for Composer, initialization, and scheduled tasks must use the same compatible PHP version/extensions.

Keep the application under a private project directory within the subscription, and set the domain's document root to that project's `public/` directory. Do not point it at the project root: that would expose configuration, credentials, source, and storage.

Install production dependencies with `composer install --no-dev --prefer-dist --optimize-autoloader`. If Composer is unavailable on the server, build a release including `vendor/` elsewhere with a compatible runtime. Do not upload a developer `.env` or temporary test database.

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

## Private storage and initialization

Set `STORAGE_PATH` to a local absolute directory outside `public/`, writable only by the subscription owner and PHP-FPM user. The app uses SQLite WAL, local lock files, browser sessions, and persistent MCP sessions there. Avoid NFS/network shares and deployments across multiple hosts.

Generate `APP_KEY`, then run:

```sh
php bin/console.php init
```

Initialization creates OAuth RSA signing keys if missing and applies migrations. Re-running it preserves existing keys. Protect `oauth-private.pem` and `.env` with restrictive permissions. Keep `APP_KEY` and signing keys stable across upgrades.

Set PHP `display_errors=Off`, `log_errors=On`, and a memory limit such as 128 MiB. Allow approximately 180 seconds for PHP requests and the corresponding FPM/proxy timeout to accommodate bounded preflight and write windows; tune down bulk sizes if hosting policy permits shorter requests. Individual upstream requests have a 20-second timeout. Disable proxy buffering only if actual negotiated streaming behavior requires it, and verify rather than assuming.

Limit request bodies to 64 KiB at the web server and set a matching PHP `post_max_size`; the application also performs a bounded read. Configure PHP session garbage collection or a subscription-scoped cleanup for private session files. Session data includes transient authorization state; Google tokens pending confirmation are encrypted.

## Scheduled maintenance

Configure an hourly Plesk scheduled task using the absolute PHP CLI and script paths:

```sh
/path/to/php /path/to/project/bin/console.php cleanup
```

Run as the same subscription account with storage access. This cleans expired caches, old rate windows, expired tokens, expired unused reservations, and retained completed/partial receipts. It never dispatches YouTube writes. Monitor task failures and PHP error logs.

## Smoke checks

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
- Check the actual project quota limits in Google Cloud Console. Set `QUOTA_GENERAL`, `QUOTA_SEARCH`, user budgets, and any method-cost overrides accordingly.
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
- Verify the usage panel shows the expected user limits and reset time.
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
- Install/enable the connection and confirm tools appear. Follow [CONNECTIONS.md](CONNECTIONS.md) for PKCE/resource requirements and troubleshooting.

### 7. Run the live acceptance workflow

- Ask ChatGPT to show the connected channel and remaining usage.
- Create a disposable private playlist and add a known music video.
- Confirm it appears and plays in YouTube Music.
- Test listing entries, editing metadata, moving an entry, removing an occurrence, and replaying a completed operation key.
- Delete only the disposable playlist.
- Connect a second Google account and confirm private playlists, receipts, and permissions remain isolated.
- Test service access revocation/disconnection without affecting the other user.
- Complete and record the remaining checks in [ACCEPTANCE.md](ACCEPTANCE.md). Live compatibility is not established by health checks alone.

### 8. Enable maintenance and monitoring

- Add the hourly Plesk scheduled cleanup task described above and run it once manually.
- Confirm the task's CLI version, environment/configuration, permissions, and absolute paths are correct.
- Monitor PHP error logs for quota threshold events, bootstrap errors, and request IDs associated with failures.
- Compare estimated usage with Google Cloud Console after the first workflows.
- Confirm unused reservations recover after interruption and no scheduled task replays pending writes.
- Confirm PHP/proxy timeouts accommodate the bounded operations; reduce batch size if the hosting limits are shorter.
- Set up consistent private-state backups and perform a test restore before depending on the service.

### 9. Open access gradually

- Add users to the service invitation list and, where applicable, Google's test-user list.
- Review project capacity before increasing the user count; each account shares the same project quotas.
- Complete the appropriate Google consent publishing/verification process before broader distribution.
- Record the deployed PHP version, dependency versions, actual quota values, and successful acceptance checks.

The initial implementation supports a single Plesk host with local SQLite and filesystem locks. Docker and multi-host deployment remain deferred.
