# Docker deployment

Run this stack on one Linux Docker host with Docker Compose v2, local disk, and a domain pointing to the host. Open TCP ports 80 and 443 (optionally UDP 443 for HTTP/3). Caddy obtains and renews HTTPS certificates automatically. Those ports must be free; on an existing Plesk host, use the direct [Plesk installation](PLESK.md) or configure a separate host for this stack.

The stack contains the application running as `www-data` under PHP-FPM, an Nginx image containing only public assets and routing configuration, and Caddy for HTTPS. Only Caddy publishes ports. Google credentials and `APP_KEY` are supplied at runtime to the application alone. The build context uses an allowlist excluding `.env`, runtime storage, developer dependencies, and `HUMANS.md` at every depth.

## First start

From the repository root:

```sh
cp .env.example .env
docker build --pull --target app -t youtube-mcp:local .
docker build --pull --target web -t youtube-mcp-web:local .
```

Edit the private `.env` file. Set `APP_URL` to your exact HTTPS origin without a path, e.g. `https://music.example.com`; set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `ALLOWED_EMAILS`, and the exact `OAUTH_REDIRECT_URIS` required by your client. Keep `ALLOW_REGISTRATION=false` for invitation-only access. Compose always enables secure cookies and stores private state in `/app/var`, overriding the corresponding file settings.

Generate the encryption key once:

```sh
docker run --rm youtube-mcp:local php bin/console.php key:generate
```

Save the generated value privately as `APP_KEY` in `.env`. Do not regenerate it on upgrades: existing encrypted credentials depend on it. Restrict `.env` to its owner (on Linux, `chmod 600 .env`) and back it up securely. Then start:

```sh
docker compose up -d --build
docker compose ps
curl --fail https://music.example.com/health/ready
```

Startup validates configuration, creates the signing keys on the first run, and applies unfinished migrations before starting FPM. Failures prevent startup. Subsequent starts preserve signing keys and database state. The application health check probes FPM; verify `/health/ready` separately to check the complete HTTPS path and database. CLI invocations do not implicitly initialize storage.

Register `https://YOUR_DOMAIN/google/callback` in your Google Web application OAuth client and enable YouTube Data API v3. Follow [Google and ChatGPT connection](CONNECTIONS.md) for browser authorization and service consent. No OpenAI API key is required. Local container checks do not establish live Google or ChatGPT compatibility.

## Maintenance

Run cleanup hourly using the host's scheduler, with the absolute path to this checkout:

```cron
0 * * * * cd /srv/youtube-mcp && docker compose exec -T app php bin/console.php cleanup
```

There is no background worker or automatic retry of upstream writes. Other administrative commands use the same container:

```sh
docker compose exec -T app php bin/console.php bootstrap:check
docker compose exec -T app php bin/console.php mcp:check
docker compose exec -T app php bin/console.php client:create https://EXACT_CALLBACK_URI ChatGPT
```

The final command prints client credentials; keep its output private. Application and server errors are available with `docker compose logs`. HTTP access logging is disabled to keep OAuth callback query strings out of logs. Nginx permits 64 KiB request bodies except the bounded `/media` upload form (65 MiB). PHP/FPM and FastCGI timeouts are 180 seconds, and FastCGI response buffering is disabled for MCP streaming. Proxies in front of Caddy must preserve the public Host and Authorization headers and accommodate these timeouts.

## Upgrades and backups

Before upgrading, stop the stack and securely back up its private state:

```sh
docker compose stop
umask 077
docker compose run --rm -T --no-deps app tar -cz -C /app/var . > youtube-mcp-state.tar.gz
```

These commands target a Linux host shell. Move `youtube-mcp-state.tar.gz` to protected backup storage; also back up `.env` and the Caddy certificate volume. The archive includes SQLite and any WAL files, signing keys, staged media, sessions, and caches. The stopped stack ensures SQLite is not being written during the backup. Test restoration before depending on backups.

Update your checkout, preserving `.env`, then rebuild and restart:

```sh
docker compose build --pull
docker compose up -d
curl --fail https://music.example.com/health/ready
```

Images use maintained PHP 8.3, Nginx stable, Composer 2, and Caddy 2 tags; locked Composer production dependencies are installed at build time. For controlled releases, record built image digests and pin base-image digests in your deployment. Database migrations may require restoring the matching backup when rolling back code.

`docker compose down` preserves named volumes. **Do not use `docker compose down -v` unless intentionally deleting all installation state**, including tokens, receipts, signing keys and certificates. Keep the Compose project name stable so deployments reuse the same volumes. Do not scale `app`, share its SQLite volume between installations, or use network-backed storage.

## Build and distribute images

The default Dockerfile target is the application. Compose also builds the separate web target:

```sh
docker build --target app -t YOUR_REGISTRY/youtube-mcp:VERSION .
docker build --target web -t YOUR_REGISTRY/youtube-mcp-web:VERSION .
```

Publish both images through your normal release process. On the destination host, retain `compose.yaml`, `docker/Caddyfile`, and the private `.env`; replace the `app` and `web` image names with the release tags, remove their `build` sections, then run `docker compose pull` and `docker compose up -d`. No source checkout or Composer installation is needed on that host. The application image speaks FastCGI on port 9000 and needs the included web/TLS services; never publish that port to the internet.
