<?php
// Echo Cross
// Telegram: @tasjJson

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('Europe/Moscow');

function out($ok, $result) {
    echo json_encode(['ok' => (bool)$ok, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function s($v) {
    if ($v === null) return '';
    $v = trim((string)$v);
    return $v;
}

function nick_ok($nick) {
    return (bool)preg_match('/^[A-Za-z0-9_]{3,24}$/', $nick);
}

function pick_col($cols, $candidates) {
    foreach ($candidates as $c) {
        if (isset($cols[$c])) return $c;
    }
    return null;
}

function http_post($url, $fields, $timeoutMs=2000) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $timeoutMs);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeoutMs);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $resp];
}

$nick   = s($_GET['nick'] ?? '');
$device = s($_GET['device'] ?? '');
$slot   = s($_GET['slot'] ?? '');
$model  = s($_GET['model'] ?? '');
$sdk    = s($_GET['sdk'] ?? '');
$android= s($_GET['android'] ?? '');

if (!nick_ok($nick)) out(true, ['allowed' => false, 'reason' => 'bad_nick', 'bound' => false]);

if ($device === '') $device = 'unknown';

$ip = s($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
$ua = s($_SERVER['HTTP_USER_AGENT'] ?? '');

$DB_HOST = '149.202.88.119';
$DB_NAME = 'gs306141';
$DB_USER = 'gs306141';
$DB_PASS = 'gs306141';

$BOT_TOKEN = '8313260270:AAHT-7hXb8LhkRtsWyuU7QCnoxe9BThJFJk';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `accounts`") as $row) {
        $cols[$row['Field']] = true;
    }

    $colNick = pick_col($cols, ['nick','name','Nick','Name']);
    if ($colNick === null) out(true, ['allowed'=>false,'reason'=>'schema_no_nick','bound'=>false]);

    $colTgId = pick_col($cols, ['telegram_id','tg_user_id','tg_id','telegramId']);
    $colTgUser = pick_col($cols, ['tg_username','telegram_username','telegram_login']);
    $colDev = pick_col($cols, ['device_id','tg_device_id','telegram_device','device','android_id']);
    $colBound = pick_col($cols, ['tg_bound','telegram_bound','is_tg_bound','bound']);

    $stmt = $pdo->prepare("SELECT * FROM `accounts` WHERE `{$colNick}`=? LIMIT 1");
    $stmt->execute([$nick]);
    $acc = $stmt->fetch();

    if (!$acc) {
        out(true, ['allowed'=>true,'reason'=>'need_register','bound'=>false,'exists'=>false,'register'=>true]);
    }

    $tgId = $colTgId ? s($acc[$colTgId] ?? '') : '';
    $tgUser = $colTgUser ? s($acc[$colTgUser] ?? '') : '';
    $storedDev = $colDev ? s($acc[$colDev] ?? '') : '';
    $flagBound = $colBound ? (int)($acc[$colBound] ?? 0) : 0;

    $bound = false;
    if ($flagBound === 1) $bound = true;
    if ($tgId !== '') $bound = true;

    if (!$bound) {
        out(true, ['allowed'=>false,'reason'=>'need_bind','bound'=>false,'exists'=>true]);
    }

    if ($storedDev === '' || $storedDev === 'unknown') {
        out(true, ['allowed'=>false,'reason'=>'need_bind','bound'=>true,'exists'=>true]);
    }

    if (hash_equals($storedDev, $device)) {
        out(true, ['allowed'=>true,'reason'=>'ok','bound'=>true,'exists'=>true]);
    }

    if ($tgId !== '' && $BOT_TOKEN !== '' && $BOT_TOKEN !== 'PUT_BOT_TOKEN_HERE') {
        $txt = "🚫 Попытка входа отклонена\n\n"
             . "Ник: {$nick}\n"
             . "Слот: {$slot}\n"
             . "IP: {$ip}\n"
             . "Android ID: {$device}\n"
             . "Модель: {$model}\n"
             . "SDK: {$sdk}\n"
             . "Android: {$android}\n"
             . "UA: {$ua}\n"
             . "Время: " . date('Y-m-d H:i:s');

        http_post("https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage", [
            'chat_id' => $tgId,
            'text' => $txt
        ], 1800);
    }

    out(true, ['allowed'=>false,'reason'=>'device_mismatch','bound'=>true,'exists'=>true]);
} catch (Throwable $e) {
    out(false, ['allowed'=>false,'reason'=>'server_error','bound'=>false,'error'=>$e->getMessage()]);
}