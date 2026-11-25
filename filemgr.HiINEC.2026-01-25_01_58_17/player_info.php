<?php
// Echo Cross
// Telegram: @tasjJson

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('Europe/Chisinau');

function ip_addr(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $v = (string)$_SERVER[$k];
            if ($k === 'HTTP_X_FORWARDED_FOR') $v = explode(',', $v)[0];
            return trim($v);
        }
    }
    return 'unknown';
}

function ua(): string {
    return isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '-';
}

function now_ts(): string {
    return date('Y-m-d H:i:s');
}

function ensure_logs_dir(string $dir): void {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

function log_line(string $file, array $row): void {
    ensure_logs_dir(dirname($file));
    $row['ts'] = now_ts();
    $row['ip'] = ip_addr();
    $row['ua'] = ua();
    @file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
}

function resp(array $obj): void {
    echo json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$LOG_MAIN = __DIR__ . '/logs/player_info.log';
$LOG_DB   = __DIR__ . '/logs/player_info_db.log';

$nick = isset($_GET['nick']) ? trim((string)$_GET['nick']) : '';
$debug = isset($_GET['debug']) ? trim((string)$_GET['debug']) : '';
$isDebug = ($debug === '1' || strtolower($debug) === 'true');

if ($nick === '' || !preg_match('/^[A-Za-z0-9_]{3,24}$/', $nick)) {
    log_line($LOG_MAIN, ['tag'=>'bad_nick','msg'=>'invalid','nick'=>$nick]);
    resp(['ok'=>false,'reason'=>'bad_nick']);
}

log_line($LOG_MAIN, ['tag'=>'request','msg'=>'start','nick'=>$nick]);

// DB credentials (site -> remote host)
$DB_HOST = '149.202.88.119';
$DB_NAME = 'gs306141';
$DB_USER = 'gs306141';
$DB_PASS = 'gs306141';
$DB_CHARSET = 'utf8mb4';

try {
    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 3,
    ]);

    // Detect existing columns to avoid "Unknown column" errors
    $cols = [];
    $q = $pdo->query("SHOW COLUMNS FROM `accounts`");
    foreach ($q as $r) {
        if (!empty($r['Field'])) $cols[strtolower((string)$r['Field'])] = true;
    }
    $has = function(string $name) use (&$cols): bool {
        return isset($cols[strtolower($name)]);
    };

    // Map columns (supports different schemas)
    $col_skin   = $has('skin')   ? 'skin'   : ($has('player_skin') ? 'player_skin' : null);
    $col_money  = $has('donate_total')  ? 'donate_total'  : ($has('donate_total') ? 'donate_total' : ($has('dollars') ? 'dollars' : null));
    $col_admin  = $has('admin')  ? 'admin'  : ($has('adminlvl') ? 'adminlvl' : ($has('admin_level') ? 'admin_level' : null));
    $col_vip    = $has('vip')    ? 'vip'    : ($has('premium') ? 'premium' : ($has('vip_level') ? 'vip_level' : null));
    $col_level  = $has('level')  ? 'level'  : ($has('lvl') ? 'lvl' : ($has('player_level') ? 'player_level' : null));
    $col_prefix = $has('prefix') ? 'prefix' : ($has('adm_prefix') ? 'adm_prefix' : null);

    $select = [];
    $select[] = ($col_skin   ? "`{$col_skin}`"   : "94") . " AS `skin`";
    $select[] = ($col_money  ? "`{$col_money}`"  : "0")  . " AS `money`";
    $select[] = ($col_admin  ? "`{$col_admin}`"  : "0")  . " AS `admin`";
    $select[] = ($col_vip    ? "`{$col_vip}`"    : "0")  . " AS `vip`";
    $select[] = ($col_level  ? "`{$col_level}`"  : "0")  . " AS `level`";
    $select[] = ($col_prefix ? "`{$col_prefix}`" : "''") . " AS `prefix`";

    $sql = "SELECT " . implode(", ", $select) . " FROM `accounts` WHERE `name` = :nick LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':nick' => $nick]);
    $row = $st->fetch();

    if (!$row) {
        log_line($LOG_MAIN, ['tag'=>'not_found','msg'=>'no_row','nick'=>$nick]);
        resp(['ok'=>false,'reason'=>'not_found']);
    }

    // Normalize types
    $result = [
        'nick'   => $nick,
        'skin'   => (int)($row['skin'] ?? 94),
        'money'  => (int)($row['money'] ?? 0),
        'admin'  => (int)($row['admin'] ?? 0),
        'vip'    => (int)($row['vip'] ?? 0),
        'level'  => (int)($row['level'] ?? 0),
        'prefix' => (string)($row['prefix'] ?? ''),
    ];

    log_line($LOG_MAIN, ['tag'=>'ok','msg'=>'done','nick'=>$nick,'skin'=>$result['skin'],'vip'=>$result['vip'],'admin'=>$result['admin']]);

    resp(['ok'=>true,'result'=>$result]);

} catch (Throwable $e) {
    log_line($LOG_DB, [
        'tag'=>'db_fail',
        'msg'=>substr($e->getMessage(), 0, 500),
        'nick'=>$nick
    ]);
    log_line($LOG_MAIN, ['tag'=>'db_fail','msg'=>'exception','nick'=>$nick]);

    if ($isDebug) {
        resp(['ok'=>false,'reason'=>'db_fail','debug'=>substr($e->getMessage(), 0, 300)]);
    }
    resp(['ok'=>false,'reason'=>'db_fail']);
}
