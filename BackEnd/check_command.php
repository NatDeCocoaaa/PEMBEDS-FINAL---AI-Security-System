<?php
$db = new mysqli('localhost', 'root', '', 'aegis_node_db');
$res = $db->query("SELECT id FROM device_commands WHERE device_id = 1 AND command = 'UNLOCK' AND status = 'PENDING' LIMIT 1");

if ($row = $res->fetch_assoc()) {
    echo "REMOTE_ACTION:UNLOCK"; // The Arduino looks for this string
    $db->query("UPDATE device_commands SET status = 'EXECUTED' WHERE id = " . $row['id']);
} else {
    echo "WAITING";
}