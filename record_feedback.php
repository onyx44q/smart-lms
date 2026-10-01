<?php
/**
 * record_feedback.php — Records "Was this recommendation helpful? Yes / No"
 *
 * Called via fetch() from student_dashboard.php right after a quiz result
 * toast is shown. Stores the vote against the specific results row so the
 * Recommendation Evaluation report can compute an acceptance percentage.
 */

header('Content-Type: application/json');
include 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authorised']);
    exit();
}

$student_id = intval($_SESSION['user_id']);
$result_id  = intval($_POST['result_id'] ?? 0);
$helpful    = ($_POST['helpful'] ?? '') === 'yes' ? 'yes' : (($_POST['helpful'] ?? '') === 'no' ? 'no' : null);

if (!$result_id || !$helpful) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing result_id or helpful value']);
    exit();
}

// Make sure the column exists (safe no-op if it already does).
@mysqli_query($conn, "ALTER TABLE results ADD COLUMN IF NOT EXISTS helpful ENUM('yes','no') DEFAULT NULL");

// Only allow a student to rate their own recommendation.
$owner = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id FROM results WHERE id = $result_id AND student_id = $student_id LIMIT 1"
));

if (!$owner) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Result not found']);
    exit();
}

$helpful_escaped = mysqli_real_escape_string($conn, $helpful);
mysqli_query($conn, "UPDATE results SET helpful = '$helpful_escaped' WHERE id = $result_id");

echo json_encode(['ok' => true]);
