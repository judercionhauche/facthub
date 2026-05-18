<?php
require_login();
$user = current_user();

// Load existing session if session_key param present
$sessionKey = preg_replace('/[^a-f0-9]/', '', $_GET['s'] ?? '');
$turns = [];
if ($sessionKey) {
    $stmt = $conn->prepare('SELECT turns FROM search_sessions WHERE session_key = ? AND user_id = ? LIMIT 1');
    $stmt->bind_param('si', $sessionKey, (int)$user['id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        $turns = json_decode($row['turns'], true) ?? [];
    }
}

// Generate new session key if none
if (!$sessionKey) {
    $sessionKey = bin2hex(random_bytes(16));
}
?>

<div class="search-container">
    <div class="search-header">
        <h1>Search FACT Hub</h1>
        <a href="?page=search" class="ghost-btn" style="font-size:12px">+ New Chat</a>
    </div>

    <div class="chat-history" id="chatHistory">
        <?php if (empty($turns)): ?>
            <div class="idle-state">
                <p style="font-size:16px;margin-bottom:24px">Hi <?= h($user['name'] ?? 'there') ?>, what are you looking for today?</p>
                <div class="example-queries">
                    <button class="example-chip" onclick="submitQuery('climate funding East Africa')">Climate funding East Africa</button>
                    <button class="example-chip" onclick="submitQuery('health researchers Ghana')">Health researchers Ghana</button>
                    <button class="example-chip" onclick="submitQuery('water projects with open deadlines')">Water projects with open deadlines</button>
                    <button class="example-chip" onclick="submitQuery('food security funding sub-saharan africa')">Food security funding sub-saharan africa</button>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($turns as $turn): ?>
                <div class="chat-bubble user-bubble">
                    <div class="bubble-content"><?= h($turn['user'] ?? '') ?></div>
                </div>

                <div class="chat-bubble assistant-bubble">
                    <div class="bubble-content"><?= h($turn['assistant'] ?? '') ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="search-footer">
        <div class="filter-strip">
            <a href="#" class="filter-pill active" onclick="setFilter('type','');return false">All</a>
            <a href="#" class="filter-pill" onclick="setFilter('type','funding');return false">Funding Calls</a>
            <a href="#" class="filter-pill" onclick="setFilter('type','researcher');return false">Researchers</a>
            <a href="#" class="filter-pill" onclick="setFilter('type','funder');return false">Funders</a>
            <span style="opacity:.3;margin:0 8px">|</span>
            <a href="#" class="filter-pill active" onclick="setFilter('status','');return false">Any Status</a>
            <a href="#" class="filter-pill" onclick="setFilter('status','open');return false">Open</a>
            <a href="#" class="filter-pill" onclick="setFilter('status','rolling');return false">Rolling</a>
        </div>

        <form id="chatForm" onsubmit="submitForm(event)" style="display:flex;gap:8px;margin-top:16px">
            <input type="hidden" id="sessionKey" value="<?= h($sessionKey) ?>">
            <input type="hidden" id="filterType" value="">
            <input type="hidden" id="filterStatus" value="">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input
                type="text"
                id="queryInput"
                name="q"
                placeholder="Search for researchers, funding, funders, institutions..."
                autocomplete="off"
                style="flex:1;padding:12px;border:1px solid var(--line);border-radius:8px;font-family:inherit;font-size:14px"
                maxlength="300"
            >
            <button type="submit" class="primary-btn" style="padding:12px 20px">
                <span id="submitText">Search</span>
                <span id="loadingIndicator" style="display:none;margin-left:6px">
                    <span style="animation:dots 1.4s infinite">.</span><span style="animation:dots 1.4s infinite 0.2s">.</span><span style="animation:dots 1.4s infinite 0.4s">.</span>
                </span>
            </button>
        </form>
    </div>
</div>

<style>
.search-container {
    display: flex;
    flex-direction: column;
    height: 100vh;
    background: var(--bg);
}

.search-header {
    padding: 20px;
    background: white;
    border-bottom: 1px solid var(--line);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.search-header h1 {
    margin: 0;
    font-size: 24px;
    font-weight: 600;
}

.chat-history {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.idle-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: var(--text);
    text-align: center;
}

.example-queries {
    display: flex;
    flex-direction: column;
    gap: 8px;
    max-width: 400px;
}

.example-chip {
    padding: 12px 16px;
    background: white;
    border: 1px solid var(--line);
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    color: var(--primary);
    transition: all 0.2s;
    font-family: inherit;
}

.example-chip:hover {
    background: var(--primary-2);
    border-color: var(--primary);
}

.chat-bubble {
    display: flex;
    margin-bottom: 12px;
    animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.user-bubble {
    justify-content: flex-end;
}

.user-bubble .bubble-content {
    background: var(--primary);
    color: white;
    max-width: 70%;
    padding: 12px 16px;
    border-radius: 12px;
    word-wrap: break-word;
}

.assistant-bubble {
    justify-content: flex-start;
}

.assistant-bubble .bubble-content {
    background: white;
    border: 1px solid var(--line);
    max-width: 70%;
    padding: 12px 16px;
    border-radius: 12px;
    color: var(--text);
    word-wrap: break-word;
    line-height: 1.5;
}

.typing-cursor {
    display: inline-block;
    width: 2px;
    height: 1em;
    background: currentColor;
    margin-left: 2px;
    animation: blink 1s infinite;
    vertical-align: text-bottom;
}

@keyframes blink {
    0%, 49% { opacity: 1; }
    50%, 100% { opacity: 0; }
}

.result-cards {
    margin-left: 0;
    padding: 12px;
    background: #f9f9f9;
    border-radius: 8px;
    font-size: 13px;
}

.result-section-title {
    margin-bottom: 12px;
    font-weight: 600;
}

.result-section-title.first {
    margin-bottom: 12px;
}

.result-card {
    margin-bottom: 8px;
    padding: 8px;
    background: white;
    border-left: 3px solid;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s;
}

.result-card:hover {
    background: #fafafa;
    transform: translateX(4px);
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

.result-card::after {
    content: '→';
    display: none;
    margin-left: auto;
    color: var(--primary);
    font-weight: 600;
    align-self: center;
}

.result-card:hover::after {
    display: inline;
}

.result-card.fc {
    border-left-color: var(--primary);
}

.result-card.r {
    border-left-color: var(--secondary);
}

.result-card.funder {
    border-left-color: #f59e0b;
}

.result-card.institution {
    border-left-color: #3b82f6;
}

.entity-badge {
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 10px;
    font-weight: 600;
    white-space: nowrap;
}

.funder-badge {
    background: #fef3c7;
    color: #92400e;
}

.researcher-badge {
    background: var(--primary-2);
    color: var(--primary);
}

.fc-badge {
    background: #ede9fe;
    color: #5b21b6;
}

.institution-badge {
    background: #f0f9ff;
    color: #0c4a6e;
}

.pivot-card {
    background: rgba(26, 107, 90, 0.02) !important;
    border-left-color: var(--primary) !important;
}

.pivot-card:hover {
    background: rgba(26, 107, 90, 0.05) !important;
}

.result-card-title {
    font-weight: 600;
    margin-bottom: 2px;
}

.result-card-subtitle {
    color: var(--muted);
    font-size: 12px;
}

.search-footer {
    padding: 20px;
    background: white;
    border-top: 1px solid var(--line);
}

.filter-strip {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 12px;
    font-size: 13px;
}

.filter-pill {
    padding: 6px 12px;
    background: transparent;
    border: 1px solid var(--line);
    border-radius: 20px;
    cursor: pointer;
    color: var(--muted);
    text-decoration: none;
    transition: all 0.2s;
    font-size: 13px;
    font-family: inherit;
}

.filter-pill:hover {
    border-color: var(--primary);
    color: var(--primary);
}

.filter-pill.active {
    background: var(--primary);
    border-color: var(--primary);
    color: white;
}

@keyframes dots {
    0%, 20% { opacity: 0.3; }
    50% { opacity: 1; }
    80%, 100% { opacity: 0.3; }
}
</style>

<script>
const sessionKey = document.getElementById('sessionKey').value;
let filterType = '';
let filterStatus = '';

function stripMarkdown(text) {
    return text
        .replace(/\*\*(.*?)\*\*/g, '$1')
        .replace(/\*(.*?)\*/g, '$1')
        .replace(/__(.*?)__/g, '$1')
        .replace(/_(.*?)_/g, '$1')
        .replace(/^#{1,6}\s+/gm, '')
        .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
        .replace(/`([^`]+)`/g, '$1')
        .trim();
}

async function submitForm(event) {
    event.preventDefault();
    const q = document.getElementById('queryInput').value.trim();
    if (!q) return;
    await submitQuery(q);
}

async function submitQuery(query) {
    const queryInput = document.getElementById('queryInput');
    const submitBtn = queryInput.parentElement.querySelector('button');
    const submitText = document.getElementById('submitText');
    const loadingIndicator = document.getElementById('loadingIndicator');
    const chatHistory = document.getElementById('chatHistory');

    queryInput.value = query;
    submitBtn.disabled = true;
    submitText.style.display = 'none';
    loadingIndicator.style.display = 'inline';

    // Clear idle state if present
    const idleState = chatHistory.querySelector('.idle-state');
    if (idleState) chatHistory.innerHTML = '';

    // Add user bubble immediately
    appendUserBubble(query);

    // Create empty AI bubble with cursor
    const aiBubble = appendAiBubble('');
    const bubbleContent = aiBubble.querySelector('.bubble-content');
    const cursor = document.createElement('span');
    cursor.className = 'typing-cursor';
    cursor.textContent = '|';
    bubbleContent.appendChild(cursor);

    try {
        const response = await fetch('chat_search.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('input[name="_csrf"]').value
            },
            body: JSON.stringify({
                q: query,
                session_key: sessionKey,
                filter_type: filterType,
                filter_status: filterStatus
            })
        });

        if (!response.ok) {
            bubbleContent.textContent = 'Error: ' + response.status;
            cursor.remove();
            return;
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let textSoFar = '';

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            buffer += decoder.decode(value, { stream: true });
            const lines = buffer.split('\n');
            buffer = lines.pop(); // keep incomplete line

            for (const line of lines) {
                if (!line.startsWith('data: ')) continue;
                let event;
                try {
                    event = JSON.parse(line.slice(6));
                } catch (e) {
                    continue;
                }

                if (event.t === 'results') {
                    renderResultCards(event, chatHistory);
                } else if (event.t === 'token') {
                    textSoFar += event.v;
                    bubbleContent.textContent = stripMarkdown(textSoFar);
                    const newCursor = document.createElement('span');
                    newCursor.className = 'typing-cursor';
                    newCursor.textContent = '|';
                    bubbleContent.appendChild(newCursor);
                    chatHistory.scrollTop = chatHistory.scrollHeight;
                } else if (event.t === 'done') {
                    bubbleContent.querySelector('.typing-cursor')?.remove();
                    if (event.sk) {
                        history.pushState({}, '', '?page=search&s=' + event.sk);
                    }
                } else if (event.t === 'error') {
                    bubbleContent.textContent = 'Error: ' + (event.msg || 'Unknown error');
                    cursor.remove();
                }
            }
        }

    } catch (error) {
        bubbleContent.textContent = 'Connection error: ' + error.message;
    } finally {
        submitBtn.disabled = false;
        submitText.style.display = 'inline';
        loadingIndicator.style.display = 'none';
        queryInput.focus();
    }
}

function appendUserBubble(text) {
    const div = document.createElement('div');
    div.className = 'chat-bubble user-bubble';
    div.innerHTML = '<div class="bubble-content">' + escapeHtml(text) + '</div>';
    document.getElementById('chatHistory').appendChild(div);
}

function appendAiBubble(text) {
    const div = document.createElement('div');
    div.className = 'chat-bubble assistant-bubble';
    div.innerHTML = '<div class="bubble-content">' + (text ? escapeHtml(text) : '') + '</div>';
    document.getElementById('chatHistory').appendChild(div);
    return div;
}

function renderResultCards(event, chatHistory) {
    const fc = event.fc || [];
    const r = event.r || [];
    const f = event.f || [];
    const inst = event.inst || [];
    const pivotFc = event.pivot_fc || [];
    const pivotReason = event.pivot_reason || '';
    const total = event.total || {};

    if (fc.length === 0 && r.length === 0 && f.length === 0 && inst.length === 0 && pivotFc.length === 0) return;

    const container = document.createElement('div');
    container.className = 'result-cards';

    let html = '';
    let hasResults = false;

    // Funding Calls
    if (fc.length > 0) {
        html += '<div class="result-section-title first">Funding Calls (' + total.funding_calls + ' total)</div>';
        fc.forEach(item => {
            html += '<div class="result-card fc" onclick="navigateToEntity(\'' + escapeHtmlAttr(item.destination_url) + '\'); return false">'
                + '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">'
                + '<div style="flex:1">'
                + '<div class="result-card-title">' + escapeHtml(item.title) + '</div>'
                + '<div class="result-card-subtitle">' + escapeHtml(item.funder) + ' • ' + escapeHtml(item.status) + '</div>'
                + '</div>'
                + '<span class="entity-badge fc-badge">FC</span>'
                + '</div>'
                + '</div>';
        });
        hasResults = true;
    }

    // Researchers
    if (r.length > 0) {
        html += '<div class="result-section-title' + (hasResults ? '' : ' first') + '">Researchers (' + total.researchers + ' total)</div>';
        r.forEach(item => {
            html += '<div class="result-card r" onclick="navigateToEntity(\'' + escapeHtmlAttr(item.destination_url) + '\'); return false">'
                + '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">'
                + '<div style="flex:1">'
                + '<div class="result-card-title">' + escapeHtml(item.name) + '</div>'
                + '<div class="result-card-subtitle">' + escapeHtml(item.institution) + '</div>'
                + '</div>'
                + '<span class="entity-badge researcher-badge">R</span>'
                + '</div>'
                + '</div>';
        });
        hasResults = true;
    }

    // Funders
    if (f.length > 0) {
        html += '<div class="result-section-title' + (hasResults ? '' : ' first') + '">Funders (' + total.funders + ' total)</div>';
        f.forEach(item => {
            html += '<div class="result-card funder" onclick="navigateToEntity(\'' + escapeHtmlAttr(item.destination_url) + '\'); return false">'
                + '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">'
                + '<div style="flex:1">'
                + '<div class="result-card-title">' + escapeHtml(item.name) + '</div>'
                + '<div class="result-card-subtitle">' + escapeHtml(item.organization) + '</div>'
                + '</div>'
                + '<span class="entity-badge funder-badge">F</span>'
                + '</div>'
                + '</div>';
        });
        hasResults = true;
    }

    // Institutions
    if (inst.length > 0) {
        html += '<div class="result-section-title' + (hasResults ? '' : ' first') + '">Institutions (' + total.institutions + ' total)</div>';
        inst.forEach(item => {
            html += '<div class="result-card institution" onclick="navigateToEntity(\'' + escapeHtmlAttr(item.destination_url) + '\'); return false">'
                + '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">'
                + '<div style="flex:1">'
                + '<div class="result-card-title">' + escapeHtml(item.institution) + '</div>'
                + '<div class="result-card-subtitle">' + item.researcher_count + ' researchers</div>'
                + '</div>'
                + '<span class="entity-badge institution-badge">I</span>'
                + '</div>'
                + '</div>';
        });
        hasResults = true;
    }

    // Pivot recommendations sidebar
    if (pivotFc.length > 0 && pivotReason) {
        html += '<div style="margin-top:20px;padding-top:20px;border-top:2px solid var(--line)">';
        html += '<div class="result-section-title" style="color:var(--primary);font-size:12px;letter-spacing:0.05em">💡 RECOMMENDED FOR YOU</div>';
        html += '<div style="font-size:12px;color:var(--muted);margin-bottom:12px;line-height:1.4">' + escapeHtml(pivotReason) + '</div>';
        pivotFc.forEach(item => {
            html += '<div class="result-card fc pivot-card" onclick="navigateToEntity(\'' + escapeHtmlAttr(item.destination_url) + '\'); return false">'
                + '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">'
                + '<div>'
                + '<div class="result-card-title" style="font-size:13px">' + escapeHtml(item.title) + '</div>'
                + '<div class="result-card-subtitle" style="font-size:11px">' + escapeHtml(item.funder) + ' • ' + escapeHtml(item.status) + '</div>'
                + '</div>'
                + '<span style="background:var(--primary-2);color:var(--primary);padding:2px 8px;border-radius:12px;font-size:10px;white-space:nowrap">' + escapeHtml(item.pivot_topic) + '</span>'
                + '</div>'
                + '</div>';
        });
        html += '<div style="margin-top:12px;font-size:11px;color:var(--muted)">💭 Click any result to view details</div>';
        html += '</div>';
    }

    container.innerHTML = html;
    chatHistory.appendChild(container);
}

function setFilter(filterName, value) {
    if (filterName === 'type') {
        filterType = value;
        const pills = document.querySelectorAll('.filter-strip .filter-pill');
        pills[0].classList.toggle('active', !value);
        pills[1].classList.toggle('active', value === 'funding');
        pills[2].classList.toggle('active', value === 'researcher');
        pills[3].classList.toggle('active', value === 'funder');
    } else if (filterName === 'status') {
        filterStatus = value;
        const pills = document.querySelectorAll('.filter-strip .filter-pill');
        pills[5].classList.toggle('active', !value);
        pills[6].classList.toggle('active', value === 'open');
        pills[7].classList.toggle('active', value === 'rolling');
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function escapeHtmlAttr(text) {
    return (text + '').replace(/[&<>"']/g, function(c) {
        switch (c) {
            case '&': return '&amp;';
            case '<': return '&lt;';
            case '>': return '&gt;';
            case '"': return '&quot;';
            case "'": return '&#039;';
            default: return c;
        }
    });
}

function navigateToEntity(url) {
    if (url && url !== '#') {
        window.location.href = url;
    }
}

// Auto-focus input on load
window.addEventListener('load', () => {
    document.getElementById('queryInput').focus();
});
</script>
