<?php
/**
 * attendance_actions.php
 * Handles all attendance CRUD operations via POST/GET.
 * Actions: create_session, mark_attendance, get_session_students,
 *          delete_session, get_unit_attendance (admin/student view)
 */
include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id   = intval($_SESSION['user_id']);
$user_role = $_SESSION['role'] ?? '';

// ── Auto-create attendance tables ──────────────────────────────────────────
$tables = [
    "CREATE TABLE IF NOT EXISTS `attendance_sessions` (
      `id`           INT(11)      NOT NULL AUTO_INCREMENT,
      `unit_id`      INT(11)      NOT NULL,
      `lecturer_id`  INT(11)      NOT NULL,
      `session_date` DATE         NOT NULL,
      `title`        VARCHAR(255) NOT NULL DEFAULT 'Lecture',
      `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_unit` (`unit_id`),
      KEY `idx_lecturer` (`lecturer_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `attendance_records` (
      `id`         INT(11)              NOT NULL AUTO_INCREMENT,
      `session_id` INT(11)              NOT NULL,
      `student_id` INT(11)              NOT NULL,
      `status`     ENUM('present','absent') NOT NULL DEFAULT 'absent',
      `marked_at`  TIMESTAMP            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_session_student` (`session_id`,`student_id`),
      KEY `idx_student` (`student_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];
foreach ($tables as $sql) mysqli_query($conn, $sql);

$action = $_POST['action'] ?? $_GET['action'] ?? '';
header('Content-Type: application/json');

// ────────────────────────────────────────────────────────────────────────────
// ACTION: create_session — lecturer creates a new attendance session
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'create_session' && $user_role === 'lecturer') {
    $unit_id      = intval($_POST['unit_id'] ?? 0);
    $session_date = mysqli_real_escape_string($conn, trim($_POST['session_date'] ?? date('Y-m-d')));
    $title        = mysqli_real_escape_string($conn, trim($_POST['title'] ?? 'Lecture'));

    if (!$unit_id || !$session_date) {
        echo json_encode(['success' => false, 'error' => 'Missing fields']);
        exit();
    }

    // Verify lecturer owns this unit
    $check = mysqli_query($conn,
        "SELECT id FROM course_units WHERE id = $unit_id AND lecturer_id = $user_id"
    );
    if (!mysqli_num_rows($check)) {
        echo json_encode(['success' => false, 'error' => 'Unit not assigned to you']);
        exit();
    }

    // Prevent duplicate sessions on same date for same unit
    $dup = mysqli_query($conn,
        "SELECT id FROM attendance_sessions WHERE unit_id = $unit_id AND session_date = '$session_date'"
    );
    if (mysqli_num_rows($dup)) {
        echo json_encode(['success' => false, 'error' => 'A session already exists for this date']);
        exit();
    }

    mysqli_query($conn,
        "INSERT INTO attendance_sessions (unit_id, lecturer_id, session_date, title)
         VALUES ($unit_id, $user_id, '$session_date', '$title')"
    );
    $session_id = mysqli_insert_id($conn);

    // Auto-populate attendance_records (all students → absent by default)
    mysqli_query($conn,
        "INSERT IGNORE INTO attendance_records (session_id, student_id, status)
         SELECT $session_id, ur.student_id, 'absent'
         FROM unit_registrations ur
         WHERE ur.unit_id = $unit_id"
    );

    echo json_encode(['success' => true, 'session_id' => $session_id]);
    exit();
}

// ────────────────────────────────────────────────────────────────────────────
// ACTION: get_session_students — return students + their status for a session
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'get_session_students') {
    $session_id = intval($_GET['session_id'] ?? 0);
    if (!$session_id) { echo json_encode([]); exit(); }

    // Allow lecturer, admin to access
    if ($user_role === 'lecturer') {
        $owns = mysqli_query($conn,
            "SELECT id FROM attendance_sessions WHERE id = $session_id AND lecturer_id = $user_id"
        );
        if (!mysqli_num_rows($owns)) { echo json_encode([]); exit(); }
    } elseif ($user_role !== 'admin') {
        echo json_encode([]); exit();
    }

    $res = mysqli_query($conn,
        "SELECT u.id AS student_id, u.full_name,
                COALESCE(ar.status,'absent') AS status
         FROM attendance_records ar
         JOIN users u ON u.id = ar.student_id
         WHERE ar.session_id = $session_id
         ORDER BY u.full_name ASC"
    );
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    echo json_encode($rows);
    exit();
}

// ────────────────────────────────────────────────────────────────────────────
// ACTION: mark_attendance — lecturer saves present/absent for a session
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'mark_attendance' && $user_role === 'lecturer') {
    $session_id = intval($_POST['session_id'] ?? 0);
    $records    = $_POST['records'] ?? []; // [{student_id, status}, ...]

    if (!$session_id) { echo json_encode(['success' => false, 'error' => 'No session']); exit(); }

    // Verify lecturer owns this session
    $owns = mysqli_query($conn,
        "SELECT id FROM attendance_sessions WHERE id = $session_id AND lecturer_id = $user_id"
    );
    if (!mysqli_num_rows($owns)) {
        echo json_encode(['success' => false, 'error' => 'Not your session']); exit();
    }

    foreach ($records as $rec) {
        $sid    = intval($rec['student_id'] ?? 0);
        $status = ($rec['status'] ?? 'absent') === 'present' ? 'present' : 'absent';
        if (!$sid) continue;
        mysqli_query($conn,
            "INSERT INTO attendance_records (session_id, student_id, status)
             VALUES ($session_id, $sid, '$status')
             ON DUPLICATE KEY UPDATE status = '$status', marked_at = CURRENT_TIMESTAMP"
        );
    }
    echo json_encode(['success' => true]);
    exit();
}

// ────────────────────────────────────────────────────────────────────────────
// ACTION: delete_session — lecturer deletes a session
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'delete_session' && ($user_role === 'lecturer' || $user_role === 'admin')) {
    $session_id = intval($_POST['session_id'] ?? 0);
    if ($user_role === 'lecturer') {
        $owns = mysqli_query($conn,
            "SELECT id FROM attendance_sessions WHERE id = $session_id AND lecturer_id = $user_id"
        );
        if (!mysqli_num_rows($owns)) { echo json_encode(['success' => false]); exit(); }
    }
    mysqli_query($conn, "DELETE FROM attendance_records WHERE session_id = $session_id");
    mysqli_query($conn, "DELETE FROM attendance_sessions WHERE id = $session_id");
    echo json_encode(['success' => true]);
    exit();
}

// ────────────────────────────────────────────────────────────────────────────
// ACTION: get_unit_summary — returns per-student attendance summary for a unit
// ────────────────────────────────────────────────────────────────────────────
if ($action === 'get_unit_summary') {
    $unit_id = intval($_GET['unit_id'] ?? 0);
    if (!$unit_id) { echo json_encode([]); exit(); }

    $res = mysqli_query($conn,
        "SELECT u.id AS student_id, u.full_name,
                COUNT(DISTINCT ats.id)                                                        AS total_sessions,
                SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END)                       AS attended,
                SUM(CASE WHEN ar.status = 'absent'  THEN 1 ELSE 0 END)                       AS absences,
                ROUND(SUM(CASE WHEN ar.status='absent' THEN 1 ELSE 0 END)
                      / NULLIF(COUNT(DISTINCT ats.id),0) * 100, 1)                            AS absence_pct
         FROM unit_registrations ur
         JOIN users u ON u.id = ur.student_id
         LEFT JOIN attendance_sessions ats ON ats.unit_id = $unit_id
         LEFT JOIN attendance_records  ar  ON ar.session_id = ats.id AND ar.student_id = u.id
         WHERE ur.unit_id = $unit_id
         GROUP BY u.id, u.full_name
         ORDER BY u.full_name ASC"
    );
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $r['barred'] = (floatval($r['absence_pct'] ?? 0) > 33.33);
        $rows[] = $r;
    }
    echo json_encode($rows);
    exit();
}

echo json_encode(['error' => 'Unknown action']);