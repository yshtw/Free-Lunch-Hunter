<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
$resource = $_GET['resource'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
if ($resource === 'health' && $method === 'GET') {
    query('SELECT 1');
    json_response(['status'=>'ok']);
}
// Browser sessions keep every capability and need CSRF on writes. Bearer tokens carry explicit scopes;
// a request with an Authorization header never falls back to the session cookie.
$principal = token_request() ? token_principal(authorization_header())
    : ['user'=>require_user(), 'scopes'=>TOKEN_SCOPES, 'token_id'=>null];
$user = $principal['user'];
$id = (int) $user['user_id'];
$body = fn (): array => $principal['token_id'] === null ? post_data() : request_body();
$allow = function (string ...$methods) use ($method): void {
    if (!in_array($method, $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        throw new HttpError(405, '不支援的請求方法。');
    }
};
$routes = ['session', 'wallet', 'membership-offer', 'membership-orders', 'activities', 'schedule'];
if (!in_array($resource, $routes, true)) throw new HttpError(404, 'API 資源不存在。');
if ($method === 'GET') require_scope($principal, 'read');

if ($resource === 'session') {
    $allow('GET');
    $session = ['user'=>$user, 'scopes'=>$principal['scopes'], 'auth'=>$principal['token_id'] === null ? 'session' : 'token'];
    json_response($principal['token_id'] === null ? $session + ['csrf_token'=>csrf_token()] : $session);
}
if ($resource === 'wallet') {
    $allow('GET');
    $snapshot = wallet_snapshot($id);
    json_response(['balance'=>$snapshot['wallet']['money'],'unit'=>'community_points','entries'=>$snapshot['entries']]);
}
if ($resource === 'membership-offer') {
    $allow('GET');
    json_response(membership_offer($id));
}
if ($resource === 'activities') {
    $allow('GET');
    json_response(search_activities($id, $_GET));
}
if ($resource === 'schedule') {
    $allow('GET', 'POST');
    if ($method === 'GET') json_response(['activities'=>upcoming_schedule($id)]);
    require_scope($principal, 'schedule:write');
    $data = $body();
    $action = text($data, 'action', 10);
    if (!in_array($action, ['add', 'remove'], true)) throw new HttpError(422, 'action 只能是 add 或 remove。');
    // Desired-state update: repeating add or remove is a no-op, so clients may retry safely.
    calendar_membership($id, ['activity_id'=>$data['activity_id'] ?? null, 'action'=>$action === 'add' ? 'add' : 'delete']);
    json_response(['activity_id'=>positive_id($data['activity_id']), 'joined'=>$action === 'add']);
}
// membership-orders
$allow('GET', 'POST');
if ($method === 'GET') json_response(['orders'=>query('SELECT order_id,product_code,amount,status,created_at FROM membership_orders WHERE user_id=? ORDER BY created_at DESC,order_id DESC LIMIT 20', [$id])->fetchAll()]);
require_scope($principal, 'membership:redeem');
$data = $body();
$result = membership_order($id, text(['key'=>$_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''],'key',80), text($data,'product_code',40));
header('Idempotency-Replayed: '.($result['replayed'] ? 'true' : 'false'));
json_response($result, $result['replayed'] ? 200 : 201);
