<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
$user = require_admin();
$order = ($_GET['order'] ?? '') === 'desc' ? 'DESC' : 'ASC';
$activities = query('SELECT activity_id,title,content,act_date,place,lunch,url,
    TIME_FORMAT(startt,\'%H:%i\') AS start_time,TIME_FORMAT(endt,\'%H:%i\') AS end_time
    FROM activities WHERE user_id=? ORDER BY act_date '.$order.',startt '.$order, [$user['user_id']])->fetchAll();
page_start('活動管理', $user);
?>
<div class="page-title">
<span class="eyebrow">MAKE SOMETHING HAPPEN</span>
<h1>讓好活動，被更多人看見。</h1>
<p>管理你發佈的活動，提供清楚、即時的參加資訊。</p>
</div>
<div class="section-heading">
<h2>我的活動</h2>
<a class="button" href="create_activities.php">＋ 發佈新活動</a>
</div>
<form class="filters" method="get">
<label>日期排序<select name="order">
<option value="asc">由近到遠</option>
<option value="desc"<?= $order === 'DESC' ? ' selected' : '' ?>>由遠到近</option>
</select>
</label>
<button class="button secondary">更新</button>
</form>
<div class="activity-grid"><?php foreach ($activities as $activity) activity_card($activity, true); ?><?php if (!$activities): ?><div class="empty">
<h3>一場好活動，從這裡開始。</h3>
<p>發佈活動，讓校園裡的每個人都有機會參與。</p>
</div><?php endif; ?></div>
<?php page_end(); ?>
