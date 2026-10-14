-- Retire only the separate Music credentials. Official OAuth and receipts remain.
DROP TABLE IF EXISTS music_browser_connections;
DROP TABLE IF EXISTS music_connections;
