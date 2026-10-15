<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
$user = require_user();
calendar_membership((int) $user['user_id'], post_data());
if (wants_json()) json_response(['status'=>'ok']);
flash('行事曆已更新。');
redirect('index.php#activities');
