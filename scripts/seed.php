<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
if (PHP_SAPI !== 'cli') exit(1);
$user = query('SELECT u.user_id FROM user_account u JOIN premium p ON p.user_id=u.user_id
    WHERE p.premium=2 ORDER BY u.user_id LIMIT 1')->fetch();
if (!$user) throw new RuntimeException('Create an account and grant admin access with scripts/admin.php first.');
$events = [
    ['從產品想法到第一個使用者', '和校園創作者聊聊產品驗證、使用者訪談與第一版作品的取捨。', '創新育成中心 · 2F', '輕食餐盒'],
    ['開源午餐：一起讀懂一個專案', '帶著你的筆電，從一個 issue 開始，認識開源協作與程式碼審查。', '資訊館 · 交誼廳', '蔬食便當'],
    ['設計與工程的交會', '設計師與工程師分享跨領域合作的真實經驗，歡迎一起交流。', '綜合大樓 · 201', '三明治與飲品'],
];
$created = transaction(function () use ($user, $events): int {
    lock_wallet((int) $user['user_id']);
    $created = 0;
    foreach ($events as $index => [$title, $content, $place, $lunch]) {
        $title = '【示範活動】' . $title;
        if (query('SELECT activity_id FROM activities WHERE user_id=? AND title=?', [$user['user_id'], $title])->fetch()) continue;
        save_activity((int) $user['user_id'], ['title'=>$title, 'content'=>$content.' 這是示範資料，並非真實活動。',
            'place'=>$place, 'lunch'=>$lunch, 'act_date'=>date('Y-m-d', strtotime('+'.($index+2).' days')),
            'start_time'=>'12:00', 'end_time'=>'13:00']);
        $created++;
    }
    return $created;
});
echo "Created $created clearly labeled demo activities; existing content was preserved.\n";
