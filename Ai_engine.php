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
function getLecturerDecisionData(int $lecturer_id, $conn): array
{
    global $CAREER_SKILL_MAP;

    // Lecturer's courses — check both courses.lecturer_id AND users.course_id
    $courses_res = mysqli_query($conn,
        "SELECT DISTINCT id, title FROM courses
         WHERE lecturer_id = $lecturer_id
            OR id = (SELECT course_id FROM users WHERE id = $lecturer_id AND course_id IS NOT NULL LIMIT 1)
         ORDER BY title ASC"
    );
    $courses    = [];
    $course_ids = [];
    while ($r = mysqli_fetch_assoc($courses_res)) {
        $courses[$r['id']] = $r['title'];
        $course_ids[]      = intval($r['id']);
    }

    if (empty($course_ids)) {
        return [
            'courses'           => [],
            'at_risk_students'  => [],
            'top_students'      => [],
            'skill_averages'    => [],
            'class_summary'     => [],
            'quiz_performance'  => [],
        ];
    }

    $ids_str = implode(',', $course_ids);

    // Per-course enrollment count
    $enroll_res = mysqli_query($conn,
        "SELECT course_id, COUNT(student_id) as count
         FROM enrollments WHERE course_id IN ($ids_str)
         GROUP BY course_id"
    );
    $enroll_counts = [];
    while ($r = mysqli_fetch_assoc($enroll_res)) {
        $enroll_counts[$r['course_id']] = $r['count'];
    }

    // All enrolled students
    $students_res = mysqli_query($conn,
        "SELECT DISTINCT e.student_id, u.full_name, u.career_path
         FROM enrollments e
         JOIN users u ON u.id = e.student_id
         WHERE e.course_id IN ($ids_str)"
    );
    $all_students = [];
    while ($r = mysqli_fetch_assoc($students_res)) {
        $all_students[$r['student_id']] = $r;
    }

    // Mastery per student per skill
    $mastery_res = mysqli_query($conn,
        "SELECT sm.student_id, sm.skill_name, sm.mastery_level
         FROM student_mastery sm
         WHERE sm.student_id IN (" . (empty($all_students) ? '0' : implode(',', array_keys($all_students))) . ")"
    );
    $student_mastery = [];
    while ($r = mysqli_fetch_assoc($mastery_res)) {
        $student_mastery[$r['student_id']][$r['skill_name']] = floatval($r['mastery_level']);
    }

    // Classify each student
    $at_risk_students = [];
    $top_students     = [];
    foreach ($all_students as $sid => $student) {
        $skills = $student_mastery[$sid] ?? [];
        $avg    = count($skills) > 0 ? array_sum($skills) / count($skills) : 0;

        // Rule: at risk if avg mastery < 40
        if ($avg < 40) {
            $at_risk_students[] = [
                'name'         => $student['full_name'],
                'career'       => $student['career_path'],
                'avg_mastery'  => round($avg, 1),
                'risk_level'   => $avg < 20 ? 'critical' : 'moderate',
            ];
        }

        // Rule: top performer if avg mastery >= 70
        if ($avg >= 70) {
            $top_students[] = [
                'name'        => $student['full_name'],
                'career'      => $student['career_path'],
                'avg_mastery' => round($avg, 1),
            ];
        }
    }

    // Sort
    usort($at_risk_students, fn($a, $b) => $a['avg_mastery'] <=> $b['avg_mastery']);
    usort($top_students,     fn($a, $b) => $b['avg_mastery'] <=> $a['avg_mastery']);

    // Skill averages across all enrolled students
    $skill_totals  = [];
    $skill_counts  = [];
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
            'skill'      => $skill,
            'average'    => round($avg, 1),
            'status'     => $avg >= 70 ? 'strong' : ($avg >= 40 ? 'developing' : 'weak'),
        ];
    }
    usort($skill_averages, fn($a, $b) => $a['average'] <=> $b['average']); // weakest first

    // Per-course summary
    $class_summary = [];
    foreach ($courses as $cid => $ctitle) {
        $enrolled = $enroll_counts[$cid] ?? 0;
        // Students in this course
        $course_students_res = mysqli_query($conn,
            "SELECT e.student_id FROM enrollments e WHERE e.course_id = $cid"
        );
        $course_student_ids = [];
        while ($r = mysqli_fetch_assoc($course_students_res)) {
            $course_student_ids[] = $r['student_id'];
        }

        $course_avg = 0;
        if (!empty($course_student_ids)) {
            $ids_s = implode(',', $course_student_ids);
            $r2 = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT AVG(mastery_level) as avg_m FROM student_mastery WHERE student_id IN ($ids_s)"
            ));
            $course_avg = round(floatval($r2['avg_m'] ?? 0), 1);
        }

        $class_summary[] = [
            'course_id'   => $cid,
            'title'       => $ctitle,
            'enrolled'    => $enrolled,
            'avg_mastery' => $course_avg,
            'health'      => $course_avg >= 65 ? 'good' : ($course_avg >= 40 ? 'moderate' : 'poor'),
        ];
    }

    return [
        'courses'          => $courses,
        'at_risk_students' => array_slice($at_risk_students, 0, 10),
        'top_students'     => array_slice($top_students,     0, 10),
        'skill_averages'   => $skill_averages,
        'class_summary'    => $class_summary,
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