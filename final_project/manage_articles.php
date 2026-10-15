<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
$user = require_user();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = post_data();
    $id = positive_id($data['article_id'] ?? null);
    if (isset($data['delete'])) {
        delete_article((int) $user['user_id'], $id);
        flash('文章與相關留言已刪除。');
    } else {
        save_article((int) $user['user_id'], $data, $_FILES['img'] ?? null, $id);
        flash('分享內容已更新。');
    }
    redirect('manage_articles.php');
}
$articles = query('SELECT article_id,title,content FROM articles WHERE user_id=? ORDER BY article_id DESC', [$user['user_id']])->fetchAll();
page_start('我的文章', $user);
?>
<div class="page-title">
<span class="eyebrow">YOUR FIELD NOTES</span>
<h1>每一次探索，都值得記下。</h1>
<p>整理你的心得，讓好資訊持續發揮價值。</p>
</div>
<?php if (!$articles): ?><div class="empty">
<h3>還沒有自己的分享。</h3>
<a class="button" href="post_article.php">寫下第一篇心得 ↗</a>
</div><?php endif; ?>
<?php foreach ($articles as $article): ?><form method="post" enctype="multipart/form-data" class="form-panel stack" data-confirm-delete><?= csrf_field() ?><input type="hidden" name="article_id" value="<?= e($article['article_id']) ?>">
<label>標題<input name="title" maxlength="200" value="<?= e($article['title']) ?>" required>
</label>
<label>內容<textarea name="content" rows="6" maxlength="20000" required><?= e($article['content']) ?></textarea>
</label>
<label>替換圖片（選填）<input type="file" name="img" accept="image/jpeg,image/png,image/webp">
</label>
<div class="form-actions">
<button class="button" name="update">儲存變更</button>
<button class="button danger" name="delete" value="1" formnovalidate>刪除文章</button>
</div>
</form><?php endforeach; ?>
<?php page_end(); ?>
