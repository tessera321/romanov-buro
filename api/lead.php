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

// Сообщение: сверху то, по чему звонить (телефон и способ связи), затем ответы человека; служебное — внизу мелко.
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$digits = preg_replace('/\D/', '', $phone);
if (strlen($digits) === 11 && in_array($digits[0], ['7', '8'], true)) $digits = substr($digits, 1);
$phoneNice = strlen($digits) === 10
    ? sprintf('+7 (%s) %s-%s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 2), substr($digits, 8, 2))
    : $phone;
$channel = $clean($d['channel'] ?? '', 30);
$fields = [];
foreach ((array)($d['fields'] ?? []) as $k => $v) { $fields[$clean($k, 80)] = $clean($v, 500); }
$estimate = $fields['Предварительный расчёт'] ?? '';
unset($fields['Предварительный расчёт']);
$qa = [];
foreach (array_slice((array)($d['answers'] ?? []), 0, 15) as $a) {
    $a = $clean($a, 300);
    $p = mb_strpos($a, ' — ');
    $qa[] = $p === false ? ['', $a] : [mb_substr($a, 0, $p), mb_substr($a, $p + 3)];
}
$pagePath = parse_url($clean($d['url'] ?? '', 300), PHP_URL_PATH) ?: '/';
$utm = array_filter(array_map(fn($v) => $clean($v, 120), (array)($d['utm'] ?? [])));
$meta = [];
if (!empty($d['block'])) $meta[] = 'Форма: ' . $clean($d['block'], 160);
$meta[] = 'Страница: ' . $pagePath;
if ($utm) $meta[] = 'Метки: ' . implode(' · ', array_map(fn($k, $v) => "$k=$v", array_keys($utm), $utm));
if (!empty($d['referrer'])) $meta[] = 'Откуда пришёл: ' . $clean($d['referrer'], 200);
$isTest = stripos(json_encode($utm), 'test') !== false;

// Telegram (HTML-разметка)
$t = [];
$t[] = ($isTest ? '🧪 <b>ТЕСТОВАЯ ЗАЯВКА</b>' : '🔔 <b>Новая заявка с сайта</b>');
$t[] = '';
$t[] = '📞 <b>' . $h($phoneNice) . '</b>';
if ($channel !== '') $t[] = '💬 Связаться: <b>' . $h($channel) . '</b>';
if ($qa) {
    $t[] = '';
    $t[] = '📝 <b>Ответы</b>';
    foreach ($qa as $i => [$q, $ans]) {
        $t[] = '';
        if ($q !== '') $t[] = ($i + 1) . '. ' . $h($q);
        $t[] = '     → <b>' . $h($ans) . '</b>';
    }
}
if ($estimate !== '') { $t[] = ''; $t[] = '💰 Ориентир на сайте: <b>' . $h($estimate) . '</b>'; }
if ($fields) {
    $t[] = '';
    foreach ($fields as $k => $v) $t[] = '▫️ ' . $h($k) . ': <b>' . $h($v) . '</b>';
}
$t[] = '';
$t[] = '───────────';
$t[] = '<i>' . implode("\n", array_map($h, $meta)) . '</i>';
$tgText = implode("\n", $t);

// Почта и MAX — тот же порядок, обычным текстом
$lines = [];
$lines[] = $isTest ? 'ТЕСТОВАЯ ЗАЯВКА' : 'Новая заявка с сайта romanovbyuro.ru';
$lines[] = '';
$lines[] = 'Телефон: ' . $phoneNice;
if ($channel !== '') $lines[] = 'Связаться: ' . $channel;
if ($qa) {
    $lines[] = '';
    $lines[] = 'ОТВЕТЫ';
    foreach ($qa as $i => [$q, $ans]) $lines[] = ($i + 1) . '. ' . ($q !== '' ? $q . ' — ' : '') . $ans;
}
if ($estimate !== '') { $lines[] = ''; $lines[] = 'Ориентир на сайте: ' . $estimate; }
foreach ($fields as $k => $v) $lines[] = $k . ': ' . $v;
$lines[] = '';
$lines[] = '———';
foreach ($meta as $m) $lines[] = $m;
$text = implode("\n", $lines);

// Журнал — заявка не потеряется, даже если Telegram недоступен
$row = [date('c'), $phone, $clean($d['channel'] ?? '', 30), $clean($d['url'] ?? '', 300), json_encode($d, JSON_UNESCAPED_UNICODE)];
$fh = fopen("$private/leads.csv", 'a');
if ($fh) { flock($fh, LOCK_EX); fputcsv($fh, $row); flock($fh, LOCK_UN); fclose($fh); }

$delivered = [];

// 1. Почта (внутри России, работает всегда)
foreach ((array)($config['emails'] ?? ['romanovbyuro@yandex.ru']) as $to) {
    $subj = '=?UTF-8?B?' . base64_encode(($isTest ? '[ТЕСТ] ' : '') . 'Заявка с сайта: ' . $phoneNice . ($channel !== '' ? ' · ' . $channel : '')) . '?=';
    $hdr = "From: =?UTF-8?B?" . base64_encode('Сайт Романовъ') . "?= <noreply@romanovbyuro.ru>\r\n"
         . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
    if (@mail($to, $subj, $text, $hdr, '-fnoreply@romanovbyuro.ru')) $delivered[] = 'email';
}

// 2. Telegram — через мост на Cloudflare (api.telegram.org с российского хостинга недоступен)
$tgToken = rb_tg_token($config['tg_token'] ?? '');
if ($tgToken && !empty($config['tg_relay_url'])) {
    foreach ((array)($config['tg_chat_ids'] ?? []) as $chat) {
        $r = rb_post(rtrim($config['tg_relay_url'], '/') . "/bot$tgToken/sendMessage",
            ['chat_id' => $chat, 'text' => $tgText, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true'],
            ['X-Relay-Secret: ' . ($config['tg_relay_secret'] ?? '')]);
        if ($r === 400) { // разметка не принята — шлём простым текстом, чтобы заявка не потерялась
            $r = rb_post(rtrim($config['tg_relay_url'], '/') . "/bot$tgToken/sendMessage",
                ['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => 'true'],
                ['X-Relay-Secret: ' . ($config['tg_relay_secret'] ?? '')]);
        }
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
