# Chip Prompts + Dice Button Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在控制面板輸入框上方加入可點擊的推薦提示 chip 標籤列，以及一個骰子按鈕隨機更換 chip 組合，降低訪客互動門檻。

**Architecture:** 所有變更集中在 `index.php` 的 Shadow DOM 區塊。控制面板 HTML 從單列改為雙列（chip 列 + 輸入列），樣式加在 Shadow DOM 內部 `<style>`，JS 加在同檔案的 inline script。

**Tech Stack:** PHP, vanilla JS, Shadow DOM (無框架、無建置步驟)

---

## File Map

| 動作 | 路徑 | 說明 |
|------|------|------|
| Modify | `index.php:109-205` | Shadow DOM `<style>` 區塊：新增 chip、chip-row、dice-btn 樣式 |
| Modify | `index.php:201-205` | Shadow DOM HTML：重構為 chip 列 + input 列雙排 |
| Modify | `index.php:208-215` | JS：新增 chip pool 常數與 currentChips 狀態 |
| Modify | `index.php:215` | JS：初始化時呼叫 `renderChips()` |
| Modify | `index.php:217` | JS：新增 dice button 事件監聽 |
| Add | `index.php:~320` | JS：新增 `renderChips()` 與 `handleDice()` 函式 |

---

## Task 1：重構控制面板 HTML 與 CSS 為雙列佈局

**Files:**
- Modify: `index.php`（Shadow DOM `shadow.innerHTML` 的 `<style>` 與 HTML 部分）

### 目標

把目前單列的 `#control-panel`（flex row：input + button + status）改為雙列：
- 第一列：chip 標籤區 + 骰子按鈕
- 第二列：輸入框 + 送出按鈕 + 狀態文字

---

- [ ] **Step 1：找到 Shadow DOM style 區塊末尾，在 `@media` 區塊後、`</style>` 前插入 chip 相關樣式**

在 `index.php` 第 198 行（`</style>` 前的 `}` 後面）加入以下 CSS，完整的插入位置是在現有 `@media (max-width: 600px)` 區塊的結尾 `}` 之後：

```css
                #chip-row {
                    display: flex;
                    align-items: center;
                    gap: 0.4rem;
                    flex-wrap: wrap;
                    width: 100%;
                }
                #chips {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 0.4rem;
                    flex: 1;
                }
                .chip {
                    padding: 0.3rem 0.75rem;
                    background: #25253e;
                    border: 1px solid #3a3a5c;
                    border-radius: 999px;
                    color: #ccc;
                    font-family: 'Inter', sans-serif;
                    font-size: 0.8rem;
                    cursor: pointer;
                    transition: background 0.15s, border-color 0.15s, color 0.15s;
                    white-space: nowrap;
                }
                .chip:hover {
                    background: #e94560;
                    border-color: #e94560;
                    color: #fff;
                }
                #dice-btn {
                    background: none;
                    border: none;
                    font-size: 1.2rem;
                    cursor: pointer;
                    padding: 0.2rem 0.4rem;
                    border-radius: 6px;
                    transition: transform 0.3s ease;
                    line-height: 1;
                }
                #dice-btn:hover {
                    transform: rotate(180deg);
                }
                #input-row {
                    display: flex;
                    gap: 0.75rem;
                    align-items: center;
                    width: 100%;
                }
                @media (max-width: 600px) {
                    .chip { font-size: 0.75rem; padding: 0.25rem 0.6rem; }
                    #dice-btn { font-size: 1rem; }
                }
```

- [ ] **Step 2：修改 `#control-panel` 的 CSS 為 column 方向**

找到目前的：
```css
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
```

改為：
```css
                #control-panel {
                    background: #1a1a2e;
                    border-top: 1px solid #25253e;
                    padding: 0.75rem 1.5rem;
                    display: flex;
                    flex-direction: column;
                    gap: 0.5rem;
                    backdrop-filter: blur(20px);
                    box-shadow: 0 -4px 30px rgba(0, 0, 0, 0.4);
                    font-family: 'Inter', sans-serif;
                }
```

- [ ] **Step 3：修改 Shadow DOM 的 HTML 結構**

找到目前的 HTML 區塊（`shadow.innerHTML` 裡的 HTML 部分）：
```html
            <div id="control-panel">
                <input type="text" id="prompt-input" placeholder="輸入指令...例如：把背景變星空" maxlength="120" autocomplete="off">
                <button id="submit-btn">🚀 送出</button>
                <span id="status"></span>
            </div>
```

改為：
```html
            <div id="control-panel">
                <div id="chip-row">
                    <div id="chips"></div>
                    <button id="dice-btn" title="換一批提示">🎲</button>
                </div>
                <div id="input-row">
                    <input type="text" id="prompt-input" placeholder="輸入指令...例如：把背景變星空" maxlength="120" autocomplete="off">
                    <button id="submit-btn">🚀 送出</button>
                    <span id="status"></span>
                </div>
            </div>
```

- [ ] **Step 4：手動驗證 HTML 結構正確**

用 PHP 內建伺服器啟動：
```bash
php -S localhost:8000
```

打開 `http://localhost:8000`，確認：
- 控制面板變成雙列（上方 chip 區空白但存在，下方輸入框正常）
- 骰子按鈕 🎲 出現在右側
- 輸入框、送出按鈕、狀態文字正常顯示
- hover 骰子按鈕時旋轉

- [ ] **Step 5：Commit**

```bash
git add index.php
git commit -m "feat: 重構控制面板為雙列佈局，新增 chip 列與骰子按鈕樣式"
```

---

## Task 2：加入 Chip Pool、renderChips 與 handleDice 邏輯

**Files:**
- Modify: `index.php`（Shadow DOM 之後的 JS 區塊）

### 目標

- 定義 chip 提示池（14 個）
- `renderChips(exclude)` 從池中隨機抽 4 個顯示為 chip，排除 exclude 中的項目
- 每個 chip 點擊後填入輸入框並 focus
- `handleDice()` 傳入當前 chip，換出新的一批並加骰子旋轉動畫
- 頁面初始化時呼叫一次 `renderChips([])`

---

- [ ] **Step 1：在 `const csrfToken` 那行之前加入 chip pool 與狀態變數**

找到：
```js
        const csrfToken = '<?= $csrf_token ?>';
```

在它**上方**插入：
```js
        // ---------- Chip 推薦提示 ----------
        const CHIP_POOL = [
            '星空背景', '彩虹標題文字', '霓虹燈卡片', '海浪動畫效果',
            '把背景變成熔岩', '讓按鈕閃爍', '文字變成金色',
            '卡片加上玻璃質感', '標題加上打字機效果',
            '背景變成極光', '讓整個頁面旋轉360度',
            '卡片變成浮空效果', '文字加上彩色陰影', '背景變成像素風格'
        ];
        let currentChips = [];
```

- [ ] **Step 2：在 `loadData()` 呼叫之後加入初始 renderChips 呼叫**

找到：
```js
        let lastModId = <?= (int) $last_mod_id ?>;
        loadData();
```

改為：
```js
        let lastModId = <?= (int) $last_mod_id ?>;
        loadData();
        renderChips([]);
```

- [ ] **Step 3：在 `submitBtn.addEventListener` 之後加入骰子按鈕事件監聽**

找到：
```js
        // ---------- 送出 Prompt ----------
        submitBtn.addEventListener('click', submitPrompt);
```

在它**上方**插入：
```js
        const diceBtn = shadow.getElementById('dice-btn');
        diceBtn.addEventListener('click', handleDice);
```

- [ ] **Step 4：在 `escapeHtml` 函式之後、`})()` 之前加入 renderChips 與 handleDice 函式**

找到：
```js
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    })();
```

在 `escapeHtml` 函式結尾的 `}` 後、`})();` 前插入：

```js
        // ---------- Chip 推薦 ----------
        function renderChips(exclude) {
            const available = CHIP_POOL.filter(function (p) { return exclude.indexOf(p) === -1; });
            const shuffled = available.slice().sort(function () { return Math.random() - 0.5; });
            currentChips = shuffled.slice(0, 4);

            const chipsEl = shadow.getElementById('chips');
            chipsEl.innerHTML = '';
            currentChips.forEach(function (text) {
                const chip = document.createElement('button');
                chip.className = 'chip';
                chip.textContent = text;
                chip.addEventListener('click', function () {
                    promptInput.value = text;
                    promptInput.focus();
                });
                chipsEl.appendChild(chip);
            });
        }

        function handleDice() {
            renderChips(currentChips);
            diceBtn.style.transition = 'transform 0.4s ease';
            diceBtn.style.transform = 'rotate(360deg)';
            setTimeout(function () {
                diceBtn.style.transition = 'none';
                diceBtn.style.transform = 'rotate(0deg)';
            }, 420);
        }
```

- [ ] **Step 5：手動驗證完整功能**

啟動：
```bash
php -S localhost:8000
```

打開 `http://localhost:8000`，逐項確認：

1. 頁面載入後，chip 列顯示 4 個推薦提示（隨機）
2. 點擊任意 chip → 文字填入輸入框，輸入框取得 focus
3. 輸入框內容可手動修改後送出（送出功能不受影響）
4. 點擊 🎲 → chip 組合換成新的 4 個，不重複當前顯示的，骰子有旋轉動畫
5. 連續點骰子多次，chip 持續更換（pool 足夠大）
6. 手機寬度（< 600px）下，chip 文字大小變小、版面仍正常

- [ ] **Step 6：Commit**

```bash
git add index.php
git commit -m "feat: 新增推薦提示 chip 列與骰子隨機功能"
```

---

## 驗收清單

- [ ] Chip 列出現在輸入框上方
- [ ] 顯示 4 個 chip，來自 14 個提示的隨機子集
- [ ] 點 chip → 填入輸入框並 focus，不自動送出
- [ ] 點 🎲 → 換出不重複的 4 個新 chip，骰子旋轉動畫
- [ ] 原有送出、輪詢、日誌等功能不受影響
- [ ] 手機版面正常
