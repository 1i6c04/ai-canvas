<?php
require_once __DIR__ . '/db.php';

$snapshot_css = get_snapshot_css();
$last_mod_id = get_latest_mod_id();
$recent_logs = get_recent_modifications(10);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Canvas — 讓 AI 改造這個網頁</title>
    <meta name="description" content="一個由全世界的人共同改造的網頁。輸入你的指令，AI 會即時修改這個頁面的外觀。">
    <link rel="stylesheet" href="style.css">

    <?php if ($snapshot_css !== ''): ?>
    <style id="canvas-css"><?= $snapshot_css ?></style>
    <?php endif; ?>

    <!-- 守衛樣式：永遠排最後，防止 AI 在 body/html 套 transform/animation
         body 有 transform 時會破壞 position:fixed 的 viewport 定位基準 -->
    <style id="canvas-guardian">
    html, html:active, html:hover, html:focus,
    body, body:active, body:hover, body:focus {
        animation: none !important;
        transform: none !important;
    }
    </style>
</head>
<body>

    <!-- ===== Header ===== -->
    <header class="site-header">
        <h1>🎨 AI Canvas</h1>
        <p>每個人都可以改造這個網頁的外觀。輸入你的指令，看看會發生什麼事。</p>
    </header>

    <!-- ===== Canvas Area ===== -->
    <main id="canvas-area">

        <section class="canvas-section">
            <h2>歡迎來到畫布</h2>
            <p>這個頁面上的每一個元素都可以被 AI 改造。試試看輸入「把標題變成藍色」或「加上星空背景」！所有的修改都會永久累積，你看到的是所有人共同創作的結果。</p>
        </section>

        <section class="canvas-section">
            <h2>示範元素</h2>
            <p>以下是一些可以被改造的元素：</p>

            <div class="card-grid">
                <div class="card">
                    <h3>🚀 卡片一</h3>
                    <p>這是一張普通的卡片。你可以讓 AI 改變它的顏色、邊框、大小，甚至讓它旋轉。</p>
                </div>
                <div class="card">
                    <h3>🎵 卡片二</h3>
                    <p>試試看讓 AI 加上漸層背景、陰影效果，或是把文字變成彩虹色。</p>
                </div>
                <div class="card">
                    <h3>🌈 卡片三</h3>
                    <p>你的創意是唯一的限制。讓我們看看這個網頁最終會變成什麼樣子！</p>
                </div>
            </div>
        </section>

        <section class="canvas-section">
            <h2>互動按鈕</h2>
            <div class="demo-buttons">
                <button class="btn btn-primary">主要按鈕</button>
                <button class="btn btn-secondary">次要按鈕</button>
                <button class="btn btn-accent">強調按鈕</button>
            </div>
        </section>

        <!-- 修改日誌 -->
        <div class="mutation-log">
            <h3>📝 修改日誌</h3>
            <div id="log-entries">
                <?php if (empty($recent_logs)): ?>
                    <p class="empty-log">還沒有任何修改...成為第一個改造者吧！</p>
                <?php else: ?>
                    <?php foreach ($recent_logs as $mod): ?>
                        <div class="log-entry">
                            <span class="log-type css">css</span>
                            「<?= htmlspecialchars($mod['prompt']) ?>」
                            <span style="opacity:0.5; font-size:0.7rem;"><?= $mod['created_at'] ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="footer-spacer"></div>
    </main>

    <!-- ===== Control Panel (Fixed Bottom, Shadow DOM isolated) ===== -->
    <div id="control-panel-host"></div>

    <!-- ===== JavaScript ===== -->
    <script>
    (function () {
        // ---------- Shadow DOM 隔離控制面板 ----------
        // 外部任何 CSS（包含 AI 生成的）都無法穿透 Shadow DOM 邊界
        const host = document.getElementById('control-panel-host');
        const shadow = host.attachShadow({ mode: 'open' });
        shadow.innerHTML = `
            <style>
                :host {
                    display: block;
                    position: fixed;
                    bottom: 0;
                    left: 0;
                    right: 0;
                    z-index: 9999;
                }
                #control-panel {
                    background: #1a1a2e;
                    border-top: 1px solid #25253e;
                    padding: 1rem 1.5rem;
                    display: flex;
                    gap: 0.75rem;
                    align-items: center;
                    backdrop-filter: blur(20px);
                    box-shadow: 0 -4px 30px rgba(0, 0, 0, 0.4);
                    font-family: 'Inter', sans-serif;
                }
                #prompt-input {
                    flex: 1;
                    padding: 0.75rem 1rem;
                    background: #0f0f0f;
                    border: 1px solid #25253e;
                    border-radius: 8px;
                    color: #eaeaea;
                    font-family: 'Inter', sans-serif;
                    font-size: 0.95rem;
                    outline: none;
                    transition: border-color 0.2s ease;
                }
                #prompt-input:focus {
                    border-color: #e94560;
                    box-shadow: 0 0 0 3px rgba(233, 69, 96, 0.3);
                }
                #prompt-input::placeholder {
                    color: #888;
                }
                #submit-btn {
                    padding: 0.75rem 1.5rem;
                    background: #e94560;
                    color: #fff;
                    border: none;
                    border-radius: 8px;
                    font-family: 'Inter', sans-serif;
                    font-size: 0.95rem;
                    font-weight: 600;
                    cursor: pointer;
                    transition: background 0.2s ease, box-shadow 0.2s ease;
                    white-space: nowrap;
                }
                #submit-btn:hover {
                    background: #d63851;
                    box-shadow: 0 4px 20px rgba(233, 69, 96, 0.3);
                }
                #submit-btn:disabled {
                    opacity: 0.5;
                    cursor: not-allowed;
                }
                #status {
                    font-size: 0.8rem;
                    color: #888;
                    min-width: 80px;
                    text-align: center;
                }
                #status.success { color: #4caf50; }
                #status.error   { color: #e94560; }
                #status.loading { color: #ffc107; }
                @media (max-width: 600px) {
                    #control-panel { flex-wrap: wrap; }
                    #prompt-input  { width: 100%; }
                }
            </style>
            <div id="control-panel">
                <input type="text" id="prompt-input" placeholder="輸入指令（限 120 字）...例如：把背景變星空" maxlength="120" autocomplete="off">
                <button id="submit-btn">🚀 送出</button>
                <span id="status"></span>
            </div>
        `;

        const promptInput = shadow.getElementById('prompt-input');
        const submitBtn   = shadow.getElementById('submit-btn');
        const statusEl    = shadow.getElementById('status');
        const logEntries  = document.getElementById('log-entries');

        let lastModId = 1; // 從第一筆開始讀取css
        loadData();

        // ---------- 送出 Prompt ----------
        submitBtn.addEventListener('click', submitPrompt);
        promptInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') submitPrompt();
        });

        async function submitPrompt() {
            const prompt = promptInput.value.trim();
            if (!prompt) return;

            submitBtn.disabled = true;
            setStatus('⏳ AI 正在思考...', 'loading');

            try {
                const res = await fetch('/api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ prompt: prompt }),
                });

                const data = await res.json();

                if (!res.ok || !data.success) {
                    setStatus('❌ ' + (data.error || '發生錯誤'), 'error');
                    return;
                }

                injectCSS(data.code, data.id);
                lastModId = data.id;
                addLogEntry(prompt);

                promptInput.value = '';
                setStatus('✅ 成功！', 'success');
                setTimeout(() => setStatus(''), 3000);

            } catch (err) {
                setStatus('❌ 網路錯誤', 'error');
                console.error(err);
            } finally {
                submitBtn.disabled = false;
                promptInput.focus();
            }
        }

        // ---------- 輪詢新修改（每 10 秒） ----------
        setInterval(async function () {
            await loadData();
        }, 10000);

        // ---------- 初始化 ----------
        async function loadData() {
            try {
                const res = await fetch('/api.php?since_id=' + lastModId);
                if (res.status === 429) return; // rate limited，靜默跳過
                const data = await res.json();

                if (data.success && data.modifications.length > 0) {
                    data.modifications.forEach(function (mod) {
                        if (mod.id <= lastModId) return; // submit handler 已處理，跳過
                        injectCSS(mod.code, mod.id);
                        addLogEntry(mod.prompt);
                        lastModId = mod.id;
                    });
                }
            } catch (err) {
            }
        }

        // ---------- 注入 CSS ----------
        function injectCSS(code, id) {
            const style = document.createElement('style');
            style.setAttribute('data-mod-id', id);
            style.textContent = code;
            document.head.appendChild(style);
            // 每次注入後把守衛樣式移到最末端，確保它永遠蓋過 AI 的 body/html 動畫
            const guardian = document.getElementById('canvas-guardian');
            if (guardian) document.head.appendChild(guardian);
        }

        // ---------- 新增日誌條目 ----------
        function addLogEntry(prompt) {
            const emptyLog = logEntries.querySelector('.empty-log');
            if (emptyLog) emptyLog.remove();

            const entry = document.createElement('div');
            entry.className = 'log-entry';
            entry.innerHTML =
                '<span class="log-type css">css</span>' +
                '「' + escapeHtml(prompt) + '」' +
                '<span style="opacity:0.5; font-size:0.7rem;"> 剛剛</span>';
            logEntries.appendChild(entry);

            while (logEntries.children.length > 10) {
                logEntries.removeChild(logEntries.firstChild);
            }

            logEntries.parentElement.scrollTop = logEntries.parentElement.scrollHeight;
        }

        // ---------- 工具函式 ----------
        function setStatus(text, className) {
            statusEl.textContent = text;
            statusEl.className = className || '';
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    })();
    </script>

</body>
</html>
