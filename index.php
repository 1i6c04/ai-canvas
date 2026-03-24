<?php
require_once __DIR__ . '/db.php';

// 取得所有歷史修改（頁面載入時直接渲染）
$modifications = get_all_modifications();
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Canvas — 讓 AI 改造這個網頁</title>
    <meta name="description" content="一個由全世界的人共同改造的網頁。輸入你的指令，AI 會即時修改這個頁面的外觀。">
    <link rel="stylesheet" href="style.css">

    <?php
    // 將所有歷史 CSS 合併成單一 <style> tag，減少瀏覽器解析開銷
    $all_css = implode("\n", array_column(
        array_filter($modifications, fn($m) => $m['code_type'] === 'css'),
        'code'
    ));
    if ($all_css !== '') {
        echo "<style id=\"historical-css\">{$all_css}</style>\n";
    }
    ?>
</head>
<body>

    <!-- ===== Header ===== -->
    <header class="site-header">
        <h1>🎨 AI Canvas</h1>
        <p>每個人都可以改造這個網頁。輸入你的指令，看看會發生什麼事。</p>
    </header>

    <!-- ===== Canvas Area ===== -->
    <main id="canvas-area">

        <section class="canvas-section">
            <h2>歡迎來到畫布</h2>
            <p>這個頁面上的每一個元素都可以被 AI 改造。試試看輸入「把標題變成藍色」或「加一張貓咪圖片」！所有的修改都會永久累積，你看到的是所有人共同創作的結果。</p>
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

        <!-- 動態注入的 HTML 會被加到這裡 -->
        <section id="injected-html" class="canvas-section">
            <?php
            // 將歷史 HTML 修改在這裡輸出
            foreach ($modifications as $mod) {
                if ($mod['code_type'] === 'html') {
                    echo "<div data-mod-id=\"{$mod['id']}\">{$mod['code']}</div>\n";
                }
            }
            ?>
        </section>

        <!-- 修改日誌 -->
        <div class="mutation-log">
            <h3>📝 修改日誌</h3>
            <div id="log-entries">
                <?php if (empty($modifications)): ?>
                    <p class="empty-log">還沒有任何修改...成為第一個改造者吧！</p>
                <?php else: ?>
                    <?php foreach (array_slice($modifications, -10) as $mod): ?>
                        <div class="log-entry">
                            <span class="log-type <?= $mod['code_type'] ?>"><?= $mod['code_type'] ?></span>
                            「<?= htmlspecialchars($mod['prompt']) ?>」
                            <span style="opacity:0.5; font-size:0.7rem;"><?= $mod['created_at'] ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="footer-spacer"></div>
    </main>

    <!-- ===== Control Panel (Fixed Bottom) ===== -->
    <div id="control-panel">
        <input type="text" id="prompt-input" placeholder="輸入指令（限 120 字）...例如：把背景變星空" maxlength="120" autocomplete="off">
        <button id="submit-btn">🚀 送出</button>
        <span id="status"></span>
    </div>

    <!-- ===== JavaScript ===== -->
    <script>
    (function () {
        const promptInput = document.getElementById('prompt-input');
        const submitBtn = document.getElementById('submit-btn');
        const statusEl = document.getElementById('status');
        const logEntries = document.getElementById('log-entries');
        const injectedHtml = document.getElementById('injected-html');

        // 追蹤最新的修改 ID（用於輪詢）
        let lastModId = <?= !empty($modifications) ? end($modifications)['id'] : 0 ?>;

        // 初始頁面載入時是否已經有修改紀錄
        const hasInitialMods = <?= !empty($modifications) ? 'true' : 'false' ?>;

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

                // 注入新的修改
                injectModification(data);
                lastModId = data.id;

                // 新增日誌
                addLogEntry(data.code_type, prompt);

                // 清空輸入框
                promptInput.value = '';
                setStatus('✅ 成功！', 'success');

                // 3 秒後清除狀態
                setTimeout(() => setStatus(''), 3000);

            } catch (err) {
                setStatus('❌ 網路錯誤', 'error');
                console.error(err);
            } finally {
                submitBtn.disabled = false;
                promptInput.focus();
            }
        }

        // ---------- 輪詢新修改（每 3 秒） ----------
        setInterval(async function () {
            try {
                const res = await fetch('/api.php?since_id=' + lastModId);
                const data = await res.json();

                if (data.success && data.modifications.length > 0) {
                    data.modifications.forEach(function (mod) {
                        injectModification(mod);
                        addLogEntry(mod.code_type, mod.prompt);
                    });
                    lastModId = data.modifications[data.modifications.length - 1].id;
                }
            } catch (err) {
                // 輪詢失敗不需要特別處理
            }
        }, 3000);

        // ---------- 注入修改到頁面 ----------
        function injectModification(mod) {
            if (mod.code_type === 'css') {
                const style = document.createElement('style');
                style.setAttribute('data-mod-id', mod.id);
                style.textContent = mod.code;
                document.head.appendChild(style);
            } else if (mod.code_type === 'html') {
                const wrapper = document.createElement('div');
                wrapper.setAttribute('data-mod-id', mod.id);
                wrapper.innerHTML = mod.code;
                injectedHtml.appendChild(wrapper);
            }
        }

        // ---------- 新增日誌條目 ----------
        function addLogEntry(codeType, prompt) {
            // 移除「還沒有修改」的占位文字
            const emptyLog = logEntries.querySelector('.empty-log');
            if (emptyLog) emptyLog.remove();

            const entry = document.createElement('div');
            entry.className = 'log-entry';
            entry.innerHTML =
                '<span class="log-type ' + codeType + '">' + codeType + '</span>' +
                '「' + escapeHtml(prompt) + '」' +
                '<span style="opacity:0.5; font-size:0.7rem;"> 剛剛</span>';
            logEntries.appendChild(entry);

            // 限制日誌最多顯示 10 筆
            while (logEntries.children.length > 10) {
                logEntries.removeChild(logEntries.firstChild);
            }

            // 自動滾動到最新日誌
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
