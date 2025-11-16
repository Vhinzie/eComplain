<?php
require_once 'db_connect.php';
$conn = db_connect();
$res = $conn->query("UPDATE complaints SET status = 'pending' WHERE status IS NULL OR status = ''");
echo json_encode(['affected' => $conn->affected_rows, 'ok' => (bool)$res]);
$conn->close();
