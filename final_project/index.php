<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
$user = require_user();
$id = (int) $user['user_id'];
$premium = (int) $user['premium'] > 0;
$search = text($_GET, 'q', 100, false);
$order = ($_GET['sort'] ?? '') === 'popular' && $premium ? 'a.rate DESC,a.article_id DESC' : 'a.art_date DESC,a.article_id DESC';
$page = max(1, min(10000, (int) ($_GET['page'] ?? 1)));
$offset = ($page - 1) * 5;
$activities = query('SELECT a.activity_id,a.title,a.content,a.act_date,a.place,a.lunch,a.url,
    TIME_FORMAT(a.startt,\'%H:%i\') AS start_time,TIME_FORMAT(a.endt,\'%H:%i\') AS end_time,
    EXISTS(SELECT 1 FROM join_activity j WHERE j.activity_id=a.activity_id AND j.user_id=?) AS joined
    FROM activities a WHERE a.act_date>=CURDATE() AND (a.title LIKE ? OR a.place LIKE ?)
    ORDER BY a.act_date,a.startt,a.activity_id LIMIT 12', [$id, '%'.$search.'%', '%'.$search.'%'])->fetchAll();
$articles = query('SELECT a.*,u.username,v.rate AS user_rate FROM articles a JOIN user_account u ON u.user_id=a.user_id
    LEFT JOIN votes v ON v.article_id=a.article_id AND v.user_id=? ORDER BY '.$order.' LIMIT 6 OFFSET '.$offset, [$id])->fetchAll();
$hasNext = count($articles) > 5;
$articles = array_slice($articles, 0, 5);
$comments = [];
if ($articles) {
    $ids = array_column($articles, 'article_id');
    $rows = query('SELECT c.*,u.username FROM comments c JOIN user_account u ON u.user_id=c.user_id
        WHERE c.article_id IN ('.implode(',', array_fill(0, count($ids), '?')).') ORDER BY c.comm_date,c.comment_id', $ids)->fetchAll();
    foreach ($rows as $row) $comments[$row['article_id']][] = $row;
}
$schedule = query('SELECT a.title,a.act_date,TIME_FORMAT(a.startt,\'%H:%i\') AS start_time FROM activities a
    JOIN join_activity j ON j.activity_id=a.activity_id WHERE j.user_id=? AND a.act_date>=CURDATE()
    ORDER BY a.act_date,a.startt LIMIT 8', [$id])->fetchAll();
page_start('探索活動', $user);
?>
<section class="hero">
<div>
<span class="eyebrow">YOUR NEXT GOOD THING</span>
<h1>午餐有著落，<br>
<em>生活有新發現。</em>
</h1>
<p class="hero-copy">嗨，<?= e($user['username']) ?>。從一場講座到一次交流，<br>找到值得出門的理由，也找到一起吃飯的人。</p>
<a class="button" href="#activities">探索近期活動 ↓</a>
<p class="hero-count">校園活動 × 社群心得 × 我的行事曆</p>
</div>
<div class="hero-art" aria-hidden="true">
<span class="hero-sticker">Good things are on the menu.</span>
</div>
</section>
<section id="activities">
<div class="section-heading">
<div>
<span class="eyebrow">ON THE MENU</span>
<h2>近期好活動</h2>
</div>
<p>留一個位置給新鮮事。</p>
</div>
<form class="filters" method="get">
<label>找活動或地點<input type="search" name="q" value="<?= e($search) ?>" maxlength="100" placeholder="講座、交流、校園…">
</label>
<button class="button" type="submit">搜尋活動 ↗</button>
</form>
<div class="activity-grid"><?php foreach ($activities as $activity) activity_card($activity); ?><?php if (!$activities): ?><div class="empty">
<h3>還沒有符合的活動。</h3>
<p>換個關鍵字，或稍後再回來看看。</p>
</div><?php endif; ?></div>
</section>
<div class="content-grid">
<section id="articles">
<div class="section-heading">
<div>
<span class="eyebrow">FROM THE COMMUNITY</span>
<h2>探索者的餐桌</h2>
</div>
<a href="post_article.php">分享心得 ↗</a>
</div>
<?php if ($premium): ?><form class="filters" method="get">
<label>心得排序<select name="sort">
<option value="recent">最新分享</option>
<option value="popular"<?= ($_GET['sort'] ?? '') === 'popular' ? ' selected' : '' ?>>最多推薦</option>
</select>
</label>
<button class="button secondary small">更新</button>
</form><?php endif; ?>
<?php foreach ($articles as $article): ?><article class="post" id="article-<?= e($article['article_id']) ?>">
<div class="post-meta">
<span><?= e($article['username']) ?> 的分享</span>
<time><?= e($article['art_date']) ?></time>
</div>
<h3><?= e($article['title']) ?></h3>
<p class="post-content"><?= e($article['content']) ?></p><?php if ($image = image_url($article['img'])): ?><img src="<?= e($image) ?>" alt="<?= e($article['title']) ?>的分享圖片" loading="lazy"><?php endif; ?><div class="post-actions">
<span class="muted"><?= e($article['rate']) ?> 推薦</span><?php foreach ([1 => '有幫助 ↑', -1 => '不推薦 ↓'] as $value => $label): ?><form action="rate.php" method="post"><?= csrf_field() ?><input type="hidden" name="article_id" value="<?= e($article['article_id']) ?>">
<input type="hidden" name="rate" value="<?= (int) $article['user_rate'] === $value ? 0 : $value ?>">
<button class="vote" aria-pressed="<?= (int) $article['user_rate'] === $value ? 'true' : 'false' ?>"><?= e($label) ?></button>
</form><?php endforeach; ?><?php if ((int) $article['user_id'] === $id): ?><a class="text-button" href="manage_articles.php">編輯</a><?php endif; ?></div>
<div class="comments"><?php foreach ($comments[$article['article_id']] ?? [] as $comment): ?><p class="comment">
<strong><?= e($comment['username']) ?></strong> · <?= e($comment['content']) ?><br>
<time><?= e($comment['comm_date']) ?></time>
</p><?php endforeach; ?><form class="comment-form" method="post" action="comments.php"><?= csrf_field() ?><input type="hidden" name="article_id" value="<?= e($article['article_id']) ?>">
<label class="full">回應這篇分享<input name="content" maxlength="2000" placeholder="分享你的想法…" required>
</label>
<button class="button secondary small">送出</button>
</form>
</div>
</article><?php endforeach; ?>
<?php if (!$articles): ?><div class="empty">
<h3>第一篇分享，從你開始。</h3>
<p>哪個活動值得參加？把你的發現分享給大家。</p>
<a class="button secondary" href="post_article.php">寫一篇心得</a>
</div><?php endif; ?><nav class="pagination" aria-label="心得分頁"><?php if ($page > 1): ?><a href="?<?= e(http_build_query(['page'=>$page-1,'sort'=>$_GET['sort'] ?? 'recent'])) ?>#articles">← 上一頁</a><?php endif; ?><span>第 <?= e($page) ?> 頁</span><?php if ($hasNext): ?><a href="?<?= e(http_build_query(['page'=>$page+1,'sort'=>$_GET['sort'] ?? 'recent'])) ?>#articles">下一頁 →</a><?php endif; ?></nav>
</section>
<aside class="sidebar">
<section class="sidebar-card">
<span class="eyebrow">SAVE YOUR SEAT</span>
<h3>我的行事曆</h3><?php if ($schedule): ?><ul class="schedule-list"><?php foreach ($schedule as $event): ?><li><?= e($event['title']) ?><span><?= e($event['act_date']) ?> · <?= e($event['start_time']) ?></span>
</li><?php endforeach; ?></ul><?php else: ?><p>還沒有安排？<br>把喜歡的活動加入行事曆，讓期待成為行程。</p><?php endif; ?></section>
<section class="sidebar-card">
<span class="eyebrow">SHARE MORE, DISCOVER MORE</span>
<h3>每次分享，都有回饋。</h3>
<ol>
<li>發表心得，獲得 5 點。</li>
<li>參與留言，獲得 3 點。</li>
<li>首次評價每篇心得，獲得 1 點。</li>
</ol>
<p>累積 30 點，解鎖 Premium 熱門心得排序。</p>
<a class="button secondary full" href="wallet.php">查看點數與會員 ↗</a>
</section>
</aside>
</div>
<?php page_end(); ?>
