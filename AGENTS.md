# Instructions for AI agents

These instructions apply to all AI agents working in this repository.

## HUMANS.md is reserved for humans

- Never access or touch `HUMANS.md`, including any file with that name in a subdirectory.
- Do not read, open, inspect, summarize, quote, index, or use its contents as context.
- Do not create, edit, format, overwrite, rename, move, delete, stage, or commit it.
- Exclude it from searches, automated edits, formatters, generators, and other commands that might read or change files.
- Do not ask another agent or tool to access or modify it on your behalf.
- If a task would require touching `HUMANS.md`, leave it untouched and explain that the human must handle that part.

Keep AI-facing repository instructions in `AGENTS.md`.

## Project architecture

- This is a PHP 8.2+ multi-user MCP service hosted with PHP-FPM. Keep it compatible with the Composer constraints and the existing Plesk deployment; do not introduce a background worker or another runtime without an explicit requirement.
- Use `YouTubeMcp\` namespaces and PSR-4 autoloading under `src/`. Follow the surrounding PHP style and use `declare(strict_types=1);` in new PHP files.
- `public/` is the web root. Keep application code, configuration, credentials, and storage outside it.
- Keep HTTP routing and application wiring in `src/App.php`, OAuth and credential handling in `src/Auth/`, playlist behavior in `src/Playlist/`, and official YouTube API calls in `src/YouTube/`.
- Keep unofficial YouTube Music transport, parsing, service behavior, and tool definitions in `src/Music/`. Do not assume official YouTube API results correspond exactly to the Music catalog or library.
- SQLite on local storage and local filesystem locks are the supported storage model. Do not assume multiple hosts or network filesystems are supported.

## Security and account isolation

- Never expose or commit `.env`, Google credentials, OAuth tokens, signing keys, `APP_KEY`, or runtime database contents. Use dummy credentials in examples and tests, and avoid printing secrets in logs or command output.
- Preserve user, selected-channel, and connection-generation isolation in queries, caches, locks, and operation receipts. Check ownership and the specific service scope before accessing or changing account data.
- Preserve PKCE S256, exact permitted OAuth redirects, token expiry, refresh rotation, revocation, audience checks, encrypted credential storage, and secure production cookies.
- Production requires HTTPS. Any HTTP smoke-test configuration must be temporary and isolated from production credentials and storage.

## MCP tools and upstream mutations

- When adding or changing a tool, keep its schema, required arguments, scope checks, annotations, handler, service behavior, and documentation consistent. Review both `src/Mcp/Tools.php` and `src/Music/Tools.php` as applicable.
- Route account mutations through the existing receipt and locking mechanisms. Preserve operation-key argument checks, completed-operation replay protection, and explicit `resume: true` for pending steps of partial operations.
- Never automatically retry or resume an uncertain upstream write. A timeout or interrupted request may already have changed the account; retain enough receipt information for reconciliation.
- Preserve private defaults for new playlists. Distinguish video IDs from playlist-item IDs: removing or moving an occurrence requires its playlist-item ID.
- Keep batch sizes, pagination, full-scan limits, and request/write time bounds explicit. Do not silently truncate results or imply a bounded response contains the complete library.
- Usage reporting is informational. Do not describe locally observed estimates as remaining YouTube quota or add local quota admission limits or automatic upstream-error retries without an explicit requirement.

## Development and verification

- Use `composer install` to install locked development dependencies. Do not edit `vendor/` or generated caches, or run a broad dependency update for an unrelated change.
- Run `composer test` for PHP behavior changes; use a focused Pest test while iterating. Add regression coverage for changed behavior, especially authorization, account isolation, receipts, and upstream failure handling.
- On the current Windows machine, the documented test invocation is:

  ```powershell
  $env:OPENSSL_CONF = 'C:/tools/php82/extras/ssl/openssl.cnf'
  php -d extension=fileinfo -d extension=pdo_sqlite -d extension=sodium vendor/bin/pest
  ```

  This path is machine-specific; check the installed PHP configuration if it is unavailable elsewhere.

- Use fixtures, mocked HTTP responses, in-memory SQLite, or isolated temporary storage for automated tests. Do not run tests against a real user's playlists, tokens, or database.
- Run `php -l` on changed PHP files and `composer audit` when changing dependencies. Use `composer phpstan` for level-7 static analysis, `composer cs` for PHP_CodeSniffer, and `composer cs-check` for a PHP-CS-Fixer dry run (also available as Make targets). Both style tools share the non-risky PER Coding Style 3.0 preset in `.php-cs-fixer.dist.php`; CodeSniffer checks it in memory through a local sniff. Apply `composer cs-fix` only to the intended formatting scope and avoid unrelated formatting changes. Use PHP-CS-Fixer for repairs, since the local sniff does not provide PHPCBF fixes. CI gates tests, PHPStan, and the PHP-CS-Fixer formatting check.
- Local tests do not establish live Google, ChatGPT, Music endpoint, or Plesk compatibility. State which checks ran and any live verification still needed.

## Storage, deployment, and documentation

- Add schema changes as new ordered SQL files under `migrations/`; do not rewrite an already-applied migration. Verify migration behavior with isolated storage.
- Treat `var/` as runtime state. Do not delete or overwrite existing databases, locks, receipts, or keys while developing. Do not rotate `APP_KEY` casually: existing encrypted credentials depend on it.
- Deployment and initialization scripts can change configuration and storage. Inspect their effects before running them, and do not deploy or mutate a live account unless the user requested that work.
- Update `README.md`, `.env.example`, and the relevant existing files in `docs/` when changing setup, configuration, permissions, tool behavior, or deployment. Verify referenced documentation exists before relying on it; some README links may be stale.
- Preserve unrelated local changes. Stage explicit task files rather than using blanket staging, so the protected human file and unrelated work cannot be included.
