<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
$user = require_admin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    save_activity((int) $user['user_id'], post_data());
    flash('新活動已發佈。');
    redirect('admin_dashboard.php');
}
page_start('發佈活動', $user);
?>
<div class="page-title">
<span class="eyebrow">BRING PEOPLE TOGETHER</span>
<h1>下一場好活動，由你發起。</h1>
<p>提供完整的時間、地點與報名資訊，讓參加變得更容易。</p>
</div>
<form method="post" class="form-panel stack"><?= csrf_field() ?><?php activity_fields(); ?><div class="form-actions">
<button class="button">發佈活動 ↗</button>
<a href="admin_dashboard.php">返回活動管理</a>
</div>
</form>
<?php page_end(); ?>
