CREATE TABLE music_browser_connections (
    user_id TEXT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    channel_id TEXT NOT NULL,
    google_generation TEXT NOT NULL,
    generation TEXT NOT NULL,
    credentials TEXT NOT NULL,
    channel_handle TEXT NOT NULL,
    expires_at INTEGER NOT NULL
);
