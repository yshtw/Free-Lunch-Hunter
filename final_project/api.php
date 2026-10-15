<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
$resource = $_GET['resource'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
if ($resource === 'health' && $method === 'GET') {
    query('SELECT 1');
    json_response(['status'=>'ok']);
}
$user = require_user();
$id = (int) $user['user_id'];
if ($resource === 'session' && $method === 'GET') json_response(['user'=>$user,'csrf_token'=>csrf_token()]);
if ($resource === 'wallet' && $method === 'GET') {
    $entries = query('SELECT entry_id,amount,action,reference,created_at FROM wallet_entries WHERE user_id=? ORDER BY entry_id DESC LIMIT 50', [$id])->fetchAll();
    json_response(['balance'=>$user['money'],'unit'=>'community_points','entries'=>$entries]);
}
if ($resource === 'membership-orders') {
    if ($method === 'GET') json_response(['orders'=>query('SELECT order_id,product_code,amount,status,created_at FROM membership_orders WHERE user_id=? ORDER BY created_at DESC,order_id DESC LIMIT 20', [$id])->fetchAll()]);
    if ($method === 'POST') {
        $data = post_data();
        $result = membership_order($id, text(['key'=>$_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''],'key',80), text($data,'product_code',40));
        header('Idempotency-Replayed: '.($result['replayed'] ? 'true' : 'false'));
        json_response($result, $result['replayed'] ? 200 : 201);
    }
    header('Allow: GET, POST');
    throw new HttpError(405, '不支援的請求方法。');
}
throw new HttpError(404, 'API 資源不存在。');
