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
