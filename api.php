<?php

// ===================================================
// AI Canvas — 後端 API
// ===================================================
// POST /api.php  → 接收 Prompt，呼叫 Gemini，儲存結果
// GET  /api.php?since_id=N → 取得新修改紀錄

require_once __DIR__ . '/db.php';

// ---------- CORS ----------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed_origins = CORS_ALLOWED_ORIGINS;

if (in_array('*', $allowed_origins, true)) {
    header('Access-Control-Allow-Origin: *');
} elseif (in_array($origin, $allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// ---------- 路由 ----------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_submit();
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_poll();
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

// ===================================================
// POST Handler — 提交 Prompt
// ===================================================
function handle_submit(): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!check_rate_limit($ip, RATE_LIMIT_POST_COOLDOWN)) {
        http_response_code(429);
        echo json_encode(['error' => '請給 AI 一點喘息時間，每 ' . RATE_LIMIT_POST_COOLDOWN . ' 秒只能送出一條指令！']);
        return;
    }

    if (empty(GEMINI_API_KEY)) {
        http_response_code(500);
        echo json_encode(['error' => '請先在 .env 中設定你的 Gemini API Key']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $prompt = trim($input['prompt'] ?? '');

    if ($prompt === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Prompt 不能為空']);
        return;
    }

    if (mb_strlen($prompt) > 120) {
        http_response_code(400);
        echo json_encode(['error' => 'Prompt 太長了（上限 120 字）']);
        return;
    }

    $ai_response = call_gemini($prompt);

    if ($ai_response === null) {
        http_response_code(502);
        echo json_encode(['error' => 'AI 回應失敗，請稍後再試']);
        return;
    }

    $code = clean_ai_response($ai_response);

    if (!is_safe($code)) {
        http_response_code(400);
        echo json_encode(['error' => '此命令可能破壞網頁，已被拒絕']);
        return;
    }

    $id = insert_modification($prompt, $code, $ip);
    append_snapshot_css($code);

    echo json_encode([
        'success'   => true,
        'id'        => $id,
        'code_type' => 'css',
        'code'      => $code,
    ]);
}

// ===================================================
// GET Handler — 輪詢新的修改
// ===================================================
function handle_poll(): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!check_poll_rate_limit($ip, RATE_LIMIT_GET_COOLDOWN)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many requests', 'retry_after' => RATE_LIMIT_GET_COOLDOWN]);
        return;
    }

    $since_id = (int) ($_GET['since_id'] ?? 0);
    $modifications = get_modifications_since($since_id);

    echo json_encode([
        'success'       => true,
        'modifications' => $modifications,
    ]);
}

// ===================================================
// Gemini API 呼叫
// ===================================================
function call_gemini(string $user_prompt): ?string
{
    $url = GEMINI_API_URL . '?key=' . GEMINI_API_KEY;

    $payload = [
        'system_instruction' => [
            'parts' => [
                ['text' => SYSTEM_PROMPT],
            ],
        ],
        'contents' => [
            [
                'parts' => [
                    ['text' => $user_prompt],
                ],
            ],
        ],
        'generationConfig' => [
            'temperature'     => 0.9,
            'maxOutputTokens' => 300,
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || $response === false) {
        return null;
    }

    $data = json_decode($response, true);

    return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
}

// ===================================================
// 清理 AI 回應（去除 Markdown 包裝）
// ===================================================
function clean_ai_response(string $raw): string
{
    $code = $raw;

    $code = preg_replace('/^```(?:css|CSS)?\s*\n?/m', '', $code);
    $code = preg_replace('/\n?```\s*$/m', '', $code);

    return trim($code);
}

// ===================================================
// 安全性過濾
// ===================================================
function is_safe(string $code): bool
{
    $forbidden = [
        '<script',
        '</script',
        '<html',
        '<body',
        '<head',
        '<iframe',
        'javascript:',
        'expression(',
        'url(javascript',
        'display: none',
        'display:none',
        'opacity: 0',
        'opacity:0',
        'visibility: hidden',
        '#control-panel',
        '#prompt-input',
        '#submit-btn',
        'position: fixed',
        'position:fixed',
        'body:active',
        'body:hover',
        'body:focus',
        'html:active',
        'html:hover',
        'html:focus',
        ':root:active',
        ':root:hover',
    ];

    $code_lower = strtolower($code);

    foreach ($forbidden as $keyword) {
        if (str_contains($code_lower, $keyword)) {
            return false;
        }
    }

    // 含有 HTML 標籤就拒絕（CSS-only）
    if (preg_match('/<[a-z]/i', $code)) {
        return false;
    }

    return true;
}
