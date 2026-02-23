<?php
header('Content-Type: application/json; charset=utf-8');

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'aegis_node_db';

$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
if ($limit < 1) $limit = 1;
if ($limit > 1000) $limit = 1000;

try {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) throw new Exception('DB connect error: ' . $mysqli->connect_error);

    $sql = "SELECT id, prompt, response, created_at FROM ai_logs ORDER BY created_at DESC LIMIT ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;

    echo json_encode(['ok' => true, 'data' => $rows]);
    $stmt->close();
    $mysqli->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}