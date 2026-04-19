<?php
include 'config.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = $_POST['role'];
    $career_path = $_POST['career_path'];

    // 1. Basic Validation
    if ($password !== $confirm_password) {
        die("Passwords do not match.");
    }

    // 2. Hash Password for security
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);

    // 3. Check if user exists
    $check_email = $conn->query("SELECT id FROM users WHERE email = '$email'");
    if ($check_email->num_rows > 0) {
        die("Email already registered.");
    }

    // 4. Insert User
    $sql = "INSERT INTO users (full_name, email, password, role, career_path) 
            VALUES ('$full_name', '$email', '$hashed_password', '$role', '$career_path')";

    if ($conn->query($sql) === TRUE) {
        $user_id = $conn->insert_id;

        // 5. SMART INITIALIZATION: If user is a student, create their mastery profile
        if ($role == 'student') {
            // Seed the AI with baseline skills for their career path
            $skills = ['General Aptitude', 'Core Theory', 'Practical Application'];
            foreach ($skills as $skill) {
                $conn->query("INSERT INTO student_mastery (student_id, skill_name, mastery_level) 
                              VALUES ($user_id, '$skill', 0.00)");
            }
        }

        // 6. Success Redirect (Updated with the 'status' flag for your toast notification)
        header("Location: index.php?status=registered");
        exit();
    } else {
        echo "Error: " . $conn->error;
    }
}
?>