<?php
/**
 * get_unit_materials_list.php
 * Returns JSON list of materials for a given unit.
 * Only callable by logged-in lecturers assigned to that unit.
 */
include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

$lecturer_id = (int)($_SESSION['user_id'] ?? 0);
$unit_id     = (int)($_GET['unit_id']     ?? 0);

if (!$lecturer_id || !$unit_id) { echo json_encode([]); exit(); }

// Verify this lecturer is assigned to the unit
$ok = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id FROM course_units WHERE id = $unit_id AND lecturer_id = $lecturer_id"
));
if (!$ok) { echo json_encode([]); exit(); }

// Return all materials for this unit (PDFs prioritised first, then others)
$res = mysqli_query($conn,
    "SELECT id, title, type, file_path
     FROM materials
     WHERE unit_id = $unit_id AND lecturer_id = $lecturer_id
     ORDER BY
         FIELD(type, 'pdf', 'word', 'video') ASC,
         id ASC"
);

$data = [];
while ($row = mysqli_fetch_assoc($res)) {
    $data[] = [
        'id'        => (int)$row['id'],
        'title'     => $row['title'],
        'type'      => $row['type'],
        'file_path' => $row['file_path'],
    ];
}
echo json_encode($data);