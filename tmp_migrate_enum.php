<?php
require_once 'db_connect.php';
$conn = db_connect();

$alter = $conn->query("ALTER TABLE complaints MODIFY COLUMN status ENUM('pending','in_progress','resolved','cancelled','finished') NOT NULL DEFAULT 'pending'");
$affected1 = $conn->affected_rows;

// Update complaints that have status_change updates mentioning cancelled
$update_cancelled = $conn->query("UPDATE complaints c SET status = 'cancelled' WHERE EXISTS(SELECT 1 FROM complaint_updates cu WHERE cu.complaint_id=c.id AND cu.update_type='status_change' AND cu.content LIKE '%cancelled%')");
$update_finished = $conn->query("UPDATE complaints c SET status = 'resolved' WHERE EXISTS(SELECT 1 FROM complaint_updates cu WHERE cu.complaint_id=c.id AND cu.update_type='status_change' AND cu.content LIKE '%finished%')");

$results = [
    'alter_ok' => (bool)$alter,
    'update_cancelled' => (bool)$update_cancelled,
    'update_finished' => (bool)$update_finished,
    'affected_rows_alter' => $affected1,
];

$conn->close();
header('Content-Type: application/json');
echo json_encode($results);
