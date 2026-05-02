<?php
/**
 * recalculate_mastery.php
 * ─────────────────────────────────────────────────────────────────────
 * Utility: replays every quiz result for a student (or all students)
 * through the AI engine rules to recompute mastery_level from scratch.
 *
 * Called two ways:
 *   1. include + recalculate_mastery_for_student($student_id, $conn)
 *      → called by student/lecturer dashboards on every page load,
 *        but only runs the heavy query when mastery looks stale.
 *   2. Directly via browser: recalculate_mastery.php?all=1  (admin use)
 *      → rebuilds mastery for every student in the DB.
 *
 * Skill-name inference mirrors Student_quiz.php so results map to the
 * same three skills that the dashboards display.
 * ─────────────────────────────────────────────────────────────────────
 */

// ── AI engine constants (kept in sync with Ai_engine.php) ────────────
if (!defined('AI_SCORE_ADVANCE'))  define('AI_SCORE_ADVANCE',   80);
if (!defined('AI_SCORE_RETRY'))    define('AI_SCORE_RETRY',     50);
if (!defined('AI_MAX_RETRIES'))    define('AI_MAX_RETRIES',      3);
if (!defined('AI_MASTERY_ADVANCE'))define('AI_MASTERY_ADVANCE',  8.5);
if (!defined('AI_MASTERY_RETRY'))  define('AI_MASTERY_RETRY',    3.2);
if (!defined('AI_MASTERY_REMEDIAL'))define('AI_MASTERY_REMEDIAL',-5.0);
if (!defined('AI_MASTERY_CAP'))    define('AI_MASTERY_CAP',    100.0);
if (!defined('AI_MASTERY_FLOOR'))  define('AI_MASTERY_FLOOR',    0.0);

/**
 * Infer skill_name from quiz data — identical logic to Student_quiz.php.
 * Priority: explicit DB value → title keywords → quiz_id modulo rotation.
 */
function infer_skill_name(array $quiz): string {
    $skill = trim($quiz['skill_name'] ?? '');
    if ($skill !== '') return $skill;

    $t = strtolower($quiz['title'] ?? '');
    if (str_contains($t, 'practical') || str_contains($t, 'lab') || str_contains($t, 'applied')) {
        return 'Practical Application';
    }
    if (str_contains($t, 'aptitude') || str_contains($t, 'general') || str_contains($t, 'reasoning')) {
        return 'General Aptitude';
    }
    if (str_contains($t, 'theory') || str_contains($t, 'core') || str_contains($t, 'concept')) {
        return 'Core Theory';
    }
    // Rotate across all three skills by quiz ID so unlabelled quizzes
    // spread evenly rather than piling onto one skill.
    $pool = ['General Aptitude', 'Practical Application', 'Core Theory'];
    return $pool[intval($quiz['id']) % 3];
}

/**
 * Replay all quiz results for $student_id and rewrite student_mastery.
 *
 * @param  int      $student_id
 * @param  mysqli   $conn
 * @return array    ['skill' => float, ...]   — the recalculated levels
 */
function recalculate_mastery_for_student(int $student_id, $conn): array {


    // ── One-time schema fix: add UNIQUE key if missing ──────────────
    // The original table had no unique constraint, causing duplicate rows.
    // This safely adds it (IF NOT EXISTS) and deduplicates old data.
    @mysqli_query($conn,
        "ALTER TABLE student_mastery ADD UNIQUE KEY IF NOT EXISTS uniq_student_skill (student_id, skill_name)"
    );
    // Deduplicate: keep the row with highest mastery_level per (student_id, skill_name)
    @mysqli_query($conn,
        "DELETE sm1 FROM student_mastery sm1
         INNER JOIN student_mastery sm2
         WHERE sm1.student_id = sm2.student_id
           AND sm1.skill_name = sm2.skill_name
           AND sm1.mastery_level < sm2.mastery_level"
    );
    // If two rows have the same mastery_level, keep the one with the lower id
    @mysqli_query($conn,
        "DELETE sm1 FROM student_mastery sm1
         INNER JOIN student_mastery sm2
         WHERE sm1.student_id = sm2.student_id
           AND sm1.skill_name = sm2.skill_name
           AND sm1.id > sm2.id"
    );



    // ── Fetch all results for this student, oldest first ────────────
    $res = mysqli_query($conn,
        "SELECT r.score, r.attempt_no, r.created_at,
                q.id AS quiz_id, q.title, q.skill_name
         FROM results r
         JOIN quizzes q ON q.id = r.quiz_id
         WHERE r.student_id = $student_id
         ORDER BY r.created_at ASC"
    );
    if (!$res) return [];

    // ── Replay results → accumulate mastery per skill ────────────────
    $mastery = [];   // ['skill_name' => float]

    while ($row = mysqli_fetch_assoc($res)) {
        $skill       = infer_skill_name($row);
        $current     = $mastery[$skill] ?? 0.0;
        $score       = floatval($row['score']);
        $attempt_no  = intval($row['attempt_no']);

        if ($score >= AI_SCORE_ADVANCE) {
            $delta = AI_MASTERY_ADVANCE;
        } elseif ($score >= AI_SCORE_RETRY && $attempt_no < AI_MAX_RETRIES) {
            $delta = AI_MASTERY_RETRY;
        } else {
            $delta = AI_MASTERY_REMEDIAL;
        }

        $mastery[$skill] = max(AI_MASTERY_FLOOR, min(AI_MASTERY_CAP, $current + $delta));
    }

    if (empty($mastery)) return [];

    // ── Upsert recalculated values into student_mastery ─────────────
    foreach ($mastery as $skill => $level) {
        $skill_esc = mysqli_real_escape_string($conn, $skill);
        $level_val = round($level, 2);

        mysqli_query($conn,
            "INSERT INTO student_mastery (student_id, skill_name, mastery_level, last_updated)
             VALUES ($student_id, '$skill_esc', $level_val, NOW())
             ON DUPLICATE KEY UPDATE mastery_level = $level_val, last_updated = NOW()"
        );
    }

    return $mastery;
}

/**
 * Lightweight staleness check: returns true when any skill has 0 mastery
 * but the student has at least one quiz result — meaning the DB is stale.
 */
function mastery_needs_recalculation(int $student_id, $conn): bool {
    $has_results = intval(
        (mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) AS c FROM results WHERE student_id = $student_id"
        )))['c'] ?? 0
    );
    if ($has_results === 0) return false;   // No quiz attempts — nothing to recalculate

    $has_zero = intval(
        (mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) AS c FROM student_mastery
             WHERE student_id = $student_id AND mastery_level = 0.00"
        )))['c'] ?? 0
    );
    return $has_zero > 0;
}

// ── Direct CLI / browser invocation (admin rebuild) ─────────────────
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    include_once __DIR__ . '/config.php';
    if (session_status() === PHP_SESSION_NONE) session_start();

    $all = isset($_GET['all']) && $_GET['all'] === '1';
    if (!$all) {
        // Single student (must be logged in)
        if (!isset($_SESSION['user_id'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Not logged in']);
            exit;
        }
        $sid    = intval($_SESSION['user_id']);
        $result = recalculate_mastery_for_student($sid, $conn);
        header('Content-Type: application/json');
        echo json_encode(['student_id' => $sid, 'mastery' => $result]);
        exit;
    }

    // All students — admin only
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin only']);
        exit;
    }
    $students_res = mysqli_query($conn,
        "SELECT DISTINCT student_id FROM results"
    );
    $out = [];
    while ($s = mysqli_fetch_assoc($students_res)) {
        $sid       = intval($s['student_id']);
        $out[$sid] = recalculate_mastery_for_student($sid, $conn);
    }
    header('Content-Type: application/json');
    echo json_encode(['rebuilt' => count($out), 'detail' => $out]);
    exit;
}