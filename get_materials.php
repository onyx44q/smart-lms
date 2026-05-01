<?php
include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

$unit_id   = isset($_GET['unit_id'])   ? (int)$_GET['unit_id']   : 0;
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$student_id = (int)($_SESSION['user_id'] ?? 0);

// If requesting by unit_id, verify student is registered for that unit
if ($unit_id > 0 && $student_id > 0) {
    $reg = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM unit_registrations
         WHERE student_id = $student_id AND unit_id = $unit_id"
    ));
    if (!$reg) {
        // Not registered — return empty (no sneaking other units' materials)
        echo json_encode([]); exit();
    }
}

if ($unit_id > 0) {
    $res = mysqli_query($conn,
        "SELECT m.id, m.title, m.type, m.file_path,
                cu.title AS unit_title, cu.unit_code,
                c.title AS course_title
         FROM materials m
         LEFT JOIN course_units cu ON cu.id = m.unit_id
         LEFT JOIN courses c ON c.id = m.course_id
         WHERE m.unit_id = $unit_id
         ORDER BY m.id ASC"
    );
} elseif ($course_id > 0) {
    // Fallback: fetch all materials for a course (all its units)
    $res = mysqli_query($conn,
        "SELECT m.id, m.title, m.type, m.file_path,
                cu.title AS unit_title, cu.unit_code,
                c.title AS course_title
         FROM materials m
         LEFT JOIN course_units cu ON cu.id = m.unit_id
         LEFT JOIN courses c ON c.id = m.course_id
         WHERE m.course_id = $course_id
         ORDER BY m.unit_id ASC, m.id ASC"
    );
} else {
    echo json_encode([]); exit();
}

$data = [];
while ($row = mysqli_fetch_assoc($res)) $data[] = $row;
echo json_encode($data);