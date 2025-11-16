<?php
// Configure session to persist longer
ini_set('session.gc_maxlifetime', 1800); // 30 minutes
ini_set('session.cookie_lifetime', 1800); // 30 minutes
session_start();
require_once 'db_connect.php';

// Undo window in minutes
define('UNDO_WINDOW_MINUTES', 10);

// Check if user is logged in and has teacher role
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'teacher') {
    header('Location: login.php');
    exit;
}

// Refresh session timeout on each page load
$_SESSION['last_activity'] = time();

$message = '';
$user_id = $_SESSION['user']['id'];
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $should_redirect = false;
    
    if ($action === 'submit_complaint') {
        $assigned_role = trim($_POST['assigned_role'] ?? '');
        $room_number = trim($_POST['room_number'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($assigned_role) || empty($room_number) || empty($description)) {
            $message = 'Please fill in all fields.';
        } else {
            $complaint_id = submit_complaint($user_id, $_SESSION['user']['role'], $assigned_role, $room_number, $description);
            if ($complaint_id) {
                $message = 'Complaint submitted successfully!';
                $should_redirect = true;
            } else {
                $message = 'Error submitting complaint. Please try again.';
            }
        }
    } elseif ($action === 'cancel_complaint') {
        $complaint_id = isset($_POST['complaint_id']) ? intval($_POST['complaint_id']) : 0;
        if ($complaint_id > 0) {
            $check = db_query("SELECT submitter_id, status, updated_at FROM complaints WHERE id = ?", "i", [$complaint_id]);
            $row = $check ? $check->fetch_assoc() : null;
            if ($row && $row['submitter_id'] == $user_id) {
                // Allow cancellation only when status is pending
                if ($row['status'] === 'pending') {
                    update_complaint_status($complaint_id, $user_id, 'cancelled', 'Cancelled by submitter');
                    $message = 'Complaint cancelled successfully.';
                    $should_redirect = true;
                } else {
                    $message = 'Only pending complaints can be cancelled.';
                }
            } else {
                $message = 'Unable to cancel this complaint.';
            }
        } else {
            $message = 'Invalid complaint selected.';
        }
    } elseif ($action === 'undo_cancel') {
        $complaint_id = isset($_POST['complaint_id']) ? intval($_POST['complaint_id']) : 0;
        if ($complaint_id > 0) {
            $check = db_query("SELECT submitter_id, status, updated_at FROM complaints WHERE id = ?", "i", [$complaint_id]);
            $row = $check ? $check->fetch_assoc() : null;
            if ($row && $row['submitter_id'] == $user_id && $row['status'] === 'cancelled') {
                $age = time() - strtotime($row['updated_at']);
                if ($age <= (UNDO_WINDOW_MINUTES * 60)) {
                    update_complaint_status($complaint_id, $user_id, 'pending', 'Reopened by submitter');
                    $message = 'Complaint reopened successfully.';
                    $should_redirect = true;
                } else {
                    $message = 'Undo window expired; cannot reopen this complaint.';
                }
            } else {
                $message = 'Unable to reopen this complaint.';
            }
        } else {
            $message = 'Invalid complaint selected.';
        }
    }
    
    // Use POST-Redirect-GET pattern to prevent form resubmission on refresh
    if ($should_redirect) {
        header('Location: teacher_dashboard.php');
        exit;
    }
}

$complaints_result = get_user_complaints($user_id);
$complaints = [];
if ($complaints_result) {
    while ($row = $complaints_result->fetch_assoc()) {
        $complaints[] = $row;
    }
}

$notifications_result = get_user_notifications($user_id, 5);
$notifications = [];
if ($notifications_result) {
    while ($n = $notifications_result->fetch_assoc()) {
        $notifications[] = $n;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard - ACLC eComplain</title>
    <link rel="stylesheet" href="login.css">
    <style>
        .container { 
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            display: block;
        }
        .card {
            margin: 0 0 20px 0;
            width: auto;
            padding: 20px;
        }
        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .btn-small {
            padding: 8px 16px;
            font-size: 14px;
        }
        .section-title {
            border-bottom: 2px solid #0056b3;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .form-group { margin-bottom: 15px; }
        .form-group label { display:block; margin-bottom:5px; font-weight:600; }
        .form-group input, .form-group textarea, .form-group select { width:100%; padding:10px; border:1px solid #ddd; border-radius:4px; }
        .message { padding:10px 15px; margin-bottom:20px; background:#d4edda; color:#155724; border:1px solid #c3e6cb; border-radius:4px; }
        .message.error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
        .table { width:100%; border-collapse:collapse; margin-top:10px; }
        .table th, .table td { border:1px solid #ddd; padding:12px; text-align:left; }
        .table th { background:#0056b3; color:#fff; }
        .complaint-status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
            color: white;
        }
        .status-pending { background-color: #ffc107; }
        .status-in_progress { background-color: #17a2b8; }
        .status-resolved { background-color: #28a745; }
        .status-cancelled { background-color: #6c757d; }
        .toast {
            position: fixed;
            right: 20px;
            bottom: 20px;
            background: rgba(0,0,0,0.85);
            color: #fff;
            padding: 12px 18px;
            border-radius: 6px;
            box-shadow: 0 6px 18px rgba(0,0,0,0.2);
            z-index: 9999;
            display: none;
            max-width: 320px;
        }
        .notification-item {
            border-left: 4px solid #0056b3;
            padding: 10px 15px;
            margin-bottom: 10px;
            background-color: #e7f3ff;
            border-radius: 3px;
        }
    </style>
</head>
<body>
    <header class="header">
        <h1>ACLC eComplain - Teacher Dashboard</h1>
    </header>

    <div id="toast" class="toast" aria-live="polite"></div>

    <div class="container">
        <div class="card">
            <div class="header-actions">
                <h2>Welcome, <?= htmlspecialchars($_SESSION['user']['full_name'] ?? $_SESSION['user']['username']) ?></h2>
                <form method="post" action="logout.php" style="margin:0">
                    <button type="submit" class="btn-small">Logout</button>
                </form>
            </div>

            <?php if (!empty($notifications)): ?>
            <div class="card">
                <h3 class="section-title">Recent Notifications</h3>
                <?php foreach ($notifications as $notif): ?>
                <div class="notification-item">
                    <strong>Room <?= htmlspecialchars($notif['room_number']) ?></strong>: 
                    <?= htmlspecialchars($notif['message']) ?>
                    <br>
                    <small><?= date('M d, Y H:i', strtotime($notif['created_at'])) ?></small>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="card">
                <h3 class="section-title">Submit a New Complaint</h3>
                <?php if ($message !== ''): ?>
                    <div class="message <?= strpos($message, 'Error') !== false ? 'error' : '' ?>"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <form method="post" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">
                    <input type="hidden" name="action" value="submit_complaint">
                    <div class="form-group">
                        <label for="assigned_role">Assign to Department</label>
                        <select id="assigned_role" name="assigned_role" required>
                            <option value="">Select Department</option>
                            <option value="technician">Technician</option>
                            <option value="nurse">Nurse</option>
                            <option value="custodian">Custodian</option>
                            <option value="guard">Guard</option>
                            <option value="principal">Director</option>
                            <option value="ssc">SSC (Supreme Student Council)</option>
                            <option value="faculty">Faculty</option>
                        </select>
                    </div>
                    <div class="form-group">
                    <label for="room_number">Room Number:</label>
                    <input type="text" id="room_number" name="room_number" placeholder="e.g., 101, Lab 1" required>
                </div>
                    <div class="form-group">
                        <label for="description">Description:</label>
                        <textarea id="description" name="description" required></textarea>
                    </div>
                    <button type="submit" class="btn-small">Submit Complaint</button>
                </form>
            </div>

            <div class="card">
                <h3 class="section-title">My Complaints</h3>
                <?php if (empty($complaints)): ?>
                    <p>You have not submitted any complaints yet.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Room</th>
                                <th>Department</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Submitted</th>
                                <th>Last Updated</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($complaints as $c): ?>
                            <tr>
                                <td><?= htmlspecialchars($c['room_number']) ?></td>
                                <td><?= htmlspecialchars($c['assigned_role']) ?></td>
                                <td><?= htmlspecialchars(substr($c['description'],0,50)) . (strlen($c['description'])>50?'...':'') ?></td>
                                <td>
                                    <span class="complaint-status status-<?= htmlspecialchars($c['status']) ?>">
                                        <?= htmlspecialchars(ucwords(str_replace('_', ' ', $c['status']))) ?>
                                    </span>
                                </td>
                                <td><?= date('M d, Y', strtotime($c['created_at'])) ?></td>
                                <td><?= date('M d, Y H:i', strtotime($c['updated_at'] ?? $c['created_at'])) ?></td>
                                <td>
                                    <?php if ($c['status'] === 'pending'): ?>
                                        <form method="post" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" onsubmit="return confirm('Are you sure you want to cancel this complaint?');" style="margin:0">
                                            <input type="hidden" name="action" value="cancel_complaint">
                                            <input type="hidden" name="complaint_id" value="<?= intval($c['id']) ?>">
                                            <button type="submit" class="btn-small">Cancel</button>
                                        </form>
                                    <?php elseif ($c['status'] === 'cancelled'): ?>
                                        <?php $can_undo = (time() - strtotime($c['updated_at'])) <= (UNDO_WINDOW_MINUTES * 60); ?>
                                        <?php if ($can_undo): ?>
                                            <form method="post" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" onsubmit="return confirm('Reopen this complaint?');" style="margin:0">
                                                <input type="hidden" name="action" value="undo_cancel">
                                                <input type="hidden" name="complaint_id" value="<?= intval($c['id']) ?>">
                                                <button type="submit" class="btn-small">Undo</button>
                                            </form>
                                        <?php else: ?>
                                            &ndash;
                                        <?php endif; ?>
                                    <?php else: ?>
                                        &ndash;
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>

<script>
// Toast display
function showToast(text) {
    var t = document.getElementById('toast');
    if (!t) return;
    t.textContent = text;
    t.style.display = 'block';
    setTimeout(function() { t.style.opacity = '1'; }, 10);
    setTimeout(function() { t.style.opacity = '0'; setTimeout(function(){ t.style.display='none'; }, 400); }, 4500);
}

<?php if (!empty($message)): ?>
    // Show server message as toast
    document.addEventListener('DOMContentLoaded', function(){
        showToast(<?= json_encode($message) ?>);
    });
<?php endif; ?>
</script>