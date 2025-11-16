<?php
// Configure session to persist longer
ini_set('session.gc_maxlifetime', 1800); // 30 minutes
ini_set('session.cookie_lifetime', 1800); // 30 minutes
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'nurse') {
    header('Location: login.php');
    exit;
}

// Refresh session timeout on each page load
$_SESSION['last_activity'] = time();

$user_id = $_SESSION['user']['id'];
$username = $_SESSION['user']['username'];

// Handle dismiss notification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'dismiss_notification') {
    $notif_id = isset($_POST['notification_id']) ? intval($_POST['notification_id']) : 0;
    if ($notif_id > 0) {
        mark_notification_read($notif_id);
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Get complaints assigned to nurse role
$complaints = [];
$complaint_result = get_complaints_for_role('nurse');
if ($complaint_result) {
    while ($row = $complaint_result->fetch_assoc()) {
        $complaints[] = $row;
    }
}

// Get user's notifications
$notifications = [];
$notification_result = get_user_notifications($user_id);
if ($notification_result) {
    while ($row = $notification_result->fetch_assoc()) {
        $notifications[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nurse Dashboard - ACLC eComplain</title>
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
        .table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.85em;
            font-weight: 700;
        }
        .status-pending { background: #ffc107; color: #78350f; }
        .status-in-progress { background: #17a2b8; color: #042c45; }
        .status-resolved { background: #28a745; color: #ffffffff; }
        .status-cancelled { background: #6c757d; color: #fff; }
        .status-finished { background: #28a745; color: #08361a; }
        .notification-dismiss {
            display: inline-block;
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 500;
            background: #dc3545 !important;
            color: white !important;
            border: 1px solid #dc3545 !important;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 10px;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .notification-dismiss:hover { 
            background: #c82333 !important;
            border-color: #c82333 !important;
        }
    </style>
</head>
<body>
    <header class="header">
        <h1>ACLC eComplain - Nurse Dashboard</h1>
    </header>

    <div class="container">
        <div class="card">
            <div class="header-actions">
                <h2>Welcome, <?= htmlspecialchars($username) ?></h2>
                <form method="post" action="logout.php" style="margin:0">
                    <button type="submit" class="btn-small">Logout</button>
                </form>
            </div>
            
            <div style="background: var(--accent); color: white; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
                <h3 style="margin:0 0 6px; color: white;">Health Services Portal</h3>
                <p style="margin:0; font-size: 0.95em;">Monitor and respond to health-related concerns and complaints.</p>
            </div>
        </div>

        <!-- Notifications Section -->
        <div class="card">
            <h3 class="section-title">Recent Notifications</h3>
            <?php if (empty($notifications)): ?>
                <div class="empty-message">No new notifications</div>
            <?php else: ?>
                <?php foreach ($notifications as $notif): ?>
                    <div class="notification-item <?= $notif['is_read'] ? '' : 'unread' ?>">
                        <p class="notification-message"><?= htmlspecialchars($notif['message']) ?></p>
                        <div class="notification-time"><?= date('Y-m-d H:i', strtotime($notif['created_at'])) ?></div>
                        <form method="post" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" style="margin:0;">
                            <input type="hidden" name="action" value="dismiss_notification">
                            <input type="hidden" name="notification_id" value="<?= intval($notif['id']) ?>">
                            <button type="submit" class="notification-dismiss">Dismiss</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Complaints Section -->
        <div class="card">
            <h3 class="section-title">Complaints</h3>
            <?php if (empty($complaints)): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Room</th>
                            <th>Submitter</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th>Last Updated</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 20px;">No complaints assigned yet.</td>
                        </tr>
                    </tbody>
                </table>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Room</th>
                            <th>Submitter</th>
                            <th>Description</th>
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
                                <td><?= htmlspecialchars($complaint['submitter_role']) ?></td>
                                <td><?= htmlspecialchars(substr($complaint['description'], 0, 50)) . (strlen($complaint['description']) > 50 ? '...' : '') ?></td>
                                <td>
                                    <?php
                                    $status = htmlspecialchars($complaint['status']);
                                    $status_class = 'status-pending';
                                    $status_text = 'Pending';
                                    
                                    if ($status === 'in_progress') {
                                        $status_class = 'status-in-progress';
                                        $status_text = 'In Progress';
                                    } elseif ($status === 'resolved') {
                                        $status_class = 'status-resolved';
                                        $status_text = 'Resolved';
                                    } elseif ($status === 'cancelled') {
                                        $status_class = 'status-cancelled';
                                        $status_text = 'Cancelled';
                                    } elseif ($status === 'finished') {
                                        $status_class = 'status-finished';
                                        $status_text = 'Finished';
                                    }
                                    ?>
                                    <span class="status-badge <?= $status_class ?>">
                                        <?= $status_text ?>
                                    </span>
                                </td>
                                <td><?= date('Y-m-d', strtotime($complaint['created_at'])) ?></td>
                                <td><?= date('Y-m-d H:i', strtotime($complaint['updated_at'])) ?></td>
                                <td>
                                    <a class="btn-small" href="view_complaint.php?id=<?= intval($complaint['id']) ?>">View</a>
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
