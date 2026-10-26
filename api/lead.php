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

$sent = false;
if (!empty($config['tg_token'])) {
    foreach ((array)($config['tg_chat_ids'] ?? []) as $chat) {
        $ch = curl_init('https://api.telegram.org/bot' . $config['tg_token'] . '/sendMessage');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_POSTFIELDS => ['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => 'true'],
        ]);
        $res = curl_exec($ch);
        $sent = $sent || (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200);
        curl_close($ch);
    }
}
if (!$sent) @file_put_contents("$private/telegram-errors.log", date('c') . " not delivered: $phone\n", FILE_APPEND);

echo json_encode(['ok' => true]);
