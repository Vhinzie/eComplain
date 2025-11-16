<?php
require_once 'db_connect.php';
$rows = [];
$res = db_query("SELECT id, submitter_role, assigned_role, room_number, description, status, created_at, updated_at FROM complaints ORDER BY created_at DESC LIMIT 20");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
}
header('Content-Type: application/json');
echo json_encode($rows, JSON_PRETTY_PRINT);
