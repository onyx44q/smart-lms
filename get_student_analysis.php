<?php
include 'config.php';
session_start();

header('Content-Type: application/json');

$student_id = $_SESSION['user_id'] ?? 0;

if ($student_id == 0) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// 1. Fetch Skill Mastery levels from the table updated by your Ai_engine.php
$query = "SELECT s.skill_name as name, sm.mastery_level as level 
          FROM student_mastery sm
          JOIN skills s ON sm.skill_id = s.skill_id
          WHERE sm.student_id = '$student_id'";

$result = mysqli_query($conn, $query);
$skills = [];

while ($row = mysqli_fetch_assoc($result)) {
    // Ensure the level is a number for the progress bar width
    $row['level'] = (float)$row['level'];
    $skills[] = $row;
}

// 2. Fetch the latest trajectory/action (stored from previous quiz submissions)
// Note: This assumes you have a table tracking the AI's latest decisions
$perf_query = "SELECT trajectory, next_action, remedial_flag 
               FROM student_performance_logs 
               WHERE student_id = '$student_id' 
               ORDER BY created_at DESC LIMIT 1";

$perf_result = mysqli_query($conn, $perf_query);
$perf_data = mysqli_fetch_assoc($perf_result);

// 3. Prepare the final response object
$response = [
    'skill_mastery' => $skills,
    'trajectory'    => $perf_data['trajectory'] ?? 'Stable',
    'next_action'   => $perf_data['next_action'] ?? 'Complete more quizzes',
    'remedial_flag' => (bool)($perf_data['remedial_flag'] ?? false)
];

echo json_encode($response);