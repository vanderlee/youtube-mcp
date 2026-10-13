# Operations and recovery

## Usage reporting

YouTube is the sole quota and rate-limit authority. The app records estimated usage but never rejects a request based on local counters, reservations, per-user limits, or a cached quota error. Each explicit request can reach YouTube; quota and rate-limit responses are returned without automatic retry. Safe GET requests may retry 5xx responses twice; ambiguous writes are never automatically retried.

Compare usage estimates with Google Cloud Console. Remaining quota is unknown. Legacy quota environment settings are ignored. Cleanup removes obsolete reservations and circuit breakers while retaining request history.
## Mutation receipts

- `completed`: repeated same key/arguments return the saved result without upstream calls.
- `partial`: completed steps are recorded; explicitly request `resume: true` to attempt pending steps with the original arguments.
- `uncertain`: a dispatched write may have succeeded. No automatic resume.
- `running` after a crashed process: treat as uncertain; inspect the pending step and YouTube state.

For ambiguous creation or insertion, inspect playlist contents and occurrence IDs through the service or YouTube UI. If the change succeeded, do not reissue it under a new key. If state can be established reliably, issue a new narrowly scoped operation only for missing work. Otherwise stop and resolve manually. The current implementation does not provide a privileged force-resume tool or automatic reconciliation.

Successful earlier changes are never rolled back automatically. A bulk operation is not an upstream transaction. A scan or budget error before the first write still returns a partial receipt with zero completed steps; it can be explicitly resumed when the condition changes.

Receipt retention defaults to seven days for completed/partial operations. Uncertain/running receipts are retained for investigation. Do not reuse a key after retention expiry. Quota aggregates and request history remain for 30 days; normal cache entries last at most five minutes. Review YouTube developer-policy storage requirements before public release.

## Backup and restore

Back up `.env`/`APP_KEY`, the OAuth key pair, and the SQLite database as a consistent set, with encryption and restricted access. SQLite WAL requires a consistent backup: use SQLite's backup facility, or stop PHP traffic and checkpoint before copying the database. Do not copy only the main database during active writes and ignore WAL.

Restore to a private directory, preserve file permissions, run migrations, and verify decryptability and revocation state with a disposable account. Without `APP_KEY`, Google tokens cannot be decrypted. Rotating the OAuth signing key invalidates existing service JWTs; rotating `APP_KEY` without migrating encrypted data invalidates Google connections and outstanding encrypted authorization artifacts. An automated key-rotation mechanism is not implemented.

After restoring an older backup, revoke service tokens and require fresh connections if revocation or operation state might have rolled back. Do not assume restored receipts prove that a mutation never happened since the backup.

## Upgrades

Put the domain into maintenance mode, back up private state, deploy locked dependencies, run `php bin/console.php migrate`, and restart PHP workers if necessary. Preserve storage and keys. Test health, metadata, login, usage, and a disposable mutation before reopening access. Rollback must account for database migration compatibility; do not restore old operation state while continuing to accept writes.

## Privacy and logs

Audit events store user ID, action, target operation ID, outcome, and timestamp. Quota records store method/cost/state. They do not store authorization headers or Google secrets. Internal-error logs include request ID and source location without exception text, preventing an upstream payload or credential from entering logs.

Deleting a service account removes its Google credentials, OAuth tokens, cached data, receipts, and audit records. Usage accounting retains anonymous internal identifiers for the daily history period. No plaintext email is kept in quota records. Browser-session files are private and governed by PHP session expiry/garbage collection; include them in the installation's private-storage retention policy.

## Troubleshooting

| Symptom | Action |
|---|---|
| `SETUP_REQUIRED` | Check PHP extensions, `.env`, storage access, migrations, and OAuth key pair |
| `INVALID_HOST` | Match APP_URL and the public Host forwarded to PHP |
| 401 on MCP | Reconnect using service OAuth; confirm bearer headers reach PHP |
| OAuth callback error | Check exact callback allowlist and resource URL, then restart authorization |
| Google reconnect required | Re-grant offline YouTube access; inspect consent/testing configuration |
| Wrong channel | Reconnect Google using the intended channel and then reconnect ChatGPT |
| YouTube quota/rate-limit response | Inspect Google Cloud Console; retry later when upstream capacity is available |
| Upstream quota exhausted despite local balance | Inspect Cloud Console for outside traffic and actual limits |
| Busy operation | Retry shortly with the same operation key |
| Uncertain/running receipt | Inspect actual YouTube state; do not blindly replay |
| Large playlist scan limit | Read pages individually; operations needing a complete scan are unsupported beyond the bound |

## Deferred features

MariaDB support, multi-host locks, CIMD, automatic Cloud metrics reconciliation, automatic ambiguous-mutation reconciliation, background jobs, and automated key rotation are not included. Docker remains outside scope.

