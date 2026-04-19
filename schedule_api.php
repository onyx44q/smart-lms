<?php
/**
 * schedule_api.php — Handles schedule CRUD for lecturers
 * and returns schedule data for students (JSON)
 *
 * Zoom integration: automatically creates/deletes Zoom meetings
 * when a session is added or removed by a lecturer.
 */
include 'config.php';
include 'zoom_helper.php';   // ← Zoom API wrapper

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }

$user_id = intval($_SESSION['user_id']);
$role    = $_SESSION['role'] ?? '';

// ── LECTURER: Create schedule ────────────────────────────────────────
if ($role === 'lecturer' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ── ADD ──────────────────────────────────────────────────────────
    if ($_POST['action'] === 'add') {
        $title       = mysqli_real_escape_string($conn, trim($_POST['title'] ?? ''));
        $description = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));
        $course_id   = intval($_POST['course_id'] ?? 0);
        $meet_date   = mysqli_real_escape_string($conn, $_POST['meet_date'] ?? '');
        $meet_time   = mysqli_real_escape_string($conn, $_POST['meet_time'] ?? '');
        $manual_link = mysqli_real_escape_string($conn, trim($_POST['meet_link'] ?? ''));

        if ($title && $meet_date && $meet_time) {

            // ── Try to create a Zoom meeting automatically ────────────
            $zoom      = zoom_create_meeting($title, $meet_date, $meet_time);
            $join_url  = '';
            $start_url = '';
            $zoom_id   = '';

            if ($zoom) {
                // Zoom meeting created successfully
                $zoom_id   = mysqli_real_escape_string($conn, $zoom['meeting_id']);
                $join_url  = mysqli_real_escape_string($conn, $zoom['join_url']);
                $start_url = mysqli_real_escape_string($conn, $zoom['start_url']);
            } else {
                // Zoom unavailable — fall back to manually entered link (if any)
                $join_url = $manual_link;
            }

            $cid_val = $course_id > 0 ? $course_id : 'NULL';

            mysqli_query($conn,
                "INSERT INTO schedules
                    (lecturer_id, course_id, title, description,
                     meet_date, meet_time,
                     meet_link, zoom_start_url, zoom_meeting_id)
                 VALUES
                    ($user_id, $cid_val, '$title', '$description',
                     '$meet_date', '$meet_time',
                     '$join_url', '$start_url', '$zoom_id')"
            );
        }

        header("Location: lecturer_dashboard.php?view=schedule&status=added");
        exit();
    }

    // ── DELETE ───────────────────────────────────────────────────────
    if ($_POST['action'] === 'delete') {
        $id = intval($_POST['schedule_id'] ?? 0);

        // Fetch the Zoom meeting ID before deleting the DB row
        $row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT zoom_meeting_id FROM schedules WHERE id = $id AND lecturer_id = $user_id"
        ));

        if ($row) {
            // Cancel the Zoom meeting if one exists
            if (!empty($row['zoom_meeting_id'])) {
                zoom_delete_meeting($row['zoom_meeting_id']);
            }
            mysqli_query($conn, "DELETE FROM schedules WHERE id = $id AND lecturer_id = $user_id");
        }

        header("Location: lecturer_dashboard.php?view=schedule&status=deleted");
        exit();
    }
}

// ── STUDENT: Return upcoming schedules as JSON ───────────────────────
if ($role === 'student' && isset($_GET['fetch'])) {
    header('Content-Type: application/json');

    // Get enrolled course IDs
    $enr_res = mysqli_query($conn, "SELECT course_id FROM enrollments WHERE student_id = $user_id");
    $course_ids = [];
    while ($r = mysqli_fetch_assoc($enr_res)) $course_ids[] = intval($r['course_id']);

    if (empty($course_ids)) { echo json_encode([]); exit(); }

    $ids_str = implode(',', $course_ids);
    $res = mysqli_query($conn,
        "SELECT s.id, s.title, s.description, s.meet_date, s.meet_time, s.meet_link,
                u.full_name AS lecturer_name, c.title AS course_title
         FROM schedules s
         JOIN users    u ON u.id = s.lecturer_id
         LEFT JOIN courses c ON c.id = s.course_id
         WHERE (s.course_id IN ($ids_str) OR s.course_id IS NULL)
           AND s.meet_date >= CURDATE()
         ORDER BY s.meet_date ASC, s.meet_time ASC
         LIMIT 20"
    );
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    echo json_encode($rows);
    exit();
}

// ── LECTURER: Return own schedules as JSON ───────────────────────────
if ($role === 'lecturer' && isset($_GET['fetch'])) {
    header('Content-Type: application/json');
    $res = mysqli_query($conn,
        "SELECT s.*, c.title AS course_title
         FROM schedules s
         LEFT JOIN courses c ON c.id = s.course_id
         WHERE s.lecturer_id = $user_id
         ORDER BY s.meet_date DESC, s.meet_time DESC
         LIMIT 30"
    );
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    echo json_encode($rows);
    exit();
}