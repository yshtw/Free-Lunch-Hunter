-- Migration 2: scoped personal API tokens for non-browser clients (for example the MCP server).
-- Only a SHA-256 digest of the random secret is stored; the plaintext token is shown once by scripts/token.php.
CREATE TABLE IF NOT EXISTS api_tokens (
    token_id CHAR(16) PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    secret_hash CHAR(64) NOT NULL,
    scopes VARCHAR(200) NOT NULL,
    label VARCHAR(80) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    KEY token_owner (user_id),
    FOREIGN KEY (user_id) REFERENCES user_account(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
