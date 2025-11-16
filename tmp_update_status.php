<?php
require_once 'db_connect.php';
$complaint_id = isset($_GET['id'])?intval($_GET['id']):0;
$status = isset($_GET['status'])?$_GET['status']:'pending';
if (!$complaint_id) { echo json_encode(['error'=>'need id']); exit; }
$row = db_query("SELECT id, status FROM complaints WHERE id=?","i",[$complaint_id])->fetch_assoc();
$before = $row;
$ok = update_complaint_status($complaint_id, 1, $status, "test via tmp");
$after = db_query("SELECT id, status, updated_at, resolved_at FROM complaints WHERE id=?","i",[$complaint_id])->fetch_assoc();
echo json_encode(['before'=>$before, 'ok'=>$ok, 'after'=>$after]);
