<?php
/**
 * Chimera PHP — Web Chat UI.
 * Handles chat directly (no proxy) — API key stays server-side.
 */
declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) { header('Location: ../index.php'); exit; }
$config = require $configFile;
$agentName = $config['agent_name'] ?? 'Chimera';

// Handle chat POST directly (no curl proxy — much faster)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'chat') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $message = $body['message'] ?? '';
    if ($message === '') { echo json_encode(['error' => 'Empty message']); exit; }

    require_once __DIR__ . '/vendor/autoload.php';

    // Set env vars from config
    putenv("CHIMERA_LLM_PROVIDER=workers-ai");
    putenv("CHIMERA_LLM_MODEL={$config['model']}");
    putenv("CF_ACCOUNT_ID={$config['cf_account_id']}");
    putenv("CF_API_TOKEN={$config['cf_api_token']}");
    putenv("CHIMERA_MAX_ITERATIONS=" . ($config['max_iterations'] ?? 15));
    putenv("CHIMERA_DATA_DIR={$config['data_dir']}");

    try {
        $agent = new \ChimeraPHP\Chimera(new \ChimeraPHP\Config());
        $result = $agent->chat($message);
        echo json_encode([
            'response' => $result['content'],
            'iterations' => $result['iterations'],
            'tokens' => $result['totalTokens'],
            'tools_used' => $result['toolsUsed'],
            'learned' => $result['learned'] ?? [],
        ], JSON_UNESCAPED_SLASHES);
    } catch (\Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($agentName) ?> — Chat</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #0f172a; color: #e2e8f0; height: 100vh; display: flex; flex-direction: column; }
header { padding: 1rem 1.5rem; background: #1e293b; border-bottom: 1px solid #334155; display: flex; align-items: center; justify-content: space-between; }
header h1 { font-size: 1.1rem; background: linear-gradient(135deg, #818cf8, #c084fc); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
header .status { font-size: 0.8rem; color: #64748b; }
#messages { flex: 1; overflow-y: auto; padding: 1rem 1.5rem; display: flex; flex-direction: column; gap: 0.8rem; }
.msg { max-width: 80%; padding: 0.8rem 1rem; border-radius: 12px; line-height: 1.5; font-size: 0.9rem; white-space: pre-wrap; word-wrap: break-word; }
.msg.user { align-self: flex-end; background: #4f46e5; color: white; border-bottom-right-radius: 4px; }
.msg.assistant { align-self: flex-start; background: #1e293b; border: 1px solid #334155; border-bottom-left-radius: 4px; }
.msg.system { align-self: center; background: #1e293b; color: #64748b; font-size: 0.8rem; padding: 0.4rem 0.8rem; border-radius: 20px; }
.msg .meta { font-size: 0.75rem; color: #64748b; margin-top: 0.4rem; }
.typing { align-self: flex-start; color: #64748b; font-size: 0.85rem; padding: 0.5rem 0; }
.typing::after { content: '...'; animation: dots 1.5s infinite; }
@keyframes dots { 0%, 20% { content: '.'; } 40% { content: '..'; } 60%, 100% { content: '...'; } }
#input-area { padding: 1rem 1.5rem; background: #1e293b; border-top: 1px solid #334155; display: flex; gap: 0.5rem; }
#input { flex: 1; padding: 0.7rem 1rem; background: #0f172a; border: 1px solid #334155; border-radius: 8px; color: #e2e8f0; font-size: 0.9rem; font-family: inherit; outline: none; resize: none; }
#input:focus { border-color: #818cf8; }
#send { padding: 0.7rem 1.5rem; background: linear-gradient(135deg, #818cf8, #c084fc); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; }
#send:hover { opacity: 0.9; }
#send:disabled { opacity: 0.5; cursor: not-allowed; }
</style>
</head>
<body>
<header>
    <h1><?= htmlspecialchars($agentName) ?></h1>
    <span class="status">Model: <?= htmlspecialchars($config['model'] ?? 'unknown') ?></span>
</header>
<div id="messages">
    <div class="msg system">Type a message to start chatting with <?= htmlspecialchars($agentName) ?></div>
</div>
<div id="input-area">
    <textarea id="input" rows="1" placeholder="Type your message..." autofocus></textarea>
    <button id="send" onclick="sendMessage()">Send</button>
</div>
<script>
const API_URL = 'chat.php?action=chat';
const messagesEl = document.getElementById('messages');
const inputEl = document.getElementById('input');
const sendBtn = document.getElementById('send');

inputEl.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
});
inputEl.addEventListener('input', () => {
    inputEl.style.height = 'auto';
    inputEl.style.height = Math.min(inputEl.scrollHeight, 120) + 'px';
});

async function sendMessage() {
    const text = inputEl.value.trim();
    if (!text) return;
    addMessage('user', text);
    inputEl.value = '';
    inputEl.style.height = 'auto';
    sendBtn.disabled = true;

    const typing = document.createElement('div');
    typing.className = 'typing';
    typing.textContent = 'Thinking';
    messagesEl.appendChild(typing);
    messagesEl.scrollTop = messagesEl.scrollHeight;

    try {
        const res = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: text }),
        });
        const data = await res.json();
        typing.remove();

        if (data.error) {
            addMessage('system', 'Error: ' + data.error);
        } else {
            const meta = `${data.iterations || '?'} iter · ${data.tokens || '?'} tokens` +
                (data.tools_used?.length ? ' · ' + data.tools_used.join(', ') : '');
            addMessage('assistant', data.response || 'No response', meta);
        }
    } catch (err) {
        typing.remove();
        addMessage('system', 'Error: ' + err.message);
    }
    sendBtn.disabled = false;
    inputEl.focus();
}

function addMessage(role, content, meta = '') {
    const div = document.createElement('div');
    div.className = 'msg ' + role;
    div.textContent = content;
    if (meta) {
        const m = document.createElement('div');
        m.className = 'meta';
        m.textContent = meta;
        div.appendChild(m);
    }
    messagesEl.appendChild(div);
    messagesEl.scrollTop = messagesEl.scrollHeight;
}
</script>
</body>
</html>
