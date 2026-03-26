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

    $db->exec("
        CREATE TABLE IF NOT EXISTS modifications (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            prompt     TEXT NOT NULL,
            code_type  TEXT NOT NULL DEFAULT 'css',
            code       TEXT NOT NULL,
            ip_address TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS snapshots (
            id  INTEGER PRIMARY KEY,
            css TEXT NOT NULL DEFAULT ''
        )
    ");
    $db->exec("INSERT OR IGNORE INTO snapshots (id, css) VALUES (1, '')");

    $db->exec("
        CREATE TABLE IF NOT EXISTS rate_limits (
            ip_address   TEXT    PRIMARY KEY,
            last_poll_at INTEGER NOT NULL DEFAULT 0
        )
    ");

    // 相容舊 DATETIME 欄位：若欄位型別為 DATETIME 則重建資料表
    $col = $db->query("SELECT type FROM pragma_table_info('rate_limits') WHERE name='last_poll_at'")->fetch();
    if ($col && strtoupper($col['type']) !== 'INTEGER') {
        $db->exec("DROP TABLE rate_limits");
        $db->exec("
            CREATE TABLE rate_limits (
                ip_address   TEXT    PRIMARY KEY,
                last_poll_at INTEGER NOT NULL DEFAULT 0
            )
        ");
    }

    // 相容已建立的舊資料庫
    try {
        $db->exec("ALTER TABLE modifications ADD COLUMN ip_address TEXT");
    } catch (Exception $e) {
    }

    return $db;
}

/**
 * 新增一筆修改紀錄
 */
function insert_modification(string $prompt, string $code, string $ip_address = ''): int
{
    $db = get_db();
    $stmt = $db->prepare("INSERT INTO modifications (prompt, code_type, code, ip_address) VALUES (:prompt, 'css', :code, :ip_address)");
    $stmt->execute([
        ':prompt'     => $prompt,
        ':code'       => $code,
        ':ip_address' => $ip_address,
    ]);
    return (int) $db->lastInsertId();
}

/**
 * 取得所有修改的 CSS（頁面初始渲染用，每筆獨立 <style> 避免語法錯誤擴散）
 */
function get_all_modification_codes(): array
{
    $db = get_db();
    return $db->query("SELECT id, code FROM modifications ORDER BY id ASC")->fetchAll();
}

/**
 * 取得最新 modification ID
 */
function get_latest_mod_id(): int
{
    $db = get_db();
    $row = $db->query("SELECT MAX(id) as id FROM modifications")->fetch();
    return (int) ($row['id'] ?? 0);
}

/**
 * 檢查 POST 提交的 rate limit（冷卻秒數）
 */
function check_rate_limit(string $ip_address, int $cooldown_seconds = 60): bool
{
    if (empty($ip_address)) return true;

    $db = get_db();
    $stmt = $db->prepare("SELECT MAX(created_at) AS last_at FROM modifications WHERE ip_address = :ip");
    $stmt->execute([':ip' => $ip_address]);
    $row = $stmt->fetch();

    if (!empty($row['last_at']) && (time() - strtotime($row['last_at'])) < $cooldown_seconds) {
        return false;
    }

    return true;
}

/**
 * 檢查 GET 輪詢的 rate limit（每 $seconds 秒最多一次）
 * 通過後自動更新時間戳
 */
function check_poll_rate_limit(string $ip_address, int $seconds = 10): bool
{
    if (empty($ip_address)) return true;

    $db = get_db();
    $stmt = $db->prepare("SELECT last_poll_at FROM rate_limits WHERE ip_address = :ip");
    $stmt->execute([':ip' => $ip_address]);
    $row = $stmt->fetch();

    if ($row && (time() - (int) $row['last_poll_at']) < $seconds) {
        return false;
    }

    $now = time();
    $stmt = $db->prepare("
        INSERT INTO rate_limits (ip_address, last_poll_at)
        VALUES (:ip, :now)
        ON CONFLICT(ip_address) DO UPDATE SET last_poll_at = :now
    ");
    $stmt->execute([':ip' => $ip_address, ':now' => $now]);

    return true;
}

/**
 * 取得指定 ID 之後的所有修改紀錄（輪詢用）
 */
function get_modifications_since(int $since_id): array
{
    $db = get_db();
    $stmt = $db->prepare("SELECT id, prompt, code_type, code, created_at FROM modifications WHERE id > :since_id ORDER BY id ASC");
    $stmt->execute([':since_id' => $since_id]);
    return $stmt->fetchAll();
}

/**
 * 取得最近 N 筆修改紀錄（日誌顯示用）
 */
function get_recent_modifications(int $limit = 10): array
{
    $db = get_db();
    $stmt = $db->prepare("SELECT id, prompt, code_type, created_at FROM modifications ORDER BY id DESC LIMIT :limit");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return array_reverse($stmt->fetchAll());
}
