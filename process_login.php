<?php
session_start();
include 'config.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php"); exit();
}

$email    = mysqli_real_escape_string($conn, trim($_POST['email']    ?? ''));
$password = trim($_POST['password'] ?? '');
$role     = mysqli_real_escape_string($conn, trim($_POST['role']     ?? ''));

$result = mysqli_query($conn,
    "SELECT id, full_name, role, password FROM users WHERE email='$email' AND role='$role' LIMIT 1");

if ($result && mysqli_num_rows($result) === 1) {
    $user = mysqli_fetch_assoc($result);
    if (password_verify($password, $user['password'])) {
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['role']      = $user['role'];

        switch ($user['role']) {
            case 'admin':                header("Location: admin_dashboard.php");     break;
            case 'lecturer':             header("Location: lecturer_dashboard.php");  break;
            case 'financial_accountant': header("Location: financial_dashboard.php"); break;
            default:                     header("Location: student_dashboard.php");   break;
        }
        exit();
    }
}

header("Location: index.php?error=invalid");
exit();
