<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
$user = require_user();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('wallet.php');
sandbox_credit((int) $user['user_id'], post_data());
flash('示範點數已加入帳戶；點數不具有現金價值。');
redirect('wallet.php');
