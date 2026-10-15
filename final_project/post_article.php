<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
$user = require_user();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = save_article((int) $user['user_id'], post_data(), $_FILES['img'] ?? null);
    flash('分享已發佈，5 點獎勵已加入你的帳戶。');
    redirect('index.php#article-'.$id);
}
page_start('分享心得', $user);
?>
<div class="page-title">
<span class="eyebrow">PASS THE GOOD THINGS ON</span>
<h1>這次，有什麼新發現？</h1>
<p>一場值得參加的活動，一份意外好吃的午餐。把你的體驗，變成下一個人的靈感。</p>
</div>
<form method="post" enctype="multipart/form-data" class="form-panel stack" data-article-editor><?= csrf_field() ?><label>給分享一個標題<input name="title" maxlength="200" placeholder="例如：在設計講座遇見的午餐驚喜" required>
</label>
<label>你的心得<textarea name="content" maxlength="20000" rows="9" required placeholder="聊聊活動內容、交通與午餐，幫助其他人做選擇。">
</textarea>
</label>
<label>附上照片（選填）<input type="file" name="img" accept="image/jpeg,image/png,image/webp">
<small>JPEG、PNG、WebP，最多 2 MB。</small>
</label>
<div class="preview" data-preview aria-live="polite">你的文字預覽會顯示在這裡。</div>
<div class="form-actions">
<button class="button">發佈分享 ↗</button>
<a href="index.php">稍後再寫</a>
</div>
</form>
<?php page_end(); ?>
