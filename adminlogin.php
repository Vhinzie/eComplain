<?php
// Configure session to persist longer
ini_set('session.gc_maxlifetime', 1800); // 30 minutes
ini_set('session.cookie_lifetime', 1800); // 30 minutes
session_start();
require_once 'db_connect.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if ($username === '' || $password === '') {
        $message = 'Please fill in both username and password.';
    } else {
        $admin = verify_admin($username, $password);
        
        if ($admin) {

            db_query(
                "UPDATE admins SET last_login = CURRENT_TIMESTAMP WHERE id = ?",
                "i",
                [$admin['id']]
            );
            
            $_SESSION['user'] = $admin;
            // Refresh session timeout
            $_SESSION['last_activity'] = time();
            header('Location: admin_dashboard.php');
            exit;
        } else {
            $message = 'Invalid admin credentials.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login - ACLC eComplain</title>
    <link rel="stylesheet" href="login.css">
    <style>
        .container {
            justify-content: center !important;
            align-items: center !important;
        }
        .card {
            margin-right: 0 !important;
        }
    </style>
</head>
<body>
    <div class="bg-overlay"></div>
    <header class="header">
        <h1>Welcome to ACLC eComplain - Admin Portal</h1>
    </header>
    
    <div class="container">
        <div class="card">
            <h2>Administrator Login</h2>
            <?php if ($message !== ''): ?>
                <div class="message"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <form method="post" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">
                <div class="field">
                    <label for="username">Username</label>
                    <input id="username" name="username" type="text" autocomplete="username" required>
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                </div>
                <div class="row">
                    <label class="small"><input id="show-password" type="checkbox"> Show password</label>
                    <button type="submit">Sign in</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const showPassword = document.getElementById('show-password');
        const passwordField = document.getElementById('password');
        
        showPassword.addEventListener('change', function() {
            passwordField.type = this.checked ? 'text' : 'password';
        });
    </script>
</body>
</html>
