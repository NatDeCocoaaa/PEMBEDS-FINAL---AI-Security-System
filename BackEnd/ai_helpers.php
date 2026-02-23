<?php

function log_ai_interaction(PDO $db, string $prompt, string $response) : void {
    $stmt = $db->prepare("INSERT INTO ai_logs (prompt, response) VALUES (:p, :r)");
    $stmt->execute([':p' => $prompt, ':r' => $response]);
}

function get_recent_ai_logs(PDO $db, int $limit = 8) : array {
    $stmt = $db->prepare("SELECT prompt, response, created_at FROM ai_logs ORDER BY id DESC LIMIT :l");
    $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function get_memory(PDO $db) : string {
    $stmt = $db->query("SELECT memory_summary FROM ai_memory ORDER BY id DESC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['memory_summary'] : '';
}

function update_memory(PDO $db, string $newSummary) : void {
    $stmt = $db->prepare("INSERT INTO ai_memory (memory_summary) VALUES (:m)");
    $stmt->execute([':m' => $newSummary]);
}
?>