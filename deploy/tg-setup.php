<?php
// Подключение Telegram-бота к заявкам. Запуск на сервере: php8.3 ~/romanovbyuro.ru/repo/deploy/tg-setup.php
// Перед запуском: токен сохранён в ~/romanovbyuro.ru/private/config.php, и боту написали /start.
// Находит чаты, которые написали боту, записывает их chat_id в конфиг и шлёт проверочное сообщение.
// Токен не выводится.

$file = getenv('HOME') . '/romanovbyuro.ru/private/config.php';
if (!is_file($file)) { fwrite(STDERR, "Нет файла $file — сначала сохраните токен\n"); exit(1); }
$cfg = require $file;
$api = 'https://api.telegram.org/bot' . ($cfg['tg_token'] ?? '');

$me = json_decode(@file_get_contents("$api/getMe"), true);
if (empty($me['ok'])) { fwrite(STDERR, "Telegram не принял токен — проверьте его\n"); exit(1); }
echo "Бот: @{$me['result']['username']}\n";

$upd = json_decode(@file_get_contents("$api/getUpdates"), true);
$chats = [];
foreach (($upd['result'] ?? []) as $u) {
    $c = $u['message']['chat'] ?? $u['my_chat_member']['chat'] ?? null;
    if ($c) $chats[$c['id']] = trim(($c['title'] ?? '') . ' ' . ($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '') . ' @' . ($c['username'] ?? ''));
}
if (!$chats) { echo "Никто ещё не написал боту. Отправьте ему /start и запустите скрипт снова.\n"; exit(1); }

$ids = array_values(array_unique(array_merge((array)($cfg['tg_chat_ids'] ?? []), array_map('strval', array_keys($chats)))));
$cfg['tg_chat_ids'] = $ids;
file_put_contents($file, "<?php return " . var_export($cfg, true) . ";\n", LOCK_EX);
chmod($file, 0600);

foreach ($chats as $id => $name) {
    echo "Подключён чат: $name\n";
    @file_get_contents("$api/sendMessage?" . http_build_query(['chat_id' => $id, 'text' => "✅ Заявки с сайта romanovbyuro.ru будут приходить сюда."]));
}
