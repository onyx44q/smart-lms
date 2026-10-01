<?php
/**
 * unit_actions.php
 * Handles: unit CRUD (admin), unit registration by students
 */
include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Auto-create all units tables ─────────────────────────────────────────
$tables = [
    "CREATE TABLE IF NOT EXISTS `course_units` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `course_id` INT(11) NOT NULL,
      `title` VARCHAR(255) NOT NULL,
      `unit_code` VARCHAR(50) DEFAULT NULL,
      `description` TEXT DEFAULT NULL,
      `lecturer_id` INT(11) DEFAULT NULL,
      `semester_number` INT(11) NOT NULL DEFAULT 1,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`), KEY `idx_course` (`course_id`), KEY `idx_lect` (`lecturer_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `unit_registrations` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `student_id` INT(11) NOT NULL,
      `unit_id` INT(11) NOT NULL,
      `status` ENUM('active','passed','failed') NOT NULL DEFAULT 'active',
      `attempt_no` INT(11) NOT NULL DEFAULT 1,
      `registered_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`), UNIQUE KEY `unique_reg_attempt` (`student_id`, `unit_id`, `attempt_no`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `unit_assessments` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `unit_id` INT(11) NOT NULL,
      `name` VARCHAR(100) NOT NULL,
      `type` ENUM('coursework','exam') NOT NULL DEFAULT 'coursework',
      `max_mark` DECIMAL(6,2) NOT NULL DEFAULT 100.00,
      `sort_order` TINYINT(3) NOT NULL DEFAULT 0,
      `created_by` INT(11) DEFAULT NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`), KEY `idx_unit` (`unit_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `unit_marks` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `assessment_id` INT(11) NOT NULL,
      `student_id` INT(11) NOT NULL,
      `mark` DECIMAL(6,2) DEFAULT NULL,
      `remarks` VARCHAR(255) DEFAULT NULL,
      `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`), UNIQUE KEY `unique_mark` (`assessment_id`, `student_id`),
      KEY `idx_student` (`student_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
foreach ($tables as $sql) mysqli_query($conn, $sql);

// ── Patch existing installations: add semester_number if the table
//    predates this feature (CREATE TABLE IF NOT EXISTS above won't touch
//    an already-existing table). Existing units default to Semester 1,
//    exactly as if they'd always belonged there.
$sem_col = mysqli_fetch_assoc(mysqli_query($conn, "SHOW COLUMNS FROM course_units LIKE 'semester_number'"));
if (!$sem_col) {
    mysqli_query($conn, "ALTER TABLE course_units ADD COLUMN semester_number INT(11) NOT NULL DEFAULT 1 AFTER lecturer_id");
}

$role    = $_SESSION['role'] ?? '';
$user_id = intval($_SESSION['user_id'] ?? 0);
$action  = $_POST['action'] ?? $_GET['action'] ?? '';

// ══════════════════════════════════════════════════════
// ADMIN ACTIONS
// ══════════════════════════════════════════════════════
if ($role === 'admin') {

    // ── Add unit to a course ──────────────────────────────
    if ($action === 'add_unit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $course_id   = intval($_POST['course_id'] ?? 0);
        $title       = mysqli_real_escape_string($conn, trim($_POST['title'] ?? ''));
        $unit_code   = mysqli_real_escape_string($conn, trim($_POST['unit_code'] ?? ''));
        $description = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));
        $lecturer_id = intval($_POST['lecturer_id'] ?? 0) ?: 'NULL';
        $semester_number = max(1, intval($_POST['semester_number'] ?? 1));

        if ($course_id && $title) {
            mysqli_query($conn,
                "INSERT INTO course_units (course_id, title, unit_code, description, lecturer_id, semester_number)
                 VALUES ($course_id, '$title', " . ($unit_code ? "'$unit_code'" : 'NULL') . ", '$description', $lecturer_id, $semester_number)"
            );
            $new_unit_id = intval(mysqli_insert_id($conn));

            // AUTO-REGISTER all students already enrolled in this course, but ONLY
            // if this unit belongs to Semester 1 — later-semester units unlock
            // progressively via the Exam Card once earlier semesters are cleared.
            if ($new_unit_id && $semester_number <= 1) {
                mysqli_query($conn,
                    "INSERT IGNORE INTO unit_registrations (student_id, unit_id)
                     SELECT e.student_id, $new_unit_id
                     FROM enrollments e
                     WHERE e.course_id = $course_id"
                );
            }

            header("Location: admin_dashboard.php?view=courses&status=unit_added&sts_course=$course_id");
        } else {
            header("Location: admin_dashboard.php?view=courses&status=error&sts_course=$course_id");
        }
        exit();
    }

    // ── Move an existing unit to a different semester ─────
    if ($action === 'update_unit_semester' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $unit_id = intval($_POST['unit_id'] ?? 0);
        $semester_number = max(1, intval($_POST['semester_number'] ?? 1));
        $urow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT course_id FROM course_units WHERE id=$unit_id"));
        if ($urow) {
            mysqli_query($conn, "UPDATE course_units SET semester_number=$semester_number WHERE id=$unit_id");
            // Semester 1 units are auto-registered for everyone already enrolled,
            // same as at creation time — keeps behaviour consistent either way.
            if ($semester_number <= 1) {
                mysqli_query($conn,
                    "INSERT IGNORE INTO unit_registrations (student_id, unit_id)
                     SELECT e.student_id, $unit_id FROM enrollments e WHERE e.course_id = {$urow['course_id']}"
                );
            }
            header("Location: admin_dashboard.php?view=courses&status=unit_added&sts_course={$urow['course_id']}");
        } else {
            header("Location: admin_dashboard.php?view=courses&status=error");
        }
        exit();
    }

    // ── Assign lecturer to unit ───────────────────────────
    if ($action === 'assign_unit_lecturer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $unit_id     = intval($_POST['unit_id'] ?? 0);
        $lecturer_id = intval($_POST['lecturer_id'] ?? 0);
        $course_id   = intval($_POST['course_id'] ?? 0);
        $lect_sql    = $lecturer_id ? $lecturer_id : 'NULL';

        mysqli_query($conn, "UPDATE course_units SET lecturer_id = $lect_sql WHERE id = $unit_id");
        header("Location: admin_dashboard.php?view=courses&status=assigned&sts_course=$course_id");
        exit();
    }

    // ── Delete unit ───────────────────────────────────────
    if ($action === 'delete_unit') {
        $unit_id   = intval($_GET['unit_id'] ?? 0);
        $course_id = intval($_GET['course_id'] ?? 0);
        mysqli_query($conn, "DELETE FROM course_units WHERE id = $unit_id");
        header("Location: admin_dashboard.php?view=courses&status=unit_deleted&sts_course=$course_id");
        exit();
    }
}

// ══════════════════════════════════════════════════════
// STUDENT ACTIONS
// ══════════════════════════════════════════════════════
if ($role === 'student') {

    // ── Register for a unit ───────────────────────────────
    if ($action === 'register_unit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $unit_id = intval($_POST['unit_id'] ?? 0);
        // Verify student is enrolled in the course that owns this unit
        $ok = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT cu.id FROM course_units cu
             JOIN enrollments e ON e.course_id = cu.course_id
             WHERE cu.id = $unit_id AND e.student_id = $user_id LIMIT 1"
        ));
        if ($ok) {
            mysqli_query($conn,
                "INSERT IGNORE INTO unit_registrations (student_id, unit_id)
                 VALUES ($user_id, $unit_id)"
            );
        }
        header("Location: student_dashboard.php#view-units");
        exit();
    }

    // ── Deregister from a unit ────────────────────────────
    if ($action === 'deregister_unit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $unit_id = intval($_POST['unit_id'] ?? 0);
        mysqli_query($conn,
            "DELETE FROM unit_registrations WHERE student_id = $user_id AND unit_id = $unit_id AND status = 'active'"
        );
        header("Location: student_dashboard.php#view-units");
        exit();
    }

    // ── Retake a failed unit in a new intake (new attempt row) ────
    if ($action === 'retake_unit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $unit_id = intval($_POST['unit_id'] ?? 0);
        // Must be enrolled in the parent course, and latest attempt must be 'failed'
        $latest = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT status, attempt_no FROM unit_registrations
             WHERE student_id=$user_id AND unit_id=$unit_id
             ORDER BY attempt_no DESC LIMIT 1"
        ));
        $enrolled_ok = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT cu.id FROM course_units cu
             JOIN enrollments e ON e.course_id = cu.course_id
             WHERE cu.id = $unit_id AND e.student_id = $user_id LIMIT 1"
        ));
        // Recognise a computed fail (overall performance below the pass mark)
        // as well as an explicit manual 'failed' status set by a lecturer.
        $resolved_status = unit_completion_status($conn, $user_id, $unit_id)['resolved'];
        if ($enrolled_ok && $latest && ($latest['status'] === 'failed' || $resolved_status === 'failed')) {
            $next_attempt = intval($latest['attempt_no']) + 1;
            mysqli_query($conn,
                "INSERT INTO unit_registrations (student_id, unit_id, status, attempt_no)
                 VALUES ($user_id, $unit_id, 'active', $next_attempt)"
            );
        }
        header("Location: student_dashboard.php#view-units");
        exit();
    }

    // ── Bulk register all units for a course ─────────────
    if ($action === 'register_all_units' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $course_id = intval($_POST['course_id'] ?? 0);
        $units = mysqli_query($conn,
            "SELECT cu.id FROM course_units cu
             JOIN enrollments e ON e.course_id = cu.course_id
             WHERE cu.course_id = $course_id AND e.student_id = $user_id"
        );
        while ($u = mysqli_fetch_assoc($units)) {
            mysqli_query($conn,
                "INSERT IGNORE INTO unit_registrations (student_id, unit_id)
                 VALUES ($user_id, {$u['id']})"
            );
        }
        header("Location: student_dashboard.php#view-units");
        exit();
    }

    // ── Register for every unit in a SPECIFIC semester of a course ──────
    // Used by the Exam Card's "next semester unlock" — deliberately scoped
    // to one semester so clearing Semester 1 never accidentally opens
    // Semester 3 units too.
    if ($action === 'register_semester_units' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $course_id = intval($_POST['course_id'] ?? 0);
        $semester  = intval($_POST['semester_number'] ?? 0);
        if ($course_id && $semester) {
            // Verify enrollment first
            $enrolled = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT id FROM enrollments WHERE course_id=$course_id AND student_id=$user_id LIMIT 1"));
            if ($enrolled) {
                $units = mysqli_query($conn,
                    "SELECT id FROM course_units WHERE course_id=$course_id AND semester_number=$semester"
                );
                while ($u = mysqli_fetch_assoc($units)) {
                    mysqli_query($conn,
                        "INSERT IGNORE INTO unit_registrations (student_id, unit_id) VALUES ($user_id, {$u['id']})"
                    );
                }
            }
        }
        header("Location: exam_card.php?registered=1");
        exit();
    }
}

// ══════════════════════════════════════════════════════
// LECTURER ACTIONS — assessment management
// ══════════════════════════════════════════════════════
if ($role === 'lecturer') {

    // ── Add assessment component to a unit ───────────────
    if ($action === 'add_assessment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $unit_id  = intval($_POST['unit_id'] ?? 0);
        $name     = mysqli_real_escape_string($conn, trim($_POST['name'] ?? ''));
        $type     = in_array($_POST['type'] ?? '', ['coursework','exam']) ? $_POST['type'] : 'coursework';
        $max_mark = max(1, floatval($_POST['max_mark'] ?? 100));

        // Verify lecturer teaches this unit (directly, or owns the parent course)
        $ok = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT cu.id FROM course_units cu
             JOIN courses c ON c.id = cu.course_id
             WHERE cu.id = $unit_id AND (cu.lecturer_id = $user_id OR c.lecturer_id = $user_id)"
        ));
        if ($ok && $name) {
            $sort = intval(mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COALESCE(MAX(sort_order),0)+1 AS nxt FROM unit_assessments WHERE unit_id = $unit_id"
            ))['nxt']);
            mysqli_query($conn,
                "INSERT INTO unit_assessments (unit_id, name, type, max_mark, sort_order, created_by)
                 VALUES ($unit_id, '$name', '$type', $max_mark, $sort, $user_id)"
            );
            header("Location: lecturer_dashboard.php?view=marks&unit_id=$unit_id&umsg=assess_added");
        } else {
            header("Location: lecturer_dashboard.php?view=marks&unit_id=$unit_id&umsg=error");
        }
        exit();
    }

    // ── Delete assessment component ───────────────────────
    if ($action === 'delete_assessment') {
        $assess_id = intval($_GET['assess_id'] ?? 0);
        $unit_id   = intval($_GET['unit_id'] ?? 0);
        // Verify ownership (directly, or owns the parent course)
        $ok = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT ua.id FROM unit_assessments ua
             JOIN course_units cu ON cu.id = ua.unit_id
             JOIN courses c ON c.id = cu.course_id
             WHERE ua.id = $assess_id AND (cu.lecturer_id = $user_id OR c.lecturer_id = $user_id)"
        ));
        if ($ok) {
            mysqli_query($conn, "DELETE FROM unit_assessments WHERE id = $assess_id");
        }
        header("Location: lecturer_dashboard.php?view=marks&unit_id=$unit_id&umsg=assess_deleted");
        exit();
    }

    // ── Set a student's pass/fail status for their latest attempt at a unit ──
    if ($action === 'set_unit_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $unit_id    = intval($_POST['unit_id'] ?? 0);
        $student_id = intval($_POST['student_id'] ?? 0);
        $new_status = in_array($_POST['new_status'] ?? '', ['active','passed','failed']) ? $_POST['new_status'] : 'active';

        // Security: a lecturer may only set pass/fail status for units they
        // actually teach — either assigned directly to the unit, or they own
        // the parent course.
        $ok = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT cu.id FROM course_units cu
             JOIN courses c ON c.id = cu.course_id
             WHERE cu.id = $unit_id AND (cu.lecturer_id = $user_id OR c.lecturer_id = $user_id)"
        ));
        $umsg = 'status_set';
        if ($ok) {
            $upd = mysqli_query($conn,
                "UPDATE unit_registrations SET status='$new_status'
                 WHERE student_id=$student_id AND unit_id=$unit_id
                 ORDER BY attempt_no DESC LIMIT 1"
            );
            if (!$upd || mysqli_affected_rows($conn) === 0) {
                $umsg = 'status_error';
            }
        } else {
            $umsg = 'status_denied';
        }
        header("Location: lecturer_dashboard.php?view=marks&unit_id=$unit_id&umsg=$umsg");
        exit();
    }
}

// ══════════════════════════════════════════════════════
// COMPLETION STATUS — student self-check or admin check
// ══════════════════════════════════════════════════════
if ($action === 'check_completion' && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($role, ['student','admin'])) {
    $check_student_id = $role === 'admin' ? intval($_POST['student_id'] ?? 0) : $user_id;
    $check_course_id  = intval($_POST['course_id'] ?? 0);

    if ($check_student_id && $check_course_id) {
        // Fees: overall clearance (system-wide, matches completion_report.php logic)
        $total_fees = floatval(mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COALESCE(SUM(net_amount),0) t FROM student_fee_assignments WHERE student_id=$check_student_id AND status != 'waived'"))['t']);
        $total_paid = floatval(mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COALESCE(SUM(amount_paid),0) t FROM fee_payments WHERE student_id=$check_student_id"))['t']);
        $fees_clear = $total_paid >= $total_fees;

        // Units for THIS course: every unit's latest attempt must resolve to
        // 'passed' — either explicitly set by a lecturer, or computed from the
        // student's overall performance across that unit's assessments, so a
        // student who has genuinely passed shows complete even if no lecturer
        // ever clicked a manual "mark as passed" button.
        $units_res = mysqli_query($conn, "SELECT id FROM course_units WHERE course_id=$check_course_id");
        $all_units_passed = true; $has_units = false;
        while ($u = mysqli_fetch_assoc($units_res)) {
            $has_units = true;
            if (!unit_is_passed($conn, $check_student_id, intval($u['id']))) { $all_units_passed = false; break; }
        }

        $complete = $fees_clear && $has_units && $all_units_passed;
        $status_val = $complete ? 'complete' : 'incomplete';

        mysqli_query($conn, "INSERT INTO course_completions (student_id, course_id, status)
            VALUES ($check_student_id, $check_course_id, '$status_val')
            ON DUPLICATE KEY UPDATE status='$status_val', checked_at=NOW()");
    }

    if ($role === 'admin') {
        header("Location: admin_dashboard.php?view=completions&status=checked");
    } else {
        header("Location: student_dashboard.php?view=overview&status=success&msg=Completion Status Updated");
    }
    exit();
}

// Fallback
header("Location: index.php");
exit();