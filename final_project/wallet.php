<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
$user = require_user();
$snapshot = wallet_snapshot((int) $user['user_id']);
$user = array_merge($user, $snapshot['wallet']);
$entries = $snapshot['entries'];
$orders = $snapshot['orders'];
$labels = ['post'=>'發佈心得','comment'=>'參與留言','vote'=>'首次評價','membership'=>'Premium 升級','sandbox_credit'=>'示範加點','opening_balance'=>'原有餘額'];
page_start('點數與會員', $user);
?>
<div class="page-title">
<span class="eyebrow">SMALL CONTRIBUTIONS, GOOD RETURNS</span>
<h1>把分享，累積成更多可能。</h1>
<p>每一次心得與交流都有回饋。你的點數、會員與交易紀錄，都在這裡。</p>
</div>
<div class="wallet-grid">
<section class="balance-card">
<span class="eyebrow">YOUR COMMUNITY POINTS</span>
<h2><?= e(number_format((float) $user['money'],0)) ?> <small>點</small>
</h2>
<p>目前會員：<?= (int) $user['premium'] === 2 ? '活動管理員' : ((int) $user['premium'] === 1 ? 'Premium' : '免費會員') ?></p>
<p>社群點數不具有現金價值。</p>
</section>
<section class="plan-card">
<span class="eyebrow">MORE WAYS TO DISCOVER</span>
<h2>Premium · 30 點</h2>
<p>一次升級，解鎖熱門心得排序。<br>從大家最推薦的分享，找到下一個值得參加的活動。</p><?php if ((int) $user['premium'] === 0): ?><form method="post" action="upgrade.php"><?= csrf_field() ?><input type="hidden" name="idempotency_key" value="<?= e(bin2hex(random_bytes(16))) ?>">
<button class="button"<?= (float) $user['money'] < 30 ? ' disabled' : '' ?>>升級 Premium ↗</button>
</form><?php if ((float) $user['money'] < 30): ?><p class="muted">還差 <?= e(30-(float)$user['money']) ?> 點。寫心得、參與留言來累積點數。</p><?php endif; ?><?php else: ?><span class="tag">✓ 已享有完整探索功能</span><?php endif; ?></section>
</div>
<?php if (getenv('ENABLE_DEMO_TOPUP') === '1'): ?><details class="form-panel">
<summary>示範加點（無真實金流）</summary>
<p class="muted">僅用於體驗會員流程，不收取費用。</p>
<form method="post" action="recharge.php" class="stack"><?= csrf_field() ?><input type="hidden" name="idempotency_key" value="<?= e(bin2hex(random_bytes(16))) ?>">
<label>加點數量<input name="amount" type="number" min="1" max="1000" step="1" value="30" required>
</label>
<button class="button secondary">加入示範點數</button>
</form>
</details><?php endif; ?>
<div class="section-heading">
<h2>點數明細</h2>
<p>最近 50 筆</p>
</div><?php if ($entries): ?><div class="table-wrap">
<table>
<thead>
<tr>
<th scope="col">時間</th>
<th scope="col">項目</th>
<th scope="col">點數變動</th>
</tr>
</thead>
<tbody><?php foreach ($entries as $entry): ?><tr>
<td><?= e($entry['created_at']) ?></td>
<td><?= e($labels[$entry['action']] ?? $entry['action']) ?></td>
<td class="<?= (float)$entry['amount'] > 0 ? 'positive' : 'negative' ?>"><?= (float)$entry['amount'] > 0 ? '+' : '' ?><?= e(number_format((float)$entry['amount'],0)) ?></td>
</tr><?php endforeach; ?></tbody>
</table>
</div><?php else: ?><div class="empty">
<h3>還沒有點數紀錄。</h3>
<p>分享第一篇心得，開始累積你的探索點數。</p>
</div><?php endif; ?>
<div class="section-heading">
<h2>會員訂單</h2>
</div><?php if ($orders): ?><div class="table-wrap">
<table>
<thead>
<tr>
<th scope="col">訂單編號</th>
<th scope="col">時間</th>
<th scope="col">點數</th>
<th scope="col">狀態</th>
</tr>
</thead>
<tbody><?php foreach ($orders as $order): ?><tr>
<td class="receipt"><?= e($order['order_id']) ?></td>
<td><?= e($order['created_at']) ?></td>
<td><?= e($order['amount']) ?></td>
<td>已完成</td>
</tr><?php endforeach; ?></tbody>
</table>
</div><?php else: ?><p class="muted">尚無會員訂單。升級後，可在這裡查看完成紀錄。</p><?php endif; ?>
<?php page_end(); ?>
