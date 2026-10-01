<?php
/**
 * chatbot.php — SmartLMS Rule-Based AI Chatbot
 * Pure PHP, no external API. Uses live DB data to answer questions.
 * Called via fetch() POST with { message: "..." }
 */

ob_start();
error_reporting(0);
ini_set('display_errors', 0);

include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['reply' => 'Please log in to use the assistant.']);
    exit();
}

$user_id = intval($_SESSION['user_id']);
$role    = strtolower($_SESSION['role'] ?? 'student');
$raw_msg = trim($_POST['message'] ?? '');
$msg     = strtolower($raw_msg);

if (empty($msg)) {
    echo json_encode(['reply' => 'Please type a message for me to help you.']);
    exit();
}

// ─────────────────────────────────────────────────────────────────────
//  HELPER: fetch student data once
// ─────────────────────────────────────────────────────────────────────
function getStudentData($conn, $user_id) {
    $s = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT full_name, career_path FROM users WHERE id = $user_id"
    ));

    $enr = mysqli_query($conn,
        "SELECT c.title FROM courses c JOIN enrollments e ON c.id = e.course_id WHERE e.student_id = $user_id"
    );
    $courses = [];
    while ($r = mysqli_fetch_assoc($enr)) $courses[] = $r['title'];

    $mast = mysqli_query($conn,
        "SELECT skill_name, mastery_level FROM student_mastery WHERE student_id = $user_id ORDER BY mastery_level DESC"
    );
    $mastery = [];
    while ($r = mysqli_fetch_assoc($mast)) $mastery[$r['skill_name']] = floatval($r['mastery_level']);

    $last_res = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT score, action_taken, predicted_grade FROM results WHERE student_id = $user_id ORDER BY id DESC LIMIT 1"
    ));

    $notif = mysqli_query($conn,
        "SELECT message FROM notifications WHERE user_id = $user_id AND is_read = 0 ORDER BY created_at DESC LIMIT 3"
    );
    $notifications = [];
    while ($r = mysqli_fetch_assoc($notif)) $notifications[] = $r['message'];

    // Upcoming schedules
    $enr_ids_res = mysqli_query($conn, "SELECT course_id FROM enrollments WHERE student_id = $user_id");
    $cids = [];
    while ($r = mysqli_fetch_assoc($enr_ids_res)) $cids[] = intval($r['course_id']);
    $schedules = [];
    if (!empty($cids)) {
        $ids_str = implode(',', $cids);
        $sch = mysqli_query($conn,
            "SELECT s.title, s.meet_date, s.meet_time, u.full_name AS lecturer
             FROM schedules s JOIN users u ON u.id = s.lecturer_id
             WHERE (s.course_id IN ($ids_str) OR s.course_id IS NULL)
               AND s.meet_date >= CURDATE()
             ORDER BY s.meet_date ASC LIMIT 3"
        );
        while ($r = mysqli_fetch_assoc($sch)) $schedules[] = $r;
    }

    $avgMastery = count($mastery) > 0 ? round(array_sum($mastery) / count($mastery), 1) : 0;
    $weakest    = !empty($mastery) ? array_key_last($mastery) : null;
    $strongest  = !empty($mastery) ? array_key_first($mastery) : null;

    // ── Assignments (pending vs submitted) ──────────────────────────────
    $assign_pending   = [];
    $assign_submitted = [];
    if (!empty($cids)) {
        $ids_a = implode(',', $cids);
        $asgn_res = mysqli_query($conn,
            "SELECT a.id, a.title, a.due_date, c.title AS course_title,
                    s.id AS submission_id,
                    pr.verdict, pr.overall_score
             FROM assignments a
             JOIN courses c ON c.id = a.course_id
             LEFT JOIN assignment_submissions s
                   ON s.assignment_id = a.id AND s.student_id = $user_id
             LEFT JOIN plagiarism_reports pr ON pr.submission_id = s.id
             WHERE a.course_id IN ($ids_a)
             ORDER BY a.due_date ASC"
        );
        if ($asgn_res) {
            while ($r = mysqli_fetch_assoc($asgn_res)) {
                if ($r['submission_id']) {
                    $assign_submitted[] = $r;
                } else {
                    $assign_pending[] = $r;
                }
            }
        }
    }

    return compact('s','courses','mastery','last_res','notifications','schedules',
                   'avgMastery','weakest','strongest','assign_pending','assign_submitted');
}

// ─────────────────────────────────────────────────────────────────────
//  HELPER: fetch lecturer data once
// ─────────────────────────────────────────────────────────────────────
function getLecturerData($conn, $user_id) {
    $courses_res = mysqli_query($conn,
        "SELECT DISTINCT id, title FROM courses
         WHERE lecturer_id = $user_id
            OR id = (SELECT course_id FROM users WHERE id = $user_id AND course_id IS NOT NULL LIMIT 1)
            OR id IN (SELECT course_id FROM course_units WHERE lecturer_id = $user_id)"
    );
    $courses = [];
    $cids    = [];
    while ($r = mysqli_fetch_assoc($courses_res)) { $courses[] = $r['title']; $cids[] = $r['id']; }

    $totalStudents = 0;
    $avgMastery    = 0;
    $atRisk        = 0;
    $weakSkill     = 'N/A';
    $quizCount     = 0;

    if (!empty($cids)) {
        $ids = implode(',', $cids);
        $ts  = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(DISTINCT student_id) as t FROM enrollments WHERE course_id IN ($ids)"
        ));
        $totalStudents = intval($ts['t']);

        $am = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT AVG(sm.mastery_level) as avg_m FROM student_mastery sm
             JOIN enrollments e ON sm.student_id = e.student_id WHERE e.course_id IN ($ids)"
        ));
        $avgMastery = round(floatval($am['avg_m'] ?? 0), 1);

        $ar = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(DISTINCT sm.student_id) as r FROM student_mastery sm
             JOIN enrollments e ON sm.student_id = e.student_id
             WHERE e.course_id IN ($ids) AND sm.mastery_level < 40"
        ));
        $atRisk = intval($ar['r']);

        $ws = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT sm.skill_name, AVG(sm.mastery_level) as avg_m FROM student_mastery sm
             JOIN enrollments e ON sm.student_id = e.student_id
             WHERE e.course_id IN ($ids) GROUP BY sm.skill_name ORDER BY avg_m ASC LIMIT 1"
        ));
        if ($ws) $weakSkill = $ws['skill_name'] . ' (' . round($ws['avg_m'],1) . '%)';

        $qc = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) as t FROM quizzes WHERE course_id IN ($ids)"
        ));
        $quizCount = intval($qc['t']);
    }

    $mats = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) as t FROM materials WHERE lecturer_id = $user_id"
    ));
    $totalMaterials = intval($mats['t']);

    // ── Units this lecturer teaches (directly, or via whole-course assignment) ──
    $units_res = mysqli_query($conn,
        "SELECT cu.id, cu.title, cu.unit_code, c.title AS course_title
         FROM course_units cu
         JOIN courses c ON c.id = cu.course_id
         WHERE cu.lecturer_id = $user_id OR c.lecturer_id = $user_id
         ORDER BY c.title, cu.title"
    );
    $units = [];
    $uids  = [];
    while ($u = mysqli_fetch_assoc($units_res)) { $units[] = $u['title'] . ' (' . $u['course_title'] . ')'; $uids[] = $u['id']; }
    $totalUnits = count($units);

    // ── Marks entered / pending across all units ──
    $marksEntered = 0;
    $marksTotal   = 0;
    if (!empty($uids)) {
        $uidsStr = implode(',', $uids);
        $mtot = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) as t FROM unit_assessments WHERE unit_id IN ($uidsStr)"
        ));
        $marksTotal = intval($mtot['t'] ?? 0);
        $ment = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) as t FROM unit_marks um
             JOIN unit_assessments ua ON ua.id = um.assessment_id
             WHERE ua.unit_id IN ($uidsStr) AND um.mark IS NOT NULL"
        ));
        $marksEntered = intval($ment['t'] ?? 0);
    }

    // ── Attendance sessions created by this lecturer ──
    $attSessions = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) as t FROM attendance_sessions WHERE lecturer_id = $user_id"
    ));
    $totalAttSessions = intval($attSessions['t'] ?? 0);

    // ── Students currently barred from exams (>33.33% absence) in this lecturer's units ──
    $barredCount = 0;
    if (!empty($uids)) {
        $uidsStr = implode(',', $uids);
        $barred_res = mysqli_query($conn,
            "SELECT ar.student_id, ats.unit_id,
                    COUNT(*) AS total_sessions,
                    SUM(CASE WHEN ar.status='absent' THEN 1 ELSE 0 END) AS absences
             FROM attendance_records ar
             JOIN attendance_sessions ats ON ats.id = ar.session_id
             WHERE ats.unit_id IN ($uidsStr)
             GROUP BY ar.student_id, ats.unit_id
             HAVING total_sessions > 0 AND (absences / total_sessions) > 0.3333"
        );
        if ($barred_res) $barredCount = mysqli_num_rows($barred_res);
    }

    // ── Pending leave requests for this lecturer ──
    $myPendingLeave = 0;
    $leave_check = mysqli_query($conn, "SHOW TABLES LIKE 'hr_leave_requests'");
    if ($leave_check && mysqli_num_rows($leave_check) > 0) {
        $lv = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) as t FROM hr_leave_requests hl
             JOIN hr_staff hs ON hs.id = hl.staff_id
             WHERE hs.user_id = $user_id AND hl.status = 'pending'"
        ));
        $myPendingLeave = intval($lv['t'] ?? 0);
    }

    return compact('courses','totalStudents','avgMastery','atRisk','weakSkill','quizCount','totalMaterials',
                    'units','totalUnits','marksEntered','marksTotal','totalAttSessions','barredCount','myPendingLeave');
}

// ─────────────────────────────────────────────────────────────────────
//  RULE ENGINE — matches intent keywords → generates reply
// ─────────────────────────────────────────────────────────────────────
$reply = '';

function contains($msg, array $keywords): bool {
    foreach ($keywords as $kw) {
        if (str_contains($msg, $kw)) return true;
    }
    return false;
}

// ══ STUDENT RULES ════════════════════════════════════════════════════
if ($role === 'student') {
    $d = getStudentData($conn, $user_id);
    $name = explode(' ', $d['s']['full_name'])[0];

    // ── Greeting ──
    if (contains($msg, ['hello','hi','hey','good morning','good afternoon','good evening','hola'])) {
        $avg = $d['avgMastery'];
        $reply = "Hello $name! I'm your SmartLMS assistant. Your current average mastery is {$avg}%. ";
        $reply .= empty($d['courses'])
            ? "You haven't enrolled in any courses yet — head to Courses to get started."
            : "You're enrolled in: " . implode(', ', $d['courses']) . ". How can I help you today?";
    }

    // ── Performance / Score ──
    elseif (contains($msg, ['my score','my result','my grade','how did i do','performance','last quiz'])) {
        if ($d['last_res']) {
            $score  = $d['last_res']['score'];
            $action = $d['last_res']['action_taken'];
            $grade  = $d['last_res']['predicted_grade'];
            $advice = match($action) {
                'advance'  => "Excellent — you advanced to the next level!",
                'retry'    => "Good effort. The system recommends reviewing and retrying.",
                'remedial' => "Foundational gaps were detected. Remedial content has been assigned.",
                default    => "Keep practising to improve."
            };
            $reply = "Your last quiz score was {$score}% (Predicted Grade: {$grade}). {$advice}";
        } else {
            $reply = "You haven't submitted any quizzes yet, $name. Take your first quiz to see your results here!";
        }
    }

    // ── Mastery ──
    elseif (contains($msg, ['mastery','skill','level','strength','weak'])) {
        if (!empty($d['mastery'])) {
            $lines = [];
            foreach ($d['mastery'] as $skill => $level) {
                $status = $level >= 70 ? 'Strong' : ($level >= 40 ? 'Developing' : 'Needs Work');
                $lines[] = "{$skill}: {$level}% ({$status})";
            }
            $reply = "Here is your current skill mastery breakdown:\n" . implode("\n", $lines);
            if ($d['weakest']) {
                $reply .= "\n\nFocus area: Your weakest skill is **{$d['weakest']}** at " . $d['mastery'][$d['weakest']] . "%. I recommend reviewing related materials.";
            }
        } else {
            $reply = "No mastery data yet. Complete a quiz to start tracking your skill levels.";
        }
    }

    // ── Courses ──
    elseif (contains($msg, ['my course','enrolled','what course','which course','course list'])) {
        if (!empty($d['courses'])) {
            $reply = "You are currently enrolled in: " . implode(', ', $d['courses']) . ". Go to My Enrolled in the sidebar to access materials and quizzes.";
        } else {
            $reply = "You are not enrolled in any courses yet. Click 'All Courses' in the sidebar to browse and enrol.";
        }
    }

    // ── Quiz help ──
    elseif (contains($msg, ['quiz','test','assessment','take a quiz','how to quiz'])) {
        $reply = "To take a quiz: scroll down on your Overview page and you'll see Available Quizzes. Click 'Take Quiz' on any published quiz. After submitting, you'll get your score, grade, and skill feedback instantly.";
        if (!empty($d['courses'])) {
            $reply .= " Your courses ({$d['courses'][0]}) should have quizzes published by your lecturer.";
        }
    }

    // ── Schedule / Meetings ──
    elseif (contains($msg, ['schedule','meeting','class','session','upcoming','when is'])) {
        if (!empty($d['schedules'])) {
            $reply = "Here are your upcoming scheduled sessions:\n";
            foreach ($d['schedules'] as $s) {
                $reply .= "• {$s['title']} — " . date('D, d M Y', strtotime($s['meet_date'])) . " at " . date('h:i A', strtotime($s['meet_time'])) . " (by {$s['lecturer']})\n";
            }
            $reply .= "\nYou can also view your full schedule by clicking 'My Schedule' in the sidebar.";
        } else {
            $reply = "No upcoming sessions scheduled yet. Check back later or click 'My Schedule' in the sidebar for more details.";
        }
    }

    // ── Notifications ──
    elseif (contains($msg, ['notification','notify','message','unread','alert'])) {
        if (!empty($d['notifications'])) {
            $reply = "You have " . count($d['notifications']) . " unread notification(s):\n";
            foreach ($d['notifications'] as $n) $reply .= "• $n\n";
        } else {
            $reply = "You have no unread notifications right now, $name.";
        }
    }

    // ── Career guidance ──
    elseif (contains($msg, ['career','job','future','path','recommend','software','data','cyber','ais'])) {
        $career = $d['s']['career_path'] ?? 'Software Development';
        $tips   = match(true) {
            str_contains(strtolower($career), 'data')   => "Focus on strengthening Core Theory (SQL, statistics) and Practical Application (Python, data visualisation). Data Science roles require strong analytical skills.",
            str_contains(strtolower($career), 'cyber')  => "Prioritise Practical Application (ethical hacking, security tools) and Core Theory (networks, cryptography). Certifications like CEH or CompTIA Security+ are valuable.",
            str_contains(strtolower($career), 'ais')    => "Focus on Core Theory (accounting, information systems) and Practical Application (ERP systems, business analytics). AIS roles bridge finance and IT.",
            default                                     => "For Software Development, strengthen Practical Application (coding projects) and General Aptitude (problem-solving). Build a portfolio to stand out."
        };
        $reply = "Your career path is set to **{$career}**. {$tips}";
        if ($d['weakest']) {
            $reply .= " Currently, your weakest area is {$d['weakest']} — prioritising this will directly impact your career readiness.";
        }
    }

    // ── How to use the system ──
    elseif (contains($msg, ['how to','navigate','use','find','where','help','guide','tutorial'])) {
        $reply = "Here's how to navigate SmartLMS:\n"
               . "• Overview — Your dashboard with stats, AI advisor, and quizzes\n"
               . "• All Courses — Browse and enrol in available courses\n"
               . "• My Enrolled — Access your courses and their materials\n"
               . "• My Schedule — View upcoming sessions and meetings\n"
               . "• AI Advisor — Personalised performance insights on your overview page\n"
               . "\nJust click any item in the left sidebar to navigate.";
    }

    // ── Materials ──
    elseif (contains($msg, ['material','pdf','video','note','lecture','download','resource'])) {
        $reply = "To access course materials: go to 'My Enrolled' in the sidebar → click 'Enter Course' on any of your courses. You'll find all uploaded PDFs and video lectures there. PDFs can be downloaded, videos play directly in the browser.";
    }

    // ── Improvement tips ──
    elseif (contains($msg, ['improve','better','tips','advice','study','help me','struggling'])) {
        $avg = $d['avgMastery'];
        if ($avg >= 70) {
            $reply = "You're doing well with {$avg}% average mastery, $name! To reach expert level: tackle advanced quizzes, review complex materials, and aim for 90%+ on your next assessment.";
        } elseif ($avg >= 40) {
            $reply = "You're making progress at {$avg}% average mastery. To improve: spend 20 minutes daily reviewing your weakest skill";
            if ($d['weakest']) $reply .= " ({$d['weakest']})";
            $reply .= ", retake quizzes you didn't pass, and watch the video lectures for better understanding.";
        } else {
            $reply = "Your mastery is at {$avg}% — let's turn that around! Start with the foundational PDF materials in your enrolled courses. Take quizzes even if you're not confident — the AI will guide your next step based on your answers.";
        }
    }

    // ── Assignments ──
    elseif (contains($msg, ['assignment','homework','task','due date','deadline','submit','submission','plagiarism report'])) {
        $pending = count($d['assign_pending']);
        $done    = count($d['assign_submitted']);
        $total   = $pending + $done;

        if ($total === 0) {
            $reply = "No assignments have been posted yet, $name. Your lecturers will post assignments here — you'll get a notification when one is published.";
        } elseif ($pending > 0) {
            $reply = "You have $pending pending assignment(s) still to submit:\n";
            foreach ($d['assign_pending'] as $a) {
                $due     = !empty($a['due_date']) ? 'due ' . date('d M Y', strtotime($a['due_date'])) : 'no due date set';
                $overdue = !empty($a['due_date']) && strtotime($a['due_date']) < time() ? ' ⚠ OVERDUE' : '';
                $reply  .= "• {$a['title']} ({$a['course_title']}) — {$due}{$overdue}\n";
            }
            $reply .= "\nClick 'Assignments' in the sidebar to submit your work.";
            if ($done > 0) $reply .= " You have already submitted $done assignment(s).";
        } else {
            $reply  = "All $done assignment(s) submitted — great work, $name!\n";
            $risky  = array_filter($d['assign_submitted'], fn($a) => ($a['verdict'] ?? '') === 'HIGH RISK');
            if (!empty($risky)) {
                $reply .= "\n⚠ " . count($risky) . " submission(s) flagged HIGH RISK for plagiarism. Visit the Assignments tab to review and consider resubmitting with original work.";
            } else {
                $reply .= "Your plagiarism reports are all clear. Check the Assignments tab for full results.";
            }
        }
    }

    // ── Default ──
    else {
        $topics = [
            'my score or results' => 'ask about your performance',
            'my mastery' => 'see your skill levels',
            'my courses' => 'see enrolled courses',
            'quiz help' => 'learn how to take a quiz',
            'assignment' => 'see pending assignments and reports',
            'my schedule' => 'see upcoming sessions',
            'career advice' => 'get career guidance',
            'improve' => 'get study tips',
            'help' => 'see navigation guide'
        ];
        $reply = "I'm not sure I understand that, $name. I can help you with:\n";
        foreach ($topics as $kw => $desc) $reply .= "• Type \"$kw\" — $desc\n";
    }
}

// ══ LECTURER RULES ════════════════════════════════════════════════════
elseif ($role === 'lecturer') {
    $d    = getLecturerData($conn, $user_id);
    $name = explode(' ', $_SESSION['user_name'])[0];

    // ── Greeting ──
    if (contains($msg, ['hello','hi','hey','good morning','good afternoon','good evening'])) {
        $reply = "Hello {$name}! You have {$d['totalStudents']} students enrolled across " . count($d['courses']) . " course(s). Class average mastery is {$d['avgMastery']}%. How can I assist you?";
    }

    // ── Marks Manager ──
    elseif (contains($msg, ['marks','mark manager','enter marks','cat','coursework','exam mark','grading','grade entry'])) {
        $reply = "Marks Manager lets you enter CAT, coursework, and exam marks for any unit you teach — go to 'Marks Manager' in the sidebar, pick a unit from the tabs at the top (grouped by course), define assessment components if not already set, then type each student's score and click 'Save All Marks'.";
        $reply .= " You've entered {$d['marksEntered']} of {$d['marksTotal']} assessment mark(s) so far across {$d['totalUnits']} unit(s).";
        if (!empty($d['units'])) {
            $reply .= "\n\nUnits you can manage marks for: " . implode(', ', $d['units']) . ".";
        }
    }

    // ── Attendance Manager / exam bar ──
    elseif (contains($msg, ['attendance','absent','present','exam bar','barred','33.33','session'])) {
        $reply = "Attendance Manager lets you create a session (title + date) for any unit you teach, then mark each student Present or Absent. Students whose absences exceed 33.33% of total sessions in a unit are automatically flagged as exam-barred for that unit.";
        $reply .= " You've created {$d['totalAttSessions']} session(s) so far.";
        if ($d['barredCount'] > 0) {
            $reply .= " Currently {$d['barredCount']} student-unit record(s) are over the 33.33% absence threshold and exam-barred — check the Attendance tab for details.";
        } else {
            $reply .= " No students are currently exam-barred in your units.";
        }
    }

    // ── Apply for Leave ──
    elseif (contains($msg, ['leave','vacation','time off','sick day','apply for leave'])) {
        $reply = "You can apply for leave from the 'Apply for Leave' link in your sidebar — choose a leave type, dates, and reason, then submit. HR will review and approve or reject it.";
        $reply .= $d['myPendingLeave'] > 0
            ? " You currently have {$d['myPendingLeave']} pending leave request(s) awaiting review."
            : " You have no pending leave requests right now.";
    }

    // ── Units taught ──
    elseif (contains($msg, ['my unit','which unit','unit list','units i teach'])) {
        $reply = !empty($d['units'])
            ? "You teach {$d['totalUnits']} unit(s): " . implode(', ', $d['units']) . ". You can manage marks and attendance for every one of them, regardless of how many courses they belong to."
            : "No units are currently assigned to you at unit level — but you may still be assigned a whole course. Try asking 'my courses'.";
    }

    // ── Students at risk ──
    elseif (contains($msg, ['at risk','struggling','failing','weak student','low score','poor'])) {
        if ($d['atRisk'] > 0) {
            $reply = "{$d['atRisk']} student(s) are currently below 40% mastery — these are your at-risk students. The weakest class skill is {$d['weakSkill']}. Recommended action: schedule a revision session or publish a beginner-level quiz targeting that skill.";
        } else {
            $reply = "No students are currently below the 40% mastery threshold. Your class is performing well overall with {$d['avgMastery']}% average mastery.";
        }
    }

    // ── Class performance ──
    elseif (contains($msg, ['class performance','how is my class','average','mastery','overall'])) {
        $status = $d['avgMastery'] >= 70 ? "above average — well done" : ($d['avgMastery'] >= 50 ? "at an acceptable level" : "below average and may need intervention");
        $reply  = "Class average mastery is {$d['avgMastery']}%, which is {$status}. {$d['atRisk']} student(s) are at risk. The weakest skill area across your class is {$d['weakSkill']}.";
    }

    // ── Quiz / assessment ──
    elseif (contains($msg, ['quiz','generate quiz','assessment','test','question'])) {
        $reply = "To generate a quiz: go to 'Quiz Manager' in the sidebar → click 'Generate New Quiz'. Select the course, topic, skill (Core Theory / General Aptitude / Practical Application), difficulty, and number of questions. The AI will generate questions based on your uploaded lecture notes. Then publish it to make it visible to students.";
        $reply .= "\n\nYou currently have {$d['quizCount']} quiz(zes) published across your courses.";
    }

    // ── Materials / upload ──
    elseif (contains($msg, ['upload','material','pdf','video','resource','lecture note'])) {
        $reply = "You have {$d['totalMaterials']} material(s) uploaded. To add more: use the Upload button in your dashboard or go to the Resource Manager. Uploading PDFs allows the AI quiz generator to create questions directly from your lecture content.";
    }

    // ── Schedule ──
    elseif (contains($msg, ['schedule','meeting','session','class time','when'])) {
        $reply = "To schedule a meeting or class session: click 'My Schedule' in the sidebar → fill in the title, date, time, and optional meeting link. Students enrolled in your courses will see the session on their schedule page.";
    }

    // ── Courses ──
    elseif (contains($msg, ['my course','which course','course list'])) {
        if (!empty($d['courses'])) {
            $reply = "You are teaching: " . implode(', ', $d['courses']) . ". With {$d['totalStudents']} total enrolled students across {$d['totalUnits']} unit(s).";
        } else {
            $reply = "No courses are currently assigned to you. Contact the admin to be assigned a course.";
        }
    }

    // ── How to use ──
    elseif (contains($msg, ['how to','navigate','help','guide','tutorial','use'])) {
        $reply = "Lecturer navigation guide:\n"
               . "• Overview — Stats, AI Decision Dashboard, class health\n"
               . "• My Courses — View and manage course materials\n"
               . "• Quiz Manager — Generate and publish AI quizzes\n"
               . "• My Schedule — Create and manage class sessions\n"
               . "• Upload Handouts — Add PDFs and videos to your courses\n"
               . "• Marks Manager — Enter CAT, coursework and exam marks per unit\n"
               . "• Attendance — Create sessions, mark present/absent, track the 33.33% exam-bar rule\n"
               . "• Apply for Leave — Submit and track your leave requests\n";
    }

    // ── Default ──
    else {
        $reply = "Hello {$name}! I can help you with:\n"
               . "• 'at risk' — see struggling students\n"
               . "• 'class performance' — overall mastery stats\n"
               . "• 'quiz' — how to generate quizzes\n"
               . "• 'upload' — managing course materials\n"
               . "• 'schedule' — creating class sessions\n"
               . "• 'my courses' — see your assigned courses\n"
               . "• 'marks' — entering CAT/coursework/exam marks\n"
               . "• 'attendance' — sessions, absences and the 33.33% exam bar\n"
               . "• 'leave' — applying for leave and checking status\n"
               . "• 'my units' — see every unit you teach, across any course";
    }
}

else {
    $reply = "Hello! I'm the SmartLMS assistant. I'm available for students and lecturers.";
}

ob_clean();
echo json_encode(['reply' => trim($reply)]);
?>