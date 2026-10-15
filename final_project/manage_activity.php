<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
$user = require_admin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = post_data();
    $id = positive_id($data['activity_id'] ?? null);
    save_activity((int) $user['user_id'], $data, $id);
    flash('活動內容已更新。');
    redirect('admin_dashboard.php');
}
$id = positive_id($_GET['activity_id'] ?? null);
$activity = query('SELECT title,content,act_date,place,lunch,url,img,TIME_FORMAT(startt,\'%H:%i\') AS start_time,
    TIME_FORMAT(endt,\'%H:%i\') AS end_time FROM activities WHERE activity_id=? AND user_id=?', [$id,$user['user_id']])->fetch();
if (!$activity) throw new HttpError(404, '找不到你可以編輯的活動。');
page_start('編輯活動', $user);
?>
<div class="page-title">
<span class="eyebrow">KEEP EVERYONE IN THE LOOP</span>
<h1>更新活動資訊。</h1>
<p>準確的資訊，讓每一次參與更安心。</p>
</div>
<form method="post" class="form-panel stack"><?= csrf_field() ?><input type="hidden" name="activity_id" value="<?= e($id) ?>"><?php activity_fields($activity); ?><div class="form-actions">
<button class="button">儲存活動</button>
<a href="admin_dashboard.php">返回活動管理</a>
</div>
</form>
<?php page_end(); ?>
