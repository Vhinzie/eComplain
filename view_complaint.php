<?php
// Configure session to persist longer
ini_set('session.gc_maxlifetime', 1800); // 30 minutes
ini_set('session.cookie_lifetime', 1800); // 30 minutes
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

// Refresh session timeout on each page load
$_SESSION['last_activity'] = time();

$user = $_SESSION['user'];
$complaint_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Determine the dashboard URL based on user role
$dashboard_map = [
    'student' => 'student_dashboard.php',
    'teacher' => 'teacher_dashboard.php',
    'nurse' => 'nurse_dashboard.php',
    'technician' => 'technician_dashboard.php',
    'custodian' => 'custodian_dashboard.php',
    'guard' => 'guard_dashboard.php',
    'faculty' => 'faculty_dashboard.php',
    'ssc' => 'ssc_dashboard.php',
    'principal' => 'principal_dashboard.php',
    'admin' => 'admin_dashboard.php'
];
$back_url = isset($dashboard_map[$user['role']]) ? $dashboard_map[$user['role']] : 'dashboard.php';

if ($complaint_id <= 0) {
    echo "Invalid complaint ID.";
    exit;
}

// Handle finish complaint
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'finish_complaint') {
    $complaint_check = db_query("SELECT id FROM complaints WHERE id = ?", "i", [$complaint_id]);
    if ($complaint_check && $complaint_check->fetch_assoc()) {
        update_complaint_status($complaint_id, $user['id'], COMPLAINT_RESOLVED, 'Complaint marked as finished');
        header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $complaint_id);
        exit;
    }
}

// Fetch complaint
$conn = db_connect();
$stmt = $conn->prepare("SELECT c.*, u.username as submitter_username FROM complaints c JOIN users u ON c.submitter_id = u.id WHERE c.id = ? LIMIT 1");
$stmt->bind_param("i", $complaint_id);
$stmt->execute();
$result = $stmt->get_result();
$complaint = $result->fetch_assoc();
$stmt->close();
$conn->close();

if (!$complaint) {
    echo "Complaint not found.";
    exit;
}

// Get updates
$updates = [];
$updates_res = db_query("SELECT cu.*, u.username FROM complaint_updates cu LEFT JOIN users u ON cu.user_id = u.id WHERE cu.complaint_id = ? ORDER BY cu.created_at ASC", "i", [$complaint_id]);
if ($updates_res) {
    while ($r = $updates_res->fetch_assoc()) {
        $updates[] = $r;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>View Complaint #<?= htmlspecialchars($complaint_id) ?></title>
    <link rel="stylesheet" href="login.css">
    <style>
        .container { max-width: 1000px; margin: 0 auto; padding: 24px; display: flex; justify-content: center; padding-top: 48px; } .card { width: 760px; max-width: 95%; }
        .card { background: #fff; padding: 18px; border-radius: 8px; box-shadow: 0 6px 18px rgba(0,0,0,0.06); }
        h1 { margin: 0 0 12px; font-size: 2rem; }
        .meta { color: #555; margin-bottom: 12px; }
        .updates { margin-top: 18px; }
        .update { border-left: 3px solid #eee; padding: 8px 12px; margin-bottom: 8px; background:#fafafa; }
        .btn-small { padding: 8px 12px; border-radius:6px; background:#0b5ed7; color:#fff; text-decoration:none; margin-right:6px; }
        .btn-finish { background:#28a745; }
        .btn-finish:hover { background:#218838; }
        .header-section { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; }
        .button-group { display: flex; gap: 6px; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="header-section">
            <h1>Complaint #<?= htmlspecialchars($complaint_id) ?></h1>
            <div class="button-group">
                <?php if ($complaint['status'] === 'pending' || $complaint['status'] === 'in_progress'): ?>
                    <form method="post" action="<?= htmlspecialchars($_SERVER['PHP_SELF'] . '?id=' . $complaint_id) ?>" style="margin:0;display:inline;">
                        <input type="hidden" name="action" value="finish_complaint">
                        <button type="submit" class="btn-small btn-finish" onclick="return confirm('Mark this complaint as finished?');">Finish</button>
                    </form>
                <?php endif; ?>
                <a href="<?= htmlspecialchars($back_url) ?>" class="btn-small">Back</a>
            </div>
        </div>
        <div class="meta">
            <strong>Room:</strong> <?= htmlspecialchars($complaint['room_number']) ?> &nbsp; • &nbsp;
            <strong>Assigned:</strong> <?= htmlspecialchars($complaint['assigned_role']) ?> &nbsp; • &nbsp;
            <strong>Status:</strong> 
            <?php
            $status = htmlspecialchars($complaint['status']);
            if ($status === 'cancelled') $status = 'Cancelled';
            elseif ($status === 'finished') $status = 'Finished';
            elseif ($status === 'in_progress') $status = 'In Progress';
            elseif ($status === 'resolved') $status = 'Resolved';
            else $status = ucfirst($status);
            echo $status;
            ?>
        </div>
        <h3>Description</h3>
        <p><?= nl2br(htmlspecialchars($complaint['description'])) ?></p>
        <h3>Submitted by</h3>
        <p><?= htmlspecialchars($complaint['submitter_username']) ?> on <?= date('Y-m-d H:i', strtotime($complaint['created_at'])) ?></p>

        <div class="updates">
            <h3>History</h3>
            <?php if (empty($updates)): ?>
                <p>No updates yet.</p>
            <?php else: ?>
                <?php foreach ($updates as $u): ?>
                    <div class="update">
                        <div style="font-size:0.9em;color:#333;"><strong><?= htmlspecialchars($u['update_type']) ?></strong> by <?= htmlspecialchars($u['username'] ?? 'system') ?> <small style="color:#666;">on <?= date('Y-m-d H:i', strtotime($u['created_at'])) ?></small></div>
                        <div style="margin-top:6px;color:#444;"><?= nl2br(htmlspecialchars($u['content'])) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
