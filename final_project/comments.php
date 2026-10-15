<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
$user = require_user();
$id = comment_article((int) $user['user_id'], post_data());
flash('留言已送出，3 點獎勵已加入帳戶。');
redirect('index.php#article-'.$id);
