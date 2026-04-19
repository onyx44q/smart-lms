<?php
session_start();
include 'config.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $password = $_POST['password']; 
    $role = $_POST['role'];

    // Select the hashed password from the database to verify it
    $stmt = $conn->prepare("SELECT id, full_name, role, password FROM users WHERE email=? AND role=?");
    $stmt->bind_param("ss", $email, $role);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        // Verify the password against the hash stored in the DB
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];

            // Redirect with the 'success' status flag for the dashboard toast
            if ($role == 'admin') {
                header("Location: admin_dashboard.php?status=success");
            } elseif ($role == 'lecturer') {
                header("Location: lecturer_dashboard.php?status=success");
            } else {
                header("Location: student_dashboard.php?status=success");
            }
            exit();
        } else {
            // Password incorrect
            header("Location: index.php?error=invalid");
            exit();
        }
    } else {
        // User not found or role mismatch
        header("Location: index.php?error=invalid");
        exit();
    }
}
?>