<?php
/**
 * ai_engine.php — SmartLMS Rule-Based Adaptive Learning Engine
 *
 * This is the CORE AI of the system. It is 100% rule-based (no external API).
 * It runs after every quiz submission and:
 *   1. Evaluates the student's score against adaptive thresholds
 *   2. Adjusts mastery level per skill
 *   3. Determines the next learning action (advance / retry / remedial)
 *   4. Detects skill gaps against the student's career path
 *   5. Predicts performance trajectory
 *   6. Generates an email message for the student
 *   7. Logs a structured notification
 *   8. Returns a full decision object used by submit_quiz.php
 *
 * Rule tiers (from conversation spec):
 *   Score >= 80  → ADVANCE   (unlock next topic, accelerated mastery +8.5)
 *   Score 50-79  → RETRY     (consolidate at same level, mastery +3.2)
 *   Score < 50   → REMEDIAL  (step back to foundational content, mastery -5.0)
 *
 * Attempt penalty:
 *   If RETRY and attempts >= 3 → force REMEDIAL regardless of score
 *
 * Career path skill requirements (rule table):
 *   Used to detect gaps between current mastery and career targets.
 */

// ─────────────────────────────────────────────────────────────────────
//  CONSTANTS
// ─────────────────────────────────────────────────────────────────────
define('AI_SCORE_ADVANCE',  80);   // Score threshold to advance
define('AI_SCORE_RETRY',    50);   // Score threshold to retry (below = remedial)
define('AI_MAX_RETRIES',     3);   // Max retries before forcing remedial
define('AI_MASTERY_ADVANCE', 8.5); // Mastery points gained on advance
define('AI_MASTERY_RETRY',   3.2); // Mastery points gained on retry
define('AI_MASTERY_REMEDIAL',-5.0);// Mastery points lost on remedial
define('AI_MASTERY_CAP',   100.0); // Mastery ceiling
define('AI_MASTERY_FLOOR',   0.0); // Mastery floor

// Career path → required skills and target mastery (0-100)
$CAREER_SKILL_MAP = [
    'Software Development' => [
        'General Aptitude'      => 70,
        'Core Theory'           => 75,
        'Practical Application' => 80,
    ],
    'Data Science' => [
        'General Aptitude'      => 80,
        'Core Theory'           => 85,
        'Practical Application' => 75,
    ],
    'AIS' => [
        'General Aptitude'      => 75,
        'Core Theory'           => 80,
        'Practical Application' => 80,
    ],
    'Cyber Security' => [
        'General Aptitude'      => 75,
        'Core Theory'           => 80,
        'Practical Application' => 85,
    ],
    'General Software Engineering' => [
        'General Aptitude'      => 70,
        'Core Theory'           => 70,
        'Practical Application' => 70,
    ],
];

// ─────────────────────────────────────────────────────────────────────
//  MAIN ENGINE FUNCTION
//  Call this after a student submits a quiz.
//
//  @param int    $student_id
//  @param int    $quiz_id
//  @param string $skill_name    — skill being tested (e.g. "Core Theory")
//  @param int    $raw_score     — percentage 0-100
//  @param int    $attempt_no    — how many times this quiz was attempted
//  @param string $career_path   — from users.career_path
//  @param array  $all_mastery   — ['skill_name' => mastery_level, ...]
//  @param mysqli $conn          — active DB connection
//
//  @return array {
//    action              => 'advance'|'retry'|'remedial'
//    mastery_delta       => float
//    new_mastery         => float
//    feedback            => string  (shown to student on dashboard)
//    next_topic_message  => string  (what to study next)
//    email_subject       => string
//    email_body          => string
//    skill_gaps          => array   (skills below career target)
//    performance_band    => string  ('excellent'|'good'|'at_risk'|'critical')
//    predicted_grade     => string  ('A'|'B'|'C'|'D'|'F')
//    difficulty_next     => string  ('advanced'|'intermediate'|'beginner')
//    notification_msg    => string  (stored in DB notifications table)
//  }
// ─────────────────────────────────────────────────────────────────────
function runAdaptiveEngine(
    int    $student_id,
    int    $quiz_id,
    string $skill_name,
    int    $raw_score,
    int    $attempt_no,
    string $career_path,
    array  $all_mastery,
    $conn
): array {
    global $CAREER_SKILL_MAP;

    $current_mastery = floatval($all_mastery[$skill_name] ?? 0.0);

    // ── RULE 1: Determine action tier ──────────────────────────────
    if ($raw_score >= AI_SCORE_ADVANCE) {
        $action      = 'advance';
        $delta       = AI_MASTERY_ADVANCE;
    } elseif ($raw_score >= AI_SCORE_RETRY && $attempt_no < AI_MAX_RETRIES) {
        $action      = 'retry';
        $delta       = AI_MASTERY_RETRY;
    } else {
        // Below 50 OR exhausted retries
        $action      = 'remedial';
        $delta       = AI_MASTERY_REMEDIAL;
    }

    // ── RULE 2: Apply mastery change with floor/ceiling ────────────
    $new_mastery = $current_mastery + $delta;
    $new_mastery = max(AI_MASTERY_FLOOR, min(AI_MASTERY_CAP, $new_mastery));
    $mastery_delta = $new_mastery - $current_mastery;

    // ── RULE 3: Performance band ───────────────────────────────────
    $avg_mastery = count($all_mastery) > 0
        ? array_sum($all_mastery) / count($all_mastery)
        : 0;

    if ($avg_mastery >= 75) {
        $performance_band = 'excellent';
    } elseif ($avg_mastery >= 55) {
        $performance_band = 'good';
    } elseif ($avg_mastery >= 35) {
        $performance_band = 'at_risk';
    } else {
        $performance_band = 'critical';
    }

    // ── RULE 4: Predicted grade (based on avg mastery + latest score)
    $composite = ($avg_mastery * 0.6) + ($raw_score * 0.4);
    if ($composite >= 80)      $predicted_grade = 'A';
    elseif ($composite >= 65)  $predicted_grade = 'B';
    elseif ($composite >= 50)  $predicted_grade = 'C';
    elseif ($composite >= 40)  $predicted_grade = 'D';
    else                       $predicted_grade = 'F';

    // ── RULE 5: Difficulty for next quiz ──────────────────────────
    if ($action === 'advance')   $difficulty_next = 'advanced';
    elseif ($action === 'retry') $difficulty_next = 'intermediate';
    else                         $difficulty_next = 'beginner';

    // ── RULE 6: Detect skill gaps vs career path ───────────────────
    $required_skills = $CAREER_SKILL_MAP[$career_path]
                    ?? $CAREER_SKILL_MAP['General Software Engineering'];

    $skill_gaps = [];
    foreach ($required_skills as $skill => $target) {
        $current = floatval($all_mastery[$skill] ?? 0);
        if ($current < $target) {
            $gap = $target - $current;
            $skill_gaps[] = [
                'skill'   => $skill,
                'current' => $current,
                'target'  => $target,
                'gap'     => round($gap, 1),
            ];
        }
    }
    // Sort gaps largest first
    usort($skill_gaps, fn($a, $b) => $b['gap'] <=> $a['gap']);

    // ── RULE 7: Build human-readable messages ──────────────────────
    switch ($action) {
        case 'advance':
            $feedback           = "Excellent performance on this assessment. You have unlocked the next level of content.";
            $next_topic_message = "Proceed to the advanced module for " . $skill_name . ". Your mastery is now " . round($new_mastery, 1) . "%.";
            $email_subject      = "Assessment Passed — Next Level Unlocked";
            $email_body         = buildEmailBody($student_id, $conn, 'advance', $skill_name, $raw_score, $new_mastery, $skill_gaps, $predicted_grade, $career_path);
            break;

        case 'retry':
            $feedback           = "Good effort. Reinforce your understanding before moving forward. Attempt " . $attempt_no . " of " . AI_MAX_RETRIES . ".";
            $next_topic_message = "Review the intermediate materials for " . $skill_name . " and retake the assessment to advance.";
            $email_subject      = "Assessment Feedback — Revision Recommended";
            $email_body         = buildEmailBody($student_id, $conn, 'retry', $skill_name, $raw_score, $new_mastery, $skill_gaps, $predicted_grade, $career_path);
            break;

        case 'remedial':
        default:
            $feedback           = "Foundational gaps detected. You have been directed to revision materials to strengthen core concepts.";
            $next_topic_message = "Return to the foundational lessons for " . $skill_name . ". Master the basics before retrying.";
            $email_subject      = "Assessment Outcome — Revision Required";
            $email_body         = buildEmailBody($student_id, $conn, 'remedial', $skill_name, $raw_score, $new_mastery, $skill_gaps, $predicted_grade, $career_path);
            break;
    }

    // Gap warning appended to feedback if critical gap found
    if (!empty($skill_gaps)) {
        $top_gap = $skill_gaps[0];
        $feedback .= " Priority gap detected: " . $top_gap['skill'] . " is " . $top_gap['gap'] . " points below your career target.";
    }

    $notification_msg = $feedback . " Predicted grade: " . $predicted_grade . ".";

    return [
        'action'              => $action,
        'mastery_delta'       => round($mastery_delta, 2),
        'new_mastery'         => round($new_mastery, 2),
        'feedback'            => $feedback,
        'next_topic_message'  => $next_topic_message,
        'email_subject'       => $email_subject,
        'email_body'          => $email_body,
        'skill_gaps'          => $skill_gaps,
        'performance_band'    => $performance_band,
        'predicted_grade'     => $predicted_grade,
        'difficulty_next'     => $difficulty_next,
        'notification_msg'    => $notification_msg,
    ];
}

// ─────────────────────────────────────────────────────────────────────
//  EMAIL BODY BUILDER (rule-based templates, no external API)
// ─────────────────────────────────────────────────────────────────────
function buildEmailBody(
    int    $student_id,
    $conn,
    string $action,
    string $skill_name,
    int    $score,
    float  $new_mastery,
    array  $skill_gaps,
    string $predicted_grade,
    string $career_path
): string {

    $student = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT full_name FROM users WHERE id = $student_id"
    ));
    $name = $student['full_name'] ?? 'Student';

    $action_line = match ($action) {
        'advance'  => "Congratulations — you have passed this assessment and unlocked the next level of content.",
        'retry'    => "You have not yet reached the passing threshold. Reviewing the course materials before your next attempt is recommended.",
        'remedial' => "Your score indicates foundational gaps that require attention. Remedial content has been assigned to help you build a stronger base.",
        default    => ""
    };

    $gap_lines = '';
    if (!empty($skill_gaps)) {
        $gap_lines .= "\nSkill Gaps Detected for Your Career Path (" . $career_path . "):\n";
        foreach ($skill_gaps as $g) {
            $gap_lines .= "  - " . $g['skill'] . ": current " . $g['current'] . "% | target " . $g['target'] . "% | gap " . $g['gap'] . " points\n";
        }
    }

    $body = "Dear {$name},\n\n"
          . "This is an automated performance report from SmartLMS.\n\n"
          . "Assessment: {$skill_name}\n"
          . "Score Achieved: {$score}%\n"
          . "Current Mastery Level: " . round($new_mastery, 1) . "%\n"
          . "Predicted Grade Trajectory: {$predicted_grade}\n\n"
          . $action_line . "\n"
          . $gap_lines . "\n"
          . "Log in to your SmartLMS dashboard to view your updated learning path and next recommended materials.\n\n"
          . "SmartLMS Academic Team";

    return $body;
}

// ─────────────────────────────────────────────────────────────────────
//  LECTURER DECISION DATA GENERATOR
//  Returns structured data for the lecturer dashboard analytics.
//  Called by lecturer_dashboard.php to populate the AI decision tables.
//
//  @param int   $lecturer_id
//  @param mysqli $conn
//  @return array
// ─────────────────────────────────────────────────────────────────────
/**
 * Infer skill name for a result row inside getLecturerDecisionData.
 */
function _lect_infer_skill(array $row): string {
    $t = strtolower($row['quiz_title'] ?? '');
    if (str_contains($t, 'practical') || str_contains($t, 'lab') || str_contains($t, 'applied'))       return 'Practical Application';
    if (str_contains($t, 'aptitude')  || str_contains($t, 'general') || str_contains($t, 'reasoning')) return 'General Aptitude';
    if (str_contains($t, 'theory')    || str_contains($t, 'core')    || str_contains($t, 'concept'))    return 'Core Theory';
    $pool = ['General Aptitude', 'Practical Application', 'Core Theory'];
    return $pool[intval($row['quiz_id'] ?? 0) % 3];
}

function getLecturerDecisionData(int $lecturer_id, $conn): array
{
    // Lecturer's courses — includes direct assignment, user.course_id, AND unit-level assignment
    $courses_res = mysqli_query($conn,
        "SELECT DISTINCT c.id, c.title FROM courses c
         WHERE c.lecturer_id = $lecturer_id
            OR c.id = (SELECT course_id FROM users WHERE id = $lecturer_id AND course_id IS NOT NULL LIMIT 1)
            OR c.id IN (SELECT cu.course_id FROM course_units cu WHERE cu.lecturer_id = $lecturer_id)
         ORDER BY c.title ASC"
    );
    $courses    = [];
    $course_ids = [];
    while ($r = mysqli_fetch_assoc($courses_res)) {
        $courses[$r['id']] = $r['title'];
        $course_ids[]      = intval($r['id']);
    }

    $empty = ['courses' => $courses, 'at_risk_students' => [], 'top_students' => [],
              'skill_averages' => [], 'class_summary' => [], 'class_avg' => 0];
    if (empty($course_ids)) return $empty;

    $ids_str = implode(',', $course_ids);

    // Enrollment counts per course
    $enroll_res = mysqli_query($conn,
        "SELECT course_id, COUNT(student_id) AS cnt FROM enrollments WHERE course_id IN ($ids_str) GROUP BY course_id"
    );
    $enroll_counts = [];
    while ($r = mysqli_fetch_assoc($enroll_res)) $enroll_counts[$r['course_id']] = intval($r['cnt']);

    // All enrolled students with their enrolled course
    $students_res = mysqli_query($conn,
        "SELECT DISTINCT e.student_id, e.course_id, u.full_name, u.career_path
         FROM enrollments e JOIN users u ON u.id = e.student_id
         WHERE e.course_id IN ($ids_str)"
    );
    $all_students    = [];
    $student_courses = [];
    while ($r = mysqli_fetch_assoc($students_res)) {
        $sid = intval($r['student_id']);
        if (!isset($all_students[$sid])) $all_students[$sid] = $r;
        $student_courses[$sid][] = intval($r['course_id']);
    }
    if (empty($all_students)) return $empty;

    $student_ids_str = implode(',', array_keys($all_students));

    // ── Compute per-student mastery by replaying RESULTS through AI engine rules ──
    // This bypasses duplicate rows in student_mastery and correctly accumulates
    // across multiple quizzes on different topics.
    $results_res = mysqli_query($conn,
        "SELECT r.student_id, r.score, r.attempt_no,
                q.id AS quiz_id, q.skill_name, q.title AS quiz_title, q.course_id
         FROM results r
         JOIN quizzes q ON q.id = r.quiz_id
         WHERE r.student_id IN ($student_ids_str) AND q.course_id IN ($ids_str)
         ORDER BY r.student_id ASC, r.created_at ASC, r.id ASC"
    );
    $student_mastery = [];
    while ($row = mysqli_fetch_assoc($results_res)) {
        $sid   = intval($row['student_id']);
        $skill = !empty($row['skill_name']) ? $row['skill_name'] : _lect_infer_skill($row);
        $cur   = $student_mastery[$sid][$skill] ?? 0.0;
        $score = floatval($row['score']);
        $att   = max(1, intval($row['attempt_no'] ?? 1));
        if ($score >= AI_SCORE_ADVANCE)                                $delta = AI_MASTERY_ADVANCE;
        elseif ($score >= AI_SCORE_RETRY && $att < AI_MAX_RETRIES)    $delta = AI_MASTERY_RETRY;
        else                                                           $delta = AI_MASTERY_REMEDIAL;
        $student_mastery[$sid][$skill] = max(AI_MASTERY_FLOOR, min(AI_MASTERY_CAP, $cur + $delta));
    }

    // ── Fetch final exam / coursework marks from legacy student_marks table ──
    $exam_res = mysqli_query($conn,
        "SELECT student_id, course_id, exam_mark, exam_max, coursework_mark, coursework_max
         FROM student_marks
         WHERE student_id IN ($student_ids_str) AND course_id IN ($ids_str)"
    );
    $student_exam = [];
    while ($r = mysqli_fetch_assoc($exam_res)) {
        $sid = intval($r['student_id']);
        $ep  = floatval($r['exam_max'])       > 0 ? floatval($r['exam_mark'])       / floatval($r['exam_max'])       * 100 : null;
        $cp  = floatval($r['coursework_max']) > 0 ? floatval($r['coursework_mark']) / floatval($r['coursework_max']) * 100 : null;
        $comb = null;
        if ($ep !== null && $cp !== null)  $comb = $ep * 0.7 + $cp * 0.3;
        elseif ($ep !== null)              $comb = $ep;
        elseif ($cp !== null)              $comb = $cp;
        if ($comb !== null) $student_exam[$sid] = round($comb, 1);
    }

    // ── Fetch exam marks from the unit_marks system ──────────────────────
    // Detects exam assessments by explicit type='exam' OR by name keywords
    // (handles lecturers who entered exam marks under type='coursework' by mistake).
    $EXAM_PATTERN = "ua.type='exam' OR LOWER(ua.name) REGEXP 'exam|final|end.?term|end.?year|end.?sem'";

    // Per-student overall exam pct
    $unit_exam_overall_res = mysqli_query($conn,
        "SELECT e.student_id,
                ROUND(SUM(um.mark) / NULLIF(SUM(ua.max_mark), 0) * 100, 1) AS exam_pct
         FROM enrollments e
         JOIN course_units cu ON cu.course_id = e.course_id
         JOIN unit_assessments ua ON ua.unit_id = cu.id AND ($EXAM_PATTERN)
         JOIN unit_marks um ON um.assessment_id = ua.id AND um.student_id = e.student_id
         WHERE e.student_id IN ($student_ids_str)
           AND e.course_id  IN ($ids_str)
         GROUP BY e.student_id"
    );
    while ($r = mysqli_fetch_assoc($unit_exam_overall_res)) {
        $sid = intval($r['student_id']);
        if (!isset($student_exam[$sid]) && $r['exam_pct'] !== null) {
            $student_exam[$sid] = floatval($r['exam_pct']);
        }
    }

    // Per-student per-course exam pct (for course health card avg_exam)
    $student_unit_exam_by_course = [];
    $unit_exam_course_res = mysqli_query($conn,
        "SELECT e.student_id, e.course_id,
                ROUND(SUM(um.mark) / NULLIF(SUM(ua.max_mark), 0) * 100, 1) AS exam_pct
         FROM enrollments e
         JOIN course_units cu ON cu.course_id = e.course_id
         JOIN unit_assessments ua ON ua.unit_id = cu.id AND ($EXAM_PATTERN)
         JOIN unit_marks um ON um.assessment_id = ua.id AND um.student_id = e.student_id
         WHERE e.student_id IN ($student_ids_str)
           AND e.course_id  IN ($ids_str)
         GROUP BY e.student_id, e.course_id"
    );
    while ($r = mysqli_fetch_assoc($unit_exam_course_res)) {
        $sid = intval($r['student_id']);
        $cid = intval($r['course_id']);
        if ($r['exam_pct'] !== null) {
            $student_unit_exam_by_course[$sid][$cid] = floatval($r['exam_pct']);
        }
    }

    // ── Classify each student ──
    $at_risk_students = [];
    $top_students     = [];
    $all_combined     = [];

    foreach ($all_students as $sid => $student) {
        $skills   = $student_mastery[$sid] ?? [];
        $quiz_avg = count($skills) > 0 ? array_sum($skills) / count($skills) : null;
        $exam_pct = $student_exam[$sid]    ?? null;

        // Combined: 60% quiz mastery + 40% exam (when both available)
        if ($quiz_avg !== null && $exam_pct !== null) $combined = round($quiz_avg * 0.6 + $exam_pct * 0.4, 1);
        elseif ($exam_pct  !== null)                  $combined = $exam_pct;
        elseif ($quiz_avg  !== null)                  $combined = round($quiz_avg, 1);
        else continue;  // no data — skip

        $all_combined[] = $combined;

        if ($combined < 40) {
            $at_risk_students[] = [
                'name'           => $student['full_name'],
                'career'         => $student['career_path'] ?? '',
                'avg_mastery'    => $quiz_avg !== null ? round($quiz_avg, 1) : 0,
                'combined_score' => $combined,
                'risk_level'     => $combined < 20 ? 'critical' : 'moderate',
                'has_exam'       => $exam_pct !== null,
            ];
        }
        if ($combined >= 70) {
            $top_students[] = [
                'name'           => $student['full_name'],
                'career'         => $student['career_path'] ?? '',
                'avg_mastery'    => $quiz_avg !== null ? round($quiz_avg, 1) : 0,
                'combined_score' => $combined,
                'has_exam'       => $exam_pct !== null,
            ];
        }
    }

    usort($at_risk_students, fn($a, $b) => $a['combined_score'] <=> $b['combined_score']);
    usort($top_students,     fn($a, $b) => $b['combined_score'] <=> $a['combined_score']);

    // ── Skill gap analysis ──
    $skill_totals = [];
    $skill_counts = [];
    foreach ($student_mastery as $skills) {
        foreach ($skills as $skill => $lvl) {
            $skill_totals[$skill] = ($skill_totals[$skill] ?? 0) + $lvl;
            $skill_counts[$skill] = ($skill_counts[$skill] ?? 0) + 1;
        }
    }
    $skill_averages = [];
    foreach ($skill_totals as $skill => $total) {
        $avg = $total / $skill_counts[$skill];
        $skill_averages[] = [
            'skill'   => $skill,
            'average' => round($avg, 1),
            'status'  => $avg >= 70 ? 'strong' : ($avg >= 40 ? 'developing' : 'weak'),
            'count'   => $skill_counts[$skill],
        ];
    }
    usort($skill_averages, fn($a, $b) => $a['average'] <=> $b['average']);

    // ── Per-course summary ──
    $class_summary = [];
    foreach ($courses as $cid => $ctitle) {
        $c_sids = array_keys(array_filter($student_courses, fn($cs) => in_array($cid, $cs)));
        $q_avgs = [];
        $e_avgs = [];
        foreach ($c_sids as $sid) {
            $skills = $student_mastery[$sid] ?? [];
            if (!empty($skills)) $q_avgs[] = array_sum($skills) / count($skills);
            // Per-course unit_marks exam takes priority — it is course-specific and accurate.
            // Fall back to legacy student_marks only when no unit-level exam exists.
            if (isset($student_unit_exam_by_course[$sid][$cid])) {
                $e_avgs[] = $student_unit_exam_by_course[$sid][$cid];
            } elseif (isset($student_exam[$sid])) {
                $e_avgs[] = $student_exam[$sid];
            }
        }
        $q_avg = !empty($q_avgs) ? round(array_sum($q_avgs) / count($q_avgs), 1) : 0;
        $e_avg = !empty($e_avgs) ? round(array_sum($e_avgs) / count($e_avgs), 1) : null;
        $c_avg = $e_avg !== null ? round($q_avg * 0.6 + $e_avg * 0.4, 1) : $q_avg;

        $class_summary[] = [
            'course_id'    => $cid,
            'title'        => $ctitle,
            'enrolled'     => $enroll_counts[$cid] ?? 0,
            'avg_mastery'  => $q_avg,
            'avg_exam'     => $e_avg,
            'combined_avg' => $c_avg,
            'health'       => $c_avg >= 65 ? 'good' : ($c_avg >= 40 ? 'moderate' : 'poor'),
        ];
    }

    $class_avg = !empty($all_combined) ? round(array_sum($all_combined) / count($all_combined), 1) : 0;

    return [
        'courses'          => $courses,
        'at_risk_students' => array_slice($at_risk_students, 0, 10),
        'top_students'     => array_slice($top_students,     0, 10),
        'skill_averages'   => $skill_averages,
        'class_summary'    => $class_summary,
        'class_avg'        => $class_avg,
    ];
}

// ─────────────────────────────────────────────────────────────────────
//  EMAIL SENDER (PHPMailer-free version using PHP mail())
//  For production, replace mail() with PHPMailer + SMTP.
// ─────────────────────────────────────────────────────────────────────

// ... previous code (getLecturerDecisionData, etc.)

// ─────────────────────────────────────────────────────────────────────
//  EMAIL SENDER (Updated with SMTP settings)
// ─────────────────────────────────────────────────────────────────────
function sendLmsEmail(string $to, string $subject, string $body): bool
{
    // Force SMTP settings for XAMPP
    ini_set("SMTP", "smtp.gmail.com"); 
    ini_set("smtp_port", "587");
    ini_set("sendmail_from", "noreply@smartlms.local");

    $headers  = "From: noreply@smartlms.local\r\n";
    $headers .= "Reply-To: noreply@smartlms.local\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "X-Mailer: SmartLMS-PHP\r\n";

    // The @ suppresses the warning if the connection still fails
    return @mail($to, $subject, $body, $headers);
}
?>







?>






