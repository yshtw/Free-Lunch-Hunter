<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
post_data();
$_SESSION = [];
setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => ($_SERVER['HTTPS'] ?? '') === 'on']);
session_destroy();
redirect('login.php');
