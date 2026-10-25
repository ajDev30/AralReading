<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized session. Please log in again.']);
    exit;
}

/**
 * Load ASR settings from MySQL `settings` table with fallback to v536/.env
 */
function get_asr_settings(PDO $pdo): array {
    $settings = [];
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['setting_key']] = trim((string)$row['setting_value']);
        }
    } catch (Exception $e) {
        // Fallback if table query fails
    }

    $env_file = __DIR__ . '/v536/.env';
    if (is_file($env_file) && is_readable($env_file)) {
        $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim(preg_replace('/^export\s+/', '', $k));
            $v = trim($v, " \t\n\r\0\x0B'\"");
            if ($k === 'AZURE_OPENAI_API_KEY' && empty($settings['azure_openai_key'])) {
                $settings['azure_openai_key'] = $v;
            } elseif ($k === 'AZURE_SPEECH_KEY' && empty($settings['azure_speech_key'])) {
                $settings['azure_speech_key'] = $v;
            } elseif ($k === 'AZURE_SPEECH_REGION' && empty($settings['azure_region'])) {
                $settings['azure_region'] = $v;
            } elseif ($k === 'AZURE_LANGUAGE' && empty($settings['azure_language'])) {
                $settings['azure_language'] = $v;
            } elseif ($k === 'OPENAI_MODEL' && empty($settings['openai_model'])) {
                $settings['openai_model'] = $v;
            }
        }
    }

    return [
        'asr_enabled'      => (!isset($settings['asr_enabled']) || $settings['asr_enabled'] === '' || $settings['asr_enabled'] === '1'),
        'openai_key'       => $settings['azure_openai_key'] ?? '',
        'openai_model'     => !empty($settings['openai_model']) ? $settings['openai_model'] : 'whisper-1',
        'azure_speech_key' => $settings['azure_speech_key'] ?? '',
        'azure_region'     => !empty($settings['azure_region']) ? $settings['azure_region'] : 'southeastasia',
        'azure_language'   => !empty($settings['azure_language']) ? $settings['azure_language'] : 'en-US',
    ];
}

$action = $_GET['action'] ?? '';
$cfg = get_asr_settings($pdo);

if ($action === 'health') {
    echo json_encode([
        'status'         => ($cfg['asr_enabled'] && !empty($cfg['openai_key']) && !empty($cfg['azure_speech_key'])) ? 'ok' : 'degraded',
        'service'        => 'readingassessment-php-asr',
        'asr_enabled'    => $cfg['asr_enabled'],
        'openai_key_set' => !empty($cfg['openai_key']),
        'azure_key_set'  => !empty($cfg['azure_speech_key']),
        'azure_region'   => $cfg['azure_region'],
        'protocol'       => 'php-token-browser-sdk-continuous',
    ]);
    exit;
}

if (!$cfg['asr_enabled']) {
    http_response_code(503);
    echo json_encode(['error' => 'ASR Service is currently disabled in Teacher Settings.']);
    exit;
}

if ($action === 'realtime_token') {
    if (empty($cfg['openai_key'])) {
        http_response_code(500);
        echo json_encode(['error' => 'OpenAI API Key is not configured in Teacher Settings.', 'stage' => 'configuration']);
        exit;
    }

    $raw_body = file_get_contents('php://input');
    $body = json_decode($raw_body ?: '{}', true) ?: [];
    $passage = (string)($body['passage'] ?? '');

    // Clean passage and extract up to 120 unique keywords (identical to v536/service.py)
    $clean_passage = mb_substr(trim(preg_replace('/\s+/u', ' ', $passage)), 0, 6000);
    $words_raw = preg_split('/\s+/u', $clean_passage, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $unique_words = [];
    $seen = [];
    foreach ($words_raw as $w) {
        $cleaned = preg_replace("/^[^a-zA-Z0-9'-]+|[^a-zA-Z0-9'-]+$/u", '', $w);
        if ($cleaned === '') {
            continue;
        }
        $lower = strtolower($cleaned);
        if (isset($seen[$lower])) {
            continue;
        }
        $seen[$lower] = true;
        $unique_words[] = $cleaned;
        if (count($unique_words) >= 120) {
            break;
        }
    }

    $user_prompt = substr(
        "Student is reading a supplied passage. Use passage context only to improve recognition of names, vocabulary, and word boundaries. " .
        "Transcribe ONLY audible speech. Do not autocorrect to the passage, invent or delete words, reorder, paraphrase, or summarize. " .
        "Preserve repeats, restarts, substitutions, reversals, transpositions, and self-corrections; keep audible attempts separate. " .
        "Pay attention to short function words (a, an, the, to, in, of, and, with), but never insert one unless audible. Prefer audio over passage context.",
        0,
        1024
    );

    // Normalize transcription model if an incompatible realtime chat model name was saved
    $transcription_model = $cfg['openai_model'];
    if (stripos($transcription_model, 'realtime') !== false) {
        $transcription_model = 'whisper-1';
    }

    $session_payload = [
        'session' => [
            'type' => 'transcription',
            'audio' => [
                'input' => [
                    'transcription' => [
                        'model'    => $transcription_model,
                        'prompt'   => $user_prompt,
                        'keywords' => $unique_words,
                    ],
                    'turn_detection' => null,
                ],
            ],
        ],
    ];

    $ch = curl_init('https://api.openai.com/v1/realtime/client_secrets');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $cfg['openai_key'],
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($session_payload),
    ]);

    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($response_body === false) {
        http_response_code(502);
        echo json_encode(['error' => 'OpenAI connection error: ' . $curl_err]);
        exit;
    }

    $res_data = json_decode($response_body, true);
    if ($http_code < 200 || $http_code >= 300) {
        http_response_code(500);
        $err_msg = $res_data['error']['message'] ?? $response_body;
        echo json_encode(['error' => "OpenAI Error {$http_code}: {$err_msg}"]);
        exit;
    }

    $client_secret = $res_data['value'] ?? ($res_data['client_secret']['value'] ?? null);
    echo json_encode([
        'client_secret' => $client_secret,
        'model'         => 'gpt-live-transcribe',
        'type'          => 'transcription',
        'context'       => 'none',
        'delay'         => 600,
    ]);
    exit;
}

if ($action === 'azure_token') {
    if (empty($cfg['azure_speech_key']) || empty($cfg['azure_region'])) {
        http_response_code(500);
        echo json_encode(['error' => 'Azure Speech Key and Region are required in Teacher Settings.']);
        exit;
    }

    $region = preg_replace('/[^a-zA-Z0-9-]/', '', $cfg['azure_region']);
    $token_url = "https://{$region}.api.cognitive.microsoft.com/sts/v1.0/issueToken";

    $ch = curl_init($token_url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Ocp-Apim-Subscription-Key: ' . $cfg['azure_speech_key'],
            'Content-Type: application/x-www-form-urlencoded',
            'Content-Length: 0',
        ],
        CURLOPT_POSTFIELDS     => '',
    ]);

    $token = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($token === false) {
        http_response_code(502);
        echo json_encode(['error' => 'Azure STS connection error: ' . $curl_err]);
        exit;
    }

    if ($http_code < 200 || $http_code >= 300 || empty(trim($token))) {
        http_response_code(502);
        echo json_encode(['error' => "Azure STS Error ({$http_code}): Failed to issue Speech authorization token. Verify Azure Speech Key and Region."]);
        exit;
    }

    echo json_encode([
        'token'    => trim($token),
        'region'   => $region,
        'language' => $cfg['azure_language'],
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action. Use action=realtime_token, action=azure_token, or action=health.']);
