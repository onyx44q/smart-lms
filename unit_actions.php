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
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`), KEY `idx_course` (`course_id`), KEY `idx_lect` (`lecturer_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `unit_registrations` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `student_id` INT(11) NOT NULL,
      `unit_id` INT(11) NOT NULL,
      `registered_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`), UNIQUE KEY `unique_reg` (`student_id`, `unit_id`)
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

        if ($course_id && $title) {
            mysqli_query($conn,
                "INSERT INTO course_units (course_id, title, unit_code, description, lecturer_id)
                 VALUES ($course_id, '$title', " . ($unit_code ? "'$unit_code'" : 'NULL') . ", '$description', $lecturer_id)"
            );
            $new_unit_id = intval(mysqli_insert_id($conn));

            // AUTO-REGISTER all students already enrolled in this course
            // into the new unit so they can see quizzes immediately.
            if ($new_unit_id) {
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
            "DELETE FROM unit_registrations WHERE student_id = $user_id AND unit_id = $unit_id"
        );
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

        // Verify lecturer teaches this unit
        $ok = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM course_units WHERE id = $unit_id AND lecturer_id = $user_id"
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
        // Verify ownership
        $ok = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT ua.id FROM unit_assessments ua
             JOIN course_units cu ON cu.id = ua.unit_id
             WHERE ua.id = $assess_id AND cu.lecturer_id = $user_id"
        ));
        if ($ok) {
            mysqli_query($conn, "DELETE FROM unit_assessments WHERE id = $assess_id");
        }
        header("Location: lecturer_dashboard.php?view=marks&unit_id=$unit_id&umsg=assess_deleted");
        exit();
    }
}

// Fallback
header("Location: index.php");
exit();