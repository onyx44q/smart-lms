<?php
/**
 * get_quiz_questions.php — Returns questions for a quiz as JSON
 * Used by quiz_panel.php preview modal and student_quiz.php
 */

include 'config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthenticated']);
    exit();
}

$quiz_id = intval($_GET['quiz_id'] ?? 0);
if ($quiz_id === 0) {
    echo json_encode(['questions' => []]);
    exit();
}

$res = mysqli_query($conn,
    "SELECT id, question_text, option_a, option_b, option_c, option_d,
            correct_option, explanation, difficulty_level
     FROM questions WHERE quiz_id = $quiz_id ORDER BY id ASC"
);

$questions = [];
while ($q = mysqli_fetch_assoc($res)) {
    // For students, hide correct_option and explanation (sent separately after submission)
    if ($_SESSION['role'] === 'student') {
        unset($q['correct_option']);
        unset($q['explanation']);
    }
    $questions[] = $q;
}

echo json_encode(['questions' => $questions]);
?>