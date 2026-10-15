# Architecture

This is a PHP 8.2+ multi-user MCP service running one application instance under PHP-FPM. It uses local SQLite and filesystem locks; it has no background worker. Official YouTube Data API results do not establish YouTube Music catalog membership or playback.

## Request and tool flow

`public/index.php` creates the PSR-7 request. `src/App.php` wires request-scoped dependencies, maps routes, and applies host/origin checks, request limits, browser authentication, error handling, and response headers. HTTP handlers live in `src/Routes/`; escaped browser templates live outside the web root in `views/`.

`Routes/McpRoute.php` authenticates the OAuth client and builds the MCP server. `Mcp/Tools.php` publishes schemas and annotations, invokes `OAuth::runAuthenticated()` for each tool call, and applies compact presentation. That authenticated boundary rechecks expiry, revocation and connection generation under the account lock before dispatch.

`Mcp/Dispatcher.php` routes tools to the services supplied by `App.php`: playlist/preferences, playlist workflows, YouTube reads/comments, and creator/media behavior. It also handles receipt lookup under the account lock. Services retain their own validation, scope checks and mutation mechanisms. They do not construct sibling domain services.

`Mcp/ToolCatalog.php` resolves tool ownership, write classification and permissions for dispatch, published metadata, service checks and receipt authorization. YouTube and creator tool definitions own their domain-specific scope rules. A new tool needs a schema, catalog routing/permission entry, handler behavior, tests and documentation together; unknown tool names fail closed.

## Effects and isolation

`Auth/` owns Google credentials, OAuth and browser sessions. Credentials are encrypted with `APP_KEY`. Browser authentication generations, Google connection generations and selected channel IDs have distinct lifetimes; preserve them when changing storage or account flows.

`Operations/Receipts.php` binds mutations to user, connection generation, tool, operation key and normalized arguments. It persists the pending step before an effect and the result afterward. Completed steps replay locally; partial operations require explicit resume. Running or uncertain effects cannot automatically resume. Receipt reads require the originating scope or its corresponding service read scope.

`Storage/Lock.php` uses bounded local lock slots. Independent owners fail busy rather than waiting indefinitely; the same request owner can reenter the same logical lock. Slot collisions can also return busy. This design requires local storage on one host.

`YouTube/Api.php` owns JSON API calls and `YouTube/MediaApi.php` owns upload/caption transport. Neither automatically retries upstream errors. Mutation transport failures remain uncertain. Cache entries are bound to user, channel and connection generation; dependency versions prevent stale in-flight reads from restoring invalidated responses. Mutation preflight reads stay fresh.

`Quota/Usage.php` records observed request estimates and operation attribution. It does not reserve quota or admit/reject requests. Historical reservation/breaker tables remain in the schema for upgrade compatibility and are cleared by cleanup; existing migrations and usage history stay unchanged.

## Operations and verification

Run the hourly CLI cleanup task, migrations and backups as described in [operations](OPERATIONS.md). Busy accounts defer expired media until a later run without stopping other cleanup. Real storage errors still fail the command.

Tests use mocked upstream calls and isolated storage. CI checks locked dependencies, tests on PHP 8.2/8.5, PHPStan level 7 and formatting. These checks do not establish deployment or client compatibility; record the [live acceptance checks](ACCEPTANCE.md) separately.
