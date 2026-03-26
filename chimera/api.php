<?php
/**
 * Chimera PHP — Unified HTTP API endpoint.
 *
 * POST /chimera/api.php?action=chat     { "message": "Hello" }
 * POST /chimera/api.php?action=recall   { "query": "preferences" }
 * POST /chimera/api.php?action=dream    {}
 * GET  /chimera/api.php?action=stats
 * GET  /chimera/api.php?action=health
 */

declare(strict_types=1);

// Load config
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(503);
    echo json_encode(['error' => 'Not installed. Run the installer first.']);
    exit;
}
$config = require $configFile;

// Autoload
require_once __DIR__ . '/vendor/autoload.php';

// CORS
header('Access-Control-Allow-Origin: ' . ($config['cors_origin'] ?? '*'));
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Auth check
$apiKey = $config['api_key'] ?? '';
if ($apiKey !== '') {
    $action = $_GET['action'] ?? '';
    if ($action !== 'health') {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : ($_SERVER['HTTP_X_API_KEY'] ?? '');
        if (!hash_equals($apiKey, $token)) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
    }
}

// Initialize agent
$agent = createAgent($config);

$action = $_GET['action'] ?? 'health';
$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];

try {
    $result = match ($action) {
        'health' => ['status' => 'ok', 'agent' => $config['agent_name'] ?? 'Chimera', 'model' => $config['model'], 'tools' => $agent->tools->count()],
        'stats' => getStats($agent, $config),
        'chat' => handleChat($agent, $body),
        'recall' => handleRecall($config, $body),
        'dream' => handleDream($config),
        default => throw new \RuntimeException("Unknown action: {$action}"),
    };
    echo json_encode($result, JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

// ── Handlers ────────────────────────────────────────────────────

function createAgent(array $config): \ChimeraPHP\Chimera
{
    // Write .env for Chimera Config
    $envContent = "CHIMERA_LLM_PROVIDER=workers-ai\n"
        . "CHIMERA_LLM_MODEL={$config['model']}\n"
        . "CF_ACCOUNT_ID={$config['cf_account_id']}\n"
        . "CF_API_TOKEN={$config['cf_api_token']}\n"
        . "CHIMERA_MAX_ITERATIONS=" . ($config['max_iterations'] ?? 15) . "\n"
        . "CHIMERA_DATA_DIR={$config['data_dir']}\n";

    $envFile = dirname(__DIR__) . '/.env';
    if (!file_exists($envFile)) {
        file_put_contents($envFile, $envContent);
    }

    return new \ChimeraPHP\Chimera(new \ChimeraPHP\Config());
}

function handleChat(\ChimeraPHP\Chimera $agent, array $body): array
{
    $message = $body['message'] ?? '';
    if ($message === '') throw new \RuntimeException('Required: message');

    $result = $agent->chat($message);
    return [
        'response' => $result['content'],
        'iterations' => $result['iterations'],
        'tokens' => $result['totalTokens'],
        'tools_used' => $result['toolsUsed'],
        'learned' => $result['learned'] ?? [],
    ];
}

function handleRecall(array $config, array $body): array
{
    $query = $body['query'] ?? '';
    if ($query === '') throw new \RuntimeException('Required: query');

    if (!class_exists(\PHPAgentMemory\AgentMemory::class)) {
        return ['error' => 'php-agent-memory not installed'];
    }

    $embedFn = createEmbedFn($config);
    $memory = createMemory($config, $embedFn);
    $vec = $embedFn($query);
    $ctx = $memory->recall('chimera', 'user', $query, $vec);

    return ['formatted' => $ctx->formatted, 'totalItems' => $ctx->totalItems];
}

function handleDream(array $config): array
{
    if (!class_exists(\PHPAgentMemory\AgentMemory::class)) {
        return ['error' => 'php-agent-memory not installed'];
    }

    $embedFn = createEmbedFn($config);
    $memory = createMemory($config, $embedFn);

    $llm = new \PHPAgentMemory\Consolidation\CloudflareLlmProvider(
        $config['cf_account_id'], $config['cf_api_token'], $config['model'],
    );
    $memConfig = new \PHPAgentMemory\Config(
        dataDir: $config['data_dir'] . '/memory', dimensions: 768, quantized: true,
        llmProvider: $llm, embedFn: $embedFn,
    );
    $memoryWithLlm = new \PHPAgentMemory\AgentMemory($memConfig);
    $report = $memoryWithLlm->dream('chimera', 'user');

    return $report->toArray();
}

function getStats(\ChimeraPHP\Chimera $agent, array $config): array
{
    $stats = ['agent' => $config['agent_name'], 'model' => $config['model'], 'tools' => $agent->tools->count()];
    if (class_exists(\PHPAgentMemory\AgentMemory::class)) {
        $embedFn = createEmbedFn($config);
        $memory = createMemory($config, $embedFn);
        $stats['memory'] = $memory->stats();
    }
    return $stats;
}

function createEmbedFn(array $config): callable
{
    return function (string $text) use ($config): array {
        $ch = curl_init("https://api.cloudflare.com/client/v4/accounts/{$config['cf_account_id']}/ai/run/@cf/google/embeddinggemma-300m");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$config['cf_api_token']}", 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['text' => [$text]]),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $data = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return $data['result']['data'][0] ?? array_fill(0, 768, 0.0);
    };
}

function createMemory(array $config, callable $embedFn): \PHPAgentMemory\AgentMemory
{
    return new \PHPAgentMemory\AgentMemory(new \PHPAgentMemory\Config(
        dataDir: $config['data_dir'] . '/memory', dimensions: 768, quantized: true, embedFn: $embedFn,
    ));
}
