<?php
/**
 * submit_quiz.php — SmartLMS Quiz Submission Handler
 *
 * Wires the quiz form submission to ai_engine.php (rule-based adaptive engine).
 * Flow:
 *   1. Receive quiz result (POST)
 *   2. Load student profile + all mastery data from DB
 *   3. Count previous attempts on this quiz
 *   4. Run ai_engine::runAdaptiveEngine() — pure rule-based decision
 *   5. Update student_mastery in DB
 *   6. Log structured notification
 *   7. Record result + AI recommendation in results table
 *   8. Send email to student
 *   9. Redirect to dashboard with outcome status
 */

include 'config.php';
include 'ai_engine.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Auth guard ─────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: student_dashboard.php");
    exit();
}

// ── Sanitise inputs ────────────────────────────────────────────────
$student_id = intval($_SESSION['user_id']);
$quiz_id    = intval($_POST['quiz_id']   ?? 0);
$skill_name = trim(mysqli_real_escape_string($conn, $_POST['skill_name'] ?? 'General Aptitude'));
$raw_score  = max(0, min(100, intval($_POST['score'] ?? 0)));

// ── Fetch student profile ──────────────────────────────────────────
$student = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT full_name, email, career_path FROM users WHERE id = $student_id"
));

if (!$student) {
    header("Location: student_dashboard.php?status=error&msg=Student+not+found");
    exit();
}

$career_path  = $student['career_path']  ?? 'General Software Engineering';
$student_email = $student['email'];

// ── Load all mastery levels for this student ───────────────────────
$mastery_res = mysqli_query($conn,
    "SELECT skill_name, mastery_level FROM student_mastery WHERE student_id = $student_id"
);
$all_mastery = [];
while ($m = mysqli_fetch_assoc($mastery_res)) {
    $all_mastery[$m['skill_name']] = floatval($m['mastery_level']);
}

// If the skill doesn't exist yet, seed it at 0
if (!isset($all_mastery[$skill_name])) {
    mysqli_query($conn,
        "INSERT IGNORE INTO student_mastery (student_id, skill_name, mastery_level)
         VALUES ($student_id, '$skill_name', 0.00)"
    );
    $all_mastery[$skill_name] = 0.0;
}

// ── Count previous attempts on this quiz ──────────────────────────
$attempt_res = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as cnt FROM results
     WHERE student_id = $student_id AND quiz_id = $quiz_id"
));
$attempt_no = intval($attempt_res['cnt']) + 1; // +1 for current attempt

// ── Run the rule-based AI engine ───────────────────────────────────
$decision = runAdaptiveEngine(
    $student_id,
    $quiz_id,
    $skill_name,
    $raw_score,
    $attempt_no,
    $career_path,
    $all_mastery,
    $conn
);

// ── 1. Update mastery level ────────────────────────────────────────
$new_mastery_escaped = floatval($decision['new_mastery']);
mysqli_query($conn,
    "UPDATE student_mastery
     SET mastery_level = $new_mastery_escaped, last_updated = NOW()
     WHERE student_id = $student_id AND skill_name = '$skill_name'"
);

// ── 2. Record the result in results table ─────────────────────────
$feedback_escaped = mysqli_real_escape_string($conn, $decision['feedback']);
$recommendation   = mysqli_real_escape_string($conn, $decision['next_topic_message']);
$action_str       = mysqli_real_escape_string($conn, $decision['action']);
$band_str         = mysqli_real_escape_string($conn, $decision['performance_band']);
$grade_str        = mysqli_real_escape_string($conn, $decision['predicted_grade']);
$diff_str         = mysqli_real_escape_string($conn, $decision['difficulty_next']);

// Ensure results table has needed columns
// (If running on the original DB, this gracefully handles missing columns)
@mysqli_query($conn, "ALTER TABLE results ADD COLUMN IF NOT EXISTS
    action_taken VARCHAR(20) DEFAULT NULL,
    performance_band VARCHAR(20) DEFAULT NULL,
    predicted_grade CHAR(1) DEFAULT NULL,
    difficulty_next VARCHAR(20) DEFAULT NULL,
    attempt_no INT DEFAULT 1"
);

mysqli_query($conn,
    "INSERT INTO results
       (student_id, quiz_id, score, recommendation, action_taken,
        performance_band, predicted_grade, difficulty_next, attempt_no, created_at)
     VALUES
       ($student_id, $quiz_id, $raw_score, '$recommendation',
        '$action_str', '$band_str', '$grade_str', '$diff_str', $attempt_no, NOW())"
);

// ── 3. Log notification for the student ───────────────────────────
$notif_msg = mysqli_real_escape_string($conn, $decision['notification_msg']);
mysqli_query($conn,
    "INSERT INTO notifications (user_id, message, is_read, created_at)
     VALUES ($student_id, '$notif_msg', 0, NOW())"
);

// ── 4. Send email to student ───────────────────────────────────────
if (!empty($student_email)) {
    sendLmsEmail(
        $student_email,
        $decision['email_subject'],
        $decision['email_body']
    );
}

// ── 5. Redirect with outcome ───────────────────────────────────────
$action_label = match ($decision['action']) {
    'advance'  => 'Excellent+work%21+You+have+advanced+to+the+next+level.',
    'retry'    => 'Good+effort.+Review+the+materials+and+try+again.',
    'remedial' => 'Revision+content+assigned.+Check+your+notifications.',
    default    => 'Quiz+submitted.'
};

$grade   = urlencode($decision['predicted_grade']);
$mastery = urlencode($decision['new_mastery']);

header("Location: student_dashboard.php?status=" . $decision['action']
     . "&msg=$action_label&grade=$grade&mastery=$mastery");
exit();
?>