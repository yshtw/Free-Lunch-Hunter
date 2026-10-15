<?php
declare(strict_types=1);

function page_start(string $title, ?array $user = null): void
{
    ?><!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($title) ?> · Free Lunch Hunter</title>
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/app.css">
<script src="assets/app.js" defer>
</script>
</head>
<body>
<a class="skip-link" href="#main">跳至主要內容</a>
<header class="site-header">
<a class="brand" href="index.php">
<span class="brand-mark">FL<span>H</span>
</span>
<span>Free Lunch<br>
<strong>Hunter.</strong>
</span>
</a>
<?php if ($user): ?><nav aria-label="主要導覽">
<a href="index.php">探索活動</a>
<a href="post_article.php">分享心得</a>
<a href="manage_articles.php">我的文章</a>
<a href="wallet.php">點數與會員</a><?php if ((int) $user['premium'] === 2): ?><a href="admin_dashboard.php">活動管理</a><?php endif; ?></nav>
<div class="account">
<span class="points"><?= e(number_format((float) $user['money'], 0)) ?> 點</span>
<form method="post" action="logout.php"><?= csrf_field() ?><button class="text-button" type="submit">登出</button>
</form>
</div>
<?php else: ?><nav aria-label="帳戶">
<a href="login.php">登入</a>
<a class="button small" href="register.php">加入社群 ↗</a>
</nav><?php endif; ?></header>
<main id="main" class="page">
<?php if (isset($_SESSION['flash'])): ?><div class="notice" role="status"><?= e($_SESSION['flash']) ?></div><?php unset($_SESSION['flash']); endif;
}

function page_end(): void
{
    ?></main>
<footer class="site-footer">
<a class="wordmark" href="index.php">Free Lunch Hunter.</a>
<span>好活動，好好分享。</span>
<a href="about.php">關於這個社群 ↗</a>
</footer>
<div id="live-status" class="toast" role="status" aria-live="polite" hidden>
</div>
</body>
</html><?php
}

function image_url(?string $path): string
{
    $path = $path ?? '';
    if (str_starts_with($path, '/final_project/')) {
        $path = substr($path, strlen('/final_project/'));
    }
    if (str_starts_with($path, 'uploads/') || str_starts_with($path, 'src/img/') || str_starts_with($path, 'images/')) {
        return preg_match('~\A(?:uploads|src/img|images)/[A-Za-z0-9_.-]+\z~', $path) ? $path : '';
    }
    return str_starts_with($path, 'https://') ? safe_url($path) : '';
}

function activity_fields(array $activity = []): void
{
    $fields = ['title' => ['活動名稱', 'text'], 'content' => ['活動介紹', 'textarea'],
        'act_date' => ['日期', 'date'], 'place' => ['地點', 'text'],
        'start_time' => ['開始時間', 'time'], 'end_time' => ['結束時間', 'time'],
        'lunch' => ['午餐內容（選填）', 'text'], 'url' => ['報名網址（選填）', 'url'], 'img' => ['HTTPS 圖片網址（選填）', 'url']];
    foreach ($fields as $key => [$label, $type]) {
        $required = in_array($key, ['lunch', 'url', 'img'], true) ? '' : ' required';
        ?><label><?= e($label) ?><?php if ($type === 'textarea'): ?><textarea name="<?= e($key) ?>" rows="5" maxlength="10000"<?= $required ?>><?= e($activity[$key] ?? '') ?></textarea><?php else: ?><input name="<?= e($key) ?>" type="<?= e($type) ?>" value="<?= e($activity[$key] ?? '') ?>"<?= $required ?><?php if ($type === 'text'): ?> maxlength="200"<?php endif; ?>><?php endif; ?></label><?php
    }
}

function activity_card(array $activity, bool $admin = false): void
{
    $date = new DateTimeImmutable($activity['act_date']);
    ?><article class="activity-card">
<div class="card-top">
<span class="tag">校園活動</span>
<span class="date-number"><?= e($date->format('m.d')) ?></span>
</div>
<h3><?= e($activity['title']) ?></h3>
<p class="card-description"><?= e($activity['content']) ?></p>
<div class="card-meta">
<span>⌖ <?= e($activity['place']) ?></span>
<span><?= e($activity['start_time']) ?>–<?= e($activity['end_time']) ?></span>
</div>
<?php if ($activity['lunch']): ?><p class="lunch-label">午餐 · <?= e($activity['lunch']) ?></p><?php endif; ?>
<?php if ($url = safe_url($activity['url'])): ?><a class="external-link" href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer">前往報名 ↗</a><?php endif; ?>
<?php if ($admin): ?><a class="button secondary full" href="manage_activity.php?activity_id=<?= e($activity['activity_id']) ?>">編輯我的活動</a>
<?php else: ?><form method="post" action="manage_calendar.php" data-calendar-form><?= csrf_field() ?><input type="hidden" name="activity_id" value="<?= e($activity['activity_id']) ?>">
<input type="hidden" name="action" value="<?= !empty($activity['joined']) ? 'delete' : 'add' ?>">
<button class="button secondary full" type="submit"><?= !empty($activity['joined']) ? '✓ 已加入行事曆' : '＋ 加入行事曆' ?></button>
</form><?php endif; ?></article>
<?php
}
