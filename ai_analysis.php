<?php
/**
 * ai_analysis.php — SmartLMS rule-based analysis (no external API).
 *
 * STUDENT  (AI Academic Advisor)
 *   Every statement is derived from the student's real quiz scores through
 *   mastery_engine.php, so it always agrees with the "Skill Mastery" bars:
 *     • Strengths   = assessed skills at 70% or above
 *     • Focus areas = assessed skills below 70%, plus skills not assessed yet
 *     • A skill can never be listed as both
 *     • Evidence is shown ("2 quizzes", "early indication" for a single quiz)
 *     • Momentum    = recent scores + trend + recent activity (not random)
 *
 * LECTURER (class health)
 *   Built from the same engine/numbers as the AI Decision Dashboard — nothing is invented.
 */

ob_start();
error_reporting(0);
ini_set('display_errors', 0);

include 'config.php';
include_once 'mastery_engine.php';
include_once 'ai_engine.php';          // $CAREER_SKILL_MAP, getLecturerDecisionData()

if (session_status() === PHP_SESSION_NONE) session_start();

function sendAnalysisJson(array $data): void {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit();
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    sendAnalysisJson(['error' => 'Unauthenticated']);
}

$user_id = intval($_SESSION['user_id']);
$role    = strtolower($_SESSION['role']);
$aiJson  = [];

const AA_STRONG = 70;      // at/above = strength (matches the green bars)
$ALL_SKILLS = ['Core Theory', 'Practical Application', 'General Aptitude'];

function aa_pct($n): string { return rtrim(rtrim(number_format((float)$n, 1), '0'), '.') . '%'; }
function aa_quizzes(int $n): string { return $n . ' quiz' . ($n === 1 ? '' : 'zes'); }

// ═════════════════════════════════════════════════════════════════════
//  STUDENT
// ═════════════════════════════════════════════════════════════════════
if ($role === 'student') {

    $student = mysqli_fetch_assoc(mysqli_query($conn, "SELECT full_name, career_path FROM users WHERE id = $user_id")) ?: [];
    $firstName = explode(' ', trim($student['full_name'] ?? 'Student'))[0];
    $career    = $student['career_path'] ?: 'General Software Engineering';

    // Optional course scope (student must really be enrolled in it)
    $scope = intval($_GET['course_id'] ?? 0);
    $courseName = 'all your courses';
    if ($scope) {
        $chk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT c.title FROM enrollments e JOIN courses c ON c.id = e.course_id WHERE e.student_id = $user_id AND e.course_id = $scope"));
        if ($chk) $courseName = $chk['title']; else $scope = 0;
    }

    $m = me_compute_student($conn, $user_id, $scope ?: null);
    $skills = $m['skills'];                                   // strongest first
    $unassessed = array_values(array_diff($ALL_SKILLS, array_keys($skills)));

    // ── No quizzes yet: be honest, don't invent strengths/weaknesses ──
    if (empty($skills)) {
        $aiJson = [
            'greeting'            => "Welcome, $firstName!",
            'performance_summary' => "You haven't taken a quiz in $courseName yet, so there is nothing to analyse. Take your first quiz and your strengths and focus areas will appear here automatically.",
            'strengths'           => [],
            'focus_areas'         => array_map(fn($s) => "$s — not assessed yet", $ALL_SKILLS),
            'recommendation'      => "Take a quiz in $courseName to start building your skill profile.",
            'career_alignment'    => "Your career path is $career. Your quiz results will be compared with the skill levels that path needs.",
            'weekly_challenge'    => "Complete your first quiz this week.",
            'motivation_score'    => 0,
            'avg_mastery'         => null,
            'quizzes'             => 0,
        ];
        sendAnalysisJson(['success' => true, 'data' => $aiJson, 'role' => $role]);
    }

    // ── Strengths / focus areas (disjoint, evidence shown) ────────────
    $label = fn($sk, $i) => "$sk — " . aa_pct($i['mastery']) . " (" . aa_quizzes($i['quizzes']) . ($i['quizzes'] < 2 ? ', early indication' : '') . ")";
    $strengths = []; $focus = [];
    foreach ($skills as $sk => $i) {
        if ($i['mastery'] >= AA_STRONG) $strengths[] = $label($sk, $i);
    }
    $weakFirst = $skills; uasort($weakFirst, fn($a, $b) => $a['mastery'] <=> $b['mastery']);
    foreach ($weakFirst as $sk => $i) {
        if ($i['mastery'] < AA_STRONG) $focus[] = $label($sk, $i);
    }
    foreach ($unassessed as $sk) $focus[] = "$sk — not assessed yet";
    if (empty($strengths)) $strengths = ["No skill has reached " . AA_STRONG . "% yet — your first strong skill is within reach."];
    if (empty($focus))     $focus     = ["Every skill is above " . AA_STRONG . "% — try advanced quizzes to keep stretching."];

    // ── Summary ──────────────────────────────────────────────────────
    $avg = $m['avg'];
    $tier = $avg >= 80 ? "Excellent work" : ($avg >= 65 ? "Strong progress" : ($avg >= 50 ? "Steady progress" : "You are still building your foundation"));
    $best = array_key_first($skills);
    $worstSkill = array_key_first($weakFirst);
    $summary = "$tier in $courseName: your average mastery is " . aa_pct($avg) . " across " . aa_quizzes($m['quizzes']) . ". "
             . (count($skills) > 1 ? "Your strongest skill is $best (" . aa_pct($skills[$best]['mastery']) . ") and your weakest assessed skill is $worstSkill (" . aa_pct($skills[$worstSkill]['mastery']) . "). "
                                   : "So far you have been assessed on $best (" . aa_pct($skills[$best]['mastery']) . "). ")
             . ($m['quizzes'] < 3 ? "This is an early picture — it becomes more reliable as you complete more quizzes." : "");

    // ── Momentum (deterministic) ─────────────────────────────────────
    $recent = $m['recent'];                                     // oldest → newest, up to 6
    $last3  = array_slice($recent, -3);
    $momentum = round(array_sum($last3) / count($last3));
    if (count($recent) >= 4) {
        $prev = array_slice($recent, 0, count($recent) - 3);
        $trend = array_sum($last3) / count($last3) - array_sum($prev) / count($prev);
        $momentum += $trend > 5 ? 5 : ($trend < -5 ? -5 : 0);
    }
    $lastAt = mysqli_fetch_assoc(mysqli_query($conn, "SELECT MAX(r.created_at) AS t FROM results r" . ($scope ? " JOIN quizzes q ON q.id = r.quiz_id AND q.course_id = $scope" : "") . " WHERE r.student_id = $user_id"));
    if (!empty($lastAt['t']) && strtotime($lastAt['t']) >= strtotime('-7 days')) $momentum += 5;
    $momentum = max(0, min(100, $momentum));

    // ── Recommendation + weekly challenge ────────────────────────────
    $lastAction = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT r.action_taken FROM results r JOIN quizzes q ON q.id = r.quiz_id" . ($scope ? " AND q.course_id = $scope" : "") . " WHERE r.student_id = $user_id ORDER BY r.created_at DESC, r.id DESC LIMIT 1"));
    if ($skills[$worstSkill]['mastery'] < AA_STRONG) {
        $w = $skills[$worstSkill];
        $recommendation = ($lastAction['action_taken'] ?? '') === 'remedial'
            ? "Revisit the foundational material for $worstSkill (" . aa_pct($w['mastery']) . "), then retake a quiz in it."
            : "Spend 20 minutes reviewing $worstSkill (" . aa_pct($w['mastery']) . " across " . aa_quizzes($w['quizzes']) . "), then retake a quiz to raise it.";
        $target = min(100, (int)(floor($w['mastery'] / 5) + 2) * 5);
        $challenge = "Raise $worstSkill from " . aa_pct($w['mastery']) . " to at least $target% with your next attempt.";
    } elseif (!empty($unassessed)) {
        $recommendation = "You're doing well in what you've taken — now take a quiz in " . $unassessed[0] . " to complete your skill profile.";
        $challenge = "Take your first " . $unassessed[0] . " quiz this week.";
    } else {
        $recommendation = "All your skills are above " . AA_STRONG . "%. Try an advanced quiz in $worstSkill (" . aa_pct($skills[$worstSkill]['mastery']) . ") to keep growing.";
        $challenge = "Score 90% or more in your next $worstSkill attempt.";
    }

    // ── Career alignment (only assessed skills can have a gap) ───────
    $targets = $CAREER_SKILL_MAP[$career] ?? $CAREER_SKILL_MAP['General Software Engineering'];
    $gaps = [];
    foreach ($targets as $sk => $t) {
        if (isset($skills[$sk]) && $skills[$sk]['mastery'] < $t) $gaps[] = ['skill' => $sk, 'cur' => $skills[$sk]['mastery'], 'target' => $t, 'gap' => $t - $skills[$sk]['mastery']];
    }
    usort($gaps, fn($a, $b) => $b['gap'] <=> $a['gap']);
    if (!empty($gaps)) {
        $g = $gaps[0];
        $alignment = "For a career in $career, $g[skill] should reach $g[target]% — you're at " . aa_pct($g['cur']) . " (a gap of " . aa_pct($g['gap']) . ").";
    } else {
        $alignment = "Every skill you've been assessed on meets the level needed for $career.";
    }
    $needUn = array_values(array_intersect(array_keys($targets), $unassessed));
    if ($needUn) $alignment .= " Not assessed yet: " . implode(', ', $needUn) . ".";

    $aiJson = [
        'greeting'            => "Hello, $firstName! Here is your live analysis for $courseName.",
        'performance_summary' => $summary,
        'strengths'           => $strengths,
        'focus_areas'         => $focus,
        'recommendation'      => $recommendation,
        'career_alignment'    => $alignment,
        'weekly_challenge'    => $challenge,
        'motivation_score'    => $momentum,
        'avg_mastery'         => $avg,
        'quizzes'             => $m['quizzes'],
    ];

// ═════════════════════════════════════════════════════════════════════
//  LECTURER
// ═════════════════════════════════════════════════════════════════════
} elseif ($role === 'lecturer') {

    $dd = getLecturerDecisionData($user_id, $conn);
    $atRisk = $dd['at_risk_students'] ?? []; $top = $dd['top_students'] ?? []; $skillAvg = $dd['skill_averages'] ?? [];
    $classAvg = floatval($dd['class_avg'] ?? 0);
    $hasData = !empty($dd['class_summary']) && count(array_filter($dd['class_summary'], fn($c) => $c['has_activity'])) > 0;

    $weak = $skillAvg[0] ?? null;
    $aiJson = [
        'class_health'     => !$hasData ? "No quiz or mark activity has been recorded in your courses yet."
                              : ($classAvg >= 70 ? "The class is performing well (average " . aa_pct($classAvg) . ")."
                              : ($classAvg >= 50 ? "The class is progressing steadily (average " . aa_pct($classAvg) . ")."
                              : "The class average is low (" . aa_pct($classAvg) . ") — consider a review session.")),
        'content_insight'  => $weak ? "Weakest skill across your classes: " . $weak['skill'] . " (" . aa_pct($weak['average']) . ")." : "Skill averages will appear once students take quizzes.",
        'student_insights' => array_values(array_filter([
            count($top)    ? count($top) . " top performer(s): " . implode(', ', array_map(fn($t) => $t['name'], array_slice($top, 0, 3))) : null,
            count($atRisk) ? count($atRisk) . " student(s) flagged at risk: " . implode(', ', array_map(fn($t) => $t['name'], array_slice($atRisk, 0, 3))) : null,
        ])),
        'recommendations'  => array_values(array_filter([
            $weak ? "Run a revision session on " . $weak['skill'] . "." : null,
            count($atRisk) ? "Follow up with the students flagged at risk." : null,
        ])),
        'priority_action'  => count($atRisk) ? "Follow up with " . $atRisk[0]['name'] . " (combined score " . aa_pct($atRisk[0]['combined_score']) . ")." : ($weak ? "Strengthen " . $weak['skill'] . "." : "Publish a quiz so student progress can be measured."),
        'at_risk_flag'     => count($atRisk) ? count($atRisk) . " student(s) are below the 40% threshold." : "No students are currently below the 40% threshold.",
        'engagement_score' => (int)max(0, min(100, round($classAvg))),
    ];
}

sendAnalysisJson(['success' => true, 'data' => $aiJson, 'role' => $role]);
