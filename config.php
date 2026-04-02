<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('BOT_TOKEN', '');
define('WEBHOOK_URL', '');

define('DB_HOST', 'localhost');
define('DB_NAME', 'DB_NAME');
define('DB_USER', 'DB_NAME');
define('DB_PASS', 'DB_PASS');

define('REPORT_DAYS_LIMIT', 3);

date_default_timezone_set('Europe/Moscow');

function sendTelegramRequest($method, $data = [])
{
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true);
}
