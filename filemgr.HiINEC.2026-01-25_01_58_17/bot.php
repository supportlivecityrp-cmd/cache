<?php
// Echo Cross
// Telegram: @tasjJson

/*
 * One-file bot (PHP 7.2+)
 * v10: Личный кабинет/статистика НЕ грузит все столбцы.
 *      Разделы по кнопкам (Авто/Имущество/Бизнес/Скиллы/Финансы/Основное).
 *      Без админ-доступа: каждый видит только своих персонажей (по telegram_id / tg_username).
 *
 * Диагностика:
 *  - /bot.php?debug=1
 * Логи:
 *  - logs/bot.log, logs/perf.log, logs/telegram.log (или fallback в tmp)
 */

error_reporting(E_ALL);

$DEBUG = isset($_GET['debug']) ? true : false;
ini_set('display_errors', $DEBUG ? '1' : '0');
ini_set('log_errors', '1');

// ====== CONFIG ======
define('BOT_TOKEN', '8313260270:AAHT-7hXb8LhkRtsWyuU7QCnoxe9BThJFJk');
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

// ====== PATHS ======
define('LOG_DIR', __DIR__ . '/logs');
define('CACHE_DIR', __DIR__ . '/cache');

function now_ts() { return date('Y-m-d H:i:s'); }
function ensure_dir($dir) { if (!is_dir($dir)) @mkdir($dir, 0775, true); }
function ensure_log_target() {
    ensure_dir(LOG_DIR);
    if (is_dir(LOG_DIR) && is_writable(LOG_DIR)) @ini_set('error_log', LOG_DIR . '/php_errors.log');
    else @ini_set('error_log', rtrim(sys_get_temp_dir(), '/\\') . '/volya_php_errors.log');
}
ensure_log_target();

function log_try($file, $line) {
    $ts = '[' . now_ts() . '] ' . $line . "\n";
    try {
        ensure_dir(LOG_DIR);
        if (is_dir(LOG_DIR) && is_writable(LOG_DIR)) { @file_put_contents(LOG_DIR . '/' . $file, $ts, FILE_APPEND | LOCK_EX); return; }
    } catch (Exception $e) { }
    catch (Error $e) { }

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

function safe($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function normalize_username($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    return ($raw[0] === '@') ? $raw : ('@' . $raw);
}

function is_valid_nick($nick) { return (bool)preg_match('/^[A-Za-z0-9_]{3,24}$/', (string)$nick); }

// Главное меню теперь через inline-кнопки (не "нижняя панель")
function kb_main($isAdmin = false) {
    $rows = array(
        array(
            array('text' => '👤 Личный кабинет', 'callback_data' => 'menu:cab'),
            array('text' => '📊 Статистика',     'callback_data' => 'menu:stats'),
        ),
        array(
            array('text' => 'ℹ️ Помощь', 'callback_data' => 'menu:help'),
        ),
    );
    if ($isAdmin) {
        $rows[] = array(
            array('text' => '🛡 Админ-панель', 'callback_data' => 'menu:admin'),
        );
    }
    return json_encode(array('inline_keyboard' => $rows), JSON_UNESCAPED_UNICODE);
}

function kb_remove_reply() {
    return json_encode(array('remove_keyboard' => true), JSON_UNESCAPED_UNICODE);
}

function state_path($tgUserId) {
    ensure_dir(CACHE_DIR);
    return rtrim(CACHE_DIR, '/\\') . '/state_' . (string)intval($tgUserId) . '.json';
}

function state_get($tgUserId) {
    $p = state_path($tgUserId);
    if (!is_file($p)) return array();
    $raw = @file_get_contents($p);
    if ($raw === false) return array();
    $j = json_decode($raw, true);
    return is_array($j) ? $j : array();
}

function state_set($tgUserId, $arr) {
    $p = state_path($tgUserId);
    @file_put_contents($p, json_encode((array)$arr, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function state_clear($tgUserId) {
    $p = state_path($tgUserId);
    if (is_file($p)) @unlink($p);
}

function inline_keyboard($rows) {
    return json_encode(array('inline_keyboard' => $rows), JSON_UNESCAPED_UNICODE);
}

function kb_back_to_menu() {
    return inline_keyboard(array(
        array(array('text' => '⬅️ В меню', 'callback_data' => 'menu:home')),
    ));
}

function kb_admin_panel() {
    return inline_keyboard(array(
        array(
            array('text' => '🔎 Чужая статистика', 'callback_data' => 'admin:ask_stats'),
            array('text' => '❌ Отвязать привязку', 'callback_data' => 'admin:ask_unbind'),
        ),
        array(
            array('text' => '⬅️ В меню', 'callback_data' => 'menu:home'),
        ),
    ));
}

function kb_cancel_admin() {
    return inline_keyboard(array(
        array(array('text' => '❌ Отмена', 'callback_data' => 'admin:cancel')),
    ));
}

function kb_confirm_admin_unbind() {
    return inline_keyboard(array(
        array(
            array('text' => '✅ Да, отвязать', 'callback_data' => 'admin:do_unbind'),
            array('text' => '❌ Отмена', 'callback_data' => 'admin:cancel'),
        )
    ));
}

function send_main_menu($tg, $chatId, $pdo, $cols, $tgUserId, $tgUsernameRaw) {
    $isAdmin = is_admin_tg($pdo, $cols, $tgUserId, $tgUsernameRaw);
    $uname = normalize_username($tgUsernameRaw);
    $title = "🏁 <b>Volya Mobile</b>";
    $line1 = "Привет! 👋";
    $line2 = $uname ? ("Твой Telegram: <b>" . htmlspecialchars($uname, ENT_QUOTES) . "</b>") : "";
    $line3 = "Выбери раздел кнопками ниже 👇";

    $text = $title . "\n" . $line1 . "\n";
    if ($line2) $text .= $line2 . "\n";
    $text .= "\n" . $line3;

    // Если раньше был reply-keyboard — убираем (отдельным сообщением).
    $st = state_get($tgUserId);
    if (empty($st['kb_removed'])) {
        $tg->sendMessage($chatId, "✅ Меню обновлено.", array('reply_markup' => kb_remove_reply()));
        $st['kb_removed'] = 1;
        state_set($tgUserId, $st);
    }

    $tg->sendMessage($chatId, $text, array(
        'parse_mode' => 'HTML',
        'reply_markup' => kb_main($isAdmin),
        'disable_web_page_preview' => true,
    ));
}

function send_help($tg, $chatId, $pdo, $cols, $tgUserId, $tgUsernameRaw) {
    $isAdmin = is_admin_tg($pdo, $cols, $tgUserId, $tgUsernameRaw);
    $text = "❓ <b>Помощь</b>\n\n"
        . "• <b>Личный кабинет</b> — выбрать персонажа и смотреть разделы (💰 деньги, 🚗 авто, 🏠 имущество, 📈 скилы).\n"
        . "• <b>Статистика</b> — быстрый просмотр по выбранному персонажу.\n"
        . "• Если лаунчер пишет, что доступ запрещён — значит ID устройства не совпадает с тем, что привязан к аккаунту.\n\n"
        . "Подсказка: обнови меню командой /start ✅";

    $tg->sendMessage($chatId, $text, array(
        'parse_mode' => 'HTML',
        'reply_markup' => kb_main($isAdmin),
        'disable_web_page_preview' => true,
    ));
}

function send_admin_menu($tg, $chatId) {
    $text = "🛡 <b>Админ-панель</b>\n\n"
        . "Тут можно проверить чужую статистику и при необходимости снять привязку.\n"
        . "Работает только для админов <b>13 уровня</b>.";
    $tg->sendMessage($chatId, $text, array('parse_mode' => 'HTML', 'reply_markup' => kb_admin_panel()));
}

// ====== SECTIONS (кандидатные поля; если их нет в accounts — просто не покажем) ======
function section_candidates($sec) {
    // Важно: здесь нет "admin" и других чувствительных вещей.
    switch ($sec) {
        case 'main':
            return array('name','level','exp','rep','wanted','vip','skin','sex','age','hours','score','job','rank','fraction');
        case 'finance':
            return array('money','bank','deposit','donate','coins','rub','bp','dc','payday','salary');
        case 'auto':
            return array('car','car1','car2','car3','veh','veh1','veh2','veh3','vehicle','vehicle1','vehicle2','vehicle3','car_model','veh_model','fuel','mileage','odometer','parking');
        case 'property':
            return array('house','house_id','home','flat','apartment','apart','garage','garage_id','property','property_id','room','rooms','warehouse');
        case 'business':
            return array('biz','biz1','biz2','biz3','business','business1','business2','biz_id','biz_balance','biz_profit','biz_level','biz_status');
        case 'skills':
            return array('skill','skills','skill_drive','skill_fly','skill_boat','skill_bike','sk_drive','sk_fly','sk_boat','sk_bike','gun','weapon','weapons','gun_skill');
        default:
            return array();
    }
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
    if (defined('PDO::ATTR_TIMEOUT')) $opts[PDO::ATTR_TIMEOUT] = 3;
    if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) $opts[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES " . DB_CHARSET;
    if (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) $opts[PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = 3;

    return new PDO($dsn, DB_USER, DB_PASS, $opts);
}

function schema_cache_path() { ensure_dir(CACHE_DIR); return CACHE_DIR . '/schema_accounts.json'; }

function account_columns_uncached($pdo) {
    $cols = array();
    $st = $pdo->query("SHOW COLUMNS FROM `accounts`");
    foreach ($st->fetchAll() as $r) {
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
    } catch (Exception $e) { }
    catch (Error $e) { }

    $t = t0();
    $cols = account_columns_uncached($pdo);
    log_try('perf.log', 'schema_refresh_ms=' . ms($t, t0()) . ' cols=' . count($cols));

    try { @file_put_contents($p, json_encode(array('ts' => $now, 'cols' => $cols), JSON_UNESCAPED_UNICODE), LOCK_EX); }
    catch (Exception $e) { }
    catch (Error $e) { }

    return $cols;
}

function has_col($cols, $name) { return isset($cols[$name]); }

function fetch_account_id_by_nick($pdo, $nick) {
    $st = $pdo->prepare("SELECT `id` FROM `accounts` WHERE `name`=? LIMIT 1");
    $st->execute(array((string)$nick));
    $r = $st->fetch();
    return $r ? (int)$r['id'] : 0;
}

function is_admin_tg($pdo, $cols, $tgUserId, $tgUsernameRaw) {
    if (!has_col($cols, 'admin')) return false;
    $tgUserId = (int)$tgUserId;
    $tgUsername = normalize_username($tgUsernameRaw);

    $where = array();
    $args = array();

    if (has_col($cols, 'telegram_id')) {
        $where[] = "`telegram_id`=?";
        $args[] = $tgUserId;
    }
    if (has_col($cols, 'tg_username') && $tgUsername !== '') {
        $where[] = "`tg_username`=?";
        $args[] = $tgUsername;
    }

    if (!$where) return false;

    $sql = "SELECT MAX(`admin`) AS mx FROM `accounts` WHERE (" . implode(' OR ', $where) . ")";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $row = $st->fetch();
    $mx = $row && isset($row['mx']) ? (int)$row['mx'] : 0;
    return $mx >= 13;
}

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
    if (!has_col($cols, 'telegram_id')) return 0;
    $st = $pdo->prepare("SELECT COUNT(*) FROM `accounts` WHERE `telegram_id`=?");
    $st->execute(array((int)$tgUserId));
    return (int)$st->fetchColumn();
}

function bind_account($pdo, $cols, $rowId, $tgUserId, $tgUsername, $deviceId) {
    $sets = array(); $vals = array();
    if (has_col($cols, 'telegram_id')) { $sets[] = "`telegram_id`=?"; $vals[] = (int)$tgUserId; }
    if (has_col($cols, 'tg_username')) { $sets[] = "`tg_username`=?"; $vals[] = (string)$tgUsername; }
    if (has_col($cols, 'device_id')) { $sets[] = "`device_id`=?"; $vals[] = (string)$deviceId; }
    if (has_col($cols, 'tg_verified')) $sets[] = "`tg_verified`=1";
    if (has_col($cols, 'tg_bound')) $sets[] = "`tg_bound`=1";
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
            $resp = curl_exec($ch);
            $err = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            log_try('perf.log', 'tg_http_ms=' . ms($t, t0()) . ' code=' . $code . ' err=' . ($err ? $err : ''));
            if ($resp === false) log_try('telegram.log', 'CURL_FAIL url=' . $url . ' err=' . $err);
            return $resp;
        }

        $opts = array('http' => array(
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data),
            'timeout' => TG_TIMEOUT,
        ));
        $resp = @file_get_contents($url, false, stream_context_create($opts));
        log_try('perf.log', 'tg_http_ms=' . ms($t, t0()) . ' code=' . ($resp === false ? 0 : 200) . ' err=' . ($resp === false ? 'fget_fail' : ''));
        return $resp;
    }

    public function call($method, $data) {
        $url = "https://api.telegram.org/bot" . $this->token . "/" . $method;
        $resp = $this->http_post($url, $data);
        if ($resp === false) { log_try('telegram.log', 'TG_API_FAIL method=' . $method); return array('ok' => false); }

        $short = $resp;
        if (strlen($short) > 900) $short = substr($short, 0, 900) . '...';
        log_try('telegram.log', 'TG_API method=' . $method . ' resp=' . $short);

        $j = json_decode($resp, true);
        if (!is_array($j)) return array('ok' => false);
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

// ====== CABINET HELPERS ======
function owner_ok($cols, $row, $tgUserId, $tgUsernameRaw) {
    $tgU = normalize_username($tgUsernameRaw);
    if (has_col($cols, 'telegram_id') && isset($row['telegram_id']) && $row['telegram_id'] !== null && (int)$row['telegram_id'] === (int)$tgUserId) return true;
    if (has_col($cols, 'tg_username') && $tgU !== '' && isset($row['tg_username']) && (string)$row['tg_username'] === $tgU) return true;
    return false;
}

function unique_fields($fields) {
    $out = array(); $seen = array();
    foreach ($fields as $f) {
        $f = (string)$f;
        if ($f === '') continue;
        if (isset($seen[$f])) continue;
        $seen[$f] = true;
        $out[] = $f;
    }
    return $out;
}

function build_select_fields($cols, $fields) {
    $base = array('id','name');
    if (has_col($cols,'telegram_id')) $base[] = 'telegram_id';
    if (has_col($cols,'tg_username')) $base[] = 'tg_username';
    if (has_col($cols,'tg_bound')) $base[] = 'tg_bound';
    if (has_col($cols,'tg_verified')) $base[] = 'tg_verified';

    $want = array_merge($base, $fields);
    $want = unique_fields($want);

    $ok = array();
    foreach ($want as $f) if (has_col($cols, $f)) $ok[] = $f;
    return $ok;
}

function fetch_account_fields($pdo, $cols, $id, $fields) {
    $sel = build_select_fields($cols, $fields);
    if (!$sel) return null;

    $sql = "SELECT `" . implode("`,`", $sel) . "` FROM `accounts` WHERE `id`=? LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute(array((int)$id));
    return $st->fetch();
}

function render_section_text($row, $sectionTitle) {
    $nick = (string)(isset($row['name']) ? $row['name'] : '');

    $lines = array();
    $lines[] = "👤 <b>Личный кабинет</b>";
    $lines[] = "🎭 Персонаж: <code>" . safe($nick) . "</code>";
    $lines[] = "📌 Раздел: <b>" . safe($sectionTitle) . "</b>";
    $lines[] = "────────────────────";

    $count = 0;
    foreach ($row as $k => $v) {
        if ($k === 'id' || $k === 'name' || $k === 'telegram_id' || $k === 'tg_username') continue;
        if ($v === null || $v === '') continue;
        if ($k === 'tg_bound' || $k === 'tg_verified') continue; // эти флаги в UI не показываем

        $count++;
        $lines[] = "• " . safe($k) . ": <code>" . safe($v) . "</code>";
        if ($count >= 30) break; // защита от спама, даже если поле вдруг большое
    }

    if ($count === 0) $lines[] = "Нет данных в этом разделе.";

    return implode("\n", $lines);
}

function cabinet_buttons($id, $active) {
    // active: main/finance/auto/property/business/skills
    $def = array(
        'main' => '⭐ Основное',
        'finance' => '💰 Финансы',
        'auto' => '🚘 Авто',
        'property' => '🏠 Имущество',
        'business' => '🏪 Бизнес',
        'skills' => '🎯 Скиллы',
    );

    $row1 = array(
        array('text' => ($active === 'main' ? '✅ ' : '') . $def['main'], 'callback_data' => "sec:" . $id . ":main"),
        array('text' => ($active === 'finance' ? '✅ ' : '') . $def['finance'], 'callback_data' => "sec:" . $id . ":finance"),
    );
    $row2 = array(
        array('text' => ($active === 'auto' ? '✅ ' : '') . $def['auto'], 'callback_data' => "sec:" . $id . ":auto"),
        array('text' => ($active === 'property' ? '✅ ' : '') . $def['property'], 'callback_data' => "sec:" . $id . ":property"),
    );
    $row3 = array(
        array('text' => ($active === 'business' ? '✅ ' : '') . $def['business'], 'callback_data' => "sec:" . $id . ":business"),
        array('text' => ($active === 'skills' ? '✅ ' : '') . $def['skills'], 'callback_data' => "sec:" . $id . ":skills"),
    );
    $row4 = array(
        array('text' => '🔁 Выбрать персонажа', 'callback_data' => 'cab_back'),
        array('text' => '❌ Отвязать', 'callback_data' => "unbind_ask:" . $id),
    );

    return inline_keyboard(array($row1, $row2, $row3, $row4));
}

function stats_buttons($id, $active) {
    // Для статистики используем те же разделы, но без кнопки "отвязать"
    $row1 = array(
        array('text' => ($active === 'main' ? '✅ ' : '') . '⭐ Основное', 'callback_data' => "stsec:" . $id . ":main"),
        array('text' => ($active === 'finance' ? '✅ ' : '') . '💰 Финансы', 'callback_data' => "stsec:" . $id . ":finance"),
    );
    $row2 = array(
        array('text' => ($active === 'auto' ? '✅ ' : '') . '🚘 Авто', 'callback_data' => "stsec:" . $id . ":auto"),
        array('text' => ($active === 'property' ? '✅ ' : '') . '🏠 Имущество', 'callback_data' => "stsec:" . $id . ":property"),
    );
    $row3 = array(
        array('text' => ($active === 'business' ? '✅ ' : '') . '🏪 Бизнес', 'callback_data' => "stsec:" . $id . ":business"),
        array('text' => ($active === 'skills' ? '✅ ' : '') . '🎯 Скиллы', 'callback_data' => "stsec:" . $id . ":skills"),
    );
    $row4 = array(
        array('text' => '🔁 Выбрать персонажа', 'callback_data' => 'st_back'),
    );

    return inline_keyboard(array($row1, $row2, $row3, $row4));
}

function pick_accounts_for_user($pdo, $cols, $tgUserId, $tgUsernameRaw) {
    $tgUsername = normalize_username($tgUsernameRaw);

    $fields = array('id','name');
    if (has_col($cols,'telegram_id')) $fields[] = 'telegram_id';
    if (has_col($cols,'tg_username')) $fields[] = 'tg_username';

    $select = "`" . implode("`,`", $fields) . "`";

    if (has_col($cols, 'telegram_id') && has_col($cols, 'tg_username') && $tgUsername !== '') {
        $st = $pdo->prepare("SELECT $select FROM `accounts` WHERE `telegram_id`=? OR `tg_username`=? ORDER BY `id` ASC LIMIT 50");
        $st->execute(array((int)$tgUserId, $tgUsername));
        return $st->fetchAll();
    }
    if (has_col($cols, 'telegram_id')) {
        $st = $pdo->prepare("SELECT $select FROM `accounts` WHERE `telegram_id`=? ORDER BY `id` ASC LIMIT 50");
        $st->execute(array((int)$tgUserId));
        return $st->fetchAll();
    }
    return array();
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

        $rows = array();
        foreach ($accs as $a) {
            $id = (int)(isset($a['id']) ? $a['id'] : 0);
            $name = (string)(isset($a['name']) ? $a['name'] : '');
            if ($id <= 0 || $name === '') continue;

            $rows[] = array(array(
                'text' => '🎭 ' . $name,
                'callback_data' => ($mode === 'stats') ? ("stpick:" . $id) : ("cabpick:" . $id),
            ));
            if (count($rows) >= 12) break;
        }

        $title = ($mode === 'stats')
            ? "📊 <b>Статистика</b>\n\nВыбери персонажа:"
            : "👤 <b>Личный кабинет</b>\n\nВыбери персонажа:";

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

function cabinet_open($tg, $chatId, $msgId, $tgUserId, $tgUsernameRaw, $id, $sec, $isStats) {
    $tAll = t0();
    try {
        $pdo = db();
        $cols = account_columns($pdo);

        $fields = section_candidates($sec);
        $row = fetch_account_fields($pdo, $cols, $id, $fields);
        if (!$row) {
            $tg->sendMessage($chatId, "❌ Не найдено.", array('reply_markup' => kb_main()));
            log_try('perf.log', 'cab_total_ms=' . ms($tAll, t0()));
            return;
        }

        if (!owner_ok($cols, $row, $tgUserId, $tgUsernameRaw)) {
            if ($msgId > 0) $tg->editMessageText($chatId, $msgId, "🚫 Нет доступа.", array('reply_markup' => kb_main()));
            else $tg->sendMessage($chatId, "🚫 Нет доступа.", array('reply_markup' => kb_main()));
            log_try('perf.log', 'cab_total_ms=' . ms($tAll, t0()));
            return;
        }

        $titleMap = array(
            'main' => 'Основное',
            'finance' => 'Финансы',
            'auto' => 'Авто',
            'property' => 'Имущество',
            'business' => 'Бизнес',
            'skills' => 'Скиллы',
        );
        $secTitle = isset($titleMap[$sec]) ? $titleMap[$sec] : 'Раздел';

        $text = render_section_text($row, $secTitle);
        $replyMarkup = $isStats ? stats_buttons($id, $sec) : cabinet_buttons($id, $sec);

        if ($msgId > 0) $tg->editMessageText($chatId, $msgId, $text, array('reply_markup' => $replyMarkup));
        else $tg->sendMessage($chatId, $text, array('reply_markup' => $replyMarkup));

        log_try('perf.log', 'cab_total_ms=' . ms($tAll, t0()));

    } catch (Exception $e) {
        $tg->sendMessage($chatId, "❌ Ошибка БД: <code>" . safe($e->getMessage()) . "</code>", array('reply_markup' => kb_main()));
        log_try('bot.log', 'CAB_ERROR ' . $e->getMessage());
        log_try('perf.log', 'cab_total_ms=' . ms($tAll, t0()));
    } catch (Error $e) {
        $tg->sendMessage($chatId, "❌ Ошибка: <code>" . safe($e->getMessage()) . "</code>", array('reply_markup' => kb_main()));
        log_try('bot.log', 'CAB_ERR ' . $e->getMessage());
        log_try('perf.log', 'cab_total_ms=' . ms($tAll, t0()));
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

        $st = $pdo->prepare("SELECT `id`,`name`" . (has_col($cols,'telegram_id') ? ",`telegram_id`" : "") . " FROM `accounts` WHERE `name`=? LIMIT 1");
        $st->execute(array($nick));
        $row = $st->fetch();

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

        bind_account($pdo, $cols, $id, $tgUserId, $tgUsername, $deviceId);

        $tg->sendMessage($chatId,
            "✅ <b>Принято!</b> Привязка сохранена 🔐\n\n" .
            "🎭 Ник: <code>" . safe($nick) . "</code>\n" .
            "👤 Telegram: <code>" . safe($tgUsername !== '' ? $tgUsername : 'unknown') . "</code>\n" .
            "📱 Device ID: <code>" . safe($deviceId) . "</code>\n\n" .
            "🧩 Устройство:\n" .
            "• Модель: <code>" . safe($model) . "</code>\n" .
            "• SDK: <code>" . safe($sdk) . "</code>\n" .
            "• Android: <code>" . safe($android) . "</code>\n\n" .
            "Открывай «👤 Личный кабинет» 👇",
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

// ====== CALLBACKS ======
function callback_handle($tg, $cb) {
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

    // back buttons
    if ($data === 'cab_back') {
        $tg->answerCallbackQuery($cbId, "Выбор персонажа");
        send_character_picker($tg, $chatId, $tgUserId, $tgUsernameRaw, 'cab');
        return true;
    }
    if ($data === 'st_back') {
        $tg->answerCallbackQuery($cbId, "Выбор персонажа");
        send_character_picker($tg, $chatId, $tgUserId, $tgUsernameRaw, 'stats');
        return true;
    }

    // main menu (inline)
    if ($data === 'menu:home') {
        try {
            $pdo = db();
            $cols = account_columns($pdo);
            $tg->answerCallbackQuery($cbId, "Меню");
            send_main_menu($tg, $chatId, $pdo, $cols, $tgUserId, $tgUsernameRaw);
            return true;
        } catch (Exception $e) {
            $tg->answerCallbackQuery($cbId, "Ошибка");
            return true;
        }
    }
    if ($data === 'menu:cab') {
        $tg->answerCallbackQuery($cbId, "Личный кабинет");
        send_character_picker($tg, $chatId, $tgUserId, $tgUsernameRaw, 'cab');
        return true;
    }
    if ($data === 'menu:stats') {
        $tg->answerCallbackQuery($cbId, "Статистика");
        send_character_picker($tg, $chatId, $tgUserId, $tgUsernameRaw, 'stats');
        return true;
    }
    if ($data === 'menu:help') {
        try {
            $pdo = db();
            $cols = account_columns($pdo);
            $tg->answerCallbackQuery($cbId, "Помощь");
            send_help($tg, $chatId, $pdo, $cols, $tgUserId, $tgUsernameRaw);
            return true;
        } catch (Exception $e) {
            $tg->answerCallbackQuery($cbId, "Ошибка");
            return true;
        }
    }
    if ($data === 'menu:admin') {
        try {
            $pdo = db();
            $cols = account_columns($pdo);
            if (!is_admin_tg($pdo, $cols, $tgUserId, $tgUsernameRaw)) {
                $tg->answerCallbackQuery($cbId, "Нет доступа");
                return true;
            }
            $tg->answerCallbackQuery($cbId, "Админ-панель");
            send_admin_menu($tg, $chatId, $pdo, $cols, $tgUserId, $tgUsernameRaw);
            return true;
        } catch (Exception $e) {
            $tg->answerCallbackQuery($cbId, "Ошибка");
            return true;
        }
    }

    // admin flow
    if ($data === 'admin:ask_stats' || $data === 'admin:ask_unbind') {
        try {
            $pdo = db();
            $cols = account_columns($pdo);
            if (!is_admin_tg($pdo, $cols, $tgUserId, $tgUsernameRaw)) {
                $tg->answerCallbackQuery($cbId, "Нет доступа");
                return true;
            }
            $mode = ($data === 'admin:ask_stats') ? 'admin_stats' : 'admin_unbind';
            state_set($tgUserId, array('mode' => $mode));
            $tg->answerCallbackQuery($cbId, "Ок");
            $hint = ($mode === 'admin_stats')
                ? "🛡 <b>Админ-панель</b>\n\nПришли <b>ник персонажа</b>, чтобы посмотреть статистику.\nПример: <code>Echo_Cross</code>"
                : "🛡 <b>Админ-панель</b>\n\nПришли <b>ник персонажа</b>, чтобы <b>отвязать</b> его от Telegram/устройства.\nПример: <code>Echo_Cross</code>";
            $rows = array(
                array(array('text' => '↩️ Отмена', 'callback_data' => 'admin:cancel')),
            );
            $tg->sendMessage($chatId, $hint, array('parse_mode' => 'HTML', 'reply_markup' => inline_keyboard($rows)));
            return true;
        } catch (Exception $e) {
            $tg->answerCallbackQuery($cbId, "Ошибка");
            return true;
        }
    }

    if ($data === 'admin:cancel') {
        state_clear($tgUserId);
        try {
            $pdo = db();
            $cols = account_columns($pdo);
            $tg->answerCallbackQuery($cbId, "Отмена");
            send_main_menu($tg, $chatId, $pdo, $cols, $tgUserId, $tgUsernameRaw);
            return true;
        } catch (Exception $e) {
            $tg->answerCallbackQuery($cbId, "Отмена");
            return true;
        }
    }

    if ($data === 'admin:do_unbind') {
        try {
            $pdo = db();
            $cols = account_columns($pdo);
            if (!is_admin_tg($pdo, $cols, $tgUserId, $tgUsernameRaw)) {
                $tg->answerCallbackQuery($cbId, "Нет доступа");
                return true;
            }
            $st = state_get($tgUserId);
            $id = isset($st['target_id']) ? (int)$st['target_id'] : 0;
            $nick = isset($st['target_nick']) ? (string)$st['target_nick'] : '';
            if ($id <= 0) {
                $tg->answerCallbackQuery($cbId, "Не выбран персонаж");
                return true;
            }
            if (!has_col($cols, 'is_bound')) {
                $tg->answerCallbackQuery($cbId, "Нет колонки is_bound");
                return true;
            }
            // снимаем привязку
            $set = array('is_bound' => 0);
            if (has_col($cols, 'device_id')) $set['device_id'] = '';
            if (has_col($cols, 'telegram_id')) $set['telegram_id'] = 0;
            if (has_col($cols, 'tg_username')) $set['tg_username'] = '';
            $parts = array();
            $params = array();
            foreach ($set as $k => $v) {
                $parts[] = "`" . $k . "`=?";
                $params[] = $v;
            }
            $sql = "UPDATE `accounts` SET " . implode(',', $parts) . " WHERE `id`=? LIMIT 1";
            $params[] = $id;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            state_clear($tgUserId);
            $tg->answerCallbackQuery($cbId, "Готово");
            $text = "✅ Отвязано!\n🎭 <code>" . safe($nick) . "</code>";
            $tg->sendMessage($chatId, $text, array('parse_mode' => 'HTML', 'reply_markup' => kb_back_to_menu()));
            return true;
        } catch (Exception $e) {
            $tg->answerCallbackQuery($cbId, "Ошибка");
            return true;
        }
    }

    // open cabinet/stat
    if (strpos($data, 'cabpick:') === 0) {
        $id = (int)substr($data, 8);
        $tg->answerCallbackQuery($cbId, "Открываю…");
        cabinet_open($tg, $chatId, $msgId, $tgUserId, $tgUsernameRaw, $id, 'main', false);
        return true;
    }
    if (strpos($data, 'stpick:') === 0) {
        $id = (int)substr($data, 7);
        $tg->answerCallbackQuery($cbId, "Открываю…");
        cabinet_open($tg, $chatId, $msgId, $tgUserId, $tgUsernameRaw, $id, 'main', true);
        return true;
    }

    // section buttons
    if (strpos($data, 'sec:') === 0) {
        $parts = explode(':', $data, 3);
        if (count($parts) === 3) {
            $id = (int)$parts[1];
            $sec = (string)$parts[2];
            $tg->answerCallbackQuery($cbId, "Загружаю…");
            cabinet_open($tg, $chatId, $msgId, $tgUserId, $tgUsernameRaw, $id, $sec, false);
            return true;
        }
    }

    if (strpos($data, 'stsec:') === 0) {
        $parts = explode(':', $data, 3);
        if (count($parts) === 3) {
            $id = (int)$parts[1];
            $sec = (string)$parts[2];
            $tg->answerCallbackQuery($cbId, "Загружаю…");
            cabinet_open($tg, $chatId, $msgId, $tgUserId, $tgUsernameRaw, $id, $sec, true);
            return true;
        }
    }

    // unbind flow
    if (strpos($data, 'unbind_ask:') === 0) {
        $id = (int)substr($data, 11);
        try {
            $pdo = db();
            $cols = account_columns($pdo);
            $row = fetch_account_fields($pdo, $cols, $id, array('name'));
            if (!$row) { $tg->answerCallbackQuery($cbId, "Не найдено"); return true; }
            if (!owner_ok($cols, $row, $tgUserId, $tgUsernameRaw)) { $tg->answerCallbackQuery($cbId, "Нет доступа"); return true; }

            $nick = (string)(isset($row['name']) ? $row['name'] : '');
            $text = "⚠️ <b>Подтверждение</b>\n\nОтвязать персонажа?\n🎭 <code>" . safe($nick) . "</code>";
            $rows = array(
                array(
                    array('text' => '✅ Да, отвязать', 'callback_data' => "unbind_do:" . $id),
                    array('text' => '↩️ Отмена', 'callback_data' => "sec:" . $id . ":main"),
                )
            );
            $tg->answerCallbackQuery($cbId, "Подтверди");
            $tg->editMessageText($chatId, $msgId, $text, array('reply_markup' => inline_keyboard($rows)));
            return true;
        } catch (Exception $e) {
            $tg->answerCallbackQuery($cbId, "Ошибка");
            log_try('bot.log', 'UNBIND_ASK_ERR ' . $e->getMessage());
            return true;
        } catch (Error $e) {
            $tg->answerCallbackQuery($cbId, "Ошибка");
            log_try('bot.log', 'UNBIND_ASK_E ' . $e->getMessage());
            return true;
        }
    }

    if (strpos($data, 'unbind_do:') === 0) {
        $id = (int)substr($data, 10);
        try {
            $pdo = db();
            $cols = account_columns($pdo);
            $row = fetch_account_fields($pdo, $cols, $id, array('name'));
            if (!$row) { $tg->answerCallbackQuery($cbId, "Не найдено"); return true; }
            if (!owner_ok($cols, $row, $tgUserId, $tgUsernameRaw)) { $tg->answerCallbackQuery($cbId, "Нет доступа"); return true; }

            unbind_account($pdo, $cols, $id);
            log_try('bot.log', "UNBIND_OK tg=" . $tgUserId . " id=" . $id);

            $tg->answerCallbackQuery($cbId, "Отвязано ✅");
            send_character_picker($tg, $chatId, $tgUserId, $tgUsernameRaw, 'cab');
            return true;

        } catch (Exception $e) {
            $tg->answerCallbackQuery($cbId, "Ошибка");
            log_try('bot.log', 'UNBIND_DO_ERR ' . $e->getMessage());
            return true;
        } catch (Error $e) {
            $tg->answerCallbackQuery($cbId, "Ошибка");
            log_try('bot.log', 'UNBIND_DO_E ' . $e->getMessage());
            return true;
        }
    }

    return false;
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

// webhook
$raw = @file_get_contents('php://input');
if ($raw === false || $raw === '') { log_try('bot.log', 'EMPTY_INPUT'); http_response_code(200); exit; }

$update = json_decode($raw, true);
if (!is_array($update)) { log_try('bot.log', 'BAD_JSON len=' . strlen($raw)); http_response_code(200); exit; }

$tg = new TgApi(BOT_TOKEN);

if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    if (callback_handle($tg, $cb)) { http_response_code(200); exit; }
    $tg->answerCallbackQuery((string)(isset($cb['id']) ? $cb['id'] : ''), "Ок");
    http_response_code(200);
    exit;
}

$msg = null;
if (isset($update['message']) && is_array($update['message'])) $msg = $update['message'];
else if (isset($update['edited_message']) && is_array($update['edited_message'])) $msg = $update['edited_message'];

if (!is_array($msg)) { log_try('bot.log', 'NO_MESSAGE'); http_response_code(200); exit; }

$chatId = (int)(isset($msg['chat']['id']) ? $msg['chat']['id'] : 0);
$text = (string)(isset($msg['text']) ? $msg['text'] : '');
$from = isset($msg['from']) ? $msg['from'] : array();
$tgUserId = (int)(isset($from['id']) ? $from['id'] : 0);
$tgUsernameRaw = (string)(isset($from['username']) ? $from['username'] : '');
$trim = trim($text);

log_try('bot.log', "UPDATE chat=" . $chatId . " tg=" . $tgUserId . " uname=" . $tgUsernameRaw . " text=" . $trim);

// Админ: ожидание ввода ника
try {
    $pdo = db();
    $cols = account_columns($pdo);
    $st = state_get($tgUserId);
    $isAdmin = is_admin_tg($pdo, $cols, $tgUserId, $tgUsernameRaw);

    if ($isAdmin && isset($st['mode']) && $trim !== '' && strpos($trim, '/') !== 0) {
        if ($st['mode'] === 'admin_stats') {
            if (!is_valid_nick($trim)) {
                $tg->sendMessage($chatId, "❌ Ник некорректен. Пример: <code>Echo_Cross</code>", array('parse_mode' => 'HTML', 'reply_markup' => kb_back_to_menu()));
                http_response_code(200); exit;
            }
            state_clear($tgUserId);
            $id = fetch_account_id_by_nick($pdo, $trim);
            if ($id <= 0) {
                $tg->sendMessage($chatId, "😕 Персонаж <b>" . htmlspecialchars($trim) . "</b> не найден в базе.", array('parse_mode' => 'HTML', 'reply_markup' => kb_back_to_menu()));
                http_response_code(200); exit;
            }
            show_account_stats($tg, $chatId, $pdo, $cols, $id, 0, 'stats');
            http_response_code(200); exit;
        }

        if ($st['mode'] === 'admin_unbind') {
            if (!is_valid_nick($trim)) {
                $tg->sendMessage($chatId, "❌ Ник некорректен. Пример: <code>Echo_Cross</code>", array('parse_mode' => 'HTML', 'reply_markup' => kb_back_to_menu()));
                http_response_code(200); exit;
            }
            $id = fetch_account_id_by_nick($pdo, $trim);
            if ($id <= 0) {
                state_clear($tgUserId);
                $tg->sendMessage($chatId, "😕 Персонаж <b>" . htmlspecialchars($trim) . "</b> не найден в базе.", array('parse_mode' => 'HTML', 'reply_markup' => kb_back_to_menu()));
                http_response_code(200); exit;
            }
            state_set($tgUserId, array('mode' => 'admin_unbind_confirm', 'id' => $id));
            $tg->sendMessage($chatId,
                "⚠️ Ты собираешься <b>отвязать</b> персонажа <b>" . htmlspecialchars($trim) . "</b>.\n\n" .
                "Подтвердить действие?",
                array('parse_mode' => 'HTML', 'reply_markup' => kb_confirm_admin_unbind())
            );
            http_response_code(200); exit;
        }
    }

    if (strpos($trim, '/start') === 0) {
        $parts = explode(' ', $trim, 2);
        $payload = (isset($parts[1]) ? trim($parts[1]) : '');

        // убираем старую нижнюю клавиатуру (если осталась)
        $tg->sendMessage($chatId, "✅ Меню обновлено.", array('reply_markup' => kb_remove_reply()));
        send_main_menu($tg, $chatId, $pdo, $cols, $tgUserId, $tgUsernameRaw);

        if ($payload !== '') handle_bind_start($tg, $chatId, $tgUserId, $tgUsernameRaw, $payload);

        http_response_code(200);
        exit;
    }

    // Совместимость: если у кого-то остались старые кнопки (reply keyboard)
    if ($trim === '👤 Личный кабинет') { send_character_picker($tg, $chatId, $tgUserId, $tgUsernameRaw, 'cab'); http_response_code(200); exit; }
    if ($trim === '📊 Статистика') { send_character_picker($tg, $chatId, $tgUserId, $tgUsernameRaw, 'stats'); http_response_code(200); exit; }
    if ($trim === '❓ Помощь' || $trim === '/help') { send_help($tg, $chatId, $pdo, $cols, $tgUserId, $tgUsernameRaw); http_response_code(200); exit; }

    if ($trim === '/admin' || $trim === '🛡 Админ-панель') {
        if (!is_admin_tg($pdo, $cols, $tgUserId, $tgUsernameRaw)) {
            $tg->sendMessage($chatId, "⛔️ Доступ закрыт.", array('reply_markup' => kb_main(false), 'parse_mode' => 'HTML'));
        } else {
            $tg->sendMessage($chatId, "🛡 <b>Админ-панель</b>\n\nВыбери действие:", array('reply_markup' => kb_admin_panel(), 'parse_mode' => 'HTML'));
        }
        http_response_code(200);
        exit;
    }

    // По умолчанию — показать меню
    send_main_menu($tg, $chatId, $pdo, $cols, $tgUserId, $tgUsernameRaw);
} catch (Exception $e) {
    log_try('bot.log', 'MAIN_ERROR ' . $e->getMessage());
    $tg->sendMessage($chatId, "⚠️ Внутренняя ошибка. Попробуй ещё раз.\n\nЕсли не поможет — открой <code>bot.php?debug=1</code>", array('parse_mode' => 'HTML'));
}
http_response_code(200);
exit;