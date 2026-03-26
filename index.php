<?php
/**
 * Chimera PHP Installer — WordPress-style setup wizard.
 *
 * Upload this folder to your hosting, open in browser, configure, done.
 */

$configFile = __DIR__ . '/chimera/config.php';
$installed = file_exists($configFile);

// Handle form submission
$step = $_POST['step'] ?? ($_GET['step'] ?? ($installed ? 'done' : '1'));
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === '2') {
        // Save configuration
        $cfAccount = trim($_POST['cf_account_id'] ?? '');
        $cfToken = trim($_POST['cf_api_token'] ?? '');
        $model = trim($_POST['model'] ?? '@cf/ibm-granite/granite-4.0-h-micro');
        $agentName = trim($_POST['agent_name'] ?? 'Chimera');
        $systemPrompt = trim($_POST['system_prompt'] ?? '');
        $apiKey = trim($_POST['api_key'] ?? '');
        $enableShell = isset($_POST['enable_shell']);

        if ($cfAccount === '' || $cfToken === '') {
            $error = 'Cloudflare Account ID and API Token are required.';
            $step = '1';
        } else {
            // Test the API connection
            $testOk = testCloudflareConnection($cfAccount, $cfToken, $model);

            if ($testOk !== true) {
                $error = "Cloudflare API test failed: {$testOk}. Check your credentials.";
                $step = '1';
            } else {
                // Generate config
                $config = "<?php\nreturn " . var_export([
                    'cf_account_id' => $cfAccount,
                    'cf_api_token' => $cfToken,
                    'model' => $model,
                    'agent_name' => $agentName,
                    'system_prompt' => $systemPrompt ?: "You are {$agentName}, a helpful AI assistant. Be concise and direct.",
                    'data_dir' => __DIR__ . '/chimera/data',
                    'max_iterations' => 15,
                    'cors_origin' => '*',
                    'api_key' => $apiKey ?: bin2hex(random_bytes(16)),
                    'dimensions' => 768,
                    'enable_memory' => true,
                    'enable_shell' => $enableShell,
                    'enable_a2e' => true,
                ], true) . ";\n";

                // Ensure data directory exists
                @mkdir(__DIR__ . '/chimera/data', 0755, true);
                @mkdir(__DIR__ . '/chimera/data/memory', 0755, true);

                // Write config
                if (file_put_contents($configFile, $config) === false) {
                    $error = 'Cannot write config file. Check directory permissions (chmod 755).';
                    $step = '1';
                } else {
                    // Write .htaccess for security
                    file_put_contents(__DIR__ . '/chimera/data/.htaccess', "Deny from all\n");
                    file_put_contents(__DIR__ . '/chimera/.htaccess', "<FilesMatch \"config\\.php$\">\nDeny from all\n</FilesMatch>\n");

                    $step = 'done';
                    $success = 'Installation complete!';
                }
            }
        }
    }
}

function testCloudflareConnection(string $accountId, string $token, string $model): string|true
{
    $ch = curl_init("https://api.cloudflare.com/client/v4/accounts/{$accountId}/ai/run/{$model}");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}", 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['messages' => [['role' => 'user', 'content' => 'Say OK']], 'max_tokens' => 10]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) return 'Connection failed (curl error)';
    $data = json_decode($response, true);
    if ($code >= 400 || !($data['success'] ?? false)) {
        return $data['errors'][0]['message'] ?? "HTTP {$code}";
    }
    return true;
}

// Check PHP requirements
$requirements = [
    'PHP >= 8.1' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'curl extension' => extension_loaded('curl'),
    'json extension' => extension_loaded('json'),
    'openssl extension' => extension_loaded('openssl'),
    'Writable directory' => is_writable(__DIR__ . '/chimera'),
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Chimera PHP — Installer</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
.container { max-width: 600px; width: 100%; margin: 2rem; }
.card { background: #1e293b; border-radius: 12px; padding: 2rem; box-shadow: 0 4px 24px rgba(0,0,0,0.3); }
h1 { font-size: 1.8rem; margin-bottom: 0.5rem; background: linear-gradient(135deg, #818cf8, #c084fc); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
h2 { font-size: 1.2rem; margin: 1.5rem 0 0.5rem; color: #94a3b8; }
p { color: #94a3b8; margin-bottom: 1rem; }
label { display: block; margin-top: 1rem; font-weight: 500; font-size: 0.9rem; }
input[type="text"], input[type="password"], textarea, select { width: 100%; padding: 0.6rem 0.8rem; margin-top: 0.3rem; background: #0f172a; border: 1px solid #334155; border-radius: 6px; color: #e2e8f0; font-size: 0.9rem; font-family: 'SF Mono', 'Fira Code', monospace; }
textarea { height: 80px; resize: vertical; }
input:focus, textarea:focus, select:focus { outline: none; border-color: #818cf8; }
button { display: inline-block; margin-top: 1.5rem; padding: 0.7rem 2rem; background: linear-gradient(135deg, #818cf8, #c084fc); color: white; border: none; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer; }
button:hover { opacity: 0.9; }
.error { background: #7f1d1d; color: #fca5a5; padding: 0.8rem; border-radius: 6px; margin-bottom: 1rem; }
.success { background: #14532d; color: #86efac; padding: 0.8rem; border-radius: 6px; margin-bottom: 1rem; }
.check { display: flex; align-items: center; gap: 0.5rem; padding: 0.3rem 0; }
.check .ok { color: #4ade80; }
.check .fail { color: #f87171; }
.links { margin-top: 1.5rem; display: flex; gap: 1rem; flex-wrap: wrap; }
.links a { padding: 0.6rem 1.2rem; background: #334155; color: #e2e8f0; text-decoration: none; border-radius: 6px; font-size: 0.9rem; }
.links a:hover { background: #475569; }
small { color: #64748b; font-size: 0.8rem; }
.checkbox-row { display: flex; align-items: center; gap: 0.5rem; margin-top: 1rem; }
.checkbox-row input { width: auto; }
.subtitle { color: #64748b; font-size: 0.9rem; margin-bottom: 1.5rem; }
</style>
</head>
<body>
<div class="container">
<div class="card">

<?php if ($step === '1' || $step === 1): ?>
    <h1>Chimera PHP</h1>
    <p class="subtitle">Self-improving AI Agent — Installer</p>

    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <h2>System Requirements</h2>
    <?php foreach ($requirements as $name => $ok): ?>
        <div class="check">
            <span class="<?= $ok ? 'ok' : 'fail' ?>"><?= $ok ? '&#10003;' : '&#10007;' ?></span>
            <?= htmlspecialchars($name) ?>
        </div>
    <?php endforeach; ?>

    <?php if (in_array(false, $requirements, true)): ?>
        <div class="error" style="margin-top:1rem">Some requirements are not met. Please fix them before continuing.</div>
    <?php else: ?>

    <form method="POST">
        <input type="hidden" name="step" value="2">

        <h2>Cloudflare Workers AI</h2>
        <p>Free tier. Get credentials at <a href="https://dash.cloudflare.com" target="_blank" style="color:#818cf8">dash.cloudflare.com</a></p>

        <label>Account ID <small>(Settings → Account ID)</small></label>
        <input type="text" name="cf_account_id" value="<?= htmlspecialchars($_POST['cf_account_id'] ?? '') ?>" required placeholder="091122c40cc6...">

        <label>API Token <small>(My Profile → API Tokens → Create Token)</small></label>
        <input type="password" name="cf_api_token" value="<?= htmlspecialchars($_POST['cf_api_token'] ?? '') ?>" required placeholder="BbzwRnz1nPh6...">

        <label>LLM Model</label>
        <select name="model">
            <option value="@cf/ibm-granite/granite-4.0-h-micro">Granite 4.0 H-Micro (fastest, cheapest)</option>
            <option value="@cf/zai-org/glm-4.7-flash">GLM-4.7-Flash (reasoning, multilingual)</option>
            <option value="@cf/meta/llama-3.1-8b-instruct">Llama 3.1 8B (balanced)</option>
        </select>

        <h2>Agent Configuration</h2>

        <label>Agent Name</label>
        <input type="text" name="agent_name" value="<?= htmlspecialchars($_POST['agent_name'] ?? 'Chimera') ?>" placeholder="Chimera">

        <label>System Prompt <small>(who is your agent?)</small></label>
        <textarea name="system_prompt" placeholder="You are a helpful AI assistant..."><?= htmlspecialchars($_POST['system_prompt'] ?? '') ?></textarea>

        <h2>Security</h2>

        <label>API Key <small>(leave empty to auto-generate)</small></label>
        <input type="text" name="api_key" value="" placeholder="Auto-generated if empty">

        <div class="checkbox-row">
            <input type="checkbox" name="enable_shell" id="shell">
            <label for="shell" style="margin:0">Enable shell commands <small>(not recommended on shared hosting)</small></label>
        </div>

        <button type="submit">Install Chimera</button>
    </form>
    <?php endif; ?>

<?php elseif ($step === 'done'): ?>
    <h1>Chimera PHP Installed!</h1>

    <?php if ($success): ?><div class="success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <?php
    $config = file_exists($configFile) ? include($configFile) : [];
    $apiKey = $config['api_key'] ?? '';
    $chatUrl = dirname($_SERVER['SCRIPT_NAME']) . '/chimera/chat.php';
    $apiUrl = dirname($_SERVER['SCRIPT_NAME']) . '/chimera/api.php';
    ?>

    <p>Your agent is ready. Here are your endpoints:</p>

    <h2>Chat Interface</h2>
    <p><a href="<?= $chatUrl ?>" style="color:#818cf8" target="_blank"><?= $chatUrl ?></a></p>

    <h2>API Endpoint</h2>
    <p><code style="background:#0f172a;padding:0.3rem 0.6rem;border-radius:4px"><?= $apiUrl ?></code></p>

    <h2>API Key</h2>
    <p><code style="background:#0f172a;padding:0.3rem 0.6rem;border-radius:4px;word-break:break-all"><?= htmlspecialchars($apiKey) ?></code></p>
    <small>Save this key — you'll need it for API access.</small>

    <div class="links">
        <a href="<?= $chatUrl ?>">Open Chat</a>
        <a href="?step=1">Reconfigure</a>
    </div>

    <p style="margin-top:1.5rem"><small>Tip: Delete <code>index.php</code> (this installer) after setup for security.</small></p>

<?php endif; ?>

</div>
</div>
</body>
</html>
