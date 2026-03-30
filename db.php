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
 * 時間比較全部在 SQLite 內完成（皆為 UTC），避免 PHP 本地時區造成偏差
 */
function check_rate_limit(string $ip_address, int $cooldown_seconds = 60): bool
{
    if (empty($ip_address)) return true;

    $db = get_db();
    $stmt = $db->prepare("
        SELECT COUNT(*) AS cnt FROM modifications
        WHERE ip_address = :ip
          AND created_at > datetime('now', :offset)
    ");
    $stmt->execute([
        ':ip'     => $ip_address,
        ':offset' => '-' . (int) $cooldown_seconds . ' seconds',
    ]);
    $row = $stmt->fetch();

    return ($row['cnt'] ?? 0) === 0;
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

// ===================================================
// CSRF Token（HMAC 簽章，無需 Session）
// ===================================================

function generate_csrf_token(): string
{
    $ts = time();
    $sig = hash_hmac('sha256', (string) $ts, CSRF_SECRET);
    return $ts . '.' . $sig;
}

function validate_csrf_token(string $token, int $max_age = 7200): bool
{
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) return false;

    [$ts, $sig] = $parts;
    $ts = (int) $ts;

    if (abs(time() - $ts) > $max_age) return false;

    $expected = hash_hmac('sha256', (string) $ts, CSRF_SECRET);
    return hash_equals($expected, $sig);
}
