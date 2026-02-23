<?php
header('Content-Type: application/json; charset=utf-8');

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'aegis_node_db';

$device_id = isset($_GET['device_id']) ? intval($_GET['device_id']) : 1;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
if ($limit < 5) $limit = 5;
if ($limit > 1000) $limit = 1000;

try {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) throw new Exception('DB connect error: ' . $mysqli->connect_error);

    $sql = "SELECT id, device_id, ultrasonic_distance_cm AS distance, sound_db, pir_status, lock_state, created_at
            FROM sensor_logs
            WHERE device_id = ?
            ORDER BY created_at DESC
            LIMIT ?";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) throw new Exception('Prepare failed: ' . $mysqli->error);

    $stmt->bind_param('ii', $device_id, $limit);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }

    // return order (oldest first)
    $rows = array_reverse($rows);

    echo json_encode(['ok' => true, 'data' => $rows]);
    $stmt->close();
    $mysqli->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}