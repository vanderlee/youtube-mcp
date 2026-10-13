CREATE TABLE music_connections (
    user_id TEXT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    channel_id TEXT NOT NULL,
    google_generation TEXT NOT NULL,
    generation TEXT NOT NULL,
    client_id_hash TEXT NOT NULL,
    tokens TEXT NOT NULL,
    created_at INTEGER NOT NULL
);
