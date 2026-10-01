<?php
include 'config.php';
checkRole('admin');

// --- HANDLING POST REQUESTS (ADDING) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action_type'];

    // ── Set Capacity for ONE class (flexible, no upper limit) ──────────
    if ($action === 'set_class_capacity') {
        $cid = intval($_POST['class_id']);
        $cap = intval($_POST['capacity']);
        if ($cap < 1) $cap = 1;
        mysqli_query($conn, "UPDATE classes SET capacity=$cap WHERE id=$cid");
        header("Location: admin_dashboard.php?view=classes&cmsg=capacity_updated"); exit();
    }

    // ── Set Capacity for ALL classes at once ────────────────────────────
    if ($action === 'set_all_capacity') {
        $cap = intval($_POST['bulk_capacity']);
        if ($cap < 1) $cap = 1;
        mysqli_query($conn, "UPDATE classes SET capacity=$cap");
        header("Location: admin_dashboard.php?view=classes&cmsg=capacity_updated"); exit();
    }

    // ── Allocate / Move Student to a Class ──────────────────────────────
    if ($action === 'allocate_class') {
        $sid = intval($_POST['student_id']);
        $cid = intval($_POST['class_id']);

        // Verify class exists and has room
        $cls = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM classes WHERE id=$cid"));
        if (!$cls) {
            header("Location: admin_dashboard.php?view=classes&cmsg=error"); exit();
        }
        $occ = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) AS c FROM class_allocations WHERE class_id=$cid AND status='active'"))['c'];

        // If student already has an active allocation to a DIFFERENT class, allow moving even if target has room
        $current = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id, class_id FROM class_allocations WHERE student_id=$sid AND status='active'"));

        if ($current && intval($current['class_id']) === $cid) {
            // No change needed
            header("Location: admin_dashboard.php?view=classes&cmsg=allocated"); exit();
        }

        if (intval($occ) >= intval($cls['capacity'])) {
            header("Location: admin_dashboard.php?view=classes&cmsg=full"); exit();
        }

        $admin_id = intval($_SESSION['user_id'] ?? 0);

        if ($current) {
            // Move: update existing active row to new class
            mysqli_query($conn, "UPDATE class_allocations SET class_id=$cid, allocated_by=$admin_id, allocated_at=NOW() WHERE id={$current['id']}");
        } else {
            mysqli_query($conn, "INSERT INTO class_allocations (student_id, class_id, allocated_by, status) VALUES ($sid, $cid, $admin_id, 'active')");
        }
        header("Location: admin_dashboard.php?view=classes&cmsg=allocated"); exit();
    }

    // ── Set Admission Number ──────────────────────────────────────────
    if ($action === 'set_admission_number') {
        $uid    = intval($_POST['user_id']);
        $adm_no = mysqli_real_escape_string($conn, trim($_POST['admission_number'] ?? ''));

        // Ensure column exists
        @mysqli_query($conn, "ALTER TABLE users ADD COLUMN `admission_number` VARCHAR(30) DEFAULT NULL AFTER `email`");
        @mysqli_query($conn, "ALTER TABLE users ADD UNIQUE KEY `uq_admission_number` (`admission_number`)");

        if ($adm_no === '') {
            // Clear the admission number
            mysqli_query($conn, "UPDATE users SET admission_number=NULL WHERE id=$uid");
            header("Location: admin_dashboard.php?view=users&status=adm_cleared"); exit();
        } else {
            // Check not taken by another student
            $exists = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM users WHERE admission_number='$adm_no' AND id != $uid LIMIT 1"));
            if ($exists) {
                header("Location: admin_dashboard.php?view=users&status=adm_taken"); exit();
            }
            mysqli_query($conn, "UPDATE users SET admission_number='$adm_no' WHERE id=$uid");
            header("Location: admin_dashboard.php?view=users&status=adm_set"); exit();
        }
    }

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

    // ── Review a student's graduation registration ──────────────────────
    if ($action === 'review_graduation') {
        $grad_id    = intval($_POST['grad_id'] ?? 0);
        $new_status = in_array($_POST['new_status'] ?? '', ['approved','rejected','cleared']) ? $_POST['new_status'] : '';
        if ($grad_id && $new_status) {
            mysqli_query($conn, "UPDATE graduation_registrations SET status='$new_status' WHERE id=$grad_id");
        }
        header("Location: admin_dashboard.php?view=graduation&status=reviewed");
        exit;
    }

    // ── Set/update the official ceremony date for a student's graduation
    //    registration — visible to the student on their Graduation page
    //    once saved. ──────────────────────────────────────────────────
    if ($action === 'set_graduation_date') {
        $grad_id = intval($_POST['grad_id'] ?? 0);
        $g_date  = trim($_POST['graduation_date'] ?? '');
        $valid_date = $g_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $g_date);
        if ($grad_id && $valid_date) {
            $g_date_esc = mysqli_real_escape_string($conn, $g_date);
            mysqli_query($conn, "UPDATE graduation_registrations SET graduation_date='$g_date_esc' WHERE id=$grad_id");
            header("Location: admin_dashboard.php?view=graduation&status=date_set");
        } else {
            header("Location: admin_dashboard.php?view=graduation&status=error");
        }
        exit;
    }

    // ── Bulk-approve every pending graduation request in one click ──────
    // Safe because submit_graduation on the student side already enforces
    // fee clearance + all-units-passed before a row can even reach 'pending'.
    if ($action === 'bulk_approve_graduation') {
        mysqli_query($conn, "UPDATE graduation_registrations SET status='approved' WHERE status='pending'");
        header("Location: admin_dashboard.php?view=graduation&status=bulk_approved");
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

if (isset($_GET['remove_class_allocation'])) {
    $aid = intval($_GET['remove_class_allocation']);
    mysqli_query($conn, "UPDATE class_allocations SET status='removed' WHERE id=$aid");
    header("Location: admin_dashboard.php?view=classes&cmsg=removed");
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