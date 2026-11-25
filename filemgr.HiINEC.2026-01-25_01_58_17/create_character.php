<?php
require __DIR__ . '/config.php';

try {
    $name = isset($_GET['name']) ? trim((string)$_GET['name']) : '';
    $skin = isset($_GET['skin']) ? (int)$_GET['skin'] : -1;
    $sex  = isset($_GET['sex'])  ? (int)$_GET['sex']  : 0;

    $device = isset($_GET['device']) ? trim((string)$_GET['device']) : '';
    if ($device === '') $device = 'unknown';
    if (strlen($device) > 128) $device = substr($device, 0, 128);

    $tg_id = isset($_GET['telegram_id']) ? trim((string)$_GET['telegram_id']) : null;
    $tg_user = isset($_GET['tg_username']) ? trim((string)$_GET['tg_username']) : null;

        // check-only mode (no creation), returns {ok:true, exists:true|false}
    $checkOnly = isset($_GET['check']) && (string)$_GET['check'] !== '' && (int)$_GET['check'] === 1;

// validate name like Daniil_Volkov, max 24 chars
    if ($name === '' || strlen($name) > 24 || !preg_match('~^[A-Za-z0-9]{3,12}_[A-Za-z0-9]{3,12}$~', $name)) {
        json_out(['ok' => false, 'error' => 'bad_name'], 400);
    }
        if ($checkOnly) {
        $pdo = db();
        $st = $pdo->prepare('SELECT id FROM `accounts` WHERE `name` = ? LIMIT 1');
        $st->execute([$name]);
        $row = $st->fetch();
        json_out(['ok' => true, 'exists' => $row ? true : false]);
    }

if ($skin < 0 || $skin > 311) {
        json_out(['ok' => false, 'error' => 'bad_skin'], 400);
    }
    $sex = ($sex == 1) ? 1 : 0;

    $pdo = db();

    // exists?
    $st = $pdo->prepare('SELECT id, name, skin FROM `accounts` WHERE `name` = ? LIMIT 1');
    $st->execute([$name]);
    $row = $st->fetch();
    if ($row) {
        json_out(['ok' => false, 'error' => 'nick_taken', 'id' => (int)$row['id'], 'name' => $row['name'], 'skin' => (int)$row['skin']], 409);
    }

    // random password + salt
    $saltAlphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789<>:';
    $salt = rand_str(10, $saltAlphabet);
    $plainPass = bin2hex(random_bytes(8)); // 16 chars
    $hash = strtoupper(hash('sha256', $plainPass . $salt));

    $nowTs = time();
    $nowDt = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (strlen($ip) > 16) $ip = substr($ip, 0, 16);

    // Read columns and dynamically satisfy NOT NULL columns without defaults
    $cols = $pdo->query('SHOW COLUMNS FROM `accounts`')->fetchAll();
    $values = [];

    // Required / desired fields
    $values['name'] = $name;
    $values['skin'] = $skin;
    $values['sex']  = $sex;

    $values['device_id'] = $device;

    $values['tg_bound'] = 0;
    $values['password'] = $hash;
    $values['salt'] = $salt;

    // Core defaults from your example
    $values['level'] = 1;
    $values['exp'] = 0;
    $values['money'] = 1000;
    $values['bank'] = 0;

    $values['driving_lic'] = 1;
    $values['weapon_lic'] = 0;

    $values['phone_color'] = 9;
    $values['phone_balance'] = 0;

    $values['health'] = 100;

    $values['car_slots'] = 1;

    $values['companyjob_bus'] = -1;
    $values['house_type'] = -1;
    $values['house_room'] = -1;
    $values['house'] = -1;
    $values['business'] = -1;
    $values['company'] = -1;
    $values['fuel_st'] = -1;

    $values['prefix'] = '[A]';
    $values['cmdaccess'] = '000000000000000';
    $values['setting_phone'] = 'None';
    $values['setting_pin_code'] = 'None';

    $values['reg_time'] = $nowTs;
    $values['last_login'] = $nowTs;
    $values['reg_ip'] = $ip;
    $values['last_ip'] = $ip;

    $values['tg_bound_at'] = null;
    $values['tg_last_alert_at'] = null;

    if ($tg_id !== null && $tg_id !== '') $values['telegram_id'] = $tg_id;
    if ($tg_user !== null && $tg_user !== '') $values['tg_username'] = $tg_user;

    // Satisfy strict columns if needed
    foreach ($cols as $c) {
        $field = $c['Field'];
        $null = $c['Null'];
        $def  = $c['Default'];
        $extra = $c['Extra'];

        if (array_key_exists($field, $values)) continue;

        // if DB has default, let it do its job
        if ($def !== null) continue;
        if ($null === 'YES') continue;
        if (strpos($extra, 'auto_increment') !== false) continue;

        // No default and NOT NULL -> must supply something
        $values[$field] = guess_default_from_type($c['Type']);
    }

    // Build insert (only for known columns)
    $insertCols = [];
    $ph = [];
    $params = [];
    foreach ($values as $k => $v) {
        // ensure column exists
        $exists = false;
        foreach ($cols as $c) {
            if ($c['Field'] === $k) { $exists = true; break; }
        }
        if (!$exists) continue;

        $insertCols[] = '`' . str_replace('`', '', $k) . '`';
        $ph[] = '?';
        $params[] = $v;
    }

    if (empty($insertCols)) {
        json_out(['ok' => false, 'error' => 'no_columns'], 500);
    }

    $sql = 'INSERT INTO `accounts` (' . implode(',', $insertCols) . ') VALUES (' . implode(',', $ph) . ')';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $id = (int)$pdo->lastInsertId();

    json_out([
        'ok' => true,
        'id' => $id,
        'name' => $name,
        'skin' => $skin,
        // debug / optional fields (can be removed)
        'created_at' => $nowDt,
    ], 200);

} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()], 500);
}
