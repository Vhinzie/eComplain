<?php
require_once 'db_connect.php';
$cols = db_query("SHOW COLUMNS FROM complaints LIKE 'status'");
$rows=[]; while($r=$cols->fetch_assoc()){ $rows[]=$r;} header('Content-Type: application/json'); echo json_encode($rows);
