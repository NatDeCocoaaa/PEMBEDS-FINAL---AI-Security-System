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

// validation / defaults
$device_id = isset($input['device_id']) ? intval($input['device_id']) : 1;
$distance = isset($input['distance']) ? floatval($input['distance']) : null;
$sound_db = isset($input['sound_db']) ? floatval($input['sound_db']) : null;
$pir_status = isset($input['pir_status']) ? $input['pir_status'] : 'STABLE';
$lock_state = isset($input['lock_state']) ? $input['lock_state'] : 'UNLOCKED';

try {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) throw new Exception('DB connect error: ' . $mysqli->connect_error);

    $insSql = "INSERT INTO sensor_logs (device_id, ultrasonic_distance_cm, sound_db, pir_status, lock_state)
               VALUES (?, ?, ?, ?, ?)";
    $ins = $mysqli->prepare($insSql);
    if (!$ins) throw new Exception('Prepare insert failed: ' . $mysqli->error);
    $ins->bind_param('iddss', $device_id, $distance, $sound_db, $pir_status, $lock_state);
    $insOk = $ins->execute();
    if (!$insOk) throw new Exception('Insert failed: ' . $ins->error);
    $ins->close();

    $check = $mysqli->prepare("SELECT id FROM system_status WHERE device_id = ? LIMIT 1");
    $check->bind_param('i', $device_id);
    $check->execute();
    $res = $check->get_result();
    $exists = $res->fetch_assoc();
    $check->close();

    if ($exists) {
        $update = $mysqli->prepare("UPDATE system_status SET ultrasonic_distance_cm = ?, sound_db = ?, pir_status = ?, lock_state = ?, updated_at = NOW() WHERE device_id = ?");
        if (!$update) throw new Exception('Prepare update failed: ' . $mysqli->error);
        $update->bind_param('ddssi', $distance, $sound_db, $pir_status, $lock_state, $device_id);
        $ok = $update->execute();
        if (!$ok) throw new Exception('Update failed: ' . $update->error);
        $update->close();
    } else {
        $insert = $mysqli->prepare("INSERT INTO system_status (device_id, ultrasonic_distance_cm, sound_db, pir_status, lock_state, updated_at) VALUES (?, ?, ?, ?, ?, NOW())");
        if (!$insert) throw new Exception('Prepare insert status failed: ' . $mysqli->error);
        $insert->bind_param('iddss', $device_id, $distance, $sound_db, $pir_status, $lock_state);
        $ok = $insert->execute();
        if (!$ok) throw new Exception('Insert system_status failed: ' . $insert->error);
        $insert->close();
    }

    $mysqli->close();

    echo json_encode(['ok' => true, 'message' => 'status updated and logged']);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}