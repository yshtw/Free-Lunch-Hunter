<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    register_account(post_data());
    flash('帳戶已建立，請使用新帳戶登入。');
    redirect('login.php');
}
page_start('建立帳戶');
?>
<section class="auth-card">
<span class="eyebrow">A SEAT AT THE TABLE</span>
<h1>加入午餐探索。</h1>
<p class="muted">分享有用的資訊，讓每一頓午餐都值得期待。</p>
<form method="post" class="stack"><?= csrf_field() ?><label>Email<input name="email" type="email" autocomplete="email" maxlength="254" required>
</label>
<label>使用者名稱<input name="username" autocomplete="username" maxlength="80" required>
</label>
<label>生日<input name="birth_date" type="date" max="<?= e(date('Y-m-d')) ?>" min="1900-01-01" required>
</label>
<label>密碼<input name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required>
<small>12–72 位元組，建議使用不重複的長密碼。</small>
</label>
<label>再次輸入密碼<input name="confirm_password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required>
</label>
<button class="button full" type="submit">建立帳戶 ↗</button>
<p class="muted">已經是成員？<a href="login.php">登入</a>
</p>
</form>
</section>
<?php page_end(); ?>
