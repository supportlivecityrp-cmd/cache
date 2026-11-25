<?php
// Echo Cross
// Telegram: @tasjJson

/*
 * One-file bot (PHP 7.2+)
 * v9 FAST: ускорение + замеры времени + кэш схемы accounts (SHOW COLUMNS больше не дергаем каждый апдейт)
 *
 * Диагностика:
 *  - /bot.php?debug=1 (покажет php, права, db connect)
 *  - логи: logs/bot.log, logs/perf.log, logs/telegram.log (или fallback в tmp)
 */

error_reporting(E_ALL);

$DEBUG = isset($_GET['debug']) ? true : false;
ini_set('display_errors', $DEBUG ? '1' : '0');
ini_set('log_errors', '1');

// ====== CONFIG ======
define('BOT_TOKEN', 'PASTE_YOUR_BOT_TOKEN_HERE');
define('BOT_VER', 'bot_2025-12-22_v2');

define('DB_HOST', '149.202.88.119');
define('DB_NAME', 'gs306141');
define('DB_USER', 'gs306141');
define('DB_PASS', 'gs306141');
define('DB_CHARSET', 'utf8');

define('MAX_CHARS_PER_TG', 3);

// TTL cache schema accounts (seconds)
define('SCHEMA_CACHE_TTL', 300);

// Telegram timeouts (seconds)
define('TG_CONNECT_TIMEOUT', 3);
define('TG_TIMEOUT', 10);

// ====== LOGGING ======
define('LOG_DIR', __DIR__ . '/logs');
define('CACHE_DIR', __DIR__ . '/cache');

function now_ts() { return date('Y-m-d H:i:s'); }

function ensure_dir($dir) {
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
}

function ensure_log_target() {
    ensure_dir(LOG_DIR);
    if (is_dir(LOG_DIR) && is_writable(LOG_DIR)) {
        @ini_set('error_log', LOG_DIR . '/php_errors.log');
    } else {
        @ini_set('error_log', rtrim(sys_get_temp_dir(), '/\\') . '/volya_php_errors.log');
    }
}
ensure_log_target();

function log_try($file, $line) {
    $ts = '[' . now_ts() . '] ' . $line . "\n";

    try {
        ensure_dir(LOG_DIR);
        if (is_dir(LOG_DIR) && is_writable(LOG_DIR)) {
            @file_put_contents(LOG_DIR . '/' . $file, $ts, FILE_APPEND | LOCK_EX);
            return;
        }
    } catch (Exception $e) { /* ignore */ }
    catch (Error $e) { /* ignore */ }

    try {
        $tmp = rtrim(sys_get_temp_dir(), '/\\') . '/volya_bot_fallback.log';
        @file_put_contents($tmp, $ts, FILE_APPEND | LOCK_EX);
    } catch (Exception $e) { error_log('VOLYA_FALLBACK_FAIL ' . $e->getMessage()); }
    catch (Error $e) { error_log('VOLYA_FALLBACK_ERR ' . $e->getMessage()); }
}

function t0() { return microtime(true); }
function ms($a, $b) { return (int)(($b - $a) * 1000.0); }

set_exception_handler(function($e) {
    $msg = is_object($e) && method_exists($e, 'getMessage') ? $e->getMessage() : 'unknown';
    log_try('bot.log', 'UNCAUGHT ' . $msg);
    http_response_code(200);
    exit;
});

function safe($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalize_username($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    return ($raw[0] === '@') ? $raw : ('@' . $raw);
}

function is_valid_nick($nick) {
    return (bool)preg_match('/^[A-Za-z0-9_]{3,24}$/', (string)$nick);
}

function kb_main() {
    return json_encode(array(
        'keyboard' => array(
            array(array('text' => '👤 Личный кабинет'), array('text' => '📊 Статистика')),
            array(array('text' => '❓ Помощь')),
        ),
        'resize_keyboard' => true,
        'one_time_keyboard' => false,
    ), JSON_UNESCAPED_UNICODE);
}

function inline_keyboard($rows) {
    return json_encode(array('inline_keyboard' => $rows), JSON_UNESCAPED_UNICODE);
}

// ====== DB ======
function db() {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

    $opts = array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => true,
    );

    // Some hosts support these mysql attrs; ignore if not.
    if (defined('PDO::ATTR_TIMEOUT')) $opts[PDO::ATTR_TIMEOUT] = 3;
    if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) $opts[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES " . DB_CHARSET;
    if (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) $opts[PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = 3;

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
    return $pdo;
}

function schema_cache_path() {
    ensure_dir(CACHE_DIR);
    return CACHE_DIR . '/schema_accounts.json';
}

function account_columns_uncached($pdo) {
    $cols = array();
    $st = $pdo->query("SHOW COLUMNS FROM `accounts`");
    $rows = $st->fetchAll();
    foreach ($rows as $r) {
        $name = (string)$r['Field'];
        $cols[$name] = array(
            'type' => strtolower((string)$r['Type']),
            'null' => ((string)$r['Null'] === 'YES'),
            'default' => $r['Default'],
            'extra' => strtolower((string)$r['Extra']),
        );
    }
    return $cols;
}

function account_columns($pdo) {
    $p = schema_cache_path();
    $now = time();

    try {
        if (is_file($p)) {
            $raw = @file_get_contents($p);
            if ($raw !== false && $raw !== '') {
                $j = json_decode($raw, true);
                if (is_array($j) && isset($j['ts']) && isset($j['cols']) && is_array($j['cols'])) {
                    if (($now - (int)$j['ts']) <= SCHEMA_CACHE_TTL) return $j['cols'];
                }
            }
        }
    } catch (Exception $e) { /* ignore */ }
    catch (Error $e) { /* ignore */ }

    $t = t0();
    $cols = account_columns_uncached($pdo);
    $dt = ms($t, t0());
    log_try('perf.log', 'schema_refresh_ms=' . $dt . ' cols=' . count($cols));

    try {
        @file_put_contents($p, json_encode(array('ts' => $now, 'cols' => $cols), JSON_UNESCAPED_UNICODE), LOCK_EX);
    } catch (Exception $e) { /* ignore */ }
    catch (Error $e) { /* ignore */ }

    return $cols;
}

function has_col($cols, $name) { return isset($cols[$name]); }

function guess_default_for_type($type) {
    $t = strtolower((string)$type);
    if (strpos($t, 'int') !== false || strpos($t, 'decimal') !== false || strpos($t, 'float') !== false || strpos($t, 'double') !== false) return 0;
    if (strpos($t, 'datetime') !== false) return date('Y-m-d H:i:s');
    if (strpos($t, 'timestamp') !== false) return date('Y-m-d H:i:s');
    if (strpos($t, 'date') !== false) return date('Y-m-d');
    return '';
}

function ensure_account_exists($pdo, $cols, $nick) {
    if (!is_valid_nick($nick)) return null;
    $st = $pdo->prepare("SELECT `id`,`name`" . (has_col($cols, 'tg_username') ? ",`tg_username`" : "") . (has_col($cols, 'telegram_id') ? ",`telegram_id`" : "") . " FROM `accounts` WHERE `name`=? LIMIT 1");
    $st->execute(array($nick));
    $row = $st->fetch();
    return $row ? $row : null;
}

function count_chars_for_tg($pdo, $cols, $tgUserId) {
    if (has_col($cols, 'telegram_id')) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM `accounts` WHERE `telegram_id`=?");
        $st->execute(array((int)$tgUserId));
        return (int)$st->fetchColumn();
    }
    return 0;
}

function bind_account($pdo, $cols, $rowId, $tgUserId, $tgUsername, $deviceId) {
    $sets = array(); $vals = array();

    if (has_col($cols, 'telegram_id')) { $sets[] = "`telegram_id`=?"; $vals[] = (int)$tgUserId; }
    if (has_col($cols, 'tg_username')) { $sets[] = "`tg_username`=?"; $vals[] = (string)$tgUsername; }
    if (has_col($cols, 'device_id')) { $sets[] = "`device_id`=?"; $vals[] = (string)$deviceId; }
    if (has_col($cols, 'tg_verified')) { $sets[] = "`tg_verified`=1"; }
    if (has_col($cols, 'tg_bound')) { $sets[] = "`tg_bound`=1"; }

    if (!$sets) return;

    $vals[] = (int)$rowId;
    $sql = "UPDATE `accounts` SET " . implode(", ", $sets) . " WHERE `id`=? LIMIT 1";
    $upd = $pdo->prepare($sql);
    $upd->execute($vals);
}

function unbind_account($pdo, $cols, $rowId) {
    $sets = array();

    if (has_col($cols, 'telegram_id')) $sets[] = "`telegram_id`=NULL";
    if (has_col($cols, 'tg_username')) $sets[] = "`tg_username`=NULL";
    if (has_col($cols, 'device_id')) $sets[] = "`device_id`=NULL";
    if (has_col($cols, 'tg_verified')) $sets[] = "`tg_verified`=0";
    if (has_col($cols, 'tg_bound')) $sets[] = "`tg_bound`=0";

    if (!$sets) return;

    $sql = "UPDATE `accounts` SET " . implode(", ", $sets) . " WHERE `id`=? LIMIT 1";
    $upd = $pdo->prepare($sql);
    $upd->execute(array((int)$rowId));
}

// ====== TG API ======
class TgApi {
    private $token;
    public function __construct($token) { $this->token = (string)$token; }

    private function http_post($url, $data) {
        $t = t0();

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, TG_CONNECT_TIMEOUT);
            curl_setopt($ch, CURLOPT_TIMEOUT, TG_TIMEOUT);
            curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 120);
            curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
            $resp = curl_exec($ch);
            $err = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            log_try('perf.log', 'tg_http_ms=' . ms($t, t0()) . ' code=' . $code . ' err=' . ($err ? $err : ''));

            if ($resp === false) log_try('telegram.log', 'CURL_FAIL url=' . $url . ' err=' . $err);
            return $resp;
        }

        $opts = array(
            'http' => array(
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($data),
                'timeout' => TG_TIMEOUT,
            )
        );
        $resp = @file_get_contents($url, false, stream_context_create($opts));
        log_try('perf.log', 'tg_http_ms=' . ms($t, t0()) . ' code=' . ($resp === false ? 0 : 200) . ' err=' . ($resp === false ? 'fget_fail' : ''));
        return $resp;
    }

    public function call($method, $data) {
        $url = "https://api.telegram.org/bot" . $this->token . "/" . $method;
        $resp = $this->http_post($url, $data);

        if ($resp === false) {
            log_try('telegram.log', 'TG_API_FAIL method=' . $method . ' err=request_failed');
            return array('ok' => false, 'error' => 'request_failed');
        }

        $short = $resp;
        if (strlen($short) > 1200) $short = substr($short, 0, 1200) . '...';
        log_try('telegram.log', 'TG_API method=' . $method . ' resp=' . $short);

        $j = json_decode($resp, true);
        if (!is_array($j)) return array('ok' => false, 'error' => 'bad_json');
        return $j;
    }

    public function sendMessage($chatId, $text, $opts = array()) {
        $data = array(
            'chat_id' => (int)$chatId,
            'text' => (string)$text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => 'true',
        );
        foreach ($opts as $k => $v) $data[$k] = $v;
        $this->call('sendMessage', $data);
    }

    public function editMessageText($chatId, $messageId, $text, $opts = array()) {
        $data = array(
            'chat_id' => (int)$chatId,
            'message_id' => (int)$messageId,
            'text' => (string)$text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => 'true',
        );
        foreach ($opts as $k => $v) $data[$k] = $v;
        $this->call('editMessageText', $data);
    }

    public function answerCallbackQuery($cbId, $text = '', $alert = false) {
        $this->call('answerCallbackQuery', array(
            'callback_query_id' => (string)$cbId,
            'text' => (string)$text,
            'show_alert' => $alert ? 'true' : 'false',
        ));
    }
}

// ====== PAYLOAD ======
function parse_payload($payload) {
    $out = array(
        'ok' => false,
        'err' => 'bad_format',
        'slot' => '',
        'nick' => '',
        'device_id' => '',
        'model' => '',
        'sdk' => '',
        'android' => '',
        'raw' => (string)$payload,
    );

    $payload = (string)$payload;
    if (strpos($payload, 'bind_') !== 0) { $out['err'] = 'not_bind'; return $out; }

    $p = substr($payload, 5);
    $arr = explode('_', $p);

    $out['slot'] = isset($arr[0]) ? (string)$arr[0] : '';

    $idxD = -1; $idxM = -1; $idxK = -1; $idxA = -1;
    for ($i = 1; $i < count($arr); $i++) {
        $v = (string)$arr[$i];
        if ($idxD < 0 && strlen($v) > 1 && $v[0] === 'd') $idxD = $i;
        if ($idxM < 0 && strlen($v) > 1 && $v[0] === 'm') $idxM = $i;
        if ($idxK < 0 && strlen($v) > 1 && $v[0] === 'k') $idxK = $i;
        if ($idxA < 0 && strlen($v) > 1 && $v[0] === 'a') $idxA = $i;
    }

    if ($idxD < 0) { $out['err'] = 'no_device'; return $out; }

    $nickParts = array_slice($arr, 1, $idxD - 1);
    $out['nick'] = implode('_', $nickParts);

    $out['device_id'] = substr((string)(isset($arr[$idxD]) ? $arr[$idxD] : ''), 1);
    if ($idxM >= 0) $out['model'] = substr((string)$arr[$idxM], 1);
    if ($idxK >= 0) $out['sdk'] = substr((string)$arr[$idxK], 1);
    if ($idxA >= 0) $out['android'] = substr((string)$arr[$idxA], 1);

    if ($out['nick'] === '' || $out['device_id'] === '') { $out['err'] = 'empty_fields'; return $out; }

    $out['ok'] = true;
    $out['err'] = '';
    return $out;
}

function filter_sensitive_key($k) {
    $k = strtolower((string)$k);
    $bad = array('password','pass','passwd','hash','token','auth','secret','salt','email','mail','ip','regip','serial','hwid','device','tg','telegram');
    foreach ($bad as $b) if (strpos($k, $b) !== false) return true;
    return false;
}

function render_sections($row) {
    $basic = array(); $finance = array(); $skills = array(); $auto = array(); $business = array(); $property = array(); $other = array();

    foreach ($row as $k => $v) {
        if ($v === null || $v === '') continue;
        if (filter_sensitive_key($k)) continue;

        $kl = strtolower((string)$k);

        if (in_array($kl, array('level','exp','rep','wanted','sex','age','skin','prefix','admin','vip','tg_bound','tg_verified'), true)) { $basic[$k] = $v; continue; }
        if (strpos($kl, 'money') !== false || strpos($kl, 'bank') !== false || strpos($kl, 'don') !== false || strpos($kl, 'cash') !== false) { $finance[$k] = $v; continue; }
        if (strpos($kl, 'skill') !== false || strpos($kl, 'sk_') === 0 || strpos($kl, 'gun') !== false || strpos($kl, 'weapon') !== false) { $skills[$k] = $v; continue; }
        if (strpos($kl, 'car') !== false || strpos($kl, 'veh') !== false || strpos($kl, 'auto') !== false) { $auto[$k] = $v; continue; }
        if (strpos($kl, 'biz') !== false || strpos($kl, 'business') !== false) { $business[$k] = $v; continue; }
        if (strpos($kl, 'house') !== false || strpos($kl, 'home') !== false || strpos($kl, 'prop') !== false || strpos($kl, 'flat') !== false || strpos($kl, 'apart') !== false) { $property[$k] = $v; continue; }
        $other[$k] = $v;
    }

    return array(
        '⭐ Основное' => $basic,
        '💰 Финансы' => $finance,
        '🚘 Авто' => $auto,
        '🏪 Бизнес' => $business,
        '🏠 Имущество' => $property,
        '🎯 Скиллы' => $skills,
        '🧩 Прочее' => $other,
    );
}

function render_cabinet($row) {
    $nick = (string)(isset($row['name']) ? $row['name'] : '');
    $lines = array();
    $lines[] = "👤 <b>Личный кабинет</b>";
    $lines[] = "🎭 Персонаж: <code>" . safe($nick) . "</code>";
    $lines[] = "────────────────────";

    $sections = render_sections($row);
    foreach ($sections as $title => $data) {
        if (!$data) continue;
        $lines[] = "<b>" . $title . "</b>";
        foreach ($data as $k => $v) $lines[] = "• " . safe($k) . ": <code>" . safe($v) . "</code>";
        $lines[] = "────────────────────";
    }
    return implode("\n", $lines);
}

function render_full_stats($row) {
    $nick = (string)(isset($row['name']) ? $row['name'] : '');
    $lines = array();
    $lines[] = "📊 <b>Полная статистика</b>";
    $lines[] = "🎭 Ник: <code>" . safe($nick) . "</code>";
    $lines[] = "────────────────────";

    $sections = render_sections($row);
    foreach ($sections as $title => $data) {
        if (!$data) continue;
        $lines[] = "<b>" . $title . "</b>";
        foreach ($data as $k => $v) $lines[] = "• " . safe($k) . ": <code>" . safe($v) . "</code>";
        $lines[] = "────────────────────";
    }
    return implode("\n", $lines);
}

function pick_accounts_for_user($pdo, $cols, $tgUserId, $tgUsernameRaw) {
    $tgUsername = normalize_username($tgUsernameRaw);

    if (has_col($cols, 'telegram_id') && has_col($cols, 'tg_username') && $tgUsername !== '') {
        $st = $pdo->prepare("SELECT * FROM `accounts` WHERE `telegram_id`=? OR `tg_username`=? ORDER BY `id` ASC LIMIT 50");
        $st->execute(array((int)$tgUserId, $tgUsername));
        return $st->fetchAll();
    }
    if (has_col($cols, 'telegram_id')) {
        $st = $pdo->prepare("SELECT * FROM `accounts` WHERE `telegram_id`=? ORDER BY `id` ASC LIMIT 50");
        $st->execute(array((int)$tgUserId));
        return $st->fetchAll();
    }
    return array();
}

function cb_owner_ok($cols, $row, $tgUserId, $tgUsernameRaw) {
    $tgU = normalize_username($tgUsernameRaw);
    if (has_col($cols, 'telegram_id') && isset($row['telegram_id']) && (int)$row['telegram_id'] === (int)$tgUserId) return true;
    if (has_col($cols, 'tg_username') && $tgU !== '' && isset($row['tg_username']) && (string)$row['tg_username'] === $tgU) return true;
    return false;
}

function send_character_picker($tg, $chatId, $tgUserId, $tgUsernameRaw, $mode) {
    $tAll = t0();
    try {
        $tDb = t0();
        $pdo = db();
        $cols = account_columns($pdo);
        log_try('perf.log', 'db_connect_ms=' . ms($tDb, t0()));

        $tQ = t0();
        $accs = pick_accounts_for_user($pdo, $cols, $tgUserId, $tgUsernameRaw);
        log_try('perf.log', 'pick_query_ms=' . ms($tQ, t0()) . ' rows=' . count($accs));

        if (!$accs) {
            $who = normalize_username($tgUsernameRaw);
            if ($who === '') $who = "TG ID " . (int)$tgUserId;
            $tg->sendMessage($chatId,
                "😕 <b>Пока пусто</b>\n\n" .
                "Не нашёл привязанных персонажей для <code>" . safe($who) . "</code>\n\n" .
                "💡 Открой меня из лаунчера и я сохраню привязку в базу.",
                array('reply_markup' => kb_main())
            );
            log_try('perf.log', 'picker_total_ms=' . ms($tAll, t0()));
            return;
        }

        if (count($accs) === 1) {
            $a = $accs[0];
            $id = (int)(isset($a['id']) ? $a['id'] : 0);

            $text = ($mode === 'stats') ? render_full_stats($a) : render_cabinet($a);

            if ($mode === 'stats') {
                $rows = array(
                    array(
                        array('text' => '🔄 Обновить', 'callback_data' => "stats_pick:" . $id),
                        array('text' => '👤 В кабинет', 'callback_data' => "cab_pick:" . $id),
                    ),
                    array(
                        array('text' => '🔁 Выбрать персонажа', 'callback_data' => 'cab_back'),
                    )
                );
            } else {
                $rows = array(
                    array(
                        array('text' => '🔄 Обновить', 'callback_data' => "cab_refresh:" . $id),
                        array('text' => '📊 Полная статистика', 'callback_data' => "stats_pick:" . $id),
                    ),
                    array(
                        array('text' => '❌ Отвязать персонажа', 'callback_data' => "unbind_ask:" . $id),
                    )
                );
            }

            $tg->sendMessage($chatId, $text, array('reply_markup' => inline_keyboard($rows)));
            log_try('perf.log', 'picker_total_ms=' . ms($tAll, t0()));
            return;
        }

        $rows = array();
        foreach ($accs as $a) {
            $id = (int)(isset($a['id']) ? $a['id'] : 0);
            $name = (string)(isset($a['name']) ? $a['name'] : '');
            if ($id <= 0 || $name === '') continue;

            $rows[] = array(array(
                'text' => '🎭 ' . $name,
                'callback_data' => ($mode === 'stats') ? ("stats_pick:" . $id) : ("cab_pick:" . $id),
            ));
            if (count($rows) >= 12) break;
        }

        $title = ($mode === 'stats') ? "📊 <b>Статистика</b>\n\nВыбери персонажа:" : "👤 <b>Личный кабинет</b>\n\nВыбери персонажа:";
        $tg->sendMessage($chatId, $title, array('reply_markup' => inline_keyboard($rows)));
        log_try('perf.log', 'picker_total_ms=' . ms($tAll, t0()));

    } catch (Exception $e) {
        $tg->sendMessage($chatId, "❌ Ошибка БД: <code>" . safe($e->getMessage()) . "</code>", array('reply_markup' => kb_main()));
        log_try('bot.log', 'PICKER_ERROR ' . $e->getMessage());
        log_try('perf.log', 'picker_total_ms=' . ms($tAll, t0()));
    } catch (Error $e) {
        $tg->sendMessage($chatId, "❌ Ошибка: <code>" . safe($e->getMessage()) . "</code>", array('reply_markup' => kb_main()));
        log_try('bot.log', 'PICKER_ERR ' . $e->getMessage());
        log_try('perf.log', 'picker_total_ms=' . ms($tAll, t0()));
    }
}

function callback_handle_all($tg, $cb) {
    $data = (string)(isset($cb['data']) ? $cb['data'] : '');
    if ($data === '') return false;

    $cbId = (string)(isset($cb['id']) ? $cb['id'] : '');
    $from = isset($cb['from']) ? $cb['from'] : array();
    $msg  = isset($cb['message']) ? $cb['message'] : array();
    $chatId = (int)(isset($msg['chat']['id']) ? $msg['chat']['id'] : 0);
    $msgId  = (int)(isset($msg['message_id']) ? $msg['message_id'] : 0);
    $tgUserId = (int)(isset($from['id']) ? $from['id'] : 0);
    $tgUsernameRaw = (string)(isset($from['username']) ? $from['username'] : '');
    if ($chatId <= 0 || $msgId <= 0) return false;

    $tAll = t0();

    try {
        $tDb = t0();
        $pdo = db();
        $cols = account_columns($pdo);
        log_try('perf.log', 'cb_db_connect_ms=' . ms($tDb, t0()));

        if ($data === 'cab_back') {
            $tg->answerCallbackQuery($cbId, "Выбор персонажа");
            send_character_picker($tg, $chatId, $tgUserId, $tgUsernameRaw, 'cab');
            log_try('perf.log', 'cb_total_ms=' . ms($tAll, t0()));
            return true;
        }

        if (strpos($data, 'stats_pick:') === 0) {
            $id = (int)substr($data, 10);
            $tQ = t0();
            $st = $pdo->prepare("SELECT * FROM `accounts` WHERE `id`=? LIMIT 1");
            $st->execute(array($id));
            $a = $st->fetch();
            log_try('perf.log', 'cb_row_ms=' . ms($tQ, t0()));
            if (!$a) { $tg->answerCallbackQuery($cbId, "Не найдено"); return true; }
            if (!cb_owner_ok($cols, $a, $tgUserId, $tgUsernameRaw)) { $tg->answerCallbackQuery($cbId, "Нет доступа"); return true; }

            $text = render_full_stats($a);
            $rows = array(
                array(
                    array('text' => '🔄 Обновить', 'callback_data' => "stats_pick:" . $id),
                    array('text' => '👤 В кабинет', 'callback_data' => "cab_pick:" . $id),
                ),
                array(
                    array('text' => '🔁 Выбрать персонажа', 'callback_data' => 'cab_back'),
                )
            );

            $tg->answerCallbackQuery($cbId, "Готово");
            $tg->editMessageText($chatId, $msgId, $text, array('reply_markup' => inline_keyboard($rows)));
            log_try('perf.log', 'cb_total_ms=' . ms($tAll, t0()));
            return true;
        }

        if (strpos($data, 'cab_pick:') === 0 || strpos($data, 'cab_refresh:') === 0) {
            $id = (int)substr($data, strpos($data, ':') + 1);
            $tQ = t0();
            $st = $pdo->prepare("SELECT * FROM `accounts` WHERE `id`=? LIMIT 1");
            $st->execute(array($id));
            $a = $st->fetch();
            log_try('perf.log', 'cb_row_ms=' . ms($tQ, t0()));
            if (!$a) { $tg->answerCallbackQuery($cbId, "Не найдено"); return true; }
            if (!cb_owner_ok($cols, $a, $tgUserId, $tgUsernameRaw)) { $tg->answerCallbackQuery($cbId, "Нет доступа"); return true; }

            $text = render_cabinet($a);
            $rows = array(
                array(
                    array('text' => '🔄 Обновить', 'callback_data' => "cab_refresh:" . $id),
                    array('text' => '📊 Полная статистика', 'callback_data' => "stats_pick:" . $id),
                ),
                array(
                    array('text' => '🔁 Выбрать персонажа', 'callback_data' => 'cab_back'),
                    array('text' => '❌ Отвязать', 'callback_data' => "unbind_ask:" . $id),
                )
            );

            $tg->answerCallbackQuery($cbId, (strpos($data, 'cab_refresh:') === 0) ? "Обновлено" : "Открыто");
            $tg->editMessageText($chatId, $msgId, $text, array('reply_markup' => inline_keyboard($rows)));
            log_try('perf.log', 'cb_total_ms=' . ms($tAll, t0()));
            return true;
        }

        if (strpos($data, 'unbind_ask:') === 0) {
            $id = (int)substr($data, 11);
            $st = $pdo->prepare("SELECT * FROM `accounts` WHERE `id`=? LIMIT 1");
            $st->execute(array($id));
            $a = $st->fetch();
            if (!$a) { $tg->answerCallbackQuery($cbId, "Не найдено"); return true; }
            if (!cb_owner_ok($cols, $a, $tgUserId, $tgUsernameRaw)) { $tg->answerCallbackQuery($cbId, "Нет доступа"); return true; }

            $nick = (string)(isset($a['name']) ? $a['name'] : '');
            $text = "⚠️ <b>Подтверждение</b>\n\nОтвязать персонажа?\n🎭 <code>" . safe($nick) . "</code>";
            $rows = array(
                array(
                    array('text' => '✅ Да, отвязать', 'callback_data' => "unbind_do:" . $id),
                    array('text' => '↩️ Отмена', 'callback_data' => "cab_pick:" . $id),
                )
            );

            $tg->answerCallbackQuery($cbId, "Подтверди");
            $tg->editMessageText($chatId, $msgId, $text, array('reply_markup' => inline_keyboard($rows)));
            log_try('perf.log', 'cb_total_ms=' . ms($tAll, t0()));
            return true;
        }

        if (strpos($data, 'unbind_do:') === 0) {
            $id = (int)substr($data, 10);
            $st = $pdo->prepare("SELECT * FROM `accounts` WHERE `id`=? LIMIT 1");
            $st->execute(array($id));
            $a = $st->fetch();
            if (!$a) { $tg->answerCallbackQuery($cbId, "Не найдено"); return true; }
            if (!cb_owner_ok($cols, $a, $tgUserId, $tgUsernameRaw)) { $tg->answerCallbackQuery($cbId, "Нет доступа"); return true; }

            unbind_account($pdo, $cols, $id);
            log_try('bot.log', "UNBIND_OK tg=" . $tgUserId . " id=" . $id);

            $tg->answerCallbackQuery($cbId, "Отвязано ✅");
            send_character_picker($tg, $chatId, $tgUserId, $tgUsernameRaw, 'cab');
            log_try('perf.log', 'cb_total_ms=' . ms($tAll, t0()));
            return true;
        }

        log_try('perf.log', 'cb_total_ms=' . ms($tAll, t0()));
        return false;

    } catch (Exception $e) {
        $tg->answerCallbackQuery($cbId, "Ошибка");
        log_try('bot.log', "CB_ERROR " . $e->getMessage());
        log_try('perf.log', 'cb_total_ms=' . ms($tAll, t0()));
        return true;
    } catch (Error $e) {
        $tg->answerCallbackQuery($cbId, "Ошибка");
        log_try('bot.log', "CB_ERR " . $e->getMessage());
        log_try('perf.log', 'cb_total_ms=' . ms($tAll, t0()));
        return true;
    }
}

function handle_bind_start($tg, $chatId, $tgUserId, $tgUsernameRaw, $payload) {
    $tAll = t0();

    $pp = parse_payload($payload);
    if (!$pp['ok']) {
        $tg->sendMessage($chatId,
            "⚠️ <b>Не смог прочитать данные</b>\n\n" .
            "Причина: <code>" . safe($pp['err']) . "</code>\n" .
            "Raw: <code>" . safe($payload) . "</code>",
            array('reply_markup' => kb_main())
        );
        log_try('bot.log', "PAYLOAD_BAD tg=" . $tgUserId . " err=" . $pp['err'] . " raw=" . $payload);
        log_try('perf.log', 'bind_total_ms=' . ms($tAll, t0()));
        return;
    }

    $nick = (string)$pp['nick'];
    $deviceId = (string)$pp['device_id'];
    $model = (string)$pp['model'];
    $sdk = (string)$pp['sdk'];
    $android = (string)$pp['android'];
    $tgUsername = normalize_username($tgUsernameRaw);

    try {
        $tDb = t0();
        $pdo = db();
        $cols = account_columns($pdo);
        log_try('perf.log', 'bind_db_connect_ms=' . ms($tDb, t0()));

        $tQ = t0();
        $st = $pdo->prepare("SELECT * FROM `accounts` WHERE `name`=? LIMIT 1");
        $st->execute(array($nick));
        $row = $st->fetch();
        log_try('perf.log', 'bind_select_ms=' . ms($tQ, t0()));

        if (!$row) {
            $cnt = count_chars_for_tg($pdo, $cols, $tgUserId);
            if ($cnt >= MAX_CHARS_PER_TG) {
                $tg->sendMessage($chatId,
                    "⛔️ <b>Лимит персонажей достигнут</b>\n\n" .
                    "Максимум: <b>" . MAX_CHARS_PER_TG . "</b>\n" .
                    "Сейчас: <b>" . $cnt . "</b>\n\n" .
                    "Чтобы привязать новый — отвяжи один в «👤 Личный кабинет».",
                    array('reply_markup' => kb_main())
                );
                log_try('bot.log', "LIMIT_CREATE_DENY tg=" . $tgUserId . " cnt=" . $cnt . " nick=" . $nick);
                log_try('perf.log', 'bind_total_ms=' . ms($tAll, t0()));
                return;
            }

            $tCr = t0();
            $tg->sendMessage($chatId,
                "ℹ️ <b>Аккаунт ещё не зарегистрирован</b>\n\n" .
                "🎭 Ник: <code>" . safe($nick) . "</code>\n\n" .
                "Зайди в игру и пройди регистрацию (пароль/регистрация на сервере).\n" .
                "После регистрации вернись сюда и повтори привязку 🔐",
                array('reply_markup' => kb_main())
            );
            log_try('bot.log', "NEED_REGISTER tg=" . $tgUserId . " nick=" . $nick . " device=" . $deviceId);
            log_try('perf.log', 'bind_total_ms=' . ms($tAll, t0()));
            return;
            log_try('perf.log', 'bind_create_ms=' . ms($tCr, t0()));

            if (!$row) {
                $tg->sendMessage($chatId,
                    "❌ <b>Не удалось создать аккаунт</b>\n\nНик: <code>" . safe($nick) . "</code>",
                    array('reply_markup' => kb_main())
                );
                log_try('bot.log', "CREATE_FAIL tg=" . $tgUserId . " nick=" . $nick);
                log_try('perf.log', 'bind_total_ms=' . ms($tAll, t0()));
                return;
            }
        }

        $id = (int)(isset($row['id']) ? $row['id'] : 0);
        if ($id <= 0) {
            $tg->sendMessage($chatId, "❌ Ошибка: аккаунт без ID.", array('reply_markup' => kb_main()));
            log_try('bot.log', "BAD_ROW_ID tg=" . $tgUserId . " nick=" . $nick);
            log_try('perf.log', 'bind_total_ms=' . ms($tAll, t0()));
            return;
        }

        $curTgId = 0;
        if (has_col($cols, 'telegram_id') && isset($row['telegram_id']) && $row['telegram_id'] !== null) $curTgId = (int)$row['telegram_id'];

        if ($curTgId > 0 && $curTgId !== (int)$tgUserId) {
            $tg->sendMessage($chatId,
                "🚫 <b>Отказ</b>\n\nЭтот персонаж уже привязан к другому Telegram.\nНик: <code>" . safe($nick) . "</code>",
                array('reply_markup' => kb_main())
            );
            log_try('bot.log', "DENY_OTHER_TG nick=" . $nick . " curTg=" . $curTgId . " newTg=" . $tgUserId);
            log_try('perf.log', 'bind_total_ms=' . ms($tAll, t0()));
            return;
        }

        if ($curTgId === 0) {
            $cnt = count_chars_for_tg($pdo, $cols, $tgUserId);
            if ($cnt >= MAX_CHARS_PER_TG) {
                $tg->sendMessage($chatId,
                    "⛔️ <b>Лимит персонажей достигнут</b>\n\n" .
                    "Максимум: <b>" . MAX_CHARS_PER_TG . "</b>\n" .
                    "Сейчас: <b>" . $cnt . "</b>",
                    array('reply_markup' => kb_main())
                );
                log_try('bot.log', "LIMIT_BIND_DENY tg=" . $tgUserId . " cnt=" . $cnt . " nick=" . $nick);
                log_try('perf.log', 'bind_total_ms=' . ms($tAll, t0()));
                return;
            }
        }

        $tUp = t0();
        bind_account($pdo, $cols, $id, $tgUserId, $tgUsername, $deviceId);
        log_try('perf.log', 'bind_update_ms=' . ms($tUp, t0()));

        $tg->sendMessage($chatId,
            "✅ <b>Принято!</b> Я сохранил привязку 🔐\n\n" .
            "🎭 Ник: <code>" . safe($nick) . "</code>\n" .
            "👤 Telegram: <code>" . safe($tgUsername !== '' ? $tgUsername : 'unknown') . "</code>\n" .
            "📱 Device ID: <code>" . safe($deviceId) . "</code>\n\n" .
            "🧩 Устройство:\n" .
            "• Модель: <code>" . safe($model) . "</code>\n" .
            "• SDK: <code>" . safe($sdk) . "</code>\n" .
            "• Android: <code>" . safe($android) . "</code>\n\n" .
            "Дальше: «👤 Личный кабинет» 👇",
            array('reply_markup' => kb_main())
        );

        log_try('bot.log', "BIND_OK nick=" . $nick . " tg=" . $tgUserId . " dev=" . $deviceId . " uname=" . $tgUsername);
        log_try('perf.log', 'bind_total_ms=' . ms($tAll, t0()));

    } catch (Exception $e) {
        $tg->sendMessage($chatId, "❌ Ошибка БД: <code>" . safe($e->getMessage()) . "</code>", array('reply_markup' => kb_main()));
        log_try('bot.log', "BIND_DB_ERROR tg=" . $tgUserId . " msg=" . $e->getMessage());
        log_try('perf.log', 'bind_total_ms=' . ms($tAll, t0()));
    } catch (Error $e) {
        $tg->sendMessage($chatId, "❌ Ошибка: <code>" . safe($e->getMessage()) . "</code>", array('reply_markup' => kb_main()));
        log_try('bot.log', "BIND_ERR tg=" . $tgUserId . " msg=" . $e->getMessage());
        log_try('perf.log', 'bind_total_ms=' . ms($tAll, t0()));
    }
}

// ====== ENTRY ======
log_try('bot.log', "ENTRY method=" . (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'unknown') .
    " uri=" . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '') .
    " ip=" . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '') .
    " ua=" . (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''));

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/plain; charset=utf-8');

    $tokenSet = (BOT_TOKEN !== '' && BOT_TOKEN !== 'PASTE_YOUR_BOT_TOKEN_HERE') ? 'yes' : 'no';

    ensure_dir(LOG_DIR);
    ensure_dir(CACHE_DIR);

    $logExists = is_dir(LOG_DIR) ? 'yes' : 'no';
    $logWritable = (is_dir(LOG_DIR) && is_writable(LOG_DIR)) ? 'yes' : 'no';
    $cacheWritable = (is_dir(CACHE_DIR) && is_writable(CACHE_DIR)) ? 'yes' : 'no';

    echo "OK\n";
    echo "time=" . now_ts() . "\n";
    echo "php=" . PHP_VERSION . "\n";
    echo "bot_ver=" . (defined('BOT_VER') ? BOT_VER : 'na') . "\n";
    echo "token_set=" . $tokenSet . "\n";
    echo "log_dir=" . LOG_DIR . "\n";
    echo "log_dir_writable=" . $logWritable . "\n";
    echo "cache_dir=" . CACHE_DIR . "\n";
    echo "cache_dir_writable=" . $cacheWritable . "\n";
    echo "tmp_dir=" . sys_get_temp_dir() . "\n";
    echo "open_basedir=" . (ini_get('open_basedir') ? ini_get('open_basedir') : 'none') . "\n";

    if ($DEBUG) {
        echo "\nDEBUG:\n";
        echo "curl=" . (function_exists('curl_init') ? 'yes' : 'no') . "\n";
        echo "pdo=" . (class_exists('PDO') ? 'yes' : 'no') . "\n";
        echo "pdo_mysql=" . (class_exists('PDO') && in_array('mysql', PDO::getAvailableDrivers(), true) ? 'yes' : 'no') . "\n";

        try {
            $t = t0();
            $pdo = db();
            echo "db_connect=yes\n";
            echo "db_connect_ms=" . ms($t, t0()) . "\n";

            $t2 = t0();
            $cols = account_columns($pdo);
            echo "schema_cols=" . count($cols) . "\n";
            echo "schema_ms=" . ms($t2, t0()) . "\n";

        } catch (Exception $e) {
            echo "db_connect=no\n";
            echo "db_error=" . $e->getMessage() . "\n";
        } catch (Error $e) {
            echo "db_connect=no\n";
            echo "db_error=" . $e->getMessage() . "\n";
        }
    }

    http_response_code(200);
    exit;
}

$raw = @file_get_contents('php://input');
if ($raw === false || $raw === '') {
    log_try('bot.log', 'EMPTY_INPUT');
    http_response_code(200);
    exit;
}

$update = json_decode($raw, true);
if (!is_array($update)) {
    log_try('bot.log', 'BAD_JSON len=' . strlen($raw));
    http_response_code(200);
    exit;
}

$tg = new TgApi(BOT_TOKEN);

if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    if (callback_handle_all($tg, $cb)) { http_response_code(200); exit; }
    $tg->answerCallbackQuery((string)(isset($cb['id']) ? $cb['id'] : ''), "Ок");
    http_response_code(200);
    exit;
}

$msg = null;
if (isset($update['message']) && is_array($update['message'])) $msg = $update['message'];
else if (isset($update['edited_message']) && is_array($update['edited_message'])) $msg = $update['edited_message'];

if (!is_array($msg)) {
    log_try('bot.log', 'NO_MESSAGE');
    http_response_code(200);
    exit;
}

$chatId = (int)(isset($msg['chat']['id']) ? $msg['chat']['id'] : 0);
$text = (string)(isset($msg['text']) ? $msg['text'] : '');
$from = isset($msg['from']) ? $msg['from'] : array();
$tgUserId = (int)(isset($from['id']) ? $from['id'] : 0);
$tgUsernameRaw = (string)(isset($from['username']) ? $from['username'] : '');
$trim = trim($text);

log_try('bot.log', "UPDATE chat=" . $chatId . " tg=" . $tgUserId . " uname=" . $tgUsernameRaw . " text=" . $trim);

if (strpos($trim, '/start') === 0) {
    $parts = explode(' ', $trim, 2);
    $payload = (isset($parts[1]) ? trim($parts[1]) : '');

    $hello =
        "Привет! 👋 Я <b>Volya Mobile</b> бот.\n\n" .
        "Меню снизу 👇\n" .
        "• 👤 <b>Личный кабинет</b>\n" .
        "• 📊 <b>Статистика</b>\n\n" .
        "Чтобы привязать персонажа: в лаунчере нажми «Играть» → «Да» → открой бота.";

    $tg->sendMessage($chatId, $hello, array('reply_markup' => kb_main()));

    if ($payload !== '') handle_bind_start($tg, $chatId, $tgUserId, $tgUsernameRaw, $payload);

    http_response_code(200);
    exit;
}

if ($trim === '👤 Личный кабинет') {
    send_character_picker($tg, $chatId, $tgUserId, $tgUsernameRaw, 'cab');
    http_response_code(200);
    exit;
}

if ($trim === '📊 Статистика') {
    send_character_picker($tg, $chatId, $tgUserId, $tgUsernameRaw, 'stats');
    http_response_code(200);
    exit;
}

if ($trim === '❓ Помощь' || $trim === '/help') {
    $tg->sendMessage($chatId,
        "❓ <b>Помощь</b>\n\n" .
        "• Открой бота из лаунчера — привязка сохранится в БД.\n" .
        "• Потом зайди в «👤 Личный кабинет».\n\n" .
        "Лимит: максимум <b>" . MAX_CHARS_PER_TG . "</b> персонажа на Telegram.\n\n" .
        "Диагностика: открой <code>bot.php?debug=1</code>\n" .
        "Perf: <code>logs/perf.log</code>",
        array('reply_markup' => kb_main())
    );
    http_response_code(200);
    exit;
}

$tg->sendMessage($chatId, "Выбери пункт в меню ниже 👇", array('reply_markup' => kb_main()));
http_response_code(200);
exit;