<?php
include 'config.php';
checkRole('admin');

// --- HANDLING POST REQUESTS (ADDING) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action_type'];

    if ($action === 'add_course') {
        $title = mysqli_real_escape_string($conn, $_POST['title']);
        mysqli_query($conn, "INSERT INTO courses (title) VALUES ('$title')");
        header("Location: admin_dashboard.php?view=courses&status=added");
        exit;
    }

    if ($action === 'add_lecturer') {
        $name = mysqli_real_escape_string($conn, $_POST['full_name']);
        $email = mysqli_real_escape_string($conn, $_POST['email']);
        $course_id = mysqli_real_escape_string($conn, $_POST['course_id']);
        $pwd = password_hash($_POST['password'], PASSWORD_BCRYPT);
        
        // 1. Insert Lecturer into users table
        $sql = "INSERT INTO users (full_name, email, password, role, course_id) 
                VALUES ('$name', '$email', '$pwd', 'lecturer', '$course_id')";
        
        if (mysqli_query($conn, $sql)) {
            // 2. Get the newly created Lecturer's ID
            $new_lecturer_id = mysqli_insert_id($conn);

            // 3. Update the courses table to link this lecturer
            if (!empty($course_id)) {
                mysqli_query($conn, "UPDATE courses SET lecturer_id = '$new_lecturer_id' WHERE id = '$course_id'");
            }
            header("Location: admin_dashboard.php?view=users&status=added");
        } else {
            header("Location: admin_dashboard.php?view=users&status=error");
        }
        exit;
    }
}

// --- HANDLING GET REQUESTS (DELETING) ---
if (isset($_GET['delete_user'])) {
    $id = intval($_GET['delete_user']);
    mysqli_query($conn, "DELETE FROM users WHERE id = $id");
    header("Location: admin_dashboard.php?view=users&status=deleted");
    exit;
}

if (isset($_GET['delete_course'])) {
    $id = intval($_GET['delete_course']);
    mysqli_query($conn, "UPDATE users SET course_id = NULL WHERE course_id = $id");
    mysqli_query($conn, "DELETE FROM courses WHERE id = $id");
    header("Location: admin_dashboard.php?view=courses&status=deleted");
    exit;
}

// ── Course Catalog view: handle units sub-actions ─────────────────
if ($view == 'courses') {
    // handled inside admin_dashboard.php or unit_actions.php
}
?>