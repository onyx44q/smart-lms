<?php
/**
 * save_marks.php
 * Saves lecturer-entered marks per unit assessment component, per student.
 * Called via POST from lecturer_dashboard.php?view=marks
 */
include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
checkRole('lecturer');

$user_id = intval($_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: lecturer_dashboard.php?view=marks');
    exit();
}

$action  = $_POST['action'] ?? '';
$unit_id = intval($_POST['unit_id'] ?? 0);
$redirect = "lecturer_dashboard.php?view=marks&unit_id=$unit_id";

// Verify lecturer teaches this unit
$unit = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT cu.id, cu.title, cu.course_id FROM course_units cu
     WHERE cu.id = $unit_id AND cu.lecturer_id = $user_id"
));
if (!$unit) {
    header("Location: $redirect&umsg=unauthorized");
    exit();
}

if ($action === 'save_unit_marks') {
    $marks_input   = $_POST['marks']   ?? [];
    $remarks_input = $_POST['remarks'] ?? [];
    $saved = 0;

    foreach ($marks_input as $assessment_id => $students) {
        $assessment_id = intval($assessment_id);
        $valid = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id, max_mark FROM unit_assessments WHERE id = $assessment_id AND unit_id = $unit_id"
        ));
        if (!$valid) continue;
        $max_mark = floatval($valid['max_mark']);

        foreach ($students as $student_id => $mark_val) {
            $student_id = intval($student_id);
            if ($student_id <= 0) continue;
            if ($mark_val === '' || $mark_val === null) {
                mysqli_query($conn,
                    "DELETE FROM unit_marks WHERE assessment_id = $assessment_id AND student_id = $student_id"
                );
            } else {
                $mark    = max(0, min($max_mark, floatval($mark_val)));
                $rem     = mysqli_real_escape_string($conn, trim($remarks_input[$student_id] ?? ''));
                $rem_sql = $rem !== '' ? "'$rem'" : 'NULL';
                mysqli_query($conn,
                    "INSERT INTO unit_marks (assessment_id, student_id, mark, remarks)
                     VALUES ($assessment_id, $student_id, $mark, $rem_sql)
                     ON DUPLICATE KEY UPDATE mark = $mark, remarks = $rem_sql"
                );
                $saved++;
            }
        }
    }
    header("Location: $redirect&umsg=saved&count=$saved");
    exit();
}

header("Location: $redirect&umsg=error");
exit();