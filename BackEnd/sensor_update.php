<?php
header('Content-Type: application/json; charset=utf-8');

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'aegis_node_db';

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input) {
    $input = $_POST;
}

// minimal validation / defaults
$device_id = isset($input['device_id']) ? intval($input['device_id']) : 1;
$distance = isset($input['distance']) ? floatval($input['distance']) : null;
$sound_db = isset($input['sound_db']) ? floatval($input['sound_db']) : null;
$pir_status = isset($input['pir_status']) ? $input['pir_status'] : 'STABLE'; 
$lock_state = isset($input['lock_state']) ? $input['lock_state'] : 'UNLOCKED'; 

try {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) throw new Exception('DB connect error: ' . $mysqli->connect_error);

    $sql = "INSERT INTO system_status (device_id, ultrasonic_distance_cm, sound_db, pir_status, lock_state, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
              ultrasonic_distance_cm = VALUES(ultrasonic_distance_cm),
              sound_db = VALUES(sound_db),
              pir_status = VALUES(pir_status),
              lock_state = VALUES(lock_state),
              updated_at = NOW()";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) throw new Exception('Prepare failed: ' . $mysqli->error);

    $stmt->bind_param('iddss', $device_id, $distance, $sound_db, $pir_status, $lock_state);
    $ok = $stmt->execute();

    if (!$ok) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }

    echo json_encode(['ok' => true, 'message' => 'status updated']);
    $stmt->close();
    $mysqli->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}