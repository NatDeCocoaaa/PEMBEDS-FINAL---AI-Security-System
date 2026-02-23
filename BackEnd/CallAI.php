<?php
header('Content-Type: application/json; charset=utf-8');

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'aegis_node_db';

$lm_url = "http://127.0.0.1:1234/v1/chat/completions";

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
$prompt = trim($in['prompt'] ?? '');

if ($prompt === '') {
    http_response_code(400);
    echo json_encode(['error' => 'No prompt provided.']);
    exit;
}

$postData = [
    "model" => "llama-3.2-1b-instruct",
    "messages" => [
        ["role" => "system", "content" => "You are a concise security assistant. When asked to analyze sensor/log data return only JSON with keys: status (OK|WARNING|ALERT), summary (one short sentence). Otherwise be brief."],
        ["role" => "user", "content" => $prompt]
    ],
    "temperature" => 0.2,
    "max_tokens" => 350
];

$ch = curl_init($lm_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
curl_setopt($ch, CURLOPT_TIMEOUT, 20);

$result = curl_exec($ch);
$errNo = curl_errno($ch);
$err = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// if AI is down, still store the prompt and a failure message
$ai_text_clean = '';
if ($errNo !== 0) {
    $ai_text_clean = "AI request failed: ($errNo) $err";
} else {
    if ($httpCode < 200 || $httpCode >= 300) {
        $ai_text_clean = "AI returned HTTP $httpCode: " . substr($result, 0, 200);
    } else {
        $lm_json = json_decode($result, true);
        if (is_array($lm_json)) {
            if (isset($lm_json['choices'][0]['message']['content'])) {
                $ai_text = $lm_json['choices'][0]['message']['content'];
            } elseif (isset($lm_json['choices'][0]['text'])) {
                $ai_text = $lm_json['choices'][0]['text'];
            } else {
                $ai_text = json_encode($lm_json);
            }
            $ai_text_clean = preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($ai_text));
        } else {
            $ai_text_clean = $result;
        }
    }
}

try {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) throw new Exception('DB connect error: ' . $mysqli->connect_error);

    $stmt = $mysqli->prepare("INSERT INTO ai_logs (prompt, response) VALUES (?, ?)");
    if (!$stmt) throw new Exception('Prepare failed: ' . $mysqli->error);
    $stmt->bind_param('ss', $prompt, $ai_text_clean);
    $ok = $stmt->execute();
    if (!$ok) {
        // if logging fails, we still return the AI output to frontend but include a warning
        $db_log_error = $stmt->error;
    } else {
        $db_log_error = null;
    }
    $stmt->close();
    $mysqli->close();
} catch (Exception $e) {
    $db_log_error = $e->getMessage();
}

$parsed_ai_json = null;
$maybe = json_decode($ai_text_clean, true);
if ($maybe !== null) $parsed_ai_json = $maybe;

$response = [
    'ok' => true,
    'ai_text' => $ai_text_clean,
    'ai_json' => $parsed_ai_json,
];

if (!empty($db_log_error)) $response['db_log_error'] = $db_log_error;

echo json_encode($response, JSON_UNESCAPED_SLASHES);
exit;