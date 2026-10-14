CREATE TABLE browser_accounts (
    user_id TEXT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    generation TEXT NOT NULL
);

INSERT INTO browser_accounts(user_id, generation)
SELECT id, lower(hex(randomblob(16))) FROM users;
