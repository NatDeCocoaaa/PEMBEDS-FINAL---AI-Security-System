<?php
header('Content-Type: application/json');
$db = new mysqli('localhost', 'root', '', 'aegis_node_db');

$data = json_decode(file_get_contents('php://input'), true);
$pin = $data['pin'] ?? '';

if ($pin === '221') {
    $db->query("INSERT INTO device_commands (device_id, command) VALUES (1, 'UNLOCK')");
    echo json_encode(['ok' => true, 'message' => 'AUTH_SUCCESS: Unlock signal queued.']);
} else {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'AUTH_FAILURE: Invalid PIN.']);
}