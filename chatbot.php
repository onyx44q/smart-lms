<?php
/**
 * chatbot.php — SmartLMS assistant (rule-based, no external API).
 *
 * Every answer is computed from the database at the moment the question is
 * asked — nothing is cached — and the numbers come from the same functions
 * the dashboards use (ai_engine.php), so the chatbot never disagrees with
 * what the user sees on screen. Supports students, lecturers and admins.
 *
 * Called via fetch() POST with { message: "..." }.
 * Responds with { reply: "...", chips: ["follow-up", ...] }.
 */

ob_start();
error_reporting(0);
ini_set('display_errors', 0);

include 'config.php';
include_once 'ai_engine.php';   // getLecturerDecisionData, getRiskDashboardData, getRecommendationEvaluation
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

function chat_out(string $reply, array $chips = []): void {
    if (ob_get_length()) ob_clean();
    echo json_encode(['reply' => trim($reply), 'chips' => array_values($chips)]);
    exit();
}

if (!isset($_SESSION['user_id'])) chat_out('Please log in to use the assistant.');

$user_id = intval($_SESSION['user_id']);
$role    = strtolower($_SESSION['role'] ?? 'student');
$raw_msg = trim($_POST['message'] ?? '');
if ($raw_msg === '') chat_out('Please type a message for me to help you.');
$msg = strtolower(preg_replace('/\s+/', ' ', $raw_msg));

// ─────────────────────────────────────────────────────────────────────
//  Small helpers
// ─────────────────────────────────────────────────────────────────────
function q_all($conn, string $sql): array {
    $out = [];
    $r = mysqli_query($conn, $sql);
    if ($r) while ($row = mysqli_fetch_assoc($r)) $out[] = $row;
    return $out;
}
function q_one($conn, string $sql): array {
    $r = mysqli_query($conn, $sql);
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return $row ?: [];
}
function ids_in(array $ids): string {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    return empty($ids) ? '0' : implode(',', $ids);
}
function money($n): string { return 'KES ' . number_format((float)$n, 2); }
function pct1($n): string  { return rtrim(rtrim(number_format((float)$n, 1), '0'), '.') . '%'; }
function as_of(): string   { return "\n\n⏱ Live data as of " . date('D d M Y, h:i A'); }

/** Does $msg contain this keyword? Single words match on word boundaries
 *  (so "hi" no longer matches "which"/"this"); phrases match as phrases. */
function has_kw(string $msg, string $kw): bool {
    $kw = strtolower($kw);
    if (strpos($kw, ' ') !== false) return str_contains($msg, $kw);
    return (bool)preg_match('/\b' . preg_quote($kw, '/') . '(s|es)?\b/', $msg);
}
/** Score every intent; the best score wins. Longer phrases score higher. */
function pick_intent(string $msg, array $map): ?string {
    $best = null; $bestScore = 0;
    foreach ($map as $intent => $kws) {
        $score = 0;
        foreach ($kws as $kw) {
            if (has_kw($msg, $kw)) $score += 1 + substr_count($kw, ' ') * 2;
        }
        if ($score > $bestScore) { $best = $intent; $bestScore = $score; }
    }
    return $best;
}
function is_greeting(string $msg): bool {
    $words = preg_split('/\W+/', $msg, -1, PREG_SPLIT_NO_EMPTY);
    if (count($words) === 0 || count($words) > 4) return false;
    return in_array($words[0], ['hello', 'hi', 'hey', 'hola', 'yo', 'greetings'], true)
        || ($words[0] === 'good' && isset($words[1]) && in_array($words[1], ['morning', 'afternoon', 'evening'], true));
}
function first_name(string $full): string {
    $p = preg_split('/\s+/', trim($full));
    return $p[0] ?? 'there';
}

// ═════════════════════════════════════════════════════════════════════
//  STUDENT
// ═════════════════════════════════════════════════════════════════════
function s_profile($conn, $uid): array {
    return q_one($conn, "SELECT full_name, career_path FROM users WHERE id = $uid");
}
function s_course_ids($conn, $uid): array {
    return array_map(fn($r) => intval($r['course_id']), q_all($conn, "SELECT course_id FROM enrollments WHERE student_id = $uid"));
}
function s_courses($conn, $uid): array {
    return array_column(q_all($conn, "SELECT c.title FROM courses c JOIN enrollments e ON c.id = e.course_id WHERE e.student_id = $uid ORDER BY c.title"), 'title');
}
function s_mastery($conn, $uid): array {
    // same score-based calculation as the dashboards (mastery_engine.php); strongest first
    $m = [];
    foreach (me_compute_student($conn, $uid)['skills'] as $skill => $s) $m[$skill] = $s['mastery'];
    return $m;
}
function s_avg_mastery(array $m): float { return empty($m) ? 0.0 : round(array_sum($m) / count($m), 1); }

function s_attendance($conn, $uid): array {
    return q_all($conn, "SELECT unit_title, unit_code, total_sessions, attended, absences, absence_pct, exam_status
                         FROM v_student_attendance WHERE student_id = $uid ORDER BY unit_title");
}
function s_marks($conn, $uid): array {
    return q_all($conn,
        "SELECT cu.id AS unit_id, cu.title AS unit_title, cu.unit_code, ua.name, ua.type, ua.max_mark, um.mark
         FROM unit_marks um
         JOIN unit_assessments ua ON ua.id = um.assessment_id
         JOIN course_units cu ON cu.id = ua.unit_id
         WHERE um.student_id = $uid AND um.mark IS NOT NULL
         ORDER BY cu.title, ua.sort_order, ua.id");
}
function s_fees($conn, $uid): array {
    $a = q_one($conn, "SELECT COALESCE(SUM(net_amount),0) AS billed FROM student_fee_assignments WHERE student_id = $uid AND status <> 'waived'");
    $p = q_one($conn, "SELECT COALESCE(SUM(amount_paid),0) AS paid FROM fee_payments WHERE student_id = $uid");
    $o = q_one($conn, "SELECT COUNT(*) AS n FROM student_fee_assignments
                       WHERE student_id = $uid AND status IN ('pending','partial','overdue')
                         AND due_date IS NOT NULL AND due_date < CURDATE()");
    $n = q_one($conn, "SELECT MIN(due_date) AS d FROM student_fee_assignments
                       WHERE student_id = $uid AND status IN ('pending','partial','overdue') AND due_date >= CURDATE()");
    $last = q_one($conn, "SELECT amount_paid, payment_date, receipt_number FROM fee_payments WHERE student_id = $uid ORDER BY payment_date DESC, id DESC LIMIT 1");
    $billed = floatval($a['billed'] ?? 0); $paid = floatval($p['paid'] ?? 0);
    return ['billed' => $billed, 'paid' => $paid, 'balance' => max(0, $billed - $paid), 'overdue' => intval($o['n'] ?? 0),
            'next_due' => $n['d'] ?? null, 'last' => $last];
}
function s_recommendations($conn, $uid, $limit = 5): array {
    return q_all($conn,
        "SELECT r.id, r.score, r.recommendation, r.action_taken, r.helpful, r.created_at, q.title AS quiz_title, q.skill_name
         FROM results r LEFT JOIN quizzes q ON q.id = r.quiz_id
         WHERE r.student_id = $uid ORDER BY r.created_at DESC, r.id DESC LIMIT " . intval($limit));
}
function s_quizzes($conn, $uid): array {
    $cids = ids_in(s_course_ids($conn, $uid));
    return q_all($conn,
        "SELECT q.id, q.title, q.difficulty, q.skill_name, c.title AS course_title,
                (SELECT COUNT(*) FROM results r WHERE r.quiz_id = q.id AND r.student_id = $uid) AS attempts
         FROM quizzes q LEFT JOIN courses c ON c.id = q.course_id
         WHERE q.is_active = 1 AND q.course_id IN ($cids)
         ORDER BY q.created_at DESC LIMIT 12");
}
function s_schedule($conn, $uid, $limit = 4): array {
    $cids = ids_in(s_course_ids($conn, $uid));
    return q_all($conn,
        "SELECT s.title, s.meet_date, s.meet_time, s.meet_link, u.full_name AS lecturer
         FROM schedules s JOIN users u ON u.id = s.lecturer_id
         WHERE (s.course_id IN ($cids) OR s.course_id IS NULL)
           AND (s.meet_date > CURDATE() OR (s.meet_date = CURDATE() AND s.meet_time >= CURTIME()))
         ORDER BY s.meet_date, s.meet_time LIMIT " . intval($limit));
}
function s_assignments($conn, $uid): array {
    $cids = ids_in(s_course_ids($conn, $uid));
    $pending = []; $done = [];
    foreach (q_all($conn,
        "SELECT a.id, a.title, a.due_date, c.title AS course_title, s.id AS submission_id, pr.verdict, pr.overall_score
         FROM assignments a JOIN courses c ON c.id = a.course_id
         LEFT JOIN assignment_submissions s ON s.assignment_id = a.id AND s.student_id = $uid
         LEFT JOIN plagiarism_reports pr ON pr.submission_id = s.id
         WHERE a.course_id IN ($cids) ORDER BY a.due_date ASC") as $r) {
        if ($r['submission_id']) $done[] = $r; else $pending[] = $r;
    }
    return [$pending, $done];
}
function s_notifications($conn, $uid): array {
    $c = q_one($conn, "SELECT COUNT(*) AS n FROM notifications WHERE user_id = $uid AND is_read = 0");
    $l = q_all($conn, "SELECT message, created_at FROM notifications WHERE user_id = $uid AND is_read = 0 ORDER BY created_at DESC LIMIT 4");
    return [intval($c['n'] ?? 0), $l];
}
function s_standing($conn, $uid): ?array {
    $cids = s_course_ids($conn, $uid);
    if (empty($cids)) return null;
    $cstr = ids_in($cids);
    $lids = array_map(fn($r) => intval($r['lid']), q_all($conn,
        "SELECT lecturer_id AS lid FROM courses WHERE id IN ($cstr) AND lecturer_id IS NOT NULL
         UNION SELECT lecturer_id FROM course_units WHERE course_id IN ($cstr) AND lecturer_id IS NOT NULL"));
    $data = getRiskViewWithEngineFlags($conn, $cids, $lids);
    foreach ($data['students'] as $row) if (intval($row['student_id']) === $uid) return $row;
    return null;
}

function student_chips(): array {
    return ['My summary', 'My fees', 'My attendance', 'My marks', 'Recommendations', 'Available quizzes'];
}

function handle_student($conn, int $uid, string $msg): void {
    $p    = s_profile($conn, $uid);
    $name = first_name($p['full_name'] ?? 'there');

    if (is_greeting($msg)) {
        $m = s_mastery($conn, $uid); $courses = s_courses($conn, $uid);
        $r = "Hello $name! I'm your SmartLMS assistant. ";
        $r .= empty($m) ? "You haven't completed a quiz yet, so there's no mastery score to show. " : "Your current average mastery is " . pct1(s_avg_mastery($m)) . ". ";
        $r .= empty($courses) ? "You aren't enrolled in any course yet — open All Courses to get started." : "You're enrolled in: " . implode(', ', $courses) . ".";
        $r .= "\n\nAsk me about your fees, attendance, marks, quizzes, assignments, schedule or recommendations.";
        chat_out($r, student_chips());
    }

    $intent = pick_intent($msg, [
        'summary'         => ['summary','overview','my progress','how am i doing','my status','everything','dashboard','catch me up','update me'],
        'score'           => ['my score','my result','my results','my grade','last quiz','quiz result','quiz results','how did i do','performance'],
        'mastery'         => ['mastery','skill','skills','strength','strengths','weakness','weak','strong'],
        'courses'         => ['my course','my courses','enrolled','course list','which course','what course','what am i studying'],
        'available_quiz'  => ['available quiz','available quizzes','quizzes available','which quiz','which quizzes','pending quiz','pending quizzes','quiz to take','quizzes to take','new quiz','new quizzes','open quizzes','quizzes'],
        'quiz_help'       => ['how to take','take a quiz','how do i take','quiz help','how does the quiz work'],
        'schedule'        => ['schedule','meeting','meetings','upcoming','timetable','next class','next session','when is','zoom'],
        'notifications'   => ['notification','notifications','unread','alert','alerts','messages'],
        'career'          => ['career','job','jobs','future','career path'],
        'nav_help'        => ['how to','navigate','where do i','where can i','guide','tutorial','help','what can you do'],
        'materials'       => ['material','materials','pdf','video','videos','notes','download','resource','resources','handout','handouts'],
        'improve'         => ['improve','tips','advice','study','struggling','get better','how can i do better'],
        'assignments'     => ['assignment','assignments','homework','due date','deadline','deadlines','submit','submission','plagiarism'],
        'attendance'      => ['attendance','absent','absences','barred','exam bar','present','eligible for exam','eligible'],
        'marks'           => ['marks','cat','cats','coursework','exam mark','exam marks','unit marks','transcript','my grades','pass mark','did i pass'],
        'fees'            => ['fee','fees','balance','payment','payments','paid','owe','owing','arrears','invoice','tuition','receipt','how much do i owe'],
        'recommendations' => ['recommendation','recommendations','what should i study','what next','next step','what should i do','recommended'],
        'lecturers'       => ['lecturer','lecturers','teacher','teachers','who teaches','instructor','instructors'],
        'risk'            => ['at risk','am i at risk','failing','will i pass','my standing','am i okay'],
    ]);

    switch ($intent) {
        case 'summary':
            $m = s_mastery($conn, $uid); $courses = s_courses($conn, $uid);
            [$pend, $done] = s_assignments($conn, $uid);
            [$unread] = s_notifications($conn, $uid);
            $fee = s_fees($conn, $uid); $att = s_attendance($conn, $uid);
            $barred = array_filter($att, fn($a) => ($a['exam_status'] ?? '') === 'BARRED');
            $next = s_schedule($conn, $uid, 1); $stand = s_standing($conn, $uid);
            $r = "Here's your snapshot, $name:\n";
            $r .= "• Courses: " . (empty($courses) ? 'none enrolled' : count($courses) . ' (' . implode(', ', $courses) . ')') . "\n";
            $r .= "• Average mastery: " . (empty($m) ? 'no quizzes taken yet' : pct1(s_avg_mastery($m))) . "\n";
            if ($stand) $r .= "• Standing: " . ucwords(str_replace('_', ' ', $stand['band'])) . " (risk " . $stand['risk'] . ")\n";
            $r .= "• Pending assignments: " . count($pend) . "\n";
            $r .= "• Attendance: " . (empty($att) ? 'no sessions recorded yet' : (count($barred) ? count($barred) . ' unit(s) BARRED from exams' : 'eligible for all exams')) . "\n";
            $r .= "• Fee balance: " . ($fee['billed'] > 0 ? money($fee['balance']) . ($fee['overdue'] ? " ({$fee['overdue']} overdue)" : '') : 'no fees assigned') . "\n";
            $r .= "• Unread notifications: $unread\n";
            $r .= "• Next session: " . (empty($next) ? 'none scheduled' : $next[0]['title'] . ' — ' . date('D d M, h:i A', strtotime($next[0]['meet_date'] . ' ' . $next[0]['meet_time'])));
            chat_out($r . as_of(), student_chips());

        case 'score':
            $rows = s_recommendations($conn, $uid, 3);
            if (empty($rows)) chat_out("You haven't submitted any quizzes yet, $name. Take your first quiz to see your results here!", ['Available quizzes', 'How to take a quiz']);
            $r = "Your latest quiz results:\n";
            foreach ($rows as $x) {
                $r .= "• " . ($x['quiz_title'] ?: 'Quiz') . ": " . pct1($x['score']) . " → " . ucfirst($x['action_taken'] ?: '—') . " (" . date('d M', strtotime($x['created_at'])) . ")\n";
            }
            $last = $rows[0];
            $r .= "\n" . match ($last['action_taken']) {
                'advance'  => "Excellent — your latest result moved you up to the next level.",
                'retry'    => "Good effort. The system recommends reviewing the topic and retrying.",
                'remedial' => "Foundational gaps were detected, so remedial practice has been recommended.",
                default    => "Keep practising to improve.",
            };
            chat_out($r . as_of(), ['Recommendations', 'My mastery', 'Available quizzes']);

        case 'mastery':
            $m = s_mastery($conn, $uid);
            if (empty($m)) chat_out("No mastery data yet. Complete a quiz to start tracking your skill levels.", ['Available quizzes']);
            $full = me_compute_student($conn, $uid);
            $r = "Your skill mastery (average of your latest score on each quiz):\n";
            foreach ($full['skills'] as $skill => $s) $r .= "• $skill: " . pct1($s['mastery']) . " — " . $s['quizzes'] . " quiz" . ($s['quizzes'] === 1 ? '' : 'zes') . ($s['quizzes'] < 2 ? ' (early indication)' : '') . " (" . ($s['mastery'] >= 70 ? 'Strong' : ($s['mastery'] >= 40 ? 'Developing' : 'Needs work')) . ")\n";
            $un = array_values(array_diff(['Core Theory', 'Practical Application', 'General Aptitude'], array_keys($full['skills'])));
            if ($un) $r .= "• Not assessed yet: " . implode(', ', $un) . "\n";
            $weak = array_key_last($m);
            if (count($m) > 1) $r .= "\nWeakest assessed skill: $weak at " . pct1($m[$weak]) . ". Revising it will lift your average fastest.";
            chat_out($r . as_of(), ['Recommendations', 'How can I improve?']);

        case 'courses':
            $courses = s_courses($conn, $uid);
            chat_out(empty($courses)
                ? "You aren't enrolled in any course yet. Open 'All Courses' in the sidebar to browse and enrol."
                : "You are enrolled in " . count($courses) . " course(s): " . implode(', ', $courses) . ". Open 'My Enrolled' to reach their materials and quizzes." . as_of(),
                ['My lecturers', 'Available quizzes', 'My schedule']);

        case 'available_quiz':
            $qs = s_quizzes($conn, $uid);
            if (empty($qs)) chat_out("There are no published quizzes for your courses right now. You'll get a notification when a lecturer publishes one." . as_of(), ['My summary']);
            $todo = array_filter($qs, fn($x) => intval($x['attempts']) === 0);
            $r = count($todo) . " quiz(zes) you haven't attempted yet, out of " . count($qs) . " published:\n";
            foreach ($qs as $x) {
                $r .= "• " . $x['title'] . " — " . ucfirst($x['difficulty']) . ($x['skill_name'] ? " · {$x['skill_name']}" : '') . ($x['course_title'] ? " · {$x['course_title']}" : '')
                    . (intval($x['attempts']) > 0 ? "  (attempted {$x['attempts']}×)" : "  ← not attempted") . "\n";
            }
            $r .= "\nOpen your Overview page and click 'Take Quiz' beside any of them.";
            chat_out($r . as_of(), ['How to take a quiz', 'My results']);

        case 'quiz_help':
            chat_out("To take a quiz: open your Overview page, scroll to Available Quizzes and click 'Take Quiz'. After you submit you get your score, predicted grade, an adaptive recommendation (Advance / Retry / Remedial) and a prompt asking whether the recommendation helped — you can also rate past ones on the Recommendations page.", ['Available quizzes', 'Recommendations']);

        case 'schedule':
            $sc = s_schedule($conn, $uid, 5);
            if (empty($sc)) chat_out("No upcoming sessions are scheduled for your courses right now." . as_of(), ['My summary']);
            $r = "Your upcoming sessions:\n";
            foreach ($sc as $s) {
                $r .= "• {$s['title']} — " . date('D d M', strtotime($s['meet_date'])) . " at " . date('h:i A', strtotime($s['meet_time'])) . " (with {$s['lecturer']})" . (!empty($s['meet_link']) ? " · link available on My Schedule" : '') . "\n";
            }
            chat_out($r . as_of(), ['My summary']);

        case 'notifications':
            [$n, $list] = s_notifications($conn, $uid);
            if ($n === 0) chat_out("You have no unread notifications, $name." . as_of());
            $r = "You have $n unread notification(s). Latest:\n";
            foreach ($list as $x) $r .= "• " . $x['message'] . " (" . date('d M, h:i A', strtotime($x['created_at'])) . ")\n";
            chat_out($r . as_of());

        case 'career':
            $career = $p['career_path'] ?: 'Software Development'; $lc = strtolower($career); $m = s_mastery($conn, $uid);
            $tips = match (true) {
                str_contains($lc, 'data')  => "Strengthen Core Theory (SQL, statistics) and Practical Application (Python, data visualisation).",
                str_contains($lc, 'cyber') => "Prioritise Practical Application (security tools) and Core Theory (networks, cryptography). CEH or Security+ are valuable certifications.",
                str_contains($lc, 'ais')   => "Focus on Core Theory (accounting, information systems) and Practical Application (ERP systems, business analytics).",
                default                    => "Strengthen Practical Application (coding projects) and General Aptitude (problem solving). Build a portfolio.",
            };
            $r = "Your career path is $career. $tips";
            if (!empty($m)) { $w = array_key_last($m); $r .= " Right now your weakest skill is $w (" . pct1($m[$w]) . ") — improving it directly helps your career readiness."; }
            chat_out($r . as_of(), ['My mastery', 'Recommendations']);

        case 'materials':
            chat_out("Open 'My Enrolled' → 'Enter Course' to find every PDF and video your lecturers uploaded. PDFs download; videos play in the browser.", ['My courses']);

        case 'improve':
            $m = s_mastery($conn, $uid); $avg = s_avg_mastery($m);
            if (empty($m)) chat_out("Take a quiz first, $name — your results unlock personalised tips.", ['Available quizzes']);
            $w = array_key_last($m);
            $r = $avg >= 70 ? "You're doing well at " . pct1($avg) . " average mastery. To reach expert level, try advanced quizzes and aim for 90%+."
               : ($avg >= 40 ? "You're progressing at " . pct1($avg) . " average mastery. Spend ~20 minutes a day on $w (" . pct1($m[$w]) . "), retake quizzes you didn't pass, and watch the video lectures."
               : "Your average mastery is " . pct1($avg) . " — let's turn that around. Start with the foundational PDFs, then retake quizzes; the system adapts to your answers. Weakest skill: $w.");
            chat_out($r . as_of(), ['Recommendations', 'My mastery']);

        case 'assignments':
            [$pend, $done] = s_assignments($conn, $uid);
            if (!$pend && !$done) chat_out("No assignments have been posted for your courses yet." . as_of());
            $r = '';
            if ($pend) {
                $r .= count($pend) . " assignment(s) still to submit:\n";
                foreach ($pend as $a) {
                    $overdue = !empty($a['due_date']) && strtotime($a['due_date'] . ' 23:59:59') < time();
                    $r .= "• {$a['title']} ({$a['course_title']}) — " . (!empty($a['due_date']) ? 'due ' . date('d M Y', strtotime($a['due_date'])) : 'no due date') . ($overdue ? ' ⚠ OVERDUE' : '') . "\n";
                }
            } else { $r .= "All assignments are submitted — well done!\n"; }
            if ($done) {
                $risky = array_filter($done, fn($a) => ($a['verdict'] ?? '') === 'HIGH RISK');
                $r .= "\nSubmitted: " . count($done) . ($risky ? " — ⚠ " . count($risky) . " flagged HIGH RISK for plagiarism; review them on the Assignments tab." : " — plagiarism checks are clear.");
            }
            chat_out($r . as_of(), ['My summary']);

        case 'attendance':
            $att = s_attendance($conn, $uid);
            if (empty($att)) chat_out("I can't find attendance records for you yet. They appear once you're registered for units and your lecturers start marking sessions." . as_of());
            $r = "Your attendance by unit:\n";
            foreach ($att as $a) {
                $tot = intval($a['total_sessions']);
                $r .= "• " . $a['unit_title'] . ($a['unit_code'] ? " ({$a['unit_code']})" : '') . ": "
                    . ($tot === 0 ? "no sessions recorded yet" : "{$a['attended']}/$tot attended, " . pct1($a['absence_pct']) . " absent → " . $a['exam_status']) . "\n";
            }
            $barred = array_filter($att, fn($a) => ($a['exam_status'] ?? '') === 'BARRED');
            $r .= $barred ? "\n⚠ You are over the 33.33% absence limit in " . count($barred) . " unit(s) and barred from those exams. Speak to your lecturer." : "\nYou're within the 33.33% absence limit in every unit.";
            chat_out($r . as_of(), ['My marks', 'My summary']);

        case 'marks':
            $rows = s_marks($conn, $uid);
            if (empty($rows)) chat_out("No marks have been entered for you yet. They'll show here as lecturers record CATs, coursework and exams." . as_of());
            $units = [];
            foreach ($rows as $x) $units[$x['unit_id']][] = $x;
            $r = "Your marks:\n";
            foreach ($units as $list) {
                $r .= "\n" . $list[0]['unit_title'] . ($list[0]['unit_code'] ? " ({$list[0]['unit_code']})" : '') . "\n";
                $got = 0; $max = 0;
                foreach ($list as $x) { $r .= "  • {$x['name']}: " . rtrim(rtrim($x['mark'], '0'), '.') . "/" . rtrim(rtrim($x['max_mark'], '0'), '.') . "\n"; $got += $x['mark']; $max += $x['max_mark']; }
                if ($max > 0) { $o = round($got / $max * 100, 1); $r .= "  Overall so far: " . pct1($o) . ($o >= UNIT_PASS_PERCENT ? " (passing)" : " (below the " . UNIT_PASS_PERCENT . "% pass mark)") . "\n"; }
            }
            chat_out($r . as_of(), ['My attendance', 'My summary']);

        case 'fees':
            $f = s_fees($conn, $uid);
            if ($f['billed'] <= 0 && $f['paid'] <= 0) chat_out("No fees have been assigned to you yet." . as_of());
            $r = "Your fee position:\n• Billed: " . money($f['billed']) . "\n• Paid: " . money($f['paid']) . "\n• Balance: " . money($f['balance']) . ($f['paid'] > $f['billed'] ? "  (you have a credit of " . money($f['paid'] - $f['billed']) . ")" : '') . "\n";
            if ($f['overdue']) $r .= "• ⚠ {$f['overdue']} fee item(s) are past their due date.\n";
            if ($f['next_due']) $r .= "• Next due date: " . date('d M Y', strtotime($f['next_due'])) . "\n";
            if (!empty($f['last'])) $r .= "• Last payment: " . money($f['last']['amount_paid']) . " on " . date('d M Y', strtotime($f['last']['payment_date'])) . (!empty($f['last']['receipt_number']) ? " (receipt {$f['last']['receipt_number']})" : '') . "\n";
            $r .= $f['balance'] <= 0 ? "\nYou're fully paid up — thank you." : "\nContact the finance office to clear the balance; your fee statement has the full breakdown.";
            chat_out($r . as_of(), ['My summary']);

        case 'recommendations':
            $rows = s_recommendations($conn, $uid, 5);
            if (empty($rows)) chat_out("You have no recommendations yet — they're generated each time you submit a quiz.", ['Available quizzes']);
            $r = "Your latest recommendations:\n";
            foreach ($rows as $x) {
                $r .= "• [" . ucfirst($x['action_taken'] ?: '—') . " · " . pct1($x['score']) . ($x['quiz_title'] ? " · {$x['quiz_title']}" : '') . "] " . $x['recommendation']
                    . "  — " . ($x['helpful'] === 'yes' ? 'you rated it helpful' : ($x['helpful'] === 'no' ? 'you rated it not helpful' : 'not rated yet')) . "\n";
            }
            $unrated = count(array_filter($rows, fn($x) => !$x['helpful']));
            if ($unrated) $r .= "\nTell us whether they helped: open Recommendations in the sidebar and tap Yes/No.";
            chat_out($r . as_of(), ['My mastery', 'Available quizzes']);

        case 'lecturers':
            $cl = q_all($conn, "SELECT c.title AS item, u.full_name AS lecturer FROM courses c JOIN enrollments e ON e.course_id = c.id JOIN users u ON u.id = c.lecturer_id WHERE e.student_id = $uid");
            $ul = q_all($conn, "SELECT cu.title AS item, u.full_name AS lecturer FROM unit_registrations ur JOIN course_units cu ON cu.id = ur.unit_id JOIN users u ON u.id = cu.lecturer_id WHERE ur.student_id = $uid");
            if (empty($cl) && empty($ul)) chat_out("I can't see any lecturers assigned to your courses or units yet." . as_of());
            $r = "Your lecturers:\n";
            foreach ($cl as $x) $r .= "• {$x['lecturer']} — course {$x['item']}\n";
            foreach ($ul as $x) $r .= "• {$x['lecturer']} — unit {$x['item']}\n";
            chat_out($r . as_of());

        case 'risk':
            $s = s_standing($conn, $uid);
            if (!$s) chat_out("I can't work out your standing yet — enrol in a course and complete some quizzes first.");
            $r = "Your current standing is " . strtoupper(str_replace('_', ' ', $s['band'])) . " (risk level " . $s['risk'] . ").\n"
               . "• Quiz average: " . ($s['quiz_avg'] === null ? 'no quizzes yet' : pct1($s['quiz_avg'])) . "\n"
               . "• Attendance: " . ($s['attendance'] === null ? 'no data yet' : pct1($s['attendance'])) . "\n"
               . "• Assignments: " . ($s['assignment'] === null ? 'no data yet' : pct1($s['assignment'])) . "\n"
               . "• Combined score: " . pct1($s['composite']) . "\n"
               . (!empty($s['engine_flag']) ? "• Your lecturers' AI Decision Dashboard also flags you: quiz + exam combined is " . pct1($s['engine_score']) . " (flag threshold is below 40%).\n" : '')
               . "\n" . ($s['risk'] === 'LOW' ? "You're on track — keep it up." : "Focus on the weakest of those three areas first — start with your lowest quiz skills.");
            chat_out($r . as_of(), ['Recommendations', 'How can I improve?']);

        case 'nav_help':
            chat_out("Here's what I can do — all answers come live from the system:\n"
                . "• My summary — one-glance snapshot\n• My fees — billed, paid, balance, due dates\n• My attendance — per unit, exam-bar status\n• My marks — CATs, coursework, exams\n"
                . "• Available quizzes · My results · My mastery\n• Recommendations — and whether you rated them\n• Assignments · My schedule · Notifications\n• My lecturers · Am I at risk?\n\n"
                . "Sidebar: Overview, All Courses, My Enrolled, My Schedule, Recommendations.", student_chips());

        default:
            chat_out("I'm not sure I understood that, $name. Try one of these:", student_chips());
    }
}

// ═════════════════════════════════════════════════════════════════════
//  LECTURER
// ═════════════════════════════════════════════════════════════════════
function l_course_ids($conn, $uid): array {
    return array_map(fn($r) => intval($r['id']), q_all($conn,
        "SELECT DISTINCT c.id FROM courses c
         WHERE c.lecturer_id = $uid
            OR c.id = (SELECT course_id FROM users WHERE id = $uid AND course_id IS NOT NULL LIMIT 1)
            OR c.id IN (SELECT cu.course_id FROM course_units cu WHERE cu.lecturer_id = $uid)"));
}
function l_unit_ids($conn, $uid): array {
    return q_all($conn,
        "SELECT cu.id, cu.title, cu.unit_code, c.title AS course_title
         FROM course_units cu JOIN courses c ON c.id = cu.course_id
         WHERE cu.lecturer_id = $uid OR c.lecturer_id = $uid ORDER BY c.title, cu.title");
}
function lecturer_chips(): array {
    return ['At-risk students', 'Class performance', 'Top students', 'Recommendation feedback', 'My quizzes', 'Assignments'];
}

function handle_lecturer($conn, int $uid, string $msg): void {
    $name  = first_name($_SESSION['user_name'] ?? 'there');
    $cids  = l_course_ids($conn, $uid);
    $cstr  = ids_in($cids);

    if (is_greeting($msg)) {
        $stu = q_one($conn, "SELECT COUNT(DISTINCT student_id) AS n FROM enrollments WHERE course_id IN ($cstr)");
        $rv  = getRiskViewWithEngineFlags($conn, $cids, [$uid]);
        $nRisk = $rv['distribution']['at_risk'] + $rv['distribution']['critical'];
        $r = "Hello $name! You have " . intval($stu['n'] ?? 0) . " student(s) across " . count($cids) . " course(s). ";
        $r .= $nRisk === 0 ? "Nobody is currently flagged at risk. " : "$nRisk student(s) are flagged at risk. ";
        $r .= "\n\nAsk me about at-risk students, class performance, quizzes, assignments, attendance, marks or your schedule.";
        chat_out($r, lecturer_chips());
    }

    $intent = pick_intent($msg, [
        'at_risk'     => ['at risk','at-risk','struggling','failing','weak student','weak students','low score','critical','who needs help','risk dashboard'],
        'performance' => ['class performance','how is my class','class average','overall','class stats','class health','course health','performance'],
        'top'         => ['top student','top students','best student','best students','top performer','top performers','highest','excelling'],
        'reco_eval'   => ['recommendation feedback','recommendations feedback','acceptance','helpful','recommendation evaluation','evaluation','recommendation'],
        'quiz'        => ['quiz','quizzes','generate quiz','assessment','published','draft','question','questions'],
        'assignments' => ['assignment','assignments','submission','submissions','plagiarism','homework'],
        'students'    => ['my students','how many students','enrolled','enrolment','enrollment','student count','class size'],
        'courses'     => ['my course','my courses','which course','course list','what do i teach'],
        'units'       => ['my unit','my units','which unit','unit list','units i teach'],
        'marks'       => ['marks','mark manager','enter marks','cat','cats','coursework','exam mark','exam marks','grading'],
        'attendance'  => ['attendance','absent','absences','present','exam bar','barred','33.33'],
        'schedule'    => ['schedule','meeting','meetings','upcoming','next class','next session','zoom'],
        'leave'       => ['leave','vacation','time off','sick day','apply for leave'],
        'materials'   => ['upload','material','materials','pdf','video','resource','lecture note','lecture notes'],
        'profile'     => ['profile','my profile','professor','qualification','experience','update my details'],
        'help'        => ['how to','navigate','help','guide','tutorial','what can you do'],
    ]);

    switch ($intent) {
        case 'at_risk':
            $risk = getRiskViewWithEngineFlags($conn, $cids, [$uid]);
            $d = $risk['distribution']; $st = $risk['students'];
            if (empty($st)) chat_out("None of your courses have enrolled students yet, so there's nothing to assess." . as_of());
            $flag = array_values(array_filter($st, fn($s) => in_array($s['band'], ['at_risk', 'critical'])));
            $r = "Student performance across your courses:\n• Excellent: {$d['excellent']}\n• Good: {$d['good']}\n• At risk: {$d['at_risk']}\n• Critical: {$d['critical']}\n" . (!empty($risk['no_data']) ? "• Not assessed yet: {$risk['no_data']} (no quiz, attendance or due work recorded)\n" : '');
            if (empty($flag)) { $r .= "\nNobody is currently at risk — nice work."; }
            else {
                $r .= "\nWho needs attention (lowest first):\n";
                foreach (array_slice($flag, 0, 6) as $s) {
                    $r .= "• {$s['name']} — " . $s['risk'] . " risk · quiz " . ($s['quiz_avg'] === null ? 'n/a' : pct1($s['quiz_avg'])) . " · attendance " . ($s['attendance'] === null ? 'n/a' : pct1($s['attendance'])) . " · assignments " . ($s['assignment'] === null ? 'n/a' : pct1($s['assignment'])) . (!empty($s['engine_flag']) ? " · quiz+exam " . pct1($s['engine_score']) : '') . "\n";
                }
                if (count($flag) > 6) $r .= "…and " . (count($flag) - 6) . " more. ";
                $r .= "\nOpen Risk Dashboard in the sidebar for the full searchable list.";
            }
            chat_out($r . as_of(), ['Class performance', 'Top students']);

        case 'performance':
            $dd = getLecturerDecisionData($uid, $conn);
            $cs = $dd['class_summary'] ?? [];
            if (empty($cs)) chat_out("No course data yet — you have no assigned courses with enrolled students." . as_of());
            $r = "Class average (live): " . pct1($dd['class_avg'] ?? 0) . ".\n";
            foreach ($cs as $c) {
                $r .= "• {$c['title']}: " . ($c['has_activity']
                    ? ucfirst($c['health']) . " — quiz avg " . ($c['avg_mastery'] === null ? '—' : pct1($c['avg_mastery'])) . ($c['avg_exam'] !== null ? ", exam " . pct1($c['avg_exam']) : '') . ", {$c['enrolled']} enrolled"
                    : "no quiz or mark activity yet, {$c['enrolled']} enrolled") . "\n";
            }
            if (!empty($dd['skill_averages'])) { $w = $dd['skill_averages'][0]; $r .= "\nWeakest skill across your classes: " . ($w['skill'] ?? 'n/a') . " (" . pct1($w['average'] ?? 0) . ")."; }
            chat_out($r . as_of(), ['At-risk students', 'Top students']);

        case 'top':
            $dd = getLecturerDecisionData($uid, $conn); $top = $dd['top_students'] ?? [];
            if (empty($top)) chat_out("No student has reached top-performer level yet." . as_of(), ['At-risk students']);
            $r = "Top performers:\n";
            foreach (array_slice($top, 0, 6) as $t) $r .= "• " . ($t['name'] ?? 'Student') . " — combined " . pct1($t['combined_score'] ?? 0) . " (quiz mastery " . pct1($t['avg_mastery'] ?? 0) . ")" . (!empty($t['career']) ? " · {$t['career']}" : '') . "\n";
            chat_out($r . as_of(), ['At-risk students']);

        case 'reco_eval':
            $e = getRecommendationEvaluation($conn, $cids);
            if ($e['generated'] === 0) chat_out("No recommendations have been generated for your courses yet — they appear when students submit quizzes." . as_of());
            $r = "Recommendation feedback for your courses:\n• Generated: {$e['generated']}\n• Marked helpful: {$e['helpful']}\n• Marked not helpful: {$e['not_helpful']}\n• Not yet rated: {$e['unrated']}\n• Acceptance rate: " . pct1($e['acceptance_pct']) . " (of rated)\n";
            if ($e['unrated'] > 0) $r .= "\nStudents rate recommendations from the Recommendations page on their dashboard, so these numbers update live as they vote.";
            chat_out($r . as_of(), ['At-risk students', 'Class performance']);

        case 'quiz':
            $qs = q_all($conn, "SELECT q.title, q.difficulty, q.skill_name, q.is_active, (SELECT COUNT(*) FROM questions x WHERE x.quiz_id = q.id) AS n_q,
                                       (SELECT COUNT(*) FROM results r WHERE r.quiz_id = q.id) AS attempts
                                FROM quizzes q WHERE q.course_id IN ($cstr) ORDER BY q.created_at DESC LIMIT 6");
            $tot = q_one($conn, "SELECT COUNT(*) AS t, SUM(is_active = 1) AS pub FROM quizzes WHERE course_id IN ($cstr)");
            $r = "You have " . intval($tot['t'] ?? 0) . " quiz(zes): " . intval($tot['pub'] ?? 0) . " published, " . (intval($tot['t'] ?? 0) - intval($tot['pub'] ?? 0)) . " draft.\n";
            foreach ($qs as $x) $r .= "• {$x['title']} — " . ucfirst($x['difficulty']) . " · {$x['n_q']} questions · " . ($x['is_active'] ? 'published' : 'draft') . " · {$x['attempts']} attempt(s)\n";
            $r .= "\nTo create one: Quiz Manager → Generate New Quiz, pick the unit, material, skill, difficulty and number of questions, then Publish.";
            chat_out($r . as_of(), ['Recommendation feedback', 'Class performance']);

        case 'assignments':
            $rows = q_all($conn,
                "SELECT a.title, a.due_date, c.title AS course_title,
                        (SELECT COUNT(*) FROM assignment_submissions s WHERE s.assignment_id = a.id) AS subs,
                        (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = a.course_id) AS enrolled,
                        (SELECT COUNT(*) FROM assignment_submissions s JOIN plagiarism_reports pr ON pr.submission_id = s.id WHERE s.assignment_id = a.id AND pr.verdict = 'HIGH RISK') AS flagged
                 FROM assignments a JOIN courses c ON c.id = a.course_id
                 WHERE a.lecturer_id = $uid OR a.course_id IN ($cstr) ORDER BY a.due_date DESC LIMIT 8");
            if (empty($rows)) chat_out("You haven't posted any assignments yet." . as_of());
            $r = "Your assignments:\n";
            foreach ($rows as $a) $r .= "• {$a['title']} ({$a['course_title']}) — " . (!empty($a['due_date']) ? 'due ' . date('d M', strtotime($a['due_date'])) : 'no due date') . " · {$a['subs']}/{$a['enrolled']} submitted" . ($a['flagged'] ? " · ⚠ {$a['flagged']} flagged HIGH RISK for plagiarism" : '') . "\n";
            chat_out($r . as_of());

        case 'students':
        case 'courses':
            $rows = q_all($conn, "SELECT c.title, (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS n FROM courses c WHERE c.id IN ($cstr) ORDER BY c.title");
            if (empty($rows)) chat_out("No courses are assigned to you yet. Ask the admin to assign you a course or a unit." . as_of());
            $tot = q_one($conn, "SELECT COUNT(DISTINCT student_id) AS n FROM enrollments WHERE course_id IN ($cstr)");
            $r = "You teach " . count($rows) . " course(s) with " . intval($tot['n'] ?? 0) . " distinct student(s):\n";
            foreach ($rows as $c) $r .= "• {$c['title']} — {$c['n']} enrolled\n";
            chat_out($r . as_of(), ['At-risk students', 'My units']);

        case 'units':
            $u = l_unit_ids($conn, $uid);
            if (empty($u)) chat_out("No units are assigned to you at unit level — you may still be assigned whole courses. Try 'my courses'." . as_of());
            $r = "You teach " . count($u) . " unit(s):\n";
            foreach ($u as $x) $r .= "• {$x['title']}" . ($x['unit_code'] ? " ({$x['unit_code']})" : '') . " — {$x['course_title']}\n";
            chat_out($r . as_of(), ['Marks', 'Attendance']);

        case 'marks':
            $u = l_unit_ids($conn, $uid); $uids = ids_in(array_column($u, 'id'));
            $tot = q_one($conn, "SELECT COUNT(*) AS n FROM unit_assessments WHERE unit_id IN ($uids)");
            $ent = q_one($conn, "SELECT COUNT(*) AS n FROM unit_marks um JOIN unit_assessments ua ON ua.id = um.assessment_id WHERE ua.unit_id IN ($uids) AND um.mark IS NOT NULL");
            chat_out("Marks Manager lets you define assessments (CATs, coursework, exams) per unit and enter each student's score. Right now: " . intval($tot['n'] ?? 0) . " assessment(s) defined and " . intval($ent['n'] ?? 0) . " mark(s) entered across " . count($u) . " unit(s). Open 'Marks Manager', choose a unit, then 'Save All Marks'." . as_of(), ['Attendance', 'My units']);

        case 'attendance':
            $u = l_unit_ids($conn, $uid); $uids = ids_in(array_column($u, 'id'));
            $ses = q_one($conn, "SELECT COUNT(*) AS n FROM attendance_sessions WHERE unit_id IN ($uids)");
            $bar = q_all($conn, "SELECT u.full_name, v.unit_title, v.absence_pct FROM v_student_attendance v JOIN users u ON u.id = v.student_id WHERE v.unit_id IN ($uids) AND v.exam_status = 'BARRED' ORDER BY v.absence_pct DESC LIMIT 8");
            $r = "You've created " . intval($ses['n'] ?? 0) . " attendance session(s) across your units.\n";
            if (empty($bar)) $r .= "No student is currently barred (over the 33.33% absence limit).";
            else { $r .= count($bar) . " student-unit record(s) barred from exams:\n"; foreach ($bar as $b) $r .= "• {$b['full_name']} — {$b['unit_title']} (" . pct1($b['absence_pct']) . " absent)\n"; }
            chat_out($r . as_of(), ['At-risk students', 'My units']);

        case 'schedule':
            $sc = q_all($conn, "SELECT s.title, s.meet_date, s.meet_time, c.title AS course_title FROM schedules s LEFT JOIN courses c ON c.id = s.course_id
                                WHERE s.lecturer_id = $uid AND (s.meet_date > CURDATE() OR (s.meet_date = CURDATE() AND s.meet_time >= CURTIME()))
                                ORDER BY s.meet_date, s.meet_time LIMIT 6");
            if (empty($sc)) chat_out("You have no upcoming sessions scheduled. Create one under My Schedule → New Session; enrolled students see it automatically." . as_of());
            $r = "Your upcoming sessions:\n";
            foreach ($sc as $s) $r .= "• {$s['title']} — " . date('D d M', strtotime($s['meet_date'])) . " at " . date('h:i A', strtotime($s['meet_time'])) . ($s['course_title'] ? " ({$s['course_title']})" : ' (all students)') . "\n";
            chat_out($r . as_of());

        case 'leave':
            $n = 0; $chk = mysqli_query($conn, "SHOW TABLES LIKE 'hr_leave_requests'");
            if ($chk && mysqli_num_rows($chk) > 0) {
                $x = q_one($conn, "SELECT COUNT(*) AS n FROM hr_leave_requests hl JOIN hr_staff hs ON hs.id = hl.staff_id WHERE hs.user_id = $uid AND hl.status = 'pending'");
                $n = intval($x['n'] ?? 0);
            }
            chat_out("Apply from 'Apply for Leave' in the sidebar (type, dates, reason); HR reviews it. " . ($n > 0 ? "You have $n pending request(s) awaiting review." : "You have no pending requests.") . as_of());

        case 'materials':
            $x = q_one($conn, "SELECT COUNT(*) AS n, SUM(type = 'pdf') AS pdfs, SUM(type = 'video') AS vids FROM materials WHERE lecturer_id = $uid");
            chat_out("You've uploaded " . intval($x['n'] ?? 0) . " material(s) (" . intval($x['pdfs'] ?? 0) . " PDF, " . intval($x['vids'] ?? 0) . " video). Upload PDFs through 'Upload Resource' — the quiz generator reads them to write questions from your own notes." . as_of(), ['My quizzes']);

        case 'profile':
            $p = q_one($conn, "SELECT is_professor, academic_rank, years_teaching, highest_qualification FROM lecturer_profiles WHERE user_id = $uid");
            chat_out(empty($p)
                ? "You haven't filled in your profile yet. Open 'My Profile' in the sidebar to add your rank, whether you're a professor, experience, qualifications, office hours and more."
                : "Your profile: " . ($p['is_professor'] ? 'Professor' : 'not a professor') . ($p['academic_rank'] ? " · {$p['academic_rank']}" : '') . ($p['years_teaching'] !== null ? " · {$p['years_teaching']} yr teaching" : '') . ($p['highest_qualification'] ? " · {$p['highest_qualification']}" : '') . ". Update it any time from 'My Profile'.");

        case 'help':
            chat_out("I answer live from the system:\n• At-risk students — names + attendance/quiz/assignment numbers\n• Class performance · Top students\n• Recommendation feedback — how many students found them helpful\n• My quizzes · Assignments (with plagiarism flags)\n• My courses/units · Attendance (exam bar) · Marks\n• My schedule · Leave · Materials · My profile", lecturer_chips());

        default:
            chat_out("I'm not sure I understood that, $name. Try one of these:", lecturer_chips());
    }
}

// ═════════════════════════════════════════════════════════════════════
//  ADMIN
// ═════════════════════════════════════════════════════════════════════
function admin_chips(): array {
    return ['System overview', 'Risk overview', 'Recommendation feedback', 'Fees overview', 'Pending leave'];
}

function handle_admin($conn, int $uid, string $msg): void {
    $name = first_name($_SESSION['user_name'] ?? 'Admin');

    if (is_greeting($msg)) chat_out("Hello $name! Ask me for a system overview, institution-wide risk, recommendation feedback, fees or pending leave requests.", admin_chips());

    $intent = pick_intent($msg, [
        'overview' => ['overview','system overview','summary','statistics','stats','how many users','totals','dashboard'],
        'users'    => ['users','students','lecturers','accounts','staff','how many students','how many lecturers'],
        'risk'     => ['risk','at risk','at-risk','struggling','failing','performance','risk overview'],
        'reco'     => ['recommendation','recommendations','helpful','feedback','acceptance','evaluation'],
        'fees'     => ['fee','fees','payments','collected','outstanding','revenue','arrears','finance'],
        'leave'    => ['leave','pending leave','leave requests'],
        'courses'  => ['course','courses','enrolment','enrollment','enrollments','units'],
        'quizzes'  => ['quiz','quizzes','questions'],
        'help'     => ['help','what can you do','guide'],
    ]);

    switch ($intent) {
        case 'users':
            $rows = q_all($conn, "SELECT role, COUNT(*) AS n FROM users GROUP BY role ORDER BY n DESC");
            $r = "Accounts by role:\n"; $tot = 0;
            foreach ($rows as $x) { $r .= "• " . ucwords(str_replace('_', ' ', $x['role'])) . ": {$x['n']}\n"; $tot += $x['n']; }
            chat_out($r . "Total: $tot" . as_of(), admin_chips());

        case 'risk':
            $risk = getRiskViewWithEngineFlags($conn, null, null); $d = $risk['distribution'];
            if (empty($risk['students'])) chat_out("No enrolled students to assess yet." . as_of());
            $r = "Institution-wide student performance (" . count($risk['students']) . " students):\n• Excellent: {$d['excellent']}\n• Good: {$d['good']}\n• At risk: {$d['at_risk']}\n• Critical: {$d['critical']}\n";
            $flag = array_values(array_filter($risk['students'], fn($s) => in_array($s['band'], ['at_risk', 'critical'])));
            if ($flag) { $r .= "\nLowest scoring:\n"; foreach (array_slice($flag, 0, 5) as $s) $r .= "• {$s['name']} — " . $s['risk'] . " risk, combined " . pct1($s['composite']) . "\n"; }
            chat_out($r . "\nFull list: Risk Dashboard in the sidebar." . as_of(), ['Recommendation feedback', 'System overview']);

        case 'reco':
            $e = getRecommendationEvaluation($conn, null);
            chat_out("Recommendation evaluation (institution-wide):\n• Generated: {$e['generated']}\n• Marked helpful: {$e['helpful']}\n• Marked not helpful: {$e['not_helpful']}\n• Not yet rated: {$e['unrated']}\n• Acceptance rate: " . pct1($e['acceptance_pct']) . " (of rated)\n\nStudents rate recommendations on their Recommendations page; this updates live." . as_of(), ['Risk overview', 'System overview']);

        case 'fees':
            $b = q_one($conn, "SELECT COALESCE(SUM(net_amount),0) AS billed FROM student_fee_assignments WHERE status <> 'waived'");
            $p = q_one($conn, "SELECT COALESCE(SUM(amount_paid),0) AS paid FROM fee_payments");
            $o = q_one($conn, "SELECT COUNT(*) AS n FROM student_fee_assignments WHERE status IN ('pending','partial','overdue') AND due_date IS NOT NULL AND due_date < CURDATE()");
            $billed = floatval($b['billed']); $paid = floatval($p['paid']);
            chat_out("Fees (all students):\n• Billed: " . money($billed) . "\n• Collected: " . money($paid) . "\n• Outstanding: " . money(max(0, $billed - $paid)) . "\n• Collection rate: " . ($billed > 0 ? pct1(min(100, $paid / $billed * 100)) : 'n/a') . "\n• Overdue fee items: " . intval($o['n'] ?? 0) . as_of(), admin_chips());

        case 'leave':
            $chk = mysqli_query($conn, "SHOW TABLES LIKE 'hr_leave_requests'");
            if (!$chk || mysqli_num_rows($chk) === 0) chat_out("The leave module isn't set up yet.");
            $x = q_one($conn, "SELECT SUM(status='pending') AS p, SUM(status='approved') AS a, SUM(status='rejected') AS r FROM hr_leave_requests");
            chat_out("Leave requests: " . intval($x['p'] ?? 0) . " pending, " . intval($x['a'] ?? 0) . " approved, " . intval($x['r'] ?? 0) . " rejected." . as_of(), admin_chips());

        case 'courses':
            $c = q_one($conn, "SELECT (SELECT COUNT(*) FROM courses) AS courses, (SELECT COUNT(*) FROM course_units) AS units, (SELECT COUNT(*) FROM enrollments) AS enr");
            $rows = q_all($conn, "SELECT c.title, (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS n, u.full_name AS lecturer FROM courses c LEFT JOIN users u ON u.id = c.lecturer_id ORDER BY n DESC LIMIT 8");
            $r = "{$c['courses']} course(s), {$c['units']} unit(s), {$c['enr']} enrolment(s).\n";
            foreach ($rows as $x) $r .= "• {$x['title']} — {$x['n']} enrolled" . ($x['lecturer'] ? " · lecturer {$x['lecturer']}" : ' · no lecturer assigned') . "\n";
            chat_out($r . as_of(), admin_chips());

        case 'quizzes':
            $x = q_one($conn, "SELECT COUNT(*) AS t, SUM(is_active=1) AS pub, (SELECT COUNT(*) FROM results) AS attempts FROM quizzes");
            chat_out("Quizzes: " . intval($x['t'] ?? 0) . " total, " . intval($x['pub'] ?? 0) . " published, " . intval($x['attempts'] ?? 0) . " student attempt(s) recorded." . as_of(), admin_chips());

        case 'overview':
            $u = q_one($conn, "SELECT SUM(role='student') AS s, SUM(role='lecturer') AS l, COUNT(*) AS t FROM users");
            $c = q_one($conn, "SELECT (SELECT COUNT(*) FROM courses) AS courses, (SELECT COUNT(*) FROM enrollments) AS enr, (SELECT COUNT(*) FROM quizzes WHERE is_active=1) AS quizzes, (SELECT COUNT(*) FROM results) AS attempts");
            $risk = getRiskViewWithEngineFlags($conn, null, null); $d = $risk['distribution']; $e = getRecommendationEvaluation($conn, null);
            chat_out("System overview:\n• Users: " . intval($u['t']) . " (" . intval($u['s']) . " students, " . intval($u['l']) . " lecturers)\n• Courses: {$c['courses']} · Enrolments: {$c['enr']}\n• Published quizzes: {$c['quizzes']} · Attempts: {$c['attempts']}\n• At risk / critical students: " . ($d['at_risk'] + $d['critical']) . "\n• Recommendation acceptance: " . pct1($e['acceptance_pct']) . " ({$e['helpful']} helpful of " . ($e['helpful'] + $e['not_helpful']) . " rated)" . as_of(), admin_chips());

        case 'help':
            chat_out("I answer live from the system:\n• System overview · Users\n• Risk overview — institution-wide bands\n• Recommendation feedback\n• Fees overview · Pending leave\n• Courses · Quizzes", admin_chips());

        default:
            chat_out("I'm not sure I understood that, $name. Try one of these:", admin_chips());
    }
}

// ─────────────────────────────────────────────────────────────────────
//  Dispatch
// ─────────────────────────────────────────────────────────────────────
if ($role === 'student')       handle_student($conn, $user_id, $msg);
elseif ($role === 'lecturer')  handle_lecturer($conn, $user_id, $msg);
elseif ($role === 'admin')     handle_admin($conn, $user_id, $msg);
else chat_out("Hello! I'm the SmartLMS assistant. I'm available for students, lecturers and administrators.");
