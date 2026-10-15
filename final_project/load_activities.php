<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
$user = require_user();
$events = query('SELECT a.activity_id AS id,a.title,CONCAT(a.act_date,\'T\',a.startt) AS start,
    CONCAT(a.act_date,\'T\',a.endt) AS end FROM activities a JOIN join_activity j ON j.activity_id=a.activity_id
    WHERE j.user_id=? ORDER BY a.act_date,a.startt', [$user['user_id']])->fetchAll();
json_response($events);
