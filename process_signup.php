<?php
include 'config.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = $_POST['role'];
    $selected_course_id = intval($_POST['career_path'] ?? 0);

    // Resolve the course title (career_path is now stored as the human-readable
    // course name for backwards compatibility with everywhere it's displayed as text)
    $career_path = '';
    $course_id_sql = 'NULL';
    if ($selected_course_id) {
        $course_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id, title FROM courses WHERE id=$selected_course_id LIMIT 1"));
        if ($course_row) {
            $career_path = mysqli_real_escape_string($conn, $course_row['title']);
            $course_id_sql = intval($course_row['id']);
        }
    }

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

    // 4. Insert User (course_id links them directly to the chosen course/career focus)
    $sql = "INSERT INTO users (full_name, email, password, role, career_path, course_id) 
            VALUES ('$full_name', '$email', '$hashed_password', '$role', '$career_path', $course_id_sql)";

    if ($conn->query($sql) === TRUE) {
        $user_id = $conn->insert_id;

        // 5. SMART INITIALIZATION: If user is a student, create their mastery profile
        //    and auto-enroll them in the course they picked at signup so their
        //    units are immediately visible without a separate manual enrollment step.
        if ($role == 'student') {
            $skills = ['General Aptitude', 'Core Theory', 'Practical Application'];
            foreach ($skills as $skill) {
                $conn->query("INSERT INTO student_mastery (student_id, skill_name, mastery_level) 
                              VALUES ($user_id, '$skill', 0.00)");
            }
            if ($selected_course_id) {
                $conn->query("INSERT IGNORE INTO enrollments (student_id, course_id)
                              VALUES ($user_id, $selected_course_id)");
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