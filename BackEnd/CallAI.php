<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // dev only

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && ($err['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        http_response_code(500);
        $msg = [
            'error' => 'Fatal PHP error',
            'message' => $err['message'],
            'file' => $err['file'],
            'line' => $err['line']
        ];
        echo json_encode($msg);
    }
});

try {
    $raw = file_get_contents('php://input');
    $in = json_decode($raw, true);
    $prompt = trim($in['prompt'] ?? '');

    if ($prompt === '') {
        http_response_code(400);
        echo json_encode(['error' => 'No prompt provided.']);
        exit;
    }

    $lm_url = "http://127.0.0.1:1234/v1/chat/completions";

    $system_instructions = "You are a concise security assistant. When asked to analyze sensor or log data return ONLY JSON with keys: status (OK|WARNING|ALERT), summary (one short sentence). If given free text, return helpful short JSON: {\"status\":\"OK\",\"summary\":\"...\"}";

    $postData = [
        "model" => "llama-3.2-1b-instruct",
        "messages" => [
            ["role" => "system", "content" => $system_instructions],
            ["role" => "user", "content" => $prompt]
        ],
        "temperature" => 0.2,
        "max_tokens" => 300
    ];

    $options = [
        "http" => [
            "header"  => "Content-Type: application/json\r\n",
            "method"  => "POST",
            "content" => json_encode($postData),
            "timeout" => 20
        ]
    ];

    $context  = stream_context_create($options);
    $result = @file_get_contents($lm_url, false, $context);

    if ($result === FALSE) {
        $err = error_get_last();
        http_response_code(502);
        echo json_encode([
            'error' => 'Failed to reach local AI server',
            'lm_url' => $lm_url,
            'php_error' => $err ? $err['message'] : null
        ]);
        exit;
    }

    // parse LM Studio standard reply
    $lm_json = json_decode($result, true);
    if (!is_array($lm_json)) {
        echo json_encode(['raw_lm' => $result]);
        exit;
    }

    // extract the chat content
    $ai_text = '';
    if (isset($lm_json['choices'][0]['message']['content'])) {
        $ai_text = $lm_json['choices'][0]['message']['content'];
    } elseif (isset($lm_json['choices'][0]['text'])) {
        $ai_text = $lm_json['choices'][0]['text'];
    } else {
        $ai_text = json_encode($lm_json);
    }

    $ai_text_clean = preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($ai_text));
    $parsed = json_decode($ai_text_clean, true);

    echo json_encode([
        'ok' => true,
        'ai_text' => $ai_text_clean,
        'ai_json' => ($parsed !== null ? $parsed : null),
        'lm_raw' => $lm_json
    ], JSON_UNESCAPED_SLASHES);

} catch (Exception $ex) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Server exception',
        'message' => $ex->getMessage(),
        'file' => $ex->getFile(),
        'line' => $ex->getLine()
    ]);
}