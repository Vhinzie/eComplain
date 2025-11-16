<?php
session_start();

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

switch ($_SESSION['user']['role']) {
    case 'admin':
        header('Location: admin_dashboard.php');
        break;
    case 'student':
        header('Location: student_dashboard.php');
        break;
    case 'teacher':
        header('Location: teacher_dashboard.php');
        break;
    case 'technician':
        header('Location: technician_dashboard.php');
        break;
    case 'nurse':
        header('Location: nurse_dashboard.php');
        break;
    case 'faculty':
        header('Location: faculty_dashboard.php');
        break;
    case 'custodian':
        header('Location: custodian_dashboard.php');
        break;
    case 'guard':
        header('Location: guard_dashboard.php');
        break;
    case 'principal':
        header('Location: principal_dashboard.php');
        break;
    case 'ssc':
        header('Location: ssc_dashboard.php');
        break;
    default:
        session_destroy();
        header('Location: login.php?error=invalid_role');
        break;
}
exit;