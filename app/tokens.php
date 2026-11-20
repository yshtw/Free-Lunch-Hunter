<?php
declare(strict_types=1);

/**
 * Scoped personal API tokens for non-browser clients such as the MCP server.
 *
 * A token is `flh_<16 hex id>_<64 hex secret>`. The id is a lookup key; only a SHA-256
 * digest of the 256-bit random secret is stored. Tokens are not ambient credentials
 * (browsers never attach them automatically), so token requests do not need CSRF tokens.
 */
const TOKEN_SCOPES = ['read', 'schedule:write', 'membership:redeem'];
const TOKEN_MAX_DAYS = 365;

function token_scopes(string $list): array
{
    $scopes = array_values(array_unique(array_filter(array_map('trim', explode(',', $list)))));
    if (!$scopes || array_diff($scopes, TOKEN_SCOPES)) {
        throw new InvalidArgumentException('Scopes must be a comma-separated subset of: ' . implode(',', TOKEN_SCOPES));
    }
    // Writing without reading is not a useful client and makes tool discovery impossible.
    if (!in_array('read', $scopes, true)) {
        $scopes[] = 'read';
    }
    sort($scopes);
    return $scopes;
}

/** Returns the plaintext token. It cannot be recovered later. */
function issue_token(int $userId, array $scopes, string $label, int $days): array
{
    if ($days < 1 || $days > TOKEN_MAX_DAYS) {
        throw new InvalidArgumentException('Token lifetime must be 1–' . TOKEN_MAX_DAYS . ' days.');
    }
    $label = trim($label);
    if ($label === '' || strlen($label) > 80) {
        throw new InvalidArgumentException('Label must be 1–80 bytes.');
    }
    $id = bin2hex(random_bytes(8));
    $secret = bin2hex(random_bytes(32));
    query('INSERT INTO api_tokens(token_id,user_id,secret_hash,scopes,label,expires_at)
        VALUES(?,?,?,?,?,NOW() + INTERVAL ? DAY)',
        [$id, $userId, hash('sha256', $secret), implode(',', $scopes), $label, $days]);
    return ['token' => "flh_{$id}_{$secret}", 'token_id' => $id, 'scopes' => $scopes,
        'expires_at' => query('SELECT expires_at FROM api_tokens WHERE token_id=?', [$id])->fetchColumn()];
}

function authorization_header(): ?string
{
    $value = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    return is_string($value) ? $value : null;
}

/** Resolves a bearer token to its account and scopes, or throws 401 with one generic message. */
function token_principal(string $header): array
{
    $invalid = new HttpError(401, 'API token 無效、已過期或已撤銷。');
    if (!preg_match('/\ABearer flh_([a-f0-9]{16})_([a-f0-9]{64})\z/', $header, $match)) {
        throw $invalid;
    }
    $row = query('SELECT token_id,user_id,secret_hash,scopes FROM api_tokens
        WHERE token_id=? AND revoked_at IS NULL AND expires_at>NOW()', [$match[1]])->fetch();
    // Compare against a fixed digest when the id is unknown so both paths hash and compare.
    $expected = $row['secret_hash'] ?? str_repeat('0', 64);
    if (!hash_equals($expected, hash('sha256', $match[2])) || !$row) {
        throw $invalid;
    }
    // Throttled bookkeeping write; a token used every second does not update every request.
    query('UPDATE api_tokens SET last_used_at=NOW() WHERE token_id=?
        AND (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL 1 MINUTE)', [$row['token_id']]);
    $user = query('SELECT u.user_id,u.username,u.email,p.premium,p.money FROM user_account u
        JOIN premium p ON p.user_id=u.user_id WHERE u.user_id=?', [$row['user_id']])->fetch();
    if (!$user) {
        throw $invalid;
    }
    return ['user' => $user, 'scopes' => explode(',', $row['scopes']), 'token_id' => $row['token_id']];
}

function require_scope(array $principal, string $scope): void
{
    if (!in_array($scope, $principal['scopes'], true)) {
        throw new HttpError(403, "此 API token 沒有 {$scope} 權限。");
    }
}
