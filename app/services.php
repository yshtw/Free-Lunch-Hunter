<?php
declare(strict_types=1);

function register_account(array $data): void
{
    $email = strtolower(text($data, 'email', 254));
    $username = text($data, 'username', 80);
    $password = $data['password'] ?? '';
    $birth = text($data, 'birth_date', 10);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $birth);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !$date || $date->format('Y-m-d') !== $birth
        || $birth > date('Y-m-d') || $birth < '1900-01-01') {
        throw new HttpError(422, '請輸入有效的 Email 與生日。');
    }
    if (!is_string($password) || str_contains($password, "\0") || strlen($password) < 12 || strlen($password) > 72
        || $password !== ($data['confirm_password'] ?? '')) {
        throw new HttpError(422, '請使用 12–72 位元組的密碼，並確認兩次輸入相同。');
    }
    try {
        transaction(function () use ($email, $username, $password, $birth): void {
            query('INSERT INTO user_account(email,username,password,birth_date,reg_date) VALUES(?,?,?,?,NOW())',
                [$email, $username, password_hash($password, PASSWORD_DEFAULT), $birth]);
            // Roles never come from public request data. Administrators are provisioned by CLI.
            query('INSERT INTO premium(user_id,premium,money) VALUES(?,0,0)', [db()->lastInsertId()]);
        });
    } catch (PDOException $error) {
        if ($error->getCode() === '23000') {
            throw new HttpError(409, 'Email 或使用者名稱已被使用。');
        }
        throw $error;
    }
}

function login_account(array $data): void
{
    $email = strtolower(text($data, 'email', 254));
    $password = $data['password'] ?? '';
    if (!is_string($password) || $password === '' || strlen($password) > 72) {
        throw new HttpError(401, 'Email 或密碼不正確。');
    }
    $user = query('SELECT user_id,password FROM user_account WHERE email=?', [$email])->fetch();
    // A valid dummy hash keeps password verification in the missing-account path.
    $hash = $user['password'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
    if (!password_verify($password, $hash) || !$user) {
        throw new HttpError(401, 'Email 或密碼不正確。');
    }
    session_regenerate_id(true);
    $_SESSION = ['user_id' => (int) $user['user_id']];
}

function lock_wallet(int $userId): array
{
    $wallet = query('SELECT premium,money FROM premium WHERE user_id=? FOR UPDATE', [$userId])->fetch();
    if (!$wallet) {
        throw new HttpError(404, '會員帳戶不存在。');
    }
    return $wallet;
}

/** Must run in the same transaction as the domain write, with the wallet row locked. */
function wallet_entry(int $userId, int $amount, string $action, string $reference): bool
{
    if (query('SELECT entry_id FROM wallet_entries WHERE user_id=? AND action=? AND reference=?',
        [$userId, $action, $reference])->fetch()) {
        return false;
    }
    query('INSERT INTO wallet_entries(user_id,amount,action,reference) VALUES(?,?,?,?)',
        [$userId, $amount, $action, $reference]);
    query('UPDATE premium SET money=money+? WHERE user_id=?', [$amount, $userId]);
    return true;
}

function membership_order(int $userId, string $key, string $product): array
{
    if (!preg_match('/\A[A-Za-z0-9_-]{16,80}\z/', $key)) {
        throw new HttpError(422, '請提供 16–80 字元的 Idempotency-Key。');
    }
    if ($product !== 'premium_lifetime') {
        throw new HttpError(422, '會員方案不存在。');
    }
    return transaction(function () use ($userId, $key, $product): array {
        $wallet = lock_wallet($userId);
        $existing = query('SELECT order_id,product_code,amount,status,created_at FROM membership_orders
            WHERE user_id=? AND idempotency_key=?', [$userId, $key])->fetch();
        if ($existing) {
            if ($existing['product_code'] !== $product) {
                throw new HttpError(409, '同一請求識別碼不可用於不同方案。');
            }
            return ['order' => $existing, 'replayed' => true];
        }
        if ((int) $wallet['premium'] !== 0) {
            throw new HttpError(409, '目前會員等級無需再次升級。');
        }
        if ((float) $wallet['money'] < 30) {
            throw new HttpError(409, '點數不足，升級需要 30 點。');
        }
        $id = bin2hex(random_bytes(16));
        query('INSERT INTO membership_orders(order_id,user_id,product_code,amount,status,idempotency_key)
            VALUES(?,?,?,30,\'completed\',?)', [$id, $userId, $product, $key]);
        wallet_entry($userId, -30, 'membership', $id);
        query('UPDATE premium SET premium=1 WHERE user_id=?', [$userId]);
        return ['order' => query('SELECT order_id,product_code,amount,status,created_at FROM membership_orders
            WHERE order_id=?', [$id])->fetch(), 'replayed' => false];
    });
}

function sandbox_credit(int $userId, array $data): void
{
    if (getenv('ENABLE_DEMO_TOPUP') !== '1') {
        throw new HttpError(403, '示範加點未啟用；本專案不處理真實金流。');
    }
    $amount = filter_var($data['amount'] ?? null, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    $key = text($data, 'idempotency_key', 80);
    if ($amount === false || !preg_match('/\A[A-Za-z0-9_-]{16,80}\z/', $key)) {
        throw new HttpError(422, '加點數量必須是 1–1000 的整數，並附上請求識別碼。');
    }
    transaction(function () use ($userId, $amount, $key): void {
        lock_wallet($userId);
        $existing = query('SELECT amount FROM wallet_entries WHERE user_id=? AND action=\'sandbox_credit\'
            AND reference=?', [$userId, $key])->fetch();
        if ($existing && (int) $existing['amount'] !== $amount) {
            throw new HttpError(409, '同一請求識別碼不可使用不同的加點數量。');
        }
        wallet_entry($userId, $amount, 'sandbox_credit', $key);
    });
}

function uploaded_image(?array $file): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? null) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 2 * 1024 * 1024
        || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new HttpError(422, '圖片上傳失敗；檔案不可超過 2 MB。');
    }
    $type = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$type] ?? null;
    $dimensions = @getimagesize($file['tmp_name']);
    if (!$extension || !$dimensions || $dimensions[0] * $dimensions[1] > 16000000) {
        throw new HttpError(422, '請上傳有效的 JPEG、PNG 或 WebP 圖片。');
    }
    $path = 'uploads/' . bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], dirname(__DIR__) . '/final_project/' . $path)) {
        throw new RuntimeException('Upload storage unavailable');
    }
    return $path;
}

function save_article(int $userId, array $data, ?array $file = null, ?int $id = null): int
{
    $title = text($data, 'title', 200);
    $content = text($data, 'content', 20000);
    $image = null;
    try {
        return transaction(function () use ($userId, $title, $content, $file, $id, &$image): int {
            lock_wallet($userId);
            if ($id !== null && !query('SELECT article_id FROM articles WHERE article_id=? AND user_id=? FOR UPDATE',
                [$id, $userId])->fetch()) {
                throw new HttpError(404, '找不到你可以編輯的文章。');
            }
            $image = uploaded_image($file);
            if ($id !== null) {
                query('UPDATE articles SET title=?,content=?,img=COALESCE(?,img) WHERE article_id=? AND user_id=?',
                    [$title, $content, $image, $id, $userId]);
                return $id;
            }
            query('INSERT INTO articles(user_id,title,content,img,art_date,rate) VALUES(?,?,?,?,NOW(),0)',
                [$userId, $title, $content, $image]);
            $newId = (int) db()->lastInsertId();
            wallet_entry($userId, 5, 'post', (string) $newId);
            return $newId;
        });
    } catch (Throwable $error) {
        if ($image !== null) {
            unlink(dirname(__DIR__) . '/final_project/' . $image);
        }
        throw $error;
    }
}

function delete_article(int $userId, int $id): void
{
    transaction(function () use ($userId, $id): void {
        if (!query('SELECT article_id FROM articles WHERE article_id=? AND user_id=? FOR UPDATE', [$id, $userId])->fetch()) {
            throw new HttpError(404, '找不到你可以刪除的文章。');
        }
        query('DELETE FROM comments WHERE article_id=?', [$id]);
        query('DELETE FROM votes WHERE article_id=?', [$id]);
        query('DELETE FROM articles WHERE article_id=? AND user_id=?', [$id, $userId]);
    });
}

function comment_article(int $userId, array $data): int
{
    $articleId = positive_id($data['article_id'] ?? null);
    $content = text($data, 'content', 2000);
    transaction(function () use ($userId, $articleId, $content): void {
        lock_wallet($userId);
        if (!query('SELECT article_id FROM articles WHERE article_id=? FOR UPDATE', [$articleId])->fetch()) {
            throw new HttpError(404, '文章不存在。');
        }
        query('INSERT INTO comments(article_id,user_id,content,comm_date) VALUES(?,?,?,NOW())', [$articleId, $userId, $content]);
        wallet_entry($userId, 3, 'comment', (string) db()->lastInsertId());
    });
    return $articleId;
}

function vote_article(int $userId, array $data): void
{
    $id = positive_id($data['article_id'] ?? null);
    $rate = filter_var($data['rate'] ?? null, FILTER_VALIDATE_INT);
    if (!in_array($rate, [-1, 0, 1], true)) {
        throw new HttpError(422, '評分只能是 -1、0 或 1。');
    }
    transaction(function () use ($userId, $id, $rate): void {
        lock_wallet($userId);
        // Serialize all score changes for this article, including concurrent voters.
        if (!query('SELECT article_id FROM articles WHERE article_id=? FOR UPDATE', [$id])->fetch()) {
            throw new HttpError(404, '文章不存在。');
        }
        $old = query('SELECT rate FROM votes WHERE user_id=? AND article_id=?', [$userId, $id])->fetch();
        $previous = $old ? (int) $old['rate'] : 0;
        if ($previous === $rate) {
            return; // Desired-state updates are safe to repeat after a network retry.
        }
        if ($rate === 0) {
            query('DELETE FROM votes WHERE user_id=? AND article_id=?', [$userId, $id]);
        } else {
            query('INSERT INTO votes(user_id,article_id,rate) VALUES(?,?,?) ON DUPLICATE KEY UPDATE rate=VALUES(rate)',
                [$userId, $id, $rate]);
            // One vote reward per user/article lifetime, even after deleting the vote.
            wallet_entry($userId, 1, 'vote', (string) $id);
        }
        query('UPDATE articles SET rate=rate+? WHERE article_id=?', [$rate - $previous, $id]);
    });
}

function save_activity(int $userId, array $data, ?int $id = null): void
{
    $date = text($data, 'act_date', 10);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $start = text($data, 'start_time', 5);
    $end = text($data, 'end_time', 5);
    if (!$parsed || $parsed->format('Y-m-d') !== $date || $date < date('Y-m-d')
        || !preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $start)
        || !preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $end) || $end <= $start) {
        throw new HttpError(422, '請選擇有效的未來日期與結束時間。');
    }
    $url = text($data, 'url', 2000, false);
    $image = text($data, 'img', 2000, false);
    if (($url !== '' && safe_url($url) === '') || ($image !== '' && !str_starts_with($image, 'https://'))) {
        throw new HttpError(422, '連結需為 HTTP(S)，圖片需使用 HTTPS。');
    }
    $values = [text($data, 'title', 200), text($data, 'content', 10000), $image, $date,
        text($data, 'place', 200), $start, $end, $url, text($data, 'lunch', 200, false)];
    if ($id !== null) {
        transaction(function () use ($id, $userId, $values): void {
            if (!query('SELECT activity_id FROM activities WHERE activity_id=? AND user_id=? FOR UPDATE', [$id, $userId])->fetch()) {
                throw new HttpError(404, '找不到你可以編輯的活動。');
            }
            query('UPDATE activities SET title=?,content=?,img=?,act_date=?,place=?,startt=?,endt=?,url=?,lunch=?
                WHERE activity_id=? AND user_id=?', [...$values, $id, $userId]);
        });
    } else {
        query('INSERT INTO activities(title,content,img,act_date,place,startt,endt,url,lunch,user_id) VALUES(?,?,?,?,?,?,?,?,?,?)',
            [...$values, $userId]);
    }
}

function calendar_membership(int $userId, array $data): void
{
    $id = positive_id($data['activity_id'] ?? null);
    $action = text($data, 'action', 10);
    if (!in_array($action, ['add', 'delete'], true)) {
        throw new HttpError(422, '不支援的行事曆操作。');
    }
    if ($action === 'delete') {
        query('DELETE FROM join_activity WHERE user_id=? AND activity_id=?', [$userId, $id]);
        return;
    }
    transaction(function () use ($userId, $id): void {
        if (!query('SELECT activity_id FROM activities WHERE activity_id=? AND act_date>=CURDATE() FOR UPDATE', [$id])->fetch()) {
            throw new HttpError(404, '活動不存在或已結束。');
        }
        query('INSERT INTO join_activity(user_id,activity_id) VALUES(?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)', [$userId, $id]);
    });
}
