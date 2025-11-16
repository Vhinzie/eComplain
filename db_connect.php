<?php
define('DB_HOST', 'localhost');  
define('DB_USER', 'root');          
define('DB_PASS', '');              
define('DB_NAME', 'ecomplain');     

define('COMPLAINT_PENDING', 'pending');
define('COMPLAINT_IN_PROGRESS', 'in_progress');
define('COMPLAINT_RESOLVED', 'resolved');

function db_connect() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    return $conn;
}

function db_query($sql, $types = null, $params = []) {
    $conn = db_connect();
    $stmt = $conn->prepare($sql);
    
    if ($stmt === false) {
        $conn->close();
        die("Error preparing statement: " . $conn->error);
    }
    
    if ($types !== null && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $stmt->close();
    $conn->close();
    
    return $result;
}

function admin_exists($username) {
    $result = db_query(
        "SELECT COUNT(*) as count FROM admins WHERE username = ?",
        "s",
        [$username]
    );
    $row = $result->fetch_assoc();
    return $row['count'] > 0;
}

function user_exists($username) {
    $result = db_query(
        "SELECT COUNT(*) as count FROM users WHERE username = ?",
        "s",
        [$username]
    );
    $row = $result->fetch_assoc();
    return $row['count'] > 0;
}

function verify_admin($username, $password) {
    $result = db_query(
        "SELECT id, username, password, status FROM admins WHERE username = ? AND status = 'active'",
        "s",
        [$username]
    );
    
    if ($admin = $result->fetch_assoc()) {
        if (password_verify($password, $admin['password'])) {
            unset($admin['password']); 
            $admin['is_admin'] = true; 
            return $admin;
        }
    }
    return false;
}

function verify_user($username, $password) {
    $conn = db_connect();
    
    $tables = ['students', 'teachers', 'technicians', 'nurses', 'custodians', 'guards', 'principals', 'ssc', 'faculty'];
    $role_map = ['students' => 'student', 'teachers' => 'teacher', 'technicians' => 'technician', 
                 'nurses' => 'nurse', 'custodians' => 'custodian', 'guards' => 'guard', 'principals' => 'principal',
                 'ssc' => 'ssc', 'faculty' => 'faculty'];
    
    foreach ($tables as $table) {
        $stmt = $conn->prepare("SELECT id, username, password, full_name, email, status FROM $table WHERE username = ? AND status = 'active' LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($user = $result->fetch_assoc()) {
            if (password_verify($password, $user['password'])) {
                $role = $role_map[$table];
                $role_id = $user['id'];
                
                $stmt2 = $conn->prepare("SELECT id FROM users WHERE username = ? AND role = ?");
                $stmt2->bind_param("ss", $username, $role);
                $stmt2->execute();
                $result2 = $stmt2->get_result();
                $user_row = $result2->fetch_assoc();
                $stmt2->close();
                
                unset($user['password']);
                $user['id'] = $user_row['id'] ?? $role_id;
                $user['role'] = $role;
                $user['is_admin'] = false;
                
                $stmt->close();
                $conn->close();
                return $user;
            }
        }
        $stmt->close();
    }
    
    $conn->close();
    return false;
}

function submit_complaint($submitter_id, $submitter_role, $assigned_role, $room_number, $description) {
    $conn = db_connect();
    
    $stmt = $conn->prepare(
        "INSERT INTO complaints (submitter_id, submitter_role, assigned_role, room_number, description, status) 
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $initial_status = COMPLAINT_PENDING;
    $stmt->bind_param("isssss", $submitter_id, $submitter_role, $assigned_role, $room_number, $description, $initial_status);
    $result = $stmt->execute();
    
    if ($result) {
        $complaint_id = $conn->insert_id;

        $role_table_map = [
            'student' => 'students',
            'teacher' => 'teachers',
            'technician' => 'technicians',
            'nurse' => 'nurses',
            'custodian' => 'custodians',
            'guard' => 'guards',
            'principal' => 'principals',
            'ssc' => 'ssc',
            'faculty' => 'faculty'
        ];

        $role_table = isset($role_table_map[$assigned_role]) ? $role_table_map[$assigned_role] : ($assigned_role . 's');

        $users_result = false;
        $stmt_users = $conn->prepare(
            "SELECT u.id FROM users u INNER JOIN {$role_table} r ON u.table_id = r.id WHERE u.role = ? AND r.status = 'active'"
        );
        if ($stmt_users) {
            $stmt_users->bind_param('s', $assigned_role);
            $stmt_users->execute();
            $users_result = $stmt_users->get_result();
        }

        if ($users_result) {
            while ($user = $users_result->fetch_assoc()) {
                create_notification(
                    $user['id'],
                    $complaint_id,
                    "New complaint received from {$submitter_role} for Room {$room_number}"
                );
            }
        }
        if (isset($stmt_users) && $stmt_users) {
            $stmt_users->close();
        }

        $admins_result = $conn->query(
            "SELECT u.id FROM users u 
             INNER JOIN admins a ON u.table_id = a.id 
             WHERE u.role = 'admin' AND a.status = 'active'"
        );
        
        if ($admins_result) {
            while ($admin = $admins_result->fetch_assoc()) {
                create_notification(
                    $admin['id'],
                    $complaint_id,
                    "New complaint from {$submitter_role} for Room {$room_number}"
                );
            }
        }

        $principals_result = $conn->query(
            "SELECT u.id FROM users u 
             INNER JOIN principals p ON u.table_id = p.id 
             WHERE u.role = 'principal' AND p.status = 'active'"
        );
        
        if ($principals_result) {
            while ($principal = $principals_result->fetch_assoc()) {
                create_notification(
                    $principal['id'],
                    $complaint_id,
                    "New complaint from {$submitter_role} for Room {$room_number}"
                );
            }
        }
        
        $stmt->close();
        $conn->close();
        return $complaint_id;
    }
    
    $stmt->close();
    $conn->close();
    return false;
}

function update_complaint_status($complaint_id, $user_id, $new_status, $comment = '') {
    db_query(
        "UPDATE complaints SET status = ?, updated_at = CURRENT_TIMESTAMP, 
         resolved_at = ? WHERE id = ?",
        "ssi",
        [$new_status, (($new_status === COMPLAINT_RESOLVED || $new_status === 'finished') ? date('Y-m-d H:i:s') : null), $complaint_id]
    );

    db_query(
        "INSERT INTO complaint_updates (complaint_id, user_id, update_type, content) 
         VALUES (?, ?, 'status_change', ?)",
        "iis",
        [$complaint_id, $user_id, "Status changed to: {$new_status}"]
    );
    
    if ($comment) {
        db_query(
            "INSERT INTO complaint_updates (complaint_id, user_id, update_type, content) 
             VALUES (?, ?, 'comment', ?)",
            "iis",
            [$complaint_id, $user_id, $comment]
        );
    }

    notify_status_change($complaint_id, $new_status);
    
    return true;
}

function create_notification($user_id, $complaint_id, $message) {
    return db_query(
        "INSERT INTO notifications (user_id, complaint_id, message) VALUES (?, ?, ?)",
        "iis",
        [$user_id, $complaint_id, $message]
    );
}

function get_user_notifications($user_id, $limit = 10) {
    $conn = db_connect();
    
    $stmt = $conn->prepare(
        "SELECT n.*, c.room_number, c.description, c.status as complaint_status 
         FROM notifications n 
         JOIN complaints c ON n.complaint_id = c.id 
         WHERE n.user_id = ? AND n.is_read = 0 
         ORDER BY n.created_at DESC LIMIT ?"
    );
    $stmt->bind_param("ii", $user_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $stmt->close();
    $conn->close();
    
    return $result;
}

function mark_notification_read($notification_id) {
    return db_query(
        "UPDATE notifications SET is_read = 1 WHERE id = ?",
        "i",
        [$notification_id]
    );
}

function notify_status_change($complaint_id, $new_status) {
    $complaint = db_query(
        "SELECT c.*, u.username as submitter_name 
         FROM complaints c 
         JOIN users u ON c.submitter_id = u.id 
         WHERE c.id = ?",
        "i",
        [$complaint_id]
    )->fetch_assoc();

    create_notification(
        $complaint['submitter_id'],
        $complaint_id,
        "[ID #{$complaint_id}] Your complaint for Room {$complaint['room_number']} has been updated to: {$new_status}"
    );
    
        if ($new_status === COMPLAINT_RESOLVED || $new_status === 'finished') {
        $staff_result = db_query(
            "SELECT id FROM users WHERE role = ? AND status = 'active'",
            "s",
            [$complaint['assigned_role']]
        );
        
        while ($staff = $staff_result->fetch_assoc()) {
                create_notification(
                    $staff['id'],
                    $complaint_id,
                    "[ID #{$complaint_id}] Complaint for Room {$complaint['room_number']} has been marked as resolved (submitter: {$complaint['submitter_name']})"
                );
        }
        return;
    }

    if ($new_status === 'cancelled' || $new_status === COMPLAINT_PENDING) {
        $staff_result = db_query(
            "SELECT id FROM users WHERE role = ? AND status = 'active'",
            "s",
            [$complaint['assigned_role']]
        );
        if ($staff_result) {
            while ($staff = $staff_result->fetch_assoc()) {
                $msg = ($new_status === 'cancelled') ?
                    "[ID #{$complaint_id}] Complaint for Room {$complaint['room_number']} has been cancelled by the submitter (submitter: {$complaint['submitter_name']})" :
                    "[ID #{$complaint_id}] Complaint for Room {$complaint['room_number']} has been reopened by the submitter (submitter: {$complaint['submitter_name']})";
                create_notification(
                    $staff['id'],
                    $complaint_id,
                    $msg
                );
            }
        }

        $admins_result = db_query(
            "SELECT id FROM users WHERE role = 'admin' AND status = 'active'"
        );
        if ($admins_result) {
            while ($admin = $admins_result->fetch_assoc()) {
                $msg = ($new_status === 'cancelled') ?
                    "[ID #{$complaint_id}] Complaint for Room {$complaint['room_number']} was cancelled by the submitter (submitter: {$complaint['submitter_name']})" :
                    "[ID #{$complaint_id}] Complaint for Room {$complaint['room_number']} was reopened by the submitter (submitter: {$complaint['submitter_name']})";
                create_notification(
                    $admin['id'],
                    $complaint_id,
                    $msg
                );
            }
        }
    }
}

function get_complaints_for_role($role, $status = null) {
    $sql = "SELECT c.*, u.username as submitter_name 
            FROM complaints c 
            JOIN users u ON c.submitter_id = u.id 
            WHERE c.assigned_role = ?";
    
    if ($status) {
        $sql .= " AND c.status = ?";
        return db_query($sql, "ss", [$role, $status]);
    }
    
    return db_query($sql, "s", [$role]);
}

function get_user_complaints($user_id) {
    $conn = db_connect();
    
    $stmt = $conn->prepare(
        "SELECT id, submitter_id, submitter_role, assigned_role, room_number, description, status, created_at, updated_at 
         FROM complaints WHERE submitter_id = ? ORDER BY created_at DESC"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $stmt->close();
    $conn->close();
    
    return $result;
}

function get_all_complaints() {
    $conn = db_connect();
    
    $stmt = $conn->prepare(
        "SELECT c.id, c.submitter_id, c.submitter_role, c.assigned_role, c.room_number, c.description, c.status, c.created_at, c.updated_at,
                u.username as submitter_name
         FROM complaints c 
         LEFT JOIN users u ON c.submitter_id = u.id 
         ORDER BY c.created_at DESC"
    );
    $stmt->execute();
    $result = $stmt->get_result();
    
    $stmt->close();
    $conn->close();
    
    return $result;
}

function create_user($username, $password, $role, $full_name, $email) {
    $conn = db_connect();
    $table_map = [
        'student' => 'students',
        'teacher' => 'teachers',
        'technician' => 'technicians',
        'nurse' => 'nurses',
        'custodian' => 'custodians',
        'guard' => 'guards',
        'principal' => 'principals',
        'ssc' => 'ssc',
        'faculty' => 'faculty'
    ];
    
    if (!isset($table_map[$role])) {
        $conn->close();
        return ['success' => false, 'error' => 'Invalid role selected'];
    }
    
    if (empty($password) || strlen($password) < 3) {
        $conn->close();
        return ['success' => false, 'error' => 'Password must be at least 3 characters'];
    }
    
    $table = $table_map[$role];
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    $stmt = $conn->prepare(
        "INSERT INTO $table (username, password, full_name, email, status) VALUES (?, ?, ?, ?, 'active')"
    );
    
    if (!$stmt) {
        $conn->close();
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }
    
    $stmt->bind_param("ssss", $username, $hashed_password, $full_name, $email);
    $result = $stmt->execute();
    
    if (!$result) {
        $error = $stmt->error;
        $stmt->close();
        $conn->close();

        if (strpos($error, 'Duplicate entry') !== false) {
            return ['success' => false, 'error' => 'Username already exists'];
        } elseif (strpos($error, 'truncated') !== false) {
            return ['success' => false, 'error' => 'One or more fields exceed maximum length'];
        } else {
            return ['success' => false, 'error' => 'Error creating user account: ' . $error];
        }
    }
    
    $role_id = $conn->insert_id;
    $stmt->close();
    
    $stmt2 = $conn->prepare(
        "INSERT INTO users (username, role, table_id, full_name, email, status) VALUES (?, ?, ?, ?, ?, 'active')"
    );
    
    if (!$stmt2) {
        $conn->close();
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }
    
    $stmt2->bind_param("ssiss", $username, $role, $role_id, $full_name, $email);
    $result2 = $stmt2->execute();
    
    if (!$result2) {
        $error = $stmt2->error;
        $stmt2->close();
        $conn->close();
        return ['success' => false, 'error' => 'Error registering user: ' . $error];
    }
    
    $stmt2->close();
    $conn->close();
    
    return ['success' => true, 'message' => 'User created successfully'];
}

function update_user($user_id, $username, $role, $full_name, $email, $password = null) {
    $conn = db_connect();
    
    $stmt = $conn->prepare("SELECT table_id FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_row = $result->fetch_assoc();
    $stmt->close();
    
    if (!$user_row) {
        $conn->close();
        return false;
    }
    
    $table_map = [
        'student' => 'students',
        'teacher' => 'teachers',
        'technician' => 'technicians',
        'nurse' => 'nurses',
        'custodian' => 'custodians',
        'guard' => 'guards',
        'principal' => 'principals',
        'ssc' => 'ssc',
        'faculty' => 'faculty'
    ];
    
    $table = $table_map[$role];
    $table_id = $user_row['table_id'];
    
    if ($password) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE $table SET username=?, password=?, full_name=?, email=? WHERE id=?");
        $stmt->bind_param("ssssi", $username, $hashed_password, $full_name, $email, $table_id);
    } else {
        $stmt = $conn->prepare("UPDATE $table SET username=?, full_name=?, email=? WHERE id=?");
        $stmt->bind_param("sssi", $username, $full_name, $email, $table_id);
    }
    
    $result1 = $stmt->execute();
    $stmt->close();
    
    $stmt2 = $conn->prepare("UPDATE users SET username=?, full_name=?, email=? WHERE id=?");
    $stmt2->bind_param("sssi", $username, $full_name, $email, $user_id);
    $result2 = $stmt2->execute();
    $stmt2->close();
    
    $conn->close();
    return $result1 && $result2;
}

function get_all_users() {
    $conn = db_connect();
    
    $stmt = $conn->prepare(
        "SELECT id, username, role, full_name, email, last_login, status FROM users WHERE role != 'admin' ORDER BY created_at DESC"
    );
    $stmt->execute();
    $result = $stmt->get_result();
    
    $stmt->close();
    $conn->close();
    
    return $result;
}

function delete_user($user_id) {
    $conn = db_connect();
    
    $stmt = $conn->prepare("SELECT role, table_id FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_row = $result->fetch_assoc();
    $stmt->close();
    
    if (!$user_row) {
        $conn->close();
        return false;
    }
    
    $table_map = [
        'student' => 'students',
        'teacher' => 'teachers',
        'technician' => 'technicians',
        'nurse' => 'nurses',
        'custodian' => 'custodians',
        'guard' => 'guards',
        'principal' => 'principals',
        'ssc' => 'ssc',
        'faculty' => 'faculty'
    ];
    
    $table = $table_map[$user_row['role']];
    $table_id = $user_row['table_id'];
    
    $stmt = $conn->prepare("DELETE FROM $table WHERE id=?");
    $stmt->bind_param("i", $table_id);
    $result1 = $stmt->execute();
    $stmt->close();
    
    $stmt2 = $conn->prepare("DELETE FROM users WHERE id=?");
    $stmt2->bind_param("i", $user_id);
    $result2 = $stmt2->execute();
    $stmt2->close();
    
    $conn->close();
    return $result1 && $result2;
}

// Helper: Archive user (soft delete)
function archive_user($user_id, $archived_by_username = 'admin') {
    $conn = db_connect();
    
    $stmt = $conn->prepare("SELECT username, role, table_id, full_name, email FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_row = $result->fetch_assoc();
    $stmt->close();
    
    if (!$user_row) {
        $conn->close();
        return false;
    }
    
    $stmt = $conn->prepare("INSERT INTO user_archive (user_id, username, role, full_name, email, table_id, archived_by_username) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssis", $user_id, $user_row['username'], $user_row['role'], $user_row['full_name'], $user_row['email'], $user_row['table_id'], $archived_by_username);
    $result = $stmt->execute();
    $stmt->close();
    
    // Update status to 'inactive' in role-specific table to prevent login
    $table_map = [
        'student' => 'students',
        'teacher' => 'teachers',
        'technician' => 'technicians',
        'nurse' => 'nurses',
        'custodian' => 'custodians',
        'guard' => 'guards',
        'principal' => 'principals',
        'ssc' => 'ssc',
        'faculty' => 'faculty'
    ];
    
    $table = isset($table_map[$user_row['role']]) ? $table_map[$user_row['role']] : null;
    if ($table && isset($user_row['table_id'])) {
        $stmt_update = $conn->prepare("UPDATE $table SET status = 'inactive' WHERE id = ?");
        $stmt_update->bind_param("i", $user_row['table_id']);
        $stmt_update->execute();
        $stmt_update->close();
    }
    
    $stmt2 = $conn->prepare("DELETE FROM users WHERE id=?");
    $stmt2->bind_param("i", $user_id);
    $stmt2->execute();
    $stmt2->close();
    
    $conn->close();
    return $result;
}

function get_archived_users() {
    return db_query("SELECT * FROM user_archive ORDER BY archived_at DESC");
}

function restore_user($archive_id) {
    $conn = db_connect();
    
    $stmt = $conn->prepare("SELECT user_id, username, role, full_name, email, table_id FROM user_archive WHERE id = ?");
    $stmt->bind_param("i", $archive_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $archived_user = $result->fetch_assoc();
    $stmt->close();
    
    if (!$archived_user) {
        $conn->close();
        return false;
    }
    
    $stmt = $conn->prepare("INSERT INTO users (id, username, role, table_id, full_name, email, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
    $stmt->bind_param("ississ", $archived_user['user_id'], $archived_user['username'], $archived_user['role'], $archived_user['table_id'], $archived_user['full_name'], $archived_user['email']);
    $result = $stmt->execute();
    $stmt->close();
    
    if ($result) {
        // Update status to 'active' in role-specific table to allow login
        $table_map = [
            'student' => 'students',
            'teacher' => 'teachers',
            'technician' => 'technicians',
            'nurse' => 'nurses',
            'custodian' => 'custodians',
            'guard' => 'guards',
            'principal' => 'principals',
            'ssc' => 'ssc',
            'faculty' => 'faculty'
        ];
        
        $table = isset($table_map[$archived_user['role']]) ? $table_map[$archived_user['role']] : null;
        if ($table && isset($archived_user['table_id'])) {
            $stmt_update = $conn->prepare("UPDATE $table SET status = 'active' WHERE id = ?");
            $stmt_update->bind_param("i", $archived_user['table_id']);
            $stmt_update->execute();
            $stmt_update->close();
        }
        
        $stmt2 = $conn->prepare("DELETE FROM user_archive WHERE id=?");
        $stmt2->bind_param("i", $archive_id);
        $stmt2->execute();
        $stmt2->close();
    }
    
    $conn->close();
    return $result;
}

function permanently_delete_archived($archive_id) {
    $conn = db_connect();
    
    $stmt = $conn->prepare("SELECT role, table_id FROM user_archive WHERE id = ?");
    $stmt->bind_param("i", $archive_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $archived_user = $result->fetch_assoc();
    $stmt->close();
    
    if (!$archived_user) {
        $conn->close();
        return false;
    }
    
    $table_map = [
        'student' => 'students',
        'teacher' => 'teachers',
        'technician' => 'technicians',
        'nurse' => 'nurses',
        'custodian' => 'custodians',
        'guard' => 'guards',
        'principal' => 'principals',
        'ssc' => 'ssc',
        'faculty' => 'faculty'
    ];
    
    $table = $table_map[$archived_user['role']];
    $table_id = $archived_user['table_id'];

    $stmt = $conn->prepare("DELETE FROM $table WHERE id=?");
    $stmt->bind_param("i", $table_id);
    $stmt->execute();
    $stmt->close();

    $stmt2 = $conn->prepare("DELETE FROM user_archive WHERE id=?");
    $stmt2->bind_param("i", $archive_id);
    $result = $stmt2->execute();
    $stmt2->close();
    
    $conn->close();
    return $result;
}
?>