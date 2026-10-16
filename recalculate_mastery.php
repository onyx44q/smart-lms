<?php
/**
 * recalculate_mastery.php — compatibility wrapper around mastery_engine.php.
 *
 * The old version replayed every quiz through "+8.5 / +3.2 / -5" bonuses, which
 * made a perfect quiz show as 8.5% mastery. Mastery is now calculated from the
 * real scores (see mastery_engine.php); these functions keep the names that the
 * dashboards already call, so they all get the corrected numbers.
 *
 *   • include + recalculate_mastery_for_student($student_id, $conn)
 *   • browser: recalculate_mastery.php?all=1   (admin) — rebuild everyone
 */
include_once __DIR__ . '/mastery_engine.php';

if (!defined('AI_MASTERY_CAP')) define('AI_MASTERY_CAP', 100.0);

/** Skill name for a quiz row (keys: skill_name, title, id). */
function infer_skill_name(array $quiz): string {
    return me_infer_skill($quiz['skill_name'] ?? '', $quiz['title'] ?? '', intval($quiz['id'] ?? 0));
}

/** Refresh stored mastery from real scores. Returns ['skill' => mastery, ...]. */
function recalculate_mastery_for_student(int $student_id, $conn): array {
    return me_sync_student($conn, $student_id);
}

/** True when the stored mastery no longer matches the student's quiz scores. */
function mastery_needs_recalculation(int $student_id, $conn): bool {
    return me_needs_sync($conn, $student_id);
}

// ── Direct browser invocation ───────────────────────────────────────
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    include_once __DIR__ . '/config.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    header('Content-Type: application/json');

    if (!(isset($_GET['all']) && $_GET['all'] === '1')) {
        if (!isset($_SESSION['user_id'])) { http_response_code(403); echo json_encode(['error' => 'Not logged in']); exit; }
        $sid = intval($_SESSION['user_id']);
        echo json_encode(['student_id' => $sid, 'mastery' => recalculate_mastery_for_student($sid, $conn)]);
        exit;
    }
    if (($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }
    $out = [];
    $r = mysqli_query($conn, "SELECT DISTINCT student_id FROM results UNION SELECT DISTINCT student_id FROM student_mastery");
    while ($r && ($s = mysqli_fetch_assoc($r))) { $sid = intval($s['student_id']); if ($sid) $out[$sid] = recalculate_mastery_for_student($sid, $conn); }
    echo json_encode(['rebuilt' => count($out), 'detail' => $out]);
    exit;
}
