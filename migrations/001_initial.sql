CREATE TABLE users
(
    id         TEXT PRIMARY KEY,
    subject    TEXT    NOT NULL UNIQUE,
    email      TEXT    NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE TABLE google_connections
(
    user_id      TEXT PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
    channel_id   TEXT NOT NULL,
    channel_name TEXT NOT NULL,
    tokens       TEXT NOT NULL,
    scopes       TEXT NOT NULL,
    generation   TEXT NOT NULL
);
CREATE TABLE oauth_clients
(
    id            TEXT PRIMARY KEY,
    name          TEXT    NOT NULL,
    redirect_uris TEXT    NOT NULL,
    secret_hash   TEXT,
    confidential  INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE oauth_tokens
(
    id         TEXT PRIMARY KEY,
    kind       TEXT    NOT NULL,
    user_id    TEXT REFERENCES users (id) ON DELETE CASCADE,
    client_id  TEXT    NOT NULL,
    expires_at INTEGER NOT NULL,
    revoked    INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE operations
(
    id            TEXT PRIMARY KEY,
    user_id       TEXT    NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    generation    TEXT    NOT NULL,
    tool          TEXT    NOT NULL,
    operation_key TEXT    NOT NULL,
    request_hash  TEXT    NOT NULL,
    status        TEXT    NOT NULL,
    receipt       TEXT    NOT NULL,
    created_at    INTEGER NOT NULL,
    updated_at    INTEGER NOT NULL,
    UNIQUE (user_id, generation, operation_key)
);
CREATE TABLE quota_windows
(
    window   TEXT    NOT NULL,
    owner    TEXT    NOT NULL,
    bucket   TEXT    NOT NULL,
    spent    INTEGER NOT NULL DEFAULT 0,
    reserved INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (window, owner, bucket)
);
CREATE TABLE quota_reservations
(
    id         TEXT PRIMARY KEY,
    user_id    TEXT    NOT NULL,
    window     TEXT    NOT NULL,
    bucket     TEXT    NOT NULL,
    remaining  INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
);
CREATE TABLE quota_requests
(
    id           TEXT PRIMARY KEY,
    user_id      TEXT    NOT NULL,
    operation_id TEXT,
    method       TEXT    NOT NULL,
    bucket       TEXT    NOT NULL,
    cost         INTEGER NOT NULL,
    window       TEXT    NOT NULL,
    state        TEXT    NOT NULL,
    created_at   INTEGER NOT NULL
);
CREATE TABLE quota_circuit_breakers
(
    bucket   TEXT PRIMARY KEY,
    until_at INTEGER NOT NULL,
    reason   TEXT    NOT NULL
);
CREATE TABLE rate_windows
(
    user_id TEXT    NOT NULL,
    window  INTEGER NOT NULL,
    calls   INTEGER NOT NULL,
    PRIMARY KEY (user_id, window)
);
CREATE TABLE api_cache
(
    cache_key  TEXT PRIMARY KEY,
    user_id    TEXT    NOT NULL,
    value      TEXT    NOT NULL,
    expires_at INTEGER NOT NULL
);
CREATE TABLE audit_events
(
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    TEXT,
    action     TEXT    NOT NULL,
    target     TEXT,
    outcome    TEXT    NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE INDEX operations_age ON operations (updated_at);
CREATE INDEX cache_expiry ON api_cache (expires_at);
CREATE INDEX tokens_expiry ON oauth_tokens (expires_at);
