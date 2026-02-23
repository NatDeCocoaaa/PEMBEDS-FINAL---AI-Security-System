<?php
header('Content-Type: application/json; charset=utf-8');

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'aegis_node_db';
$lm_url = "http://127.0.0.1:1234/v1/chat/completions";

$ALLOWED_EVENTS = ["NO_ACTIVITY","APPROACH","MOTION_DETECTED","SOUND_SPIKE","LOCK_ENGAGED","CLEAR"];
$FORBIDDEN_TOKENS = ['temperature','humidity','gas','camera','battery'];

function extract_json_from_text($text) {
    $txt = preg_replace("/\r\n?/", "\n", $text);
    if (preg_match_all('/```(?:json)?\s*(\{.*?\})\s*```/ims', $txt, $matches)) {
        foreach ($matches[1] as $candidate) {
            $dec = json_decode($candidate, true);
            if ($dec !== null) {
                if (is_array($dec) && (isset($dec['analysis']) || isset($dec['narrative']))) return $dec;
                return $dec;
            }
        }
    }
    $objects = [];
    if (preg_match_all('/\{(?:[^{}]|(?R))*\}/xms', $txt, $matches_all)) {
        foreach ($matches_all[0] as $block) {
            $dec = json_decode($block, true);
            if ($dec !== null) $objects[] = $dec;
        }
    }
    // if we found parsed JSON objects, select the one that contains 'analysis' or 'narrative'
    if (!empty($objects)) {
        foreach ($objects as $o) {
            if (is_array($o) && (array_key_exists('analysis', $o) || array_key_exists('narrative', $o))) {
                return $o;
            }
        }
        foreach ($objects as $o) {
            if (is_array($o) && array_values($o) === $o) {
                return ['analysis' => $o, 'narrative' => '']; 
            }
        }
        $mergedEvents = [];
        foreach ($objects as $o) {
            if (is_array($o) && isset($o['time']) && isset($o['event'])) {
                $mergedEvents[] = $o;
            }
        }
        if (!empty($mergedEvents)) {
            return ['analysis' => $mergedEvents, 'narrative' => ''];
        }
        return $objects[0];
    }
    $maybe_full = json_decode($txt, true);
    if ($maybe_full !== null) return $maybe_full;
    return null;
}

function validate_ai_output($ai_parsed, $sensor_context, $minDist, $maxDist, $minSound, $maxSound, $ALLOWED_EVENTS, $FORBIDDEN_TOKENS) {
    $errors = [];

    if (!is_array($ai_parsed)) {
        $errors[] = "Response is not a JSON object/array.";
        return [false, $errors];
    }

    if (!array_key_exists('analysis', $ai_parsed) || !array_key_exists('narrative', $ai_parsed)) {
        $errors[] = "Missing required top-level keys 'analysis' and/or 'narrative'.";
        return [false, $errors];
    }

    if (!is_array($ai_parsed['analysis'])) {
        $errors[] = "'analysis' must be an array.";
        return [false, $errors];
    }

    $available_times = array_map(function($r){ return $r['created_at']; }, $sensor_context);

    foreach ($ai_parsed['analysis'] as $i => $item) {
        if (!is_array($item)) {
            $errors[] = "analysis[$i] is not an object.";
            continue;
        }
        if (!isset($item['time']) || !isset($item['event']) || !isset($item['reason'])) {
            $errors[] = "analysis[$i] missing required keys (time,event,reason).";
            continue;
        }
        if (!in_array($item['event'], $ALLOWED_EVENTS, true)) {
            $errors[] = "analysis[$i].event '{$item['event']}' not one of allowed events.";
        }
        $item_time = strtotime($item['time']);
        $matched = false;
        if ($item_time !== false) {
            foreach ($available_times as $db_time_raw) {
                $db_time = strtotime($db_time_raw);
                if ($db_time !== false && abs($item_time - $db_time) <= 5) { $matched = true; break; }
                if (strpos($db_time_raw, $item['time']) !== false || strpos($item['time'], $db_time_raw) !== false) { $matched = true; break; }
            }
        } else {
            foreach ($available_times as $db_time_raw) {
                if (strpos($db_time_raw, $item['time']) !== false || strpos($item['time'], $db_time_raw) !== false) { $matched = true; break; }
            }
        }
        if (!$matched) {
            $errors[] = "analysis[$i].time '{$item['time']}' not found or not close enough to provided sensor context timestamps.";
        }
        $low = strtolower($item['reason']);
        foreach ($FORBIDDEN_TOKENS as $f) {
            if (strpos($low, $f) !== false) {
                $errors[] = "analysis[$i].reason mentions forbidden token: $f";
            }
        }
    }

    if (!is_string($ai_parsed['narrative'])) {
        $errors[] = "narrative must be a string.";
    } else {
        $low = strtolower($ai_parsed['narrative']);
        foreach ($FORBIDDEN_TOKENS as $f) {
            if (strpos($low, $f) !== false) {
                $errors[] = "narrative mentions forbidden token: $f";
            }
        }
    }

    if (isset($ai_parsed['evidence']) && is_array($ai_parsed['evidence'])) {
        $e = $ai_parsed['evidence'];
        if (isset($e['min_distance'])) {
            $v = floatval($e['min_distance']);
            if ($v < $minDist - 0.0001 || $v > $maxDist + 0.0001) {
                $errors[] = "evidence.min_distance ($v) outside observed bounds [$minDist,$maxDist].";
            }
        }
        if (isset($e['max_distance'])) {
            $v = floatval($e['max_distance']);
            if ($v < $minDist - 0.0001 || $v > $maxDist + 0.0001) {
                $errors[] = "evidence.max_distance ($v) outside observed bounds [$minDist,$maxDist].";
            }
        }
        if (isset($e['max_sound'])) {
            $v = floatval($e['max_sound']);
            if ($v < $minSound - 0.0001 || $v > $maxSound + 0.0001) {
                $errors[] = "evidence.max_sound ($v) outside observed bounds [$minSound,$maxSound].";
            }
        }
    }

    return [count($errors) === 0, $errors];
}

function build_retry_message($sensor_context, $minDist, $maxDist, $minSound, $maxSound, $validation_errors, $original_prompt) {
    $ctx = json_encode($sensor_context, JSON_UNESCAPED_SLASHES);
    $errtext = implode("; ", $validation_errors);
    $msg = "Previous model output failed validation for these reasons: " . $errtext . "\n\n"
         . "Please re-generate the required JSON (analysis + narrative) following the exact system rules. "
         . "Use ONLY the supplied sensor rows below (do NOT invent other sensors or numbers). "
         . "Sensor context (newest first):\n" . $ctx
         . "\n\nObserved numeric bounds: distance_min={$minDist},distance_max={$maxDist},sound_min={$minSound},sound_max={$maxSound}.\n\n"
         . "USER REQUEST: " . $original_prompt . "\n\nReturn JSON only (no prose).";
    return $msg;
}

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
$prompt = trim($in['prompt'] ?? '');

if ($prompt === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'No prompt provided.']);
    exit;
}

$sensor_context = [];
try {
    $db = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($db->connect_errno) throw new Exception('DB connect error: ' . $db->connect_error);

    $sqlCtx = "SELECT ultrasonic_distance_cm AS distance, sound_db, pir_status, lock_state, created_at
               FROM sensor_logs
               WHERE device_id = ?
               ORDER BY created_at DESC
               LIMIT 10";
    $stmtCtx = $db->prepare($sqlCtx);
    if (!$stmtCtx) throw new Exception('Prepare failed: ' . $db->error);
    $device_id_for_ctx = 1;
    $stmtCtx->bind_param('i', $device_id_for_ctx);
    $stmtCtx->execute();
    $resCtx = $stmtCtx->get_result();
    while ($r = $resCtx->fetch_assoc()) $sensor_context[] = $r;
    $stmtCtx->close();
    $db->close();
} catch (Exception $e) {
    $sensor_context = [];
}

$minDist = null; $maxDist = null; $minSound = null; $maxSound = null;
foreach ($sensor_context as $row) {
    if (isset($row['distance']) && $row['distance'] !== null) {
        $d = floatval($row['distance']);
        $minDist = ($minDist === null ? $d : min($minDist, $d));
        $maxDist = ($maxDist === null ? $d : max($maxDist, $d));
    }
    if (isset($row['sound_db']) && $row['sound_db'] !== null) {
        $s = floatval($row['sound_db']);
        $minSound = ($minSound === null ? $s : min($minSound, $s));
        $maxSound = ($maxSound === null ? $s : max($maxSound, $s));
    }
}
if ($minDist === null) { $minDist = 0; $maxDist = 400; }
if ($minSound === null) { $minSound = 0; $maxSound = 140; }

// build system prompt with examples for format + user message
$system_instructions = <<<SYS
IMPORTANT: Return exactly **one** JSON object and nothing else. Do not include any explanatory text, headings, or multiple JSON objects. If you must present multiple items, place them inside the single object's "analysis" array.
You are a concise security analyst. IMPORTANT RULES (follow exactly):

1) The only available sensor fields are:
   - distance (ultrasonic_distance_cm) numeric (cm)
   - sound_db numeric (raw)
   - pir_status string: "STABLE" or "MOTION"
   - lock_state string: "HARD-LOCK" or "UNLOCKED"
   - created_at timestamp

2) DO NOT MENTION OR INVENT other sensors or attributes (for example: "temperature", "humidity", "gas", "camera", "battery" are NOT available). If asked about items not present, say they are not available.

3) OUTPUT SCHEMA: Always return ONE valid JSON object with exactly these keys:
   {
     "analysis": [ { "time": "<created_at>", "event": "<one of allowed labels>", "reason": "<one-sentence reason>" }, ... ],
     "narrative": "<one short paragraph summarizing events in chronological order>"
   }

4) ALLOWED EVENT LABELS (use only these):
   "NO_ACTIVITY", "APPROACH", "MOTION_DETECTED", "SOUND_SPIKE", "LOCK_ENGAGED", "CLEAR"

5) ANALYSIS RULES:
   - Each analysis item must be derived only from the supplied sensor rows.
   - 'reason' must reference only present fields (distance, sound_db, pir_status, lock_state).
   - Use exact created_at timestamps from the provided rows for the 'time' field.

6) NARRATIVE RULES:
   - One paragraph, <= 60 words, present-tense.
   - Do not introduce new facts or numeric values beyond those in the analysis.

7) If no notable events, return analysis: [] and narrative: "No notable events observed in the provided sensor rows."

8) Return JSON only — no extra prose.
SYS;

// examples: user -> assistant (assistant provides the required JSON)
// Example 1: no activity
$example_user_1 = "Here are rows: [{\"distance\":180,\"sound_db\":32,\"pir_status\":\"STABLE\",\"lock_state\":\"UNLOCKED\",\"created_at\":\"2026-02-24 03:00:00\"}]. Analyze.";
$example_assistant_1 = json_encode([
    "analysis" => [],
    "narrative" => "No notable events observed in the provided sensor rows."
], JSON_UNESCAPED_SLASHES);

$example_user_2 = "Here are rows: [{\"distance\":180,\"sound_db\":32,\"pir_status\":\"STABLE\",\"lock_state\":\"UNLOCKED\",\"created_at\":\"2026-02-24 03:05:00\"},{\"distance\":35,\"sound_db\":80,\"pir_status\":\"MOTION\",\"lock_state\":\"HARD-LOCK\",\"created_at\":\"2026-02-24 03:07:00\"}]. Analyze.";
$example_assistant_2 = json_encode([
    "analysis" => [
        ["time"=>"2026-02-24 03:05:00", "event"=>"APPROACH", "reason"=>"distance dropped from 180 cm to 35 cm"],
        ["time"=>"2026-02-24 03:07:00", "event"=>"MOTION_DETECTED", "reason"=>"pir_status is MOTION and sound_db = 80"],
        ["time"=>"2026-02-24 03:07:00", "event"=>"LOCK_ENGAGED", "reason"=>"lock_state changed to HARD-LOCK"]
    ],
    "narrative" => "A close approach occurred, motion was detected with elevated sound and the system engaged a hard-lock."
], JSON_UNESCAPED_SLASHES);

// user content: include sensor_context and the actual request
$user_content = "Sensor context (newest first):\n" . json_encode($sensor_context, JSON_UNESCAPED_SLASHES) .
"\nObserved bounds: distance_min={$minDist},distance_max={$maxDist},sound_min={$minSound},sound_max={$maxSound}.\n\nUSER REQUEST: " . $prompt . "\n\nReturn ONLY the required JSON as specified.";

$messages = [
    ["role"=>"system", "content"=>$system_instructions],
    ["role"=>"user", "content"=>$example_user_1],
    ["role"=>"assistant", "content"=>$example_assistant_1],
    ["role"=>"user", "content"=>$example_user_2],
    ["role"=>"assistant", "content"=>$example_assistant_2],
    ["role"=>"user", "content"=>$user_content]
];

function call_lm_studio($lm_url, $messages) {
    $payload = [
        "model" => "llama-3.2-1b-instruct",
        "messages" => $messages,
        "temperature" => 0.0,
        "max_tokens" => 400
    ];
    $ch = curl_init($lm_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    $result = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$result, $errno, $err, $httpCode];
}

// first attempt
list($result, $errno, $err, $httpCode) = call_lm_studio($lm_url, $messages);
$raw_model_output = $result;
$ai_text_clean = '';
if ($errno !== 0) {
    $ai_text_clean = "AI request failed: ($errno) $err";
} else {
    if ($httpCode < 200 || $httpCode >= 300) {
        $ai_text_clean = "AI returned HTTP $httpCode: " . substr($result, 0, 400);
    } else {
        $extracted = extract_json_from_text($result);
        if ($extracted !== null) {
            $ai_text_clean = json_encode($extracted, JSON_UNESCAPED_SLASHES);
        } else {
            $ai_text_clean = trim($result);
        }
    }
}

$ai_parsed = json_decode($ai_text_clean, true);
list($is_valid, $validation_errors) = validate_ai_output($ai_parsed, $sensor_context, $minDist, $maxDist, $minSound, $maxSound, $ALLOWED_EVENTS, $FORBIDDEN_TOKENS);

// if invalid, retry once with explicit validation messages
$attempt = 1;
if (!$is_valid) {
    $attempt++;
    $retry_user_msg = build_retry_message($sensor_context, $minDist, $maxDist, $minSound, $maxSound, $validation_errors, $prompt);
    // second set of messages: keep system and examples, then retry user
    $messages_retry = [
        ["role"=>"system", "content"=>$system_instructions],
        ["role"=>"user", "content"=>$example_user_1],
        ["role"=>"assistant", "content"=>$example_assistant_1],
        ["role"=>"user", "content"=>$example_user_2],
        ["role"=>"assistant", "content"=>$example_assistant_2],
        ["role"=>"user", "content"=>$retry_user_msg]
    ];
    list($result2, $errno2, $err2, $httpCode2) = call_lm_studio($lm_url, $messages_retry);
    $raw_model_output .= "\n\n--- RETRY RAW OUTPUT ---\n" . $result2;
    if ($errno2 !== 0) {
        $ai_text_clean = "AI request failed on retry: ($errno2) $err2";
    } else {
        if ($httpCode2 < 200 || $httpCode2 >= 300) {
            $ai_text_clean = "AI returned HTTP $httpCode2 on retry: " . substr($result2, 0, 400);
        } else {
            $extracted2 = extract_json_from_text($result2);
            if ($extracted2 !== null) {
                $ai_text_clean = json_encode($extracted2, JSON_UNESCAPED_SLASHES);
                $ai_parsed = $extracted2;
            } else {
                $ai_text_clean = trim($result2);
                $ai_parsed = json_decode($ai_text_clean, true);
            }
        }
    }

    $ai_parsed = json_decode($ai_text_clean, true);
    list($is_valid_after_retry, $validation_errors_after_retry) = validate_ai_output($ai_parsed, $sensor_context, $minDist, $maxDist, $minSound, $maxSound, $ALLOWED_EVENTS, $FORBIDDEN_TOKENS);

    if ($is_valid_after_retry) {
        $is_valid = true;
        $validation_errors = [];
    } else {
        $validation_errors = $validation_errors_after_retry;
    }
}

if (!$is_valid) {
    function detect_events($sensor_context, $minSound, $maxSound) {
        $events = [];
        if (empty($sensor_context)) return $events;
        $rows = array_reverse($sensor_context); // oldest -> newest
        $prevDist = null;
        $soundThreshold = max(75, $maxSound * 0.8);
        foreach ($rows as $r) {
            $time = $r['created_at'];
            $d = isset($r['distance']) ? floatval($r['distance']) : null;
            $s = isset($r['sound_db']) ? floatval($r['sound_db']) : null;
            $pir = isset($r['pir_status']) ? $r['pir_status'] : 'STABLE';
            $lock = isset($r['lock_state']) ? $r['lock_state'] : 'UNLOCKED';

            if ($prevDist !== null && $d !== null) {
                if (($prevDist - $d) >= 25) {
                    $events[] = ["time"=>$time, "event"=>"APPROACH", "reason"=>"distance dropped from {$prevDist} cm to {$d} cm"];
                }
            }

            if ($pir === 'MOTION') {
                $events[] = ["time"=>$time, "event"=>"MOTION_DETECTED", "reason"=>"pir_status is MOTION"];
            }

            if ($s !== null && $s >= $soundThreshold) {
                $events[] = ["time"=>$time, "event"=>"SOUND_SPIKE", "reason"=>"sound_db = {$s}"];
            }

            if ($lock === 'HARD-LOCK') {
                $events[] = ["time"=>$time, "event"=>"LOCK_ENGAGED", "reason"=>"lock_state is HARD-LOCK"];
            }

            if ($d !== null) $prevDist = $d;
        }

        $dedup = [];
        $seen = [];
        foreach ($events as $e) {
            $key = $e['time'].'|'.$e['event'].'|'.$e['reason'];
            if (!isset($seen[$key])) { $dedup[] = $e; $seen[$key]=1; }
        }
        return $dedup;
    }

    $server_analysis = detect_events($sensor_context, $minSound, $maxSound);

    if (empty($server_analysis)) {
        $narrative = "No notable events observed in the provided sensor rows.";
    } else {
        $parts = [];
        $count = 0;
        foreach ($server_analysis as $ev) {
            $parts[] = "{$ev['event']} at {$ev['time']} ({$ev['reason']})";
            $count++;
            if ($count >= 3) break;
        }
        $narrative = implode("; ", $parts) . ".";
        if (strlen($narrative) > 240) $narrative = substr($narrative, 0, 236) . '...';
    }

    $ai_text_clean = json_encode([
        "analysis" => $server_analysis,
        "narrative" => $narrative
    ], JSON_UNESCAPED_SLASHES);

    $ai_parsed = json_decode($ai_text_clean, true);
}

$db_log_error = null;
try {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) throw new Exception('DB connect error: ' . $mysqli->connect_error);

    $stmt = $mysqli->prepare("INSERT INTO ai_logs (prompt, response) VALUES (?, ?)");
    if (!$stmt) throw new Exception('Prepare failed: ' . $mysqli->error);
    $stmt->bind_param('ss', $prompt, $ai_text_clean);
    $ok = $stmt->execute();
    if (!$ok) $db_log_error = $stmt->error;
    $stmt->close();
    $mysqli->close();
} catch (Exception $e) {
    $db_log_error = $e->getMessage();
}

$response = [
    'ok' => true,
    'ai_text' => $ai_text_clean,
    'ai_json' => (is_string($ai_text_clean) ? json_decode($ai_text_clean, true) : $ai_parsed),
    'validation_errors' => $validation_errors,
    'raw_model_output' => $raw_model_output
];
if (!empty($db_log_error)) $response['db_log_error'] = $db_log_error;

echo json_encode($response, JSON_UNESCAPED_SLASHES);
exit;