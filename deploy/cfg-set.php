<?php
// Запись одной настройки в ~/romanovbyuro.ru/private/config.php. Значение не выводится.
// Обычная настройка:  php8.3 cfg-set.php tg_relay_url https://...
// Секрет (без следа в истории и списке процессов):  read -rs V; V="$V" php8.3 cfg-set.php tg_relay_secret; unset V
// Списки (emails, tg_chat_ids, max_user_ids) — значения через запятую.

$key = $argv[1] ?? '';
if (!preg_match('/^[a-z_]+$/', $key)) { fwrite(STDERR, "Укажите имя настройки\n"); exit(1); }
$val = $argv[2] ?? getenv('V');
if ($val === false || $val === '') { fwrite(STDERR, "Пустое значение\n"); exit(1); }
$val = trim($val);
if (in_array($key, ['emails', 'tg_chat_ids', 'max_user_ids'], true)) $val = array_values(array_filter(array_map('trim', explode(',', $val))));

$dir = getenv('HOME') . '/romanovbyuro.ru/private';
$file = "$dir/config.php";
if (!is_dir($dir)) mkdir($dir, 0700, true);
$cfg = is_file($file) ? (require $file) : [];
$cfg[$key] = $val;
file_put_contents($file, "<?php return " . var_export($cfg, true) . ";\n", LOCK_EX);
chmod($file, 0600);
echo "Сохранено: $key (" . (is_array($val) ? count($val) . ' шт.' : 'длина ' . strlen($val)) . ")\n";
