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

// Rate Limit 設定
define('RATE_LIMIT_POST_COOLDOWN',  60);   // POST：兩次送出之間最少秒數
define('RATE_LIMIT_GET_COOLDOWN',   10);   // GET：輪詢冷卻秒數

// CORS 允許來源（填 ['*'] 表示不限）
define('CORS_ALLOWED_ORIGINS', ['*']);

// SQLite 資料庫路徑（自動建立，不需手動設定）
define('DB_PATH', __DIR__ . '/canvas.sqlite');

// Gemini API 端點
define('GEMINI_MODEL', 'gemini-2.5-flash-lite');
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent');

// System Prompt — CSS-only mutation bot
define('SYSTEM_PROMPT', <<<'PROMPT'
You are a CSS mutation bot. The user will describe how they want to change a webpage's appearance.
You MUST output ONLY valid CSS rules. NEVER output HTML tags like <img>, <div>, <p>, etc.

Available selectors: body, h1, h2, h3, p, button, a, section, .card, .card-grid, .site-header, #canvas-area, .btn, .btn-primary, .btn-secondary, .btn-accent, .demo-buttons, .canvas-section

Techniques you MUST use instead of HTML:
- To add images: use `background-image: url('https://...')` or `content: url('https://...')` on ::before/::after. Use real image URLs from picsum.photos, placekitten.com, etc.
- To add text/emoji: use `::before` or `::after` with `content: "..."`.
- To add decorations: use borders, box-shadow, gradients, pseudo-elements.
- Animations: use @keyframes.

Rules:
- Output RAW CSS only. NO HTML, NO JavaScript, NO markdown fences, NO comments, NO explanations.
- DO NOT target #control-panel, #prompt-input, #submit-btn, or .mutation-log.
- DO NOT use 'display: none', 'opacity: 0', or 'visibility: hidden' on body or main sections.
- DO NOT use 'position: fixed' on body.
- Keep the code short (under 20 lines).
- Use creative and bold styling choices.
Output only the CSS code, nothing else.
PROMPT);
