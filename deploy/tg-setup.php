<?php
// Подключение Telegram-бота к заявкам. Запуск на сервере: php8.3 ~/romanovbyuro.ru/repo/deploy/tg-setup.php
// Перед запуском: в ~/romanovbyuro.ru/private/config.php есть tg_token, tg_relay_url, tg_relay_secret, и боту написали /start.
// Находит чаты, которые написали боту, записывает их chat_id в конфиг и шлёт проверочное сообщение.
// Запросы идут через мост на Cloudflare: api.telegram.org с российского хостинга недоступен. Токен не выводится.

$file = getenv('HOME') . '/romanovbyuro.ru/private/config.php';
if (!is_file($file)) { fwrite(STDERR, "Нет файла $file — сначала сохраните токен\n"); exit(1); }
$cfg = require $file;

$token = preg_match('/\b(\d{6,12}:[A-Za-z0-9_-]{30,40})\b/', (string)($cfg['tg_token'] ?? ''), $m) ? $m[1] : '';
if (!$token) { fwrite(STDERR, "В tg_token не найден токен BotFather\n"); exit(1); }
if (empty($cfg['tg_relay_url']) || empty($cfg['tg_relay_secret'])) { fwrite(STDERR, "Нет tg_relay_url или tg_relay_secret в конфиге\n"); exit(1); }
$cfg['tg_token'] = $token; // храним сам токен, без остального текста BotFather

function tg($method, $params = []) {
    global $cfg, $token;
    $ch = curl_init(rtrim($cfg['tg_relay_url'], '/') . "/bot$token/$method");
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => $params, CURLOPT_HTTPHEADER => ['X-Relay-Secret: ' . $cfg['tg_relay_secret']],
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 403) { fwrite(STDERR, "Мост отказал: секрет не совпадает с RELAY_SECRET\n"); exit(1); }
    $r = json_decode((string)$raw, true);
    if (empty($r['ok'])) { fwrite(STDERR, "Telegram ответил ($code): " . ($r['description'] ?? 'нет ответа') . "\n"); exit(1); }
    return $r['result'];
}

$me = tg('getMe');
echo "Бот: @{$me['username']}\n";

$chats = [];
foreach (tg('getUpdates') as $u) {
    $c = $u['message']['chat'] ?? $u['my_chat_member']['chat'] ?? null;
    if ($c) $chats[$c['id']] = trim(($c['title'] ?? '') . ' ' . ($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '') . ' @' . ($c['username'] ?? ''));
}
if (!$chats && empty($cfg['tg_chat_ids'])) { echo "Никто ещё не написал боту. Отправьте ему /start и запустите скрипт снова.\n"; exit(1); }

$cfg['tg_chat_ids'] = array_values(array_unique(array_merge((array)($cfg['tg_chat_ids'] ?? []), array_map('strval', array_keys($chats)))));
file_put_contents($file, "<?php return " . var_export($cfg, true) . ";\n", LOCK_EX);
chmod($file, 0600);

foreach ($chats as $id => $name) echo "Найден чат: $name\n";
foreach ($cfg['tg_chat_ids'] as $id) {
    tg('sendMessage', ['chat_id' => $id, 'text' => "✅ Заявки с сайта romanovbyuro.ru будут приходить сюда."]);
}
echo "Подключено чатов: " . count($cfg['tg_chat_ids']) . "\n";
