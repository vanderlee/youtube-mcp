# Operations and recovery

Run one application instance on local storage. Use the subscription/application user for CLI tasks, with the same private configuration and compatible PHP extensions as PHP-FPM. Follow [Plesk](PLESK.md) or [Docker](DOCKER.md) for deployment-specific paths and commands.

## Maintenance and monitoring

Schedule `php bin/console.php cleanup` hourly from the project directory, using the installation's PHP binary. It clears expired cache/token records, old completed/partial receipts and audit events according to `OPERATION_RETENTION_DAYS` (default seven days), and old request/usage history after 30 days. Running and uncertain operation records are retained. Staged media expire after 24 hours.

If an account is busy, expired media for that account are retained and the command prints the number deferred. Other accounts, orphan-file cleanup and subsequent housekeeping continue. A later scheduled run attempts cleanup again. Repeated deferrals warrant checking active requests and lock contention; never delete lock files while workers are serving requests. Storage failures still produce a nonzero exit status and need operator attention.

Monitor `/health/live`, `/health/ready`, HTTP error rates, PHP logs, scheduled-task exit status and free disk space. Readiness is a local database check, not a Google or MCP-client acceptance test. Use the response request ID to locate request errors. Keep logs private and do not add credentials, authorization headers, upload-session URLs or raw database values to diagnostics.

Usage reports count requests observed by this installation. Compare actual limits and project traffic in Google Cloud Console; local estimates cannot determine remaining YouTube quota. Upstream errors are returned without automatic retries, including read server errors. Retry a failed read only as a separate caller decision; mutation recovery follows the receipt rules below.

## Recovering mutations

1. Stop issuing the same requested change under new operation keys. Save the operation ID, original arguments, connection/channel identity, and receipt.
2. Request `get_operation` with `response_detail: "full"` using the current connection and the originating tool's permission or corresponding service read permission. This reads the persisted receipt without calling YouTube. Playlist-only permission cannot read unrelated service receipts.
3. For `completed`, use the saved result. Repeating the original key and arguments returns the receipt without repeating effects while that receipt exists.
4. For `partial`, inspect the error and completed steps. Once the known failure is addressed, submit the same tool, operation key and arguments with `resume: true`. This attempts only pending work, subject to fresh preflight checks and bounded batches/time windows. An ordinary repeat without explicit resume returns the saved receipt.
5. For `running` or `uncertain`, inspect the pending step and the actual selected YouTube account. A timeout, interruption or server error may have applied the change. Automatic resume is blocked even when `resume: true` is supplied. Compare playlist-item IDs and occurrences, video/comment IDs, or media results as applicable; do not infer failure from a missing response.
6. Only after the outcome is established should a human decide whether a separate, explicitly requested remaining change is needed with a new key. There is no reconciliation command that marks an uncertain receipt safe to replay. Keep the original receipt; do not edit its status or delete it to force a retry.

Receipt access is limited to the current connection generation. Capture the receipt before reconnecting or disconnecting when possible. Reconnection creates a new key namespace and does not prove old effects failed. After retention removes a receipt, an old key no longer protects against replay; use retained records and the actual upstream state before deciding on a new operation.

Malformed stored receipt JSON or an invalid receipt structure stops replay and resume before any work is dispatched. Preserve the stored record and investigate storage integrity; do not replace it or create a new key as an automatic recovery step.

## Authorization failures

For Google unavailability, throttling or OAuth configuration errors, repair the reported cause and preserve stored credentials. Only an invalid Google grant requires reconnection. Reconnection invalidates existing service tokens; reauthorize clients afterward with only the scopes they need. Revoking client access is separate from disconnecting Google. Account deletion removes local account data and staged assets; check the reported Google revocation outcome.

Do not rotate `APP_KEY` to troubleshoot decryption errors. Existing encrypted credentials and saved upload sessions depend on it. Restore the matching key or investigate a configuration mismatch first. Never copy tokens or secrets into support messages or chat.

## Consistent backups and restores

For a full installation backup, stop incoming traffic, drain PHP-FPM requests and pause cleanup/deployment tasks so no process changes state. Securely copy the complete private storage directory, including the SQLite database and any WAL/SHM sidecars present, signing keys and staged media, together with the private `.env` containing the matching `APP_KEY`. Record the application revision and migration state. Use a backup destination outside `public/`, restrict access and encrypt backups. Do not copy only the main SQLite file while writers are active.

Restore first into an isolated installation with traffic, scheduled tasks and upstream mutations disabled. Restore configuration, keys and storage as one consistent set, then restore restrictive ownership/permissions. Verify database integrity and foreign keys using SQLite tooling, run the offline bootstrap/catalog checks, and inspect readiness before allowing requests. Follow the deployment guide for migration and worker-draining requirements. Do not overwrite a newer live database as a routine code rollback.

A restored receipt history can predate effects already applied on YouTube. Reconcile those effects before accepting mutations, even if the restored database has no matching receipt. Test restoration periodically; a backup is not verified until an isolated restore succeeds. Resume traffic and scheduled tasks only after the restored installation and external state have been reviewed.

## Upgrades

Back up private state before upgrading. Install locked dependencies, deploy complete application files including `views/`, apply ordered migrations, and run offline checks before serving the release. Preserve existing keys. Migration 007 and lock-format upgrades require the worker-draining procedure in [Plesk](PLESK.md#security-upgrade). New changes to the usage reporter and dispatcher require no schema migration; historical usage and receipt records are preserved.

Run the [acceptance checklist](ACCEPTANCE.md) with disposable resources after deployment. Local tests and health endpoints do not establish live Google, client or PHP-FPM compatibility.
