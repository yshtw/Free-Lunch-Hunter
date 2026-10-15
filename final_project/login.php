<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
if (current_user()) redirect('index.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    login_account(post_data());
    redirect('index.php');
}
page_start('登入');
?>
<div class="auth-layout">
<section class="auth-intro">
<span class="eyebrow">GOOD FOOD, GOOD COMPANY</span>
<h1>下一頓午餐，<br>多一點<span class="positive">可能。</span>
</h1>
<p>找到值得參加的校園活動，分享你的探索心得。<br>一起把好消息，留給需要的人。</p>
<div class="hero-art" aria-hidden="true">
<span class="hero-sticker">Lunch is better together.</span>
</div>
</section>
<section class="auth-card">
<span class="eyebrow">WELCOME BACK</span>
<h2>歡迎回來。</h2>
<p class="muted">登入，看看今天有什麼新鮮事。</p>
<form method="post" class="stack"><?= csrf_field() ?><label>Email<input name="email" type="email" autocomplete="username" maxlength="254" required>
</label>
<label>密碼<input name="password" type="password" autocomplete="current-password" maxlength="72" required>
</label>
<button class="button full" type="submit">登入帳戶 ↗</button>
<p class="muted">還沒有帳戶？<a href="register.php">建立免費帳戶</a>
</p>
</form>
</section>
</div>
<?php page_end(); ?>
