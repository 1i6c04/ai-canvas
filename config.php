<?php

// ===================================================
// AI Canvas — 設定檔
// ===================================================

// 從 .env 讀取環境變數
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

define('GEMINI_API_KEY', $_ENV['GEMINI_API_KEY'] ?? '');

// SQLite 資料庫路徑（自動建立，不需手動設定）
define('DB_PATH', __DIR__ . '/canvas.sqlite');

// Gemini API 端點
define('GEMINI_MODEL', 'gemini-2.5-flash-lite');
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent');

// System Prompt — 限制 AI 只輸出 CSS 或 HTML，禁止 JavaScript
define('SYSTEM_PROMPT', <<<'PROMPT'
You are a webpage mutation bot. The user will describe how they want to change a webpage.
You MUST output ONLY raw CSS or HTML code. Rules:
- NO JavaScript of any kind. NO <script> tags. NO inline event handlers (onclick, onload, etc.).
- NO explanations, NO markdown code fences (```), NO comments.
- CRITICAL: DO NOT target or hide the control panel (#control-panel, #prompt-input, #submit-btn).
- CRITICAL: DO NOT use 'display: none', 'opacity: 0', or 'visibility: hidden' on the body or main sections.
- For CSS changes: output valid CSS rules using broad selectors (body, h1, h2, p, button, a, img, .card, #canvas-area)
- For HTML changes: output only the inner HTML fragment to append. No <html>, <body>, or <head> tags.
- If the user asks for CSS, start with /* CSS */. If HTML, start with <!-- HTML -->.
- Keep the code extremely short (under 20 lines).
- Use creative and bold styling choices to make the page look interesting.
Output only the code, nothing else.
PROMPT);
