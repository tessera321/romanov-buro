<?php
// Приём заявок с сайта: пишет в журнал на сервере и отправляет в Telegram.
// Токен бота и chat_id лежат вне сайта: ~/romanovbyuro.ru/private/config.php
// (return ['tg_token' => '...', 'tg_chat_ids' => ['...']];)

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }

$private = dirname(__DIR__, 2) . '/private';
$config  = is_file("$private/config.php") ? require "$private/config.php" : [];

$raw = file_get_contents('php://input', false, null, 0, 20000);
$d = json_decode($raw, true);
if (!is_array($d)) { http_response_code(400); echo '{"ok":false}'; exit; }

// Ловушка для ботов: скрытое поле website должно быть пустым
if (!empty($d['website'])) { echo '{"ok":true}'; exit; }

$clean = function ($v, $max = 300) {
    $v = is_scalar($v) ? (string)$v : '';
    $v = trim(preg_replace('/\s+/u', ' ', strip_tags($v)));
    return mb_substr($v, 0, $max);
};
$phone = $clean($d['phone'] ?? '', 40);
if (strlen(preg_replace('/\D/', '', $phone)) < 10) { http_response_code(422); echo '{"ok":false,"error":"phone"}'; exit; }

if (!is_dir($private)) @mkdir($private, 0700, true);

// Ограничение частоты: не больше 5 заявок в 10 минут с одного IP; повтор той же заявки за минуту не дублируется
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ip = $clean(explode(',', $ip)[0], 64);
$rlFile = "$private/ratelimit.json";
$rl = is_file($rlFile) ? (json_decode(file_get_contents($rlFile), true) ?: []) : [];
$now = time();
foreach ($rl as $k => $t) { $rl[$k] = array_values(array_filter((array)$t, fn($x) => is_int($x) ? $x > $now - 600 : false)); if (!$rl[$k]) unset($rl[$k]); }
$sig = md5($phone . '|' . ($d['form'] ?? '') . '|' . ($d['url'] ?? ''));
if (isset($rl["sig:$sig"])) { echo '{"ok":true,"dup":true}'; exit; }
if (count($rl["ip:$ip"] ?? []) >= 5) { http_response_code(429); echo '{"ok":false,"error":"rate"}'; exit; }
$rl["ip:$ip"][] = $now; $rl["sig:$sig"] = [$now - 540]; // запись сигнатуры живёт 60 секунд
file_put_contents($rlFile, json_encode($rl), LOCK_EX);

$lines = [];
$lines[] = '🔔 Заявка с сайта romanovbyuro.ru';
$lines[] = '📞 ' . $phone . (!empty($d['channel']) ? '  ·  ответить: ' . $clean($d['channel'], 30) : '');
if (!empty($d['block'])) $lines[] = '📍 Блок: ' . $clean($d['block'], 160);
foreach ((array)($d['fields'] ?? []) as $k => $v) { $lines[] = '• ' . $clean($k, 80) . ': ' . $clean($v, 500); }
$answers = array_slice((array)($d['answers'] ?? []), 0, 15);
if ($answers) { $lines[] = '🧩 Ответы:'; foreach ($answers as $a) $lines[] = '   – ' . $clean($a, 200); }
$lines[] = '🌐 ' . $clean($d['page'] ?? '', 120) . ' — ' . $clean($d['url'] ?? '', 300);
$utm = array_filter(array_map(fn($v) => $clean($v, 120), (array)($d['utm'] ?? [])));
if ($utm) $lines[] = '🏷 ' . implode(' · ', array_map(fn($k, $v) => "$k=$v", array_keys($utm), $utm));
if (!empty($d['referrer'])) $lines[] = '↩️ ' . $clean($d['referrer'], 200);
$text = implode("\n", $lines);

// Журнал — заявка не потеряется, даже если Telegram недоступен
$row = [date('c'), $phone, $clean($d['channel'] ?? '', 30), $clean($d['url'] ?? '', 300), json_encode($d, JSON_UNESCAPED_UNICODE)];
$fh = fopen("$private/leads.csv", 'a');
if ($fh) { flock($fh, LOCK_EX); fputcsv($fh, $row); flock($fh, LOCK_UN); fclose($fh); }

$delivered = [];

// 1. Почта (внутри России, работает всегда)
foreach ((array)($config['emails'] ?? ['romanovbyuro@yandex.ru']) as $to) {
    $subj = '=?UTF-8?B?' . base64_encode('Заявка с сайта: ' . $phone) . '?=';
    $hdr = "From: =?UTF-8?B?" . base64_encode('Сайт Романовъ') . "?= <noreply@romanovbyuro.ru>\r\n"
         . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
    if (@mail($to, $subj, $text, $hdr, '-fnoreply@romanovbyuro.ru')) $delivered[] = 'email';
}

// 2. Telegram — через мост на Cloudflare (api.telegram.org с российского хостинга недоступен)
$tgToken = rb_tg_token($config['tg_token'] ?? '');
if ($tgToken && !empty($config['tg_relay_url'])) {
    foreach ((array)($config['tg_chat_ids'] ?? []) as $chat) {
        $r = rb_post(rtrim($config['tg_relay_url'], '/') . "/bot$tgToken/sendMessage",
            ['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => 'true'],
            ['X-Relay-Secret: ' . ($config['tg_relay_secret'] ?? '')]);
        if ($r === 200) $delivered[] = 'telegram';
    }
}

// 3. MAX — напрямую, российский сервис
if (!empty($config['max_token'])) {
    foreach ((array)($config['max_user_ids'] ?? []) as $uid) {
        $r = rb_post('https://platform-api.max.ru/messages?user_id=' . urlencode($uid),
            json_encode(['text' => $text], JSON_UNESCAPED_UNICODE),
            ['Authorization: ' . $config['max_token'], 'Content-Type: application/json']);
        if ($r === 200) $delivered[] = 'max';
    }
}

// Log channel acknowledgements without phone numbers, message text or credentials.
@file_put_contents("$private/delivery.log", date('c') . ' ' . $sig . ' ' . json_encode($delivered) . "\n", FILE_APPEND | LOCK_EX);
if (!$delivered) @file_put_contents("$private/delivery-errors.log", date('c') . " ни один канал не доставил: $phone\n", FILE_APPEND);

echo json_encode(['ok' => true]);

// Токен BotFather из сохранённой строки (если вставили сообщение целиком — берём сам токен)
function rb_tg_token($s) {
    return preg_match('/\b(\d{6,12}:[A-Za-z0-9_-]{30,40})\b/', (string)$s, $m) ? $m[1] : '';
}

function rb_post($url, $body, $headers = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
        CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}
