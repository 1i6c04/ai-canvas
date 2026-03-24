<?php

// ===================================================
// AI Canvas — SQLite 資料庫初始化
// ===================================================

require_once __DIR__ . '/config.php';

/**
 * 取得 SQLite 資料庫連線（含自動建表）
 */
function get_db(): PDO
{
    static $db = null;

    if ($db !== null) {
        return $db;
    }

    $db = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // 建立 modifications 表（如果不存在）
    $db->exec("
        CREATE TABLE IF NOT EXISTS modifications (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            prompt     TEXT NOT NULL,
            code_type  TEXT NOT NULL CHECK(code_type IN ('css', 'html')),
            code       TEXT NOT NULL,
            ip_address TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // 嘗試加入 ip_address 欄位（相容已建立的舊資料庫）
    try {
        $db->exec("ALTER TABLE modifications ADD COLUMN ip_address TEXT");
    } catch (Exception $e) {
        // 欄位已存在，忽略錯誤
    }

    return $db;
}

/**
 * 新增一筆修改紀錄
 */
function insert_modification(string $prompt, string $code_type, string $code, string $ip_address = ''): int
{
    $db = get_db();
    $stmt = $db->prepare("INSERT INTO modifications (prompt, code_type, code, ip_address) VALUES (:prompt, :code_type, :code, :ip_address)");
    $stmt->execute([
        ':prompt'     => $prompt,
        ':code_type'  => $code_type,
        ':code'       => $code,
        ':ip_address' => $ip_address,
    ]);
    return (int) $db->lastInsertId();
}

/**
 * 檢查使用者是否在一定時間內已經發送過請求
 */
function check_rate_limit(string $ip_address, int $seconds = 60): bool
{
    if (empty($ip_address)) return true; // 如果抓不到 IP，暫不阻擋

    $db = get_db();
    $stmt = $db->prepare("SELECT created_at FROM modifications WHERE ip_address = :ip_address ORDER BY id DESC LIMIT 1");
    $stmt->execute([':ip_address' => $ip_address]);
    $last_row = $stmt->fetch();

    if (!$last_row) {
        return true; // 從未發送過
    }

    $last_time = strtotime($last_row['created_at']);
    $now = time();

    // 如果距離上次發送的時間小於限制秒數，就回傳 false（不允許發送）
    return ($now - $last_time) >= $seconds;
}

/**
 * 取得指定 ID 之後的所有修改紀錄
 */
function get_modifications_since(int $since_id): array
{
    $db = get_db();
    $stmt = $db->prepare("SELECT id, prompt, code_type, code, created_at FROM modifications WHERE id > :since_id ORDER BY id ASC");
    $stmt->execute([':since_id' => $since_id]);
    return $stmt->fetchAll();
}

/**
 * 取得所有修改紀錄（頁面初始載入時用）
 */
function get_all_modifications(): array
{
    $db = get_db();
    return $db->query("SELECT id, prompt, code_type, code, created_at FROM modifications ORDER BY id ASC")->fetchAll();
}
