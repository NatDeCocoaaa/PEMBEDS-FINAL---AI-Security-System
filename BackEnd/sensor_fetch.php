<?php
header('Content-Type: application/json; charset=utf-8');

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'aegis_node_db';

$device_id = isset($_GET['device_id']) ? intval($_GET['device_id']) : 1;

try {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) throw new Exception('DB connect error: ' . $mysqli->connect_error);

    $sql = "SELECT device_id, ultrasonic_distance_cm AS distance, sound_db, pir_status, lock_state, updated_at
            FROM system_status
            WHERE device_id = ?
            LIMIT 1";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) throw new Exception('Prepare failed: ' . $mysqli->error);

    $stmt->bind_param('i', $device_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();

    if (!$row) {
        // return an empty stub
        $row = [
            'device_id' => $device_id,
            'distance' => null,
            'sound_db' => null,
            'pir_status' => 'STABLE',
            'lock_state' => 'UNLOCKED',
            'updated_at' => null
        ];
    }

    echo json_encode(['ok' => true, 'status' => $row]);
    $stmt->close();
    $mysqli->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}