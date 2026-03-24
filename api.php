<?php

// ===================================================
// AI Canvas — 後端 API
// ===================================================
// POST /api.php  → 接收 Prompt，呼叫 Gemini，儲存結果
// GET  /api.php?since_id=N → 取得新修改紀錄

require_once __DIR__ . '/db.php';

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
    // 0. 檢查發送頻率限制（每分鐘 1 次）
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!check_rate_limit($ip, 60)) {
        http_response_code(429);
        echo json_encode(['error' => '請給 AI 一點喘息時間，每分鐘只能送出一條指令！']);
        return;
    }

    // 1. 驗證 API Key 有沒有設定
    if (empty(GEMINI_API_KEY)) {
        http_response_code(500);
        echo json_encode(['error' => '請先在 config.php 中設定你的 Gemini API Key']);
        return;
    }

    // 2. 讀取 input
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

    // 3. 呼叫 Gemini API
    $ai_response = call_gemini($prompt);

    if ($ai_response === null) {
        http_response_code(502);
        echo json_encode(['error' => 'AI 回應失敗，請稍後再試']);
        return;
    }

    // 4. 解析並清理回應
    $code = clean_ai_response($ai_response);

    // 5. 判斷是 CSS 還是 HTML
    $code_type = detect_code_type($code);

    // 6. 安全性過濾
    if (!is_safe($code)) {
        http_response_code(400);
        echo json_encode(['error' => '偵測到不安全的內容，已被拒絕']);
        return;
    }

    // 7. 儲存到資料庫
    $id = insert_modification($prompt, $code_type, $code, $ip);

    // 8. 回應
    echo json_encode([
        'success'   => true,
        'id'        => $id,
        'code_type' => $code_type,
        'code'      => $code,
    ]);
}

// ===================================================
// GET Handler — 輪詢新的修改
// ===================================================
function handle_poll(): void
{
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

    // 從 Gemini response 中取出文字
    return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
}

// ===================================================
// 清理 AI 回應（去除 Markdown 包裝）
// ===================================================
function clean_ai_response(string $raw): string
{
    $code = $raw;

    // 移除 ```css ... ``` 或 ```html ... ``` 的包裝
    $code = preg_replace('/^```(?:css|html|CSS|HTML)?\s*\n?/m', '', $code);
    $code = preg_replace('/\n?```\s*$/m', '', $code);

    return trim($code);
}

// ===================================================
// 判斷程式碼是 CSS 還是 HTML
// ===================================================
function detect_code_type(string $code): string
{
    // 如果有我們在 System Prompt 中要求的標記
    if (str_starts_with($code, '/* CSS */')) {
        return 'css';
    }
    if (str_starts_with($code, '<!-- HTML -->')) {
        return 'html';
    }

    // 啟發式判斷：HTML 通常以 < 開頭，CSS 通常以選擇器開頭
    // 如果包含 HTML 標籤特徵
    if (preg_match('/<[a-z][\s\S]*>/i', $code)) {
        // 但如果也包含 CSS 選擇器特徵（{ ... }），則判斷為 CSS
        if (preg_match('/[a-z#.]\s*\{[^}]+\}/i', $code)) {
            return 'css';
        }
        return 'html';
    }

    // 包含 CSS 選擇器特徵
    if (preg_match('/[a-z#.]\s*\{/i', $code)) {
        return 'css';
    }

    // 預設回傳 CSS（比 HTML 更安全）
    return 'css';
}

// ===================================================
// 安全性過濾（禁止 JavaScript 注入）
// ===================================================
function is_safe(string $code): bool
{
    $forbidden = [
        '<script',
        '</script',
        'javascript:',
        'onclick=',
        'ondblclick=',
        'onmousedown=',
        'onmouseup=',
        'onmouseover=',
        'onmouseout=',
        'onkeydown=',
        'onkeyup=',
        'onkeypress=',
        'onload=',
        'onerror=',
        'onsubmit=',
        'onfocus=',
        'onblur=',
        'onchange=',
        'onresize=',
        'eval(',
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
    ];

    $code_lower = strtolower($code);

    foreach ($forbidden as $keyword) {
        if (str_contains($code_lower, $keyword)) {
            return false;
        }
    }

    return true;
}
