<?php
// Приём файла из формы («Прикрепите проект или смету»): кладёт его в закрытую папку
// ~/romanovbyuro.ru/private/uploads и возвращает номер. Сам файл уходит в Telegram
// вместе с заявкой (lead.php). Файлы старше 90 дней удаляются при следующей загрузке.

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }

const RB_MAX = 50 * 1024 * 1024;
const RB_EXT = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'dwg', 'dxf', 'xls', 'xlsx', 'doc', 'docx', 'zip', 'rar', '7z'];

$dir = dirname(__DIR__, 2) . '/private/uploads';
if (!is_dir($dir)) @mkdir($dir, 0700, true);

$f = $_FILES['file'] ?? null;
if (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $code = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    http_response_code($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE ? 413 : 400);
    echo json_encode(['ok' => false, 'error' => $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE ? 'size' : 'upload']);
    exit;
}
if ($f['size'] > RB_MAX) { http_response_code(413); echo '{"ok":false,"error":"size"}'; exit; }

$name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', basename((string)$f['name']));
$name = mb_substr(trim($name, ' ._'), -120) ?: 'file';
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!in_array($ext, RB_EXT, true)) { http_response_code(415); echo '{"ok":false,"error":"type"}'; exit; }

// Не больше 10 файлов в час с одного IP
$ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '')[0]);
$rlFile = "$dir/.ratelimit.json";
$rl = is_file($rlFile) ? (json_decode(file_get_contents($rlFile), true) ?: []) : [];
$now = time();
$key = md5($ip);
$rl[$key] = array_values(array_filter((array)($rl[$key] ?? []), fn($t) => is_int($t) && $t > $now - 3600));
if (count($rl[$key]) >= 10) { http_response_code(429); echo '{"ok":false,"error":"rate"}'; exit; }
$rl[$key][] = $now;
foreach ($rl as $k => $v) if (!$v) unset($rl[$k]);
file_put_contents($rlFile, json_encode($rl), LOCK_EX);

$id = date('Ymd-His') . '-' . bin2hex(random_bytes(6));
if (!move_uploaded_file($f['tmp_name'], "$dir/$id.bin")) { http_response_code(500); echo '{"ok":false,"error":"save"}'; exit; }
file_put_contents("$dir/$id.json", json_encode(['name' => $name, 'size' => (int)$f['size'], 'ip' => $ip, 'time' => date('c')], JSON_UNESCAPED_UNICODE));

// Уборка: файлы старше 90 дней
foreach (glob("$dir/*.{bin,json}", GLOB_BRACE) ?: [] as $old) {
    if (filemtime($old) < $now - 90 * 86400) @unlink($old);
}

echo json_encode(['ok' => true, 'id' => $id, 'name' => $name, 'size' => (int)$f['size']], JSON_UNESCAPED_UNICODE);
