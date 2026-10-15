<?php
declare(strict_types=1);

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Taipei');

final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

function db(): PDO
{
    static $connection;
    if ($connection instanceof PDO) {
        return $connection;
    }
    $connection = new PDO(
        getenv('DB_DSN') ?: 'mysql:host=localhost;dbname=free_lunch_hunter;charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'root',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]
    );
    // Keep SQL NOW()/CURDATE() aligned with the configured single-market timezone.
    $connection->exec('SET time_zone=' . $connection->quote((new DateTimeImmutable())->format('P')));
    return $connection;
}

function query(string $sql, array $parameters = []): PDOStatement
{
    $statement = db()->prepare($sql);
    $statement->execute($parameters);
    return $statement;
}

function transaction(callable $operation): mixed
{
    db()->beginTransaction();
    try {
        $result = $operation();
        db()->commit();
        return $result;
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $error;
    }
}

/** Keep related reads on one committed version without blocking wallet writers. */
function read_snapshot(callable $operation): mixed
{
    db()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    return transaction($operation);
}

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function json_response(mixed $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function wants_json(): bool
{
    return basename($_SERVER['SCRIPT_NAME'] ?? '') === 'api.php'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

function csrf_token(): string
{
    return $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function post_data(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new HttpError(405, '此操作需要 POST 請求。');
    }
    $data = $_POST;
    if (str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        try {
            $data = json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpError(400, 'JSON 格式錯誤。');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new HttpError(400, '請提供 JSON 物件。');
        }
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($data['csrf_token'] ?? '');
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        throw new HttpError(403, '驗證已失效，請重新載入頁面。');
    }
    return $data;
}

function text(array $data, string $key, int $maximum, bool $required = true): string
{
    $value = $data[$key] ?? '';
    if (!is_string($value)) {
        throw new HttpError(422, '欄位格式不正確：' . $key);
    }
    $value = trim($value);
    if (($required && $value === '') || strlen($value) > $maximum) {
        throw new HttpError(422, '請確認欄位內容與長度：' . $key);
    }
    return $value;
}

function positive_id(mixed $value): int
{
    $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($number === false) {
        throw new HttpError(422, '識別碼格式不正確。');
    }
    return $number;
}

function current_user(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    return query('SELECT u.user_id,u.username,u.email,p.premium,p.money FROM user_account u
        JOIN premium p ON p.user_id=u.user_id WHERE u.user_id=?', [$_SESSION['user_id']])->fetch() ?: null;
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        if (wants_json()) {
            throw new HttpError(401, '請先登入。');
        }
        redirect('login.php');
    }
    return $user;
}

function require_admin(): array
{
    $user = require_user();
    if ((int) $user['premium'] !== 2) {
        throw new HttpError(403, '此操作僅限活動管理員。');
    }
    return $user;
}

function flash(string $message): void
{
    $_SESSION['flash'] = $message;
}

function safe_url(?string $url): string
{
    $url = trim($url ?? '');
    return filter_var($url, FILTER_VALIDATE_URL)
        && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https', 'http'], true) ? $url : '';
}

require_once __DIR__ . '/services.php';
require_once __DIR__ . '/view.php';

if (PHP_SAPI !== 'cli') {
    $requestId = bin2hex(random_bytes(8));
    header('X-Request-ID: ' . $requestId);
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' https:; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
    header('Cache-Control: no-store');
    ini_set('session.use_strict_mode', '1');
    session_name('flh_session');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax',
        'secure' => ($_SERVER['HTTPS'] ?? '') === 'on']);
    session_start();
    set_exception_handler(function (Throwable $error) use ($requestId): void {
        $status = $error instanceof HttpError ? $error->status : 500;
        $message = $error instanceof HttpError ? $error->getMessage() : '服務暫時無法完成操作，請稍後重試。';
        // Log correlation and error type without request bodies, credentials, or SQL values.
        error_log(json_encode(['request_id' => $requestId, 'status' => $status, 'type' => get_class($error)]));
        if (wants_json()) {
            json_response(['error' => $message, 'request_id' => $requestId], $status);
        }
        http_response_code($status);
        echo '<!doctype html><html lang="zh-Hant"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>操作未完成</title><link rel="stylesheet" href="assets/app.css"><main class="auth-card"><span class="eyebrow">FREE LUNCH HUNTER</span><h1>操作未完成</h1><p>' . e($message) . '</p><a class="button" href="index.php">返回首頁</a><p class="muted">參考編號 ' . e($requestId) . '</p></main></html>';
    });
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 3 * 1024 * 1024) {
        throw new HttpError(413, '請求內容過大。');
    }
}
