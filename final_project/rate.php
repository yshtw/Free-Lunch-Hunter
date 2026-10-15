<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
$user = require_user();
$data = post_data();
vote_article((int) $user['user_id'], $data);
if (wants_json()) json_response(['status'=>'ok']);
redirect('index.php#article-'.positive_id($data['article_id'] ?? null));
