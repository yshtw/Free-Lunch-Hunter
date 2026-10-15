<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
$user = require_user();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('wallet.php');
$data = post_data();
$result = membership_order((int) $user['user_id'], text($data,'idempotency_key',80), 'premium_lifetime');
flash($result['replayed'] ? '這筆升級已完成，未再次扣點。' : 'Premium 已啟用！已扣除 30 點，收據已存入訂單紀錄。');
redirect('wallet.php');
