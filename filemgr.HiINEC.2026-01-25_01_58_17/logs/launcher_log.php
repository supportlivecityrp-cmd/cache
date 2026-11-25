<?php
// Echo Cross
// Telegram: @tasjJson

// Simple fast logger endpoint for launcher.
// Accepts POST JSON body and appends it to server/logs/launcher_YYYY-MM-DD.log

@ini_set('display_errors', '0');
@ini_set('log_errors', '0');
@error_reporting(0);

date_default_timezone_set('Europe/Chisinau');

function respond($ok, $extra = []) {
    header('Content-Type: application/json; charset=utf-8');
    $out = ['ok' => (bool)$ok];
    foreach ($extra as $k => $v) $out[$k] = $v;
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

function safe($s, $max = 512) {
    if ($s === null) return '';
    $s = (string)$s;
    $s = str_replace(["\r", "\n", "\t"], ' ', $s);
    $s = trim($s);
    if (strlen($s) > $max) $s = substr($s, 0, $max);
    return $s;
}

$ip = safe($_SERVER['REMOTE_ADDR'] ?? '');
$ua = safe($_SERVER['HTTP_USER_AGENT'] ?? '', 700);
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$raw = file_get_contents('php://input');
$raw = $raw === false ? '' : $raw;

$data = null;
if ($raw !== '') {
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = null;
}

$ts = date('Y-m-d H:i:s');
$rid = safe($data['rid'] ?? ($_GET['rid'] ?? ''), 80);
$tag = safe($data['tag'] ?? ($_GET['tag'] ?? ''), 64);
$msg = safe($data['msg'] ?? ($_GET['msg'] ?? ''), 260);
$nick = safe($data['nick'] ?? ($_GET['nick'] ?? ''), 32);
$slot = safe($data['slot'] ?? ($_GET['slot'] ?? ''), 8);
$device = safe($data['device'] ?? ($_GET['device'] ?? ''), 64);
$app = safe($data['app'] ?? ($_GET['app'] ?? ''), 80);
$ctx = $data['ctx'] ?? null;
if (!is_array($ctx)) $ctx = [];

$line = [
    'ts' => $ts,
    'rid' => $rid,
    'ip' => $ip,
    'ua' => $ua,
    'tag' => $tag,
    'msg' => $msg,
    'nick' => $nick,
    'slot' => $slot,
    'device' => $device,
    'app' => $app,
    'ctx' => $ctx,
];

$dir = __DIR__ . '/logs';
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}

$fname = $dir . '/launcher_' . date('Y-m-d') . '.log';
$payload = json_encode($line, JSON_UNESCAPED_UNICODE) . "\n";

$okWrite = false;
try {
    $fp = @fopen($fname, 'ab');
    if ($fp) {
        @flock($fp, LOCK_EX);
        @fwrite($fp, $payload);
        @fflush($fp);
        @flock($fp, LOCK_UN);
        @fclose($fp);
        $okWrite = true;
    }
} catch (Throwable $e) {
    $okWrite = false;
}

// Optional quick debug
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "OK=" . ($okWrite ? '1' : '0') . "\n";
    echo "FILE=" . $fname . "\n";
    echo "METHOD=" . $method . "\n";
    echo "RAW=" . $raw . "\n";
    exit;
}

respond(true, ['written' => $okWrite ? 1 : 0]);
