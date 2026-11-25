<?php
// DB config (as provided)
define('DB_HOST', '149.202.88.119');
define('DB_NAME', 'gs306141');
define('DB_USER', 'gs306141');
define('DB_PASS', 'gs306141');
define('DB_CHARSET', 'utf8');

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

function json_out(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rand_str(int $len, string $alphabet): string {
    $out = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

function guess_default_from_type(string $type) {
    $t = strtolower($type);

    if (strpos($t, 'int') !== false || strpos($t, 'decimal') !== false || strpos($t, 'float') !== false || strpos($t, 'double') !== false) {
        return 0;
    }
    if (strpos($t, 'tinyint(1)') !== false || strpos($t, 'bool') !== false) {
        return 0;
    }
    if (strpos($t, 'datetime') !== false || strpos($t, 'timestamp') !== false) {
        return date('Y-m-d H:i:s');
    }
    // varchar/text/longtext, etc.
    return '';
}
