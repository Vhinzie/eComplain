<?php
// Configure session to persist longer
ini_set('session.gc_maxlifetime', 1800); // 30 minutes
ini_set('session.cookie_lifetime', 1800); // 30 minutes
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user']) || !isset($_SESSION['user']['is_admin']) || !$_SESSION['user']['is_admin']) {
    header('Location: adminlogin.php');
    exit;
}

// Refresh session timeout on each page load
$_SESSION['last_activity'] = time();

$message = '';
$users = [];
$editUser = null;
$active_section = 'create';
$should_redirect = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    switch ($action) {
        case 'create':
        case 'update':
            $username = trim($_POST['username']);
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? '';
            $full_name = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');

            if (empty($username) || ($action === 'create' && empty($password)) || empty($role)) {
                $message = 'Please fill in all required fields.';
            } else {
                if ($action === 'create') {
                    if (user_exists($username)) {
                        $message = 'Error: Username already exists.';
                    } else {
                        $result = create_user($username, $password, $role, $full_name, $email);
                        if ($result['success']) {
                            $message = 'User created successfully.';
                            $should_redirect = true;
                        } else {
                            $message = 'Error: ' . $result['error'];
                        }
                    }
                } else { 
                    $id = intval($_POST['user_id']);
                    if (update_user($id, $username, $role, $full_name, $email, !empty($password) ? $password : null)) {
                        $message = 'User updated successfully.';
                        $should_redirect = true;
                    } else {
                        $message = 'Error updating user.';
                    }
                }
            }
            break;

        case 'archive':
            $id = intval($_POST['user_id']);
            if (archive_user($id, $_SESSION['user']['username'])) {
                $message = 'User archived successfully.';
                $active_section = 'manage';
                $should_redirect = true;
            } else {
                $message = 'Error archiving user.';
            }
            break;

        case 'restore':
            $archive_id = intval($_POST['archive_id']);
            if (restore_user($archive_id)) {
                $message = 'User restored successfully.';
                $active_section = 'archive';
                $should_redirect = true;
            } else {
                $message = 'Error restoring user.';
            }
            break;

        case 'permanently_delete':
            $archive_id = intval($_POST['archive_id']);
            if (permanently_delete_archived($archive_id)) {
                $message = 'User permanently deleted.';
                $active_section = 'archive';
                $should_redirect = true;
            } else {
                $message = 'Error permanently deleting user.';
            }
            break;

        case 'edit':
            $id = intval($_POST['user_id']);
            $result = db_query("SELECT id, username, role, full_name, email, status FROM users WHERE id = ?", "i", [$id]);
            $editUser = $result ? $result->fetch_assoc() : null;
            $active_section = 'create';
            break;
    }
    
    // Use POST-Redirect-GET pattern to prevent session issues and form resubmission
    if ($should_redirect) {
        header('Location: admin_dashboard.php?section=' . urlencode($active_section) . '&msg=' . urlencode($message));
        exit;
    }
}

$result = get_all_users();
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
}

$archived_users = [];
$archive_result = get_archived_users();
if ($archive_result) {
    while ($row = $archive_result->fetch_assoc()) {
        $archived_users[] = $row;
    }
}

// Handle query string parameters from redirects
if (isset($_GET['section'])) {
    $active_section = htmlspecialchars($_GET['section']);
}
if (isset($_GET['msg'])) {
    $message = htmlspecialchars($_GET['msg']);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin Dashboard - eComplain</title>
    <link rel="stylesheet" href="admin_dashboard.css">
</head>
<body>
<div class="main-layout">
    <aside class="sidebar">
        <h2>Admin Panel</h2>
        <nav>
            <a href="#create" id="nav-create" class="active">Create Account</a>
            <a href="#manage" id="nav-manage">Manage Accounts</a>
            <a href="#archive" id="nav-archive">Archive</a>
            <form method="post" action="logout.php" style="margin:16px 0 0 0;">
                <button type="submit" class="btn-small" style="width:90%;background:#444;">Logout</button>
            </form>
        </nav>
    </aside>
    <main class="content">
        <h1>ACLC eComplain - Admin Dashboard</h1>
        <div id="section-create" class="card">
            <div class="header-actions">
                <h2><?= $editUser ? 'Edit User' : 'Create New User' ?></h2>
            </div>
            <?php if ($message): ?>
                <div class="message"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <form method="post" action="">
                <input type="hidden" name="action" value="<?= $editUser ? 'update' : 'create' ?>">
                <?php if ($editUser): ?>
                    <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
                <?php endif; ?>
                <div class="form-grid">
                    <div class="field">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" required value="<?= $editUser ? htmlspecialchars($editUser['username']) : '' ?>">
                    </div>
                    <div class="field">
                        <label for="password"><?= $editUser ? 'Password (leave blank to keep current)' : 'Password' ?></label>
                        <input type="password" id="password" name="password" <?= $editUser ? '' : 'required' ?>>
                    </div>
                    <div class="field">
                        <label for="role">Role</label>
                        <select id="role" name="role" required>
                            <option value="">Select Role</option>
                            <?php
                            $roles = ['admin','student','teacher','technician','nurse','custodian','guard','principal','ssc','faculty'];
                            foreach ($roles as $r): ?>
                                <option value="<?= $r ?>" <?= ($editUser && $editUser['role'] === $r) ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $r)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="full_name">Full Name</label>
                        <input type="text" id="full_name" name="full_name" value="<?= $editUser ? htmlspecialchars($editUser['full_name']) : '' ?>">
                    </div>
                    <div class="field">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?= $editUser ? htmlspecialchars($editUser['email']) : '' ?>">
                    </div>
                </div>
                <div style="margin-top:12px;display:flex;gap:8px;">
                    <button type="submit"><?= $editUser ? 'Update User' : 'Create User' ?></button>
                    <?php if ($editUser): ?><a href="admin_dashboard.php"><button type="button">Cancel</button></a><?php endif; ?>
                </div>
            </form>
        </div>
        <div id="section-manage" class="card" style="display:none;">
            <h2>Manage Users</h2>
            <table>
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="6">No users found.</td></tr>
                    <?php else: foreach ($users as $user): ?>
                    <tr>
                        <td><?= htmlspecialchars($user['username']) ?></td>
                        <td><?= ucfirst(htmlspecialchars($user['role'])) ?></td>
                        <td><?= htmlspecialchars($user['full_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($user['email'] ?? '-') ?></td>
                        <td><?= $user['last_login'] ? date('Y-m-d H:i', strtotime($user['last_login'])) : 'Never' ?></td>
                        <td>
                            <form method="post" action="" style="display:inline">
                                <input type="hidden" name="action" value="edit">
                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                <button type="submit" class="btn-small">Edit</button>
                            </form>
                            <?php if ($user['role'] !== 'admin'): ?>
                            <form method="post" action="" style="display:inline" onsubmit="return confirm('Archive this user?')">
                                <input type="hidden" name="action" value="archive">
                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                <button type="submit" class="btn-small btn-danger">Archive</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div id="section-archive" class="card" style="display:none;">
            <h2>Archived Users</h2>
            <table>
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Archived At</th>
                        <th>Archived By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($archived_users)): ?>
                        <tr><td colspan="7">No archived users.</td></tr>
                    <?php else: foreach ($archived_users as $user): ?>
                    <tr>
                        <td><?= htmlspecialchars($user['username']) ?></td>
                        <td><?= ucfirst(htmlspecialchars($user['role'])) ?></td>
                        <td><?= htmlspecialchars($user['full_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($user['email'] ?? '-') ?></td>
                        <td><?= date('Y-m-d H:i', strtotime($user['archived_at'])) ?></td>
                        <td><?= htmlspecialchars($user['archived_by_username'] ?? '-') ?></td>
                        <td>
                            <form method="post" action="" style="display:inline">
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="archive_id" value="<?= $user['id'] ?>">
                                <button type="submit" class="btn-small" style="background:#16a34a;">Restore</button>
                            </form>
                            <form method="post" action="" style="display:inline" onsubmit="return confirm('Permanently delete this user? This cannot be undone.')">
                                <input type="hidden" name="action" value="permanently_delete">
                                <input type="hidden" name="archive_id" value="<?= $user['id'] ?>">
                                <button type="submit" class="btn-small btn-danger">Delete Permanently</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
<script>
const navCreate = document.getElementById('nav-create');
const navManage = document.getElementById('nav-manage');
const navArchive = document.getElementById('nav-archive');
const sectionCreate = document.getElementById('section-create');
const sectionManage = document.getElementById('section-manage');
const sectionArchive = document.getElementById('section-archive');

function showSection(section) {
    if (section === 'create') {
        sectionCreate.style.display = '';
        sectionManage.style.display = 'none';
        sectionArchive.style.display = 'none';
        navCreate.classList.add('active');
        navManage.classList.remove('active');
        navArchive.classList.remove('active');
    } else if (section === 'manage') {
        sectionCreate.style.display = 'none';
        sectionManage.style.display = '';
        sectionArchive.style.display = 'none';
        navCreate.classList.remove('active');
        navManage.classList.add('active');
        navArchive.classList.remove('active');
    } else if (section === 'archive') {
        sectionCreate.style.display = 'none';
        sectionManage.style.display = 'none';
        sectionArchive.style.display = '';
        navCreate.classList.remove('active');
        navManage.classList.remove('active');
        navArchive.classList.add('active');
    }
}
navCreate.addEventListener('click', function(e) { e.preventDefault(); showSection('create'); });
navManage.addEventListener('click', function(e) { e.preventDefault(); showSection('manage'); });
navArchive.addEventListener('click', function(e) { e.preventDefault(); showSection('archive'); });
if (window.location.hash === '#manage') showSection('manage');
if (window.location.hash === '#archive') showSection('archive');
<?php if (!empty($active_section)): ?>
showSection('<?= $active_section ?>');
<?php endif; ?>
</script>
</body>
</html>