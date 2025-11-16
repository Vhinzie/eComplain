<?php
// Configure session to persist longer
ini_set('session.gc_maxlifetime', 1800); // 30 minutes
ini_set('session.cookie_lifetime', 1800); // 30 minutes
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'principal') {
    header('Location: login.php');
    exit;
}

// Refresh session timeout on each page load
$_SESSION['last_activity'] = time();

$user_id = $_SESSION['user']['id'];

// Handle dismiss notification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'dismiss_notification') {
    $notif_id = isset($_POST['notification_id']) ? intval($_POST['notification_id']) : 0;
    if ($notif_id > 0) {
        mark_notification_read($notif_id);
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Get all complaints in the system
$complaints_result = get_all_complaints();
$complaints = [];
if ($complaints_result) {
    while ($complaint = $complaints_result->fetch_assoc()) {
        $complaints[] = $complaint;
    }
}

// Get user's notifications
$notifications_result = get_user_notifications($user_id, 5);
$notifications = [];
if ($notifications_result) {
    while ($notif = $notifications_result->fetch_assoc()) {
        $notifications[] = $notif;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Principal Dashboard - ACLC eComplain</title>
    <link rel="stylesheet" href="login.css">
    <style>
        .container { 
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            display: block;
        }
        .card {
            margin: 0 0 20px 0;
            width: auto;
            padding: 20px;
            background: rgba(255,255,255,0.95);
            border-radius: 8px;
            box-shadow: 0 6px 18px rgba(12,24,40,0.06);
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
            border-radius: 6px;
            background: #0b5ed7;
            color: #fff;
            border: none;
            cursor: pointer;
        }
        .section-title {
            border-bottom: 2px solid #0056b3;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .notification-item {
            border-left: 4px solid #0056b3;
            padding: 10px 15px;
            margin-bottom: 10px;
            background-color: #e7f3ff;
            border-radius: 3px;
        }
        .notification-dismiss {
            display: inline-block;
            padding: 8px 14px;
            background: #dc3545 !important;
            color: white !important;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            margin-top: 10px;
            transition: background 0.3s ease;
        }
        .notification-dismiss:hover {
            background: #c82333 !important;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .table th,
        .table td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }
        .table th {
            background-color: #0056b3;
            color: white;
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        .status-pending { background-color: #ffc107; color: #000; }
        .status-in_progress { background-color: #17a2b8; color: #fff; }
        .status-resolved { background-color: #28a745; color: #fff; }
        .status-cancelled { background-color: #6c757d; color: #fff; }
        .status-finished { background-color: #20c997; color: #fff; }
    </style>
</head>
<body>
    <header class="header">
        <h1>ACLC eComplain - Principal Dashboard</h1>
    </header>

    <div class="container">
        <div class="card">
            <div class="header-actions">
                <h2>Welcome, Principal</h2>
                <form method="post" action="logout.php" style="margin:0">
                    <button type="submit" class="btn-small">Logout</button>
                </form>
            </div>
        </div>

        <!-- Recent Notifications -->
        <div class="card">
            <h3 class="section-title">Recent Notifications</h3>
            <?php if (empty($notifications)): ?>
                <p>No new notifications</p>
            <?php else: ?>
                <?php foreach ($notifications as $notif): ?>
                    <div class="notification-item">
                        <strong><?= htmlspecialchars($notif['message']) ?></strong>
                        <form method="post" style="display:inline; margin-left:10px;">
                            <input type="hidden" name="action" value="dismiss_notification">
                            <input type="hidden" name="notification_id" value="<?= $notif['id'] ?>">
                            <button type="submit" class="notification-dismiss">Dismiss</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- All Complaints -->
        <div class="card">
            <h3 class="section-title">All Complaints in System</h3>
            <p style="font-size:14px; color:#666; margin-bottom:10px;">View all complaints submitted by students and teachers, assigned to any role.</p>
            <?php if (empty($complaints)): ?>
                <p>No complaints found.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Room</th>
                            <th>Submitted By</th>
                            <th>Description</th>
                            <th>Assigned To</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th>Last Updated</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($complaints as $complaint): ?>
                            <tr>
                                <td><?= htmlspecialchars($complaint['room_number']) ?></td>
                                <td><?= htmlspecialchars($complaint['submitter_name'] ?? $complaint['submitter_role']) ?></td>
                                <td><?= htmlspecialchars(substr($complaint['description'], 0, 50)) ?>...</td>
                                <td><?= htmlspecialchars(ucfirst($complaint['assigned_role'])) ?></td>
                                <td>
                                    <span class="status-badge status-<?= htmlspecialchars($complaint['status']) ?>">
                                        <?= htmlspecialchars(ucwords(str_replace('_', ' ', $complaint['status']))) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars(date('M d, Y', strtotime($complaint['created_at']))) ?></td>
                                <td><?= htmlspecialchars(date('M d, Y H:i', strtotime($complaint['updated_at'] ?? $complaint['created_at']))) ?></td>
                                <td>
                                    <a href="view_complaint.php?id=<?= $complaint['id'] ?>" style="color: #0b5ed7; text-decoration: none;">
                                        View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
