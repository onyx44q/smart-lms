<?php
session_start();
include 'config.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php"); exit();
}

$identifier = mysqli_real_escape_string($conn, trim($_POST['email'] ?? ''));
$password   = trim($_POST['password'] ?? '');
$role       = mysqli_real_escape_string($conn, trim($_POST['role'] ?? ''));

// Extend role ENUM silently in case it hasn't been done yet
@mysqli_query($conn, "ALTER TABLE users MODIFY COLUMN `role`
    ENUM('student','lecturer','admin','financial_accountant','boarding_master','hr_manager')
    NOT NULL DEFAULT 'student'");

// Also ensure admission_number column exists on users table
@mysqli_query($conn, "ALTER TABLE users ADD COLUMN `admission_number` VARCHAR(30) DEFAULT NULL AFTER `email`");
@mysqli_query($conn, "ALTER TABLE users ADD UNIQUE KEY `uq_admission_number` (`admission_number`)");

// ── Try to find user by EMAIL first, then by ADMISSION NUMBER ─────────
$user = null;

// Try email match
$res = mysqli_query($conn,
    "SELECT id, full_name, role, password FROM users
     WHERE email='$identifier' AND role='$role' LIMIT 1");
if ($res && mysqli_num_rows($res) === 1) {
    $user = mysqli_fetch_assoc($res);
}

// If not found by email, try admission_number (students only)
if (!$user) {
    $res2 = mysqli_query($conn,
        "SELECT id, full_name, role, password FROM users
         WHERE admission_number='$identifier' AND role='$role' LIMIT 1");
    if ($res2 && mysqli_num_rows($res2) === 1) {
        $user = mysqli_fetch_assoc($res2);
    }
}

// ── Verify password and create session ───────────────────────────────
if ($user && password_verify($password, $user['password'])) {
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['role']      = $user['role'];

    switch ($user['role']) {
        case 'admin':                header("Location: admin_dashboard.php");     break;
        case 'lecturer':             header("Location: lecturer_dashboard.php");  break;
        case 'financial_accountant': header("Location: financial_dashboard.php"); break;
        case 'boarding_master':      header("Location: boarding_dashboard.php");  break;
        case 'hr_manager':           header("Location: hr_dashboard.php");        break;
        default:                     header("Location: student_dashboard.php");   break;
    }
    exit();
}

header("Location: index.php?error=invalid");
exit();
