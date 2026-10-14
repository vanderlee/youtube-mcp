CREATE TABLE api_cache_tags (
    cache_key TEXT NOT NULL REFERENCES api_cache(cache_key) ON DELETE CASCADE,
    tag TEXT NOT NULL,
    PRIMARY KEY(cache_key, tag)
);
CREATE INDEX api_cache_tags_tag ON api_cache_tags(tag, cache_key);
CREATE TABLE api_cache_versions (
    user_id TEXT NOT NULL,
    tag TEXT NOT NULL,
    version INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY(user_id, tag)
);
-- Old entries have no dependency information and cannot be invalidated selectively.
DELETE FROM api_cache;
