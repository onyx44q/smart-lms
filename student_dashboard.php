<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'config.php'; 

if (!isset($_SESSION['user_name'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'] ?? 0;

// --- ENROLLMENT & UNENROLLMENT LOGIC ---
if (isset($_POST['enroll_course'])) {
    $course_id = intval($_POST['course_id']);
    $check = mysqli_query($conn, "SELECT * FROM enrollments WHERE student_id = '$user_id' AND course_id = '$course_id'");
    if (mysqli_num_rows($check) == 0) {
        if (mysqli_query($conn, "INSERT INTO enrollments (student_id, course_id) VALUES ('$user_id', '$course_id')")) {
            // AUTO-REGISTER student into ALL units of this course so they can
            // immediately see unit-linked quizzes uploaded by the lecturer.
            $units_res = mysqli_query($conn,
                "SELECT id FROM course_units WHERE course_id = $course_id"
            );
            if ($units_res) {
                while ($u = mysqli_fetch_assoc($units_res)) {
                    $uid = intval($u['id']);
                    mysqli_query($conn,
                        "INSERT IGNORE INTO unit_registrations (student_id, unit_id)
                         VALUES ($user_id, $uid)"
                    );
                }
            }
            header("Location: student_dashboard.php?status=success&msg=Enrolled Successfully!");
            exit();
        } else {
            header("Location: student_dashboard.php?status=error&msg=Enrollment Failed");
            exit();
        }
    }
}

if (isset($_POST['unenroll_course'])) {
    $course_id = intval($_POST['course_id']);
    // Remove unit registrations for all units of this course first
    mysqli_query($conn,
        "DELETE ur FROM unit_registrations ur
         JOIN course_units cu ON cu.id = ur.unit_id
         WHERE ur.student_id = $user_id AND cu.course_id = $course_id"
    );
    if (mysqli_query($conn, "DELETE FROM enrollments WHERE student_id = '$user_id' AND course_id = '$course_id'")) {
        header("Location: student_dashboard.php?status=success&msg=Unenrolled Successfully!");
        exit();
    } else {
        header("Location: student_dashboard.php?status=error&msg=Unenrollment Failed");
        exit();
    }
}

// --- DATA FETCHING ---
$enrolled_query = mysqli_query($conn, "SELECT courses.* FROM courses 
    JOIN enrollments ON courses.id = enrollments.course_id 
    WHERE enrollments.student_id = '$user_id'");

$available_query = mysqli_query($conn, "SELECT * FROM courses WHERE id NOT IN 
    (SELECT course_id FROM enrollments WHERE student_id = '$user_id')");

$enrolledCount = mysqli_num_rows($enrolled_query);

// Build enrolled course list + course objects for tab switcher
$enrolled_ids_res = mysqli_query($conn, "SELECT course_id FROM enrollments WHERE student_id = '$user_id'");
$enrolled_ids = [];
while ($r = mysqli_fetch_assoc($enrolled_ids_res)) $enrolled_ids[] = intval($r['course_id']);

// ── Course-scope: which course is the student currently viewing? ─────
// Default to the first enrolled course so the page is always scoped.
$view_course_id = intval($_GET['course_id'] ?? ($enrolled_ids[0] ?? 0));
if ($view_course_id && !in_array($view_course_id, $enrolled_ids)) {
    $view_course_id = $enrolled_ids[0] ?? 0; // safety: can't view a non-enrolled course
}
// Fetch the selected course's name for headings
$view_course_row = $view_course_id
    ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT title FROM courses WHERE id = $view_course_id"))
    : null;
$view_course_name = $view_course_row['title'] ?? 'All Courses';

// ── Auto-heal mastery: only for the active course's enrolled students ─
include_once __DIR__ . '/recalculate_mastery.php';
if (mastery_needs_recalculation($user_id, $conn)) {
    recalculate_mastery_for_student($user_id, $conn);
}

// ── Mastery data — computed using AI engine incremental rules, scoped to course ──
// We replay quiz RESULTS through the same +8.5 / +3.2 / -5.0 delta rules used
// in ai_engine.php so progress bars reflect accumulated mastery, NOT raw score
// averages (which incorrectly hit 100% after a single perfect quiz).
include_once __DIR__ . '/recalculate_mastery.php'; // provides infer_skill_name()

$masteryData  = [];
$skillMastery = [];   // [skill_name => accumulated_level]

if ($view_course_id) {
    // Fetch all results for this student in this course, oldest first
    $rfm_res = mysqli_query($conn,
        "SELECT r.score, r.attempt_no, q.id AS quiz_id, q.skill_name, q.title
         FROM results r
         JOIN quizzes q ON q.id = r.quiz_id
         WHERE r.student_id = $user_id AND q.course_id = $view_course_id
         ORDER BY r.created_at ASC, r.id ASC"
    );
    while ($row = mysqli_fetch_assoc($rfm_res)) {
        // Map to skill — use DB value first, then keyword inference, then modulo rotation
        $skill   = !empty($row['skill_name'])
                   ? $row['skill_name']
                   : infer_skill_name(['id' => $row['quiz_id'], 'skill_name' => '', 'title' => $row['title']]);
        $current = $skillMastery[$skill] ?? 0.0;
        $score   = floatval($row['score']);
        $att     = max(1, intval($row['attempt_no'] ?? 1));

        // AI engine thresholds (keep in sync with Ai_engine.php constants)
        if ($score >= 80)                $delta = 8.5;
        elseif ($score >= 50 && $att < 3) $delta = 3.2;
        else                              $delta = -5.0;

        $skillMastery[$skill] = max(0.0, min(100.0, $current + $delta));
    }
    foreach ($skillMastery as $skill => $level) {
        $masteryData[] = ['skill_name' => $skill, 'mastery_level' => round($level, 1)];
    }
} else {
    // Global fallback: take highest accumulated value per skill across all courses
    $mastery_query = mysqli_query($conn,
        "SELECT skill_name, MAX(mastery_level) AS mastery_level
         FROM student_mastery WHERE student_id = '$user_id' GROUP BY skill_name"
    );
    while ($m = mysqli_fetch_assoc($mastery_query)) $masteryData[] = $m;
}

$avgMastery = count($masteryData) > 0
    ? round(array_sum(array_column($masteryData, 'mastery_level')) / count($masteryData), 1)
    : 0;

// ── Materials count — scoped to the selected course ──────────────────
$totalMaterials = 0;
if ($view_course_id) {
    $matRes = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) as total FROM materials WHERE course_id = $view_course_id"
    ));
    $totalMaterials = intval($matRes['total']);
} elseif (!empty($enrolled_ids)) {
    $ids_str = implode(',', $enrolled_ids);
    $matRes = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) as total FROM materials WHERE course_id IN ($ids_str)"
    ));
    $totalMaterials = intval($matRes['total']);
}

// Student career path
$studentInfo = mysqli_fetch_assoc(mysqli_query($conn, "SELECT career_path FROM users WHERE id = '$user_id'"));
$careerPath  = $studentInfo['career_path'] ?? 'General';

// ── Assignments — scoped to the selected course ───────────────────────
$assignments_for_student = [];
if (!empty($enrolled_ids)) {
    // Scope to the active course; fall back to all courses only if nothing selected
    $assign_scope = $view_course_id ? "$view_course_id" : implode(',', $enrolled_ids);
    $assign_res = mysqli_query($conn,
        "SELECT a.id, a.title, a.description, a.due_date, a.max_words,
                c.title AS course_title,
                s.id AS submission_id, s.word_count, s.submitted_at,
                pr.overall_score, pr.verdict
         FROM assignments a
         JOIN courses c ON c.id = a.course_id
         LEFT JOIN assignment_submissions s
               ON s.assignment_id = a.id AND s.student_id = '$user_id'
         LEFT JOIN plagiarism_reports pr ON pr.submission_id = s.id
         WHERE a.course_id IN ($assign_scope)
         ORDER BY a.due_date ASC, a.created_at DESC"
    );
    if ($assign_res) {
        while ($aRow = mysqli_fetch_assoc($assign_res)) $assignments_for_student[] = $aRow;
    }
}

// ── Attendance data for student ──────────────────────────────────────────
// Auto-create tables if absent
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `attendance_sessions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT, `unit_id` INT(11) NOT NULL,
  `lecturer_id` INT(11) NOT NULL, `session_date` DATE NOT NULL,
  `title` VARCHAR(255) NOT NULL DEFAULT 'Lecture',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY(`unit_id`), KEY(`lecturer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `attendance_records` (
  `id` INT(11) NOT NULL AUTO_INCREMENT, `session_id` INT(11) NOT NULL,
  `student_id` INT(11) NOT NULL, `status` ENUM('present','absent') NOT NULL DEFAULT 'absent',
  `marked_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_ses_stu` (`session_id`,`student_id`), KEY(`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$student_attendance = [];
$att_res = mysqli_query($conn,
    "SELECT cu.id AS unit_id, cu.title AS unit_title, cu.unit_code,
            c.title AS course_title,
            COUNT(DISTINCT ats.id)                                                        AS total_sessions,
            SUM(CASE WHEN ar.status='present' THEN 1 ELSE 0 END)                          AS attended,
            SUM(CASE WHEN ar.status='absent'  THEN 1 ELSE 0 END)                          AS absences,
            ROUND(SUM(CASE WHEN ar.status='absent' THEN 1 ELSE 0 END)
                  / NULLIF(COUNT(DISTINCT ats.id),0)*100,1)                                AS absence_pct
     FROM unit_registrations ur
     JOIN course_units cu ON cu.id = ur.unit_id
     JOIN courses c ON c.id = cu.course_id
     LEFT JOIN attendance_sessions ats ON ats.unit_id = cu.id
     LEFT JOIN attendance_records  ar  ON ar.session_id = ats.id AND ar.student_id = $user_id
     WHERE ur.student_id = $user_id
     GROUP BY cu.id, cu.title, cu.unit_code, c.title
     ORDER BY c.title, cu.title"
);
if ($att_res) {
    while ($arow = mysqli_fetch_assoc($att_res)) {
        $arow['barred'] = (floatval($arow['absence_pct'] ?? 0) > 33.33);
        $student_attendance[] = $arow;
    }
}
$barred_count = count(array_filter($student_attendance, fn($a) => $a['barred']));

// ══════════════════════════════════════════════════════════════════
//  FINANCE MODULE — Fee & Payment Data
// ══════════════════════════════════════════════════════════════════
// Auto-create tables safely
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `fee_structures` (
  `id` INT(11) NOT NULL AUTO_INCREMENT, `name` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL, `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `fee_category` ENUM('tuition','examination','library','accommodation','transport','medical','activity','other') NOT NULL DEFAULT 'tuition',
  `academic_year` VARCHAR(20) NOT NULL, `semester` ENUM('Semester 1','Semester 2','Full Year','One Time') NOT NULL DEFAULT 'Semester 1',
  `course_id` INT(11) DEFAULT NULL, `is_mandatory` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT(11) DEFAULT NULL, `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `student_fee_assignments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT, `student_id` INT(11) NOT NULL,
  `fee_structure_id` INT(11) NOT NULL, `total_amount` DECIMAL(12,2) NOT NULL,
  `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00, `discount_reason` VARCHAR(255) DEFAULT NULL,
  `net_amount` DECIMAL(12,2) NOT NULL, `academic_year` VARCHAR(20) NOT NULL,
  `semester` VARCHAR(50) NOT NULL, `due_date` DATE DEFAULT NULL,
  `status` ENUM('pending','partial','paid','overdue','waived') NOT NULL DEFAULT 'pending',
  `assigned_by` INT(11) DEFAULT NULL, `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_stu_fee` (`student_id`, `fee_structure_id`), PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `fee_payments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT, `student_id` INT(11) NOT NULL,
  `fee_assignment_id` INT(11) DEFAULT NULL, `amount_paid` DECIMAL(12,2) NOT NULL,
  `payment_method` ENUM('cash','bank_transfer','mpesa','cheque','online','scholarship') NOT NULL DEFAULT 'cash',
  `transaction_ref` VARCHAR(100) DEFAULT NULL, `receipt_number` VARCHAR(50) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL, `payment_date` DATE NOT NULL,
  `recorded_by` INT(11) NOT NULL, `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `fee_reminders` (
  `id` INT(11) NOT NULL AUTO_INCREMENT, `student_id` INT(11) NOT NULL,
  `message` TEXT NOT NULL, `sent_by` INT(11) NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0, `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Mark reminders read on request
if (isset($_POST['mark_reminders_read'])) {
    mysqli_query($conn, "UPDATE fee_reminders SET is_read=1 WHERE student_id=$user_id");
    header("Location: student_dashboard.php?status=success&msg=Reminders+cleared");
    exit();
}

// ── Fetch fee assignments (without payment calc — we do it below) ──
$fee_assignments_res = mysqli_query($conn,
    "SELECT sfa.*, fs.name AS fee_name, fs.fee_category, fs.description AS fee_desc
     FROM student_fee_assignments sfa
     JOIN fee_structures fs ON fs.id=sfa.fee_structure_id
     WHERE sfa.student_id=$user_id ORDER BY sfa.due_date ASC, sfa.assigned_at DESC");
$fee_assignments = [];
while ($fa = mysqli_fetch_assoc($fee_assignments_res)) $fee_assignments[] = $fa;

// ── Total fees assigned ───────────────────────────────────────────
$total_fees_assigned = array_sum(array_column($fee_assignments, 'net_amount'));

// ── Total paid = ALL payments for this student (linked + general) ─
$paid_row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(amount_paid),0) AS total FROM fee_payments WHERE student_id=$user_id"));
$total_fees_paid_global = floatval($paid_row['total']);

// ── Distribute payments sequentially across fee lines (FIFO) ─────
// This correctly handles both linked payments and general payments.
$remaining_credit = $total_fees_paid_global;
foreach ($fee_assignments as &$fa) {
    $net = floatval($fa['net_amount']);
    if ($fa['status'] === 'waived') {
        $fa['amount_paid'] = $net;
        $fa['balance']     = 0;
        continue;
    }
    if ($remaining_credit >= $net) {
        $fa['amount_paid'] = $net;
        $fa['balance']     = 0;
        $remaining_credit -= $net;
        // Sync DB status to paid
        if ($fa['status'] !== 'paid') {
            mysqli_query($conn, "UPDATE student_fee_assignments SET status='paid' WHERE id={$fa['id']}");
            $fa['status'] = 'paid';
        }
    } elseif ($remaining_credit > 0) {
        $fa['amount_paid'] = $remaining_credit;
        $fa['balance']     = $net - $remaining_credit;
        $remaining_credit  = 0;
        // Sync DB status to partial
        if ($fa['status'] !== 'partial') {
            mysqli_query($conn, "UPDATE student_fee_assignments SET status='partial' WHERE id={$fa['id']}");
            $fa['status'] = 'partial';
        }
    } else {
        $fa['amount_paid'] = 0;
        $fa['balance']     = $net;
        // Check overdue
        if (!empty($fa['due_date']) && strtotime($fa['due_date']) < time() && $fa['status'] !== 'overdue') {
            mysqli_query($conn, "UPDATE student_fee_assignments SET status='overdue' WHERE id={$fa['id']}");
            $fa['status'] = 'overdue';
        }
    }
}
unset($fa);

// ── Summary totals ────────────────────────────────────────────────
$total_fees_paid    = min($total_fees_paid_global, $total_fees_assigned);
$total_fee_balance  = max(0, $total_fees_assigned - $total_fees_paid_global);
$fee_collection_pct = $total_fees_assigned > 0
    ? min(100, round(($total_fees_paid_global / $total_fees_assigned) * 100)) : 0;
$has_overdue_fees   = !empty(array_filter($fee_assignments, fn($f) => $f['status'] === 'overdue'));

// Payment history
$fee_payments_history = [];
$fph_res = mysqli_query($conn,
    "SELECT fp.*, COALESCE(fs.name,'General Payment') AS fee_name, u.full_name AS recorded_by_name
     FROM fee_payments fp
     LEFT JOIN student_fee_assignments sfa ON sfa.id=fp.fee_assignment_id
     LEFT JOIN fee_structures fs ON fs.id=sfa.fee_structure_id
     JOIN users u ON u.id=fp.recorded_by
     WHERE fp.student_id=$user_id ORDER BY fp.payment_date DESC");
if ($fph_res) while ($r = mysqli_fetch_assoc($fph_res)) $fee_payments_history[] = $r;

// Reminders
$fee_reminders = [];
$fr_res = mysqli_query($conn, "SELECT * FROM fee_reminders WHERE student_id=$user_id ORDER BY created_at DESC");
if ($fr_res) while ($r = mysqli_fetch_assoc($fr_res)) $fee_reminders[] = $r;
$unread_reminders = count(array_filter($fee_reminders, fn($r) => !$r['is_read']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard | Smart LMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .dashboard-card { background: #ffffff; border: 1px solid #e2e8f0; transition: all 0.2s ease; }
        .dashboard-card:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); }
        .sidebar-item:hover { background: #f1f5f9; border-left: 4px solid #3b82f6; }
        .sidebar-active { background: #eff6ff; border-left: 4px solid #3b82f6; color: #1d4ed8 !important; }
        .view-section { display: none; }
        .view-section.active { display: block; }
        .dropdown-content { max-height: 0; overflow: hidden; transition: max-height 0.3s ease-out; }
        .dropdown-active .dropdown-content { max-height: 200px; }
        @keyframes slide-in { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .animate-slide-in { animation: slide-in 0.4s ease-out forwards; }

        /* ── AI Panel ── */
        .ai-panel {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%);
            border: 1px solid rgba(99,102,241,0.3);
            position: relative;
            overflow: hidden;
        }
        .ai-panel::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(99,102,241,0.12) 0%, transparent 70%);
            pointer-events: none;
        }
        .ai-tag {
            background: linear-gradient(90deg, #6366f1, #8b5cf6);
            font-size: 9px;
            letter-spacing: 0.15em;
        }
        .mastery-track {
            height: 6px;
            border-radius: 9999px;
            background: rgba(255,255,255,0.08);
            overflow: hidden;
        }
        .mastery-fill {
            height: 100%;
            border-radius: 9999px;
            width: 0%;
            transition: width 1.2s cubic-bezier(0.22, 1, 0.36, 1);
        }
        .score-ring {
            width: 64px; height: 64px;
            border-radius: 50%;
            background: conic-gradient(#6366f1 var(--pct), rgba(255,255,255,0.08) 0);
            display: flex; align-items: center; justify-content: center;
            position: relative;
        }
        .score-ring::before {
            content: '';
            position: absolute;
            width: 48px; height: 48px;
            border-radius: 50%;
            background: #1e1b4b;
        }
        .score-ring span { position: relative; z-index: 1; font-size: 13px; font-weight: 900; color: #a78bfa; }

        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 0 0 0 rgba(99,102,241,0); }
            50% { box-shadow: 0 0 20px 4px rgba(99,102,241,0.25); }
        }
        .ai-loading { animation: pulse-glow 2s infinite; }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0.3} }
        .dot-1 { animation: blink 1.4s infinite 0s; }
        .dot-2 { animation: blink 1.4s infinite 0.2s; }
        .dot-3 { animation: blink 1.4s infinite 0.4s; }

        /* --- Floating Chatbot Styles --- */
        #chatbot-container { position: fixed; bottom: 20px; right: 20px; z-index: 1000; }
        #chatbot-button { width: 60px; height: 60px; border-radius: 50%; background: #2563eb; color: white; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); transition: transform 0.3s ease; }
        #chatbot-button:hover { transform: scale(1.1); }
        #chatbot-window { width: 350px; height: 500px; background: white; border-radius: 20px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); display: none; flex-direction: column; overflow: hidden; border: 1px solid #e2e8f0; margin-bottom: 20px; }
        #chatbot-header { padding: 15px; background: #2563eb; color: white; font-weight: bold; display: flex; justify-content: space-between; align-items: center; }
        #chatbot-messages { flex: 1; padding: 15px; overflow-y: auto; font-size: 12px; }
        .message { margin-bottom: 10px; padding: 10px; border-radius: 10px; max-width: 80%; }
        .message.bot { background: #f1f5f9; align-self: flex-start; }
        .message.user { background: #2563eb; color: white; align-self: flex-end; margin-left: auto; }
        #chatbot-input-container { padding: 10px; border-top: 1px solid #e2e8f0; display: flex; }
        #chatbot-input { flex: 1; border: 1px solid #e2e8f0; border-radius: 20px; padding: 8px 15px; outline: none; font-size: 12px; }
        #chatbot-send { margin-left: 10px; background: #2563eb; color: white; border-radius: 50%; width: 35px; height: 35px; display: flex; align-items: center; justify-content: center; cursor: pointer; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 font-sans min-h-screen flex">

    <?php if(isset($_GET['status']) && isset($_GET['msg'])): ?>
        <div id="toast" class="fixed top-6 right-6 z-[100] bg-white border border-slate-100 p-5 rounded-3xl shadow-2xl flex items-center space-x-4 animate-slide-in">
            <div class="<?php echo $_GET['status'] == 'success' ? 'bg-emerald-500' : 'bg-red-500'; ?> p-2 rounded-xl">
                <i class="fa-solid <?php echo $_GET['status'] == 'success' ? 'fa-check' : 'fa-triangle-exclamation'; ?> text-white"></i>
            </div>
            <div>
                <p class="text-[10px] font-black uppercase text-slate-400">Notification</p>
                <p class="text-xs font-bold text-slate-800"><?php echo htmlspecialchars($_GET['msg']); ?></p>
            </div>
        </div>
        <script>setTimeout(() => { document.getElementById('toast').style.opacity = '0'; setTimeout(() => document.getElementById('toast').remove(), 500); }, 3000);</script>
    <?php endif; ?>

    <aside class="w-64 border-r border-slate-200 hidden md:flex flex-col bg-white sticky top-0 h-screen">
        <div class="p-8 flex items-center space-x-2">
            <i class="fa-solid fa-brain text-blue-600 text-xl"></i>
            <span class="text-slate-900 font-black tracking-tighter text-lg uppercase">Smart<span class="text-blue-600">LMS</span></span>
        </div>
        
        <nav class="flex-1 px-4 space-y-1 mt-4">
            <a href="javascript:void(0)" onclick="switchView('overview')" id="nav-overview" class="sidebar-active sidebar-item flex items-center space-x-3 p-3 rounded-xl transition-all">
                <i class="fa-solid fa-house w-5"></i> <span class="text-xs uppercase tracking-wider">Overview</span>
            </a>

            <a href="javascript:void(0)" onclick="switchView('schedule')" id="nav-schedule" class="sidebar-item flex items-center space-x-3 p-3 rounded-xl transition-all text-slate-500">
                <i class="fa-solid fa-calendar-days w-5"></i> 
                <span class="text-xs uppercase tracking-wider">Schedule</span>
            </a>

            <a href="javascript:void(0)" onclick="switchView('assignments')" id="nav-assignments" class="sidebar-item flex items-center space-x-3 p-3 rounded-xl transition-all text-slate-500">
                <i class="fa-solid fa-file-pen w-5"></i>
                <span class="text-xs uppercase tracking-wider">Assignments</span>
            </a>

            <a href="javascript:void(0)" onclick="switchView('my-units')" id="nav-my-units" class="sidebar-item flex items-center space-x-3 p-3 rounded-xl transition-all text-slate-500">
                <i class="fa-solid fa-layer-group w-5"></i>
                <span class="text-xs uppercase tracking-wider">My Units</span>
            </a>

            <a href="javascript:void(0)" onclick="switchView('my-results')" id="nav-my-results" class="sidebar-item flex items-center space-x-3 p-3 rounded-xl transition-all text-slate-500">
                <i class="fa-solid fa-chart-simple w-5"></i>
                <span class="text-xs uppercase tracking-wider">My Results</span>
            </a>

            <a href="javascript:void(0)" onclick="switchView('my-attendance')" id="nav-my-attendance" class="sidebar-item flex items-center space-x-3 p-3 rounded-xl transition-all text-slate-500">
                <i class="fa-solid fa-calendar-check w-5"></i>
                <span class="text-xs uppercase tracking-wider">Attendance</span>
            </a>

            <a href="javascript:void(0)" onclick="switchView('my-fees')" id="nav-my-fees" class="sidebar-item flex items-center space-x-3 p-3 rounded-xl transition-all text-slate-500">
                <i class="fa-solid fa-coins w-5" style="color:#10b981;"></i>
                <span class="text-xs uppercase tracking-wider">Fees &amp; Payments</span>
                <?php if ($unread_reminders > 0 || $has_overdue_fees): ?>
                <span style="margin-left:auto;background:#ef4444;color:#fff;font-size:9px;font-weight:800;padding:2px 7px;border-radius:10px;"><?php echo $unread_reminders > 0 ? $unread_reminders : '!'; ?></span>
                <?php elseif ($total_fee_balance > 0): ?>
                <span style="margin-left:auto;background:#f59e0b;color:#fff;font-size:9px;font-weight:800;padding:2px 7px;border-radius:10px;">DUE</span>
                <?php endif; ?>
            </a>

            <div id="course-dropdown">
                <button onclick="toggleDropdown()" class="w-full sidebar-item flex items-center justify-between p-3 rounded-xl text-slate-500 transition-all">
                    <div class="flex items-center space-x-3">
                        <i class="fa-solid fa-book-open w-5"></i> 
                        <span class="text-xs uppercase tracking-wider">Courses</span>
                    </div>
                    <i class="fa-solid fa-chevron-down text-[10px]" id="drop-icon"></i>
                </button>
                <div class="dropdown-content pl-10 space-y-1 mt-1">
                    <a href="javascript:void(0)" onclick="switchView('all-courses')" class="block py-2 text-[10px] font-bold uppercase text-slate-400 hover:text-blue-600">All Courses</a>
                    <a href="javascript:void(0)" onclick="switchView('my-courses')" class="block py-2 text-[10px] font-bold uppercase text-slate-400 hover:text-blue-600">My Enrolled</a>
                </div>
            </div>
        </nav>

        <div class="p-4 border-t border-slate-100">
            <a href="javascript:void(0);" onclick="confirmLogout()" class="flex items-center space-x-3 p-3 rounded-xl text-slate-400 hover:text-red-600 hover:bg-red-50 transition-all">
                <i class="fa-solid fa-right-from-bracket"></i> <span class="text-xs font-bold uppercase tracking-widest">Logout</span>
            </a>
        </div>
    </aside>

    <main class="flex-1 p-8 overflow-y-auto">
        <header class="flex justify-between items-center mb-6">
            <div>
                <h1 id="page-title" class="text-2xl font-black text-slate-900 uppercase italic">Dashboard</h1>
                <p class="text-slate-500 text-xs font-bold uppercase tracking-widest">Education Management</p>
            </div>
            <div class="flex items-center space-x-4">
                <div class="text-right">
                    <p class="text-xs font-black text-slate-900 uppercase"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                    <p class="text-[10px] text-blue-600 font-bold uppercase tracking-widest">Student Account</p>
                </div>
                <div class="w-10 h-10 rounded-full bg-gradient-to-tr from-blue-700 to-blue-400 flex items-center justify-center font-bold text-white shadow-xl">
                    <?php echo substr($_SESSION['user_name'], 0, 1); ?>
                </div>
            </div>
        </header>

        <?php if ($enrolledCount > 1): ?>
        <!-- ── Course Tab Switcher ───────────────────────────────────── -->
        <div class="flex items-center space-x-2 mb-8 overflow-x-auto pb-1">
            <span class="text-[9px] font-black uppercase text-slate-400 tracking-widest flex-shrink-0 mr-2">
                <i class="fa-solid fa-filter mr-1"></i>Viewing:
            </span>
            <?php
            mysqli_data_seek($enrolled_query, 0);
            while ($tc = mysqli_fetch_assoc($enrolled_query)):
                $isActive = intval($tc['id']) === $view_course_id;
            ?>
            <a href="?course_id=<?php echo $tc['id']; ?>"
               class="flex-shrink-0 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all
                      <?php echo $isActive
                          ? 'bg-blue-600 text-white shadow-lg shadow-blue-100'
                          : 'bg-white border border-slate-200 text-slate-500 hover:border-blue-400 hover:text-blue-600'; ?>">
                <i class="fa-solid fa-graduation-cap mr-1"></i><?php echo htmlspecialchars($tc['title']); ?>
            </a>
            <?php endwhile; ?>
        </div>
        <?php else: ?>
        <!-- Single course: show the course name as a heading badge -->
        <?php if ($view_course_name): ?>
        <div class="mb-6">
            <span class="inline-flex items-center px-4 py-2 bg-blue-50 border border-blue-200 rounded-xl text-[10px] font-black uppercase text-blue-700 tracking-widest">
                <i class="fa-solid fa-graduation-cap mr-2"></i><?php echo htmlspecialchars($view_course_name); ?>
            </span>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <div id="view-overview" class="view-section active">

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-8">
                <div class="dashboard-card p-5 rounded-2xl border-l-4 border-l-blue-600 flex items-center space-x-4">
                    <div class="w-12 h-12 bg-blue-50 rounded-xl flex items-center justify-center">
                        <i class="fa-solid fa-book-open text-blue-600 text-lg"></i>
                    </div>
                    <div>
                        <p class="text-[10px] font-black text-slate-400 uppercase">Enrolled Courses</p>
                        <h3 class="text-2xl font-black text-slate-900"><?php echo $enrolledCount; ?></h3>
                    </div>
                </div>

                <div class="dashboard-card p-5 rounded-2xl border-l-4 border-l-violet-500 flex items-center space-x-4">
                    <div class="w-12 h-12 bg-violet-50 rounded-xl flex items-center justify-center">
                        <i class="fa-solid fa-chart-line text-violet-600 text-lg"></i>
                    </div>
                    <div>
                        <p class="text-[10px] font-black text-slate-400 uppercase">Avg. Mastery <?php echo $view_course_id ? '· ' . htmlspecialchars($view_course_name) : ''; ?></p>
                        <h3 class="text-2xl font-black text-slate-900"><?php echo $avgMastery; ?><span class="text-sm text-slate-400">%</span></h3>
                    </div>
                </div>

                <div class="dashboard-card p-5 rounded-2xl border-l-4 border-l-emerald-500 flex items-center space-x-4">
                    <div class="w-12 h-12 bg-emerald-50 rounded-xl flex items-center justify-center">
                        <i class="fa-solid fa-file-circle-check text-emerald-600 text-lg"></i>
                    </div>
                    <div>
                        <p class="text-[10px] font-black text-slate-400 uppercase">Materials Access</p>
                        <h3 class="text-2xl font-black text-slate-900"><?php echo $totalMaterials; ?></h3>
                    </div>
                </div>
            </div>

            <?php if (!empty($masteryData)): ?>
            <div class="dashboard-card p-6 rounded-2xl mb-8">
                <div class="flex items-center justify-between mb-5">
                    <h4 class="font-black text-sm uppercase text-slate-700 tracking-wide">
                        Skill Mastery &mdash; <?php echo htmlspecialchars($view_course_name); ?>
                    </h4>
                    <span class="text-[9px] font-black uppercase text-slate-400">Career: <?php echo htmlspecialchars($careerPath); ?></span>
                </div>
                <div class="space-y-4">
                    <?php foreach ($masteryData as $skill): 
                        $level     = floatval($skill['mastery_level']);
                        $display   = max(2, min(100, $level));
                        $colorGrad = $level >= 70 ? 'linear-gradient(90deg,#34d399,#059669)' : ($level >= 40 ? 'linear-gradient(90deg,#60a5fa,#6366f1)' : 'linear-gradient(90deg,#f87171,#e11d48)');
                        $labelColor = $level >= 70 ? 'text-emerald-600' : ($level >= 40 ? 'text-blue-600' : 'text-red-500');
                    ?>
                    <div>
                        <div class="flex justify-between mb-1.5">
                            <span class="text-[10px] font-bold uppercase text-slate-600"><?php echo htmlspecialchars($skill['skill_name']); ?></span>
                            <span class="text-[10px] font-black <?php echo $labelColor; ?>"><?php echo $level; ?>%</span>
                        </div>
                        <div class="h-2 bg-slate-100 rounded-full overflow-hidden">
                            <div class="mastery-fill"
                                 style="background:<?php echo $colorGrad; ?>; width:0%"
                                 data-target="<?php echo $display; ?>"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <!-- No quiz activity for this course yet -->
            <div class="dashboard-card p-6 rounded-2xl mb-8 border-dashed border-2 border-slate-200 bg-slate-50 text-center">
                <i class="fa-solid fa-chart-simple text-slate-300 text-3xl mb-3"></i>
                <p class="text-slate-500 font-bold text-sm">No quiz activity yet for <span class="text-blue-600"><?php echo htmlspecialchars($view_course_name); ?></span></p>
                <p class="text-slate-400 text-xs mt-1">Mastery scores will appear here after you take quizzes in this course.</p>
            </div>
            <?php endif; ?>

            <div id="ai-panel" class="ai-panel rounded-3xl p-7 ai-loading">
                <div id="ai-loading-state">
                    <div class="flex items-center space-x-3 mb-6">
                        <div class="w-8 h-8 bg-indigo-500/20 rounded-xl flex items-center justify-center">
                            <i class="fa-solid fa-brain text-indigo-400 text-sm"></i>
                        </div>
                        <div>
                            <span class="ai-tag text-white font-black uppercase px-2 py-0.5 rounded-md mr-2">AI Advisor</span>
                            <span class="text-slate-400 text-[10px] uppercase tracking-widest">Analysing your profile</span>
                        </div>
                    </div>
                    <div class="space-y-3">
                        <div class="flex items-center space-x-2 text-slate-400 text-sm">
                            <span class="dot-1 text-indigo-400 text-lg">●</span>
                            <span class="dot-2 text-indigo-400 text-lg">●</span>
                            <span class="dot-3 text-indigo-400 text-lg">●</span>
                            <span class="text-xs text-slate-500 italic ml-2">Generating personalised insights…</span>
                        </div>
                        <div class="h-3 bg-white/5 rounded-full w-3/4 animate-pulse"></div>
                        <div class="h-3 bg-white/5 rounded-full w-full animate-pulse"></div>
                        <div class="h-3 bg-white/5 rounded-full w-2/3 animate-pulse"></div>
                    </div>
                </div>

                <div id="ai-result-state" class="hidden">
                    <div class="flex items-center justify-between mb-6">
                        <div class="flex items-center space-x-3">
                            <div class="w-9 h-9 bg-indigo-500/20 rounded-xl flex items-center justify-center">
                                <i class="fa-solid fa-brain text-indigo-400"></i>
                            </div>
                            <div>
                                <span class="ai-tag text-white font-black uppercase px-2 py-0.5 rounded-md">AI Academic Advisor</span>
                                <p id="ai-greeting" class="text-slate-300 text-xs mt-1"></p>
                            </div>
                        </div>
                        <div class="flex flex-col items-center">
                            <div class="score-ring" id="score-ring" style="--pct: 0%">
                                <span id="score-val">—</span>
                            </div>
                            <p class="text-[9px] text-slate-500 uppercase mt-1 tracking-widest">Motivation</p>
                        </div>
                    </div>

                    <p id="ai-summary" class="text-slate-300 text-sm leading-relaxed mb-6 border-l-2 border-indigo-500/50 pl-4"></p>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-6">
                        <div class="bg-white/5 rounded-2xl p-4">
                            <p class="text-[9px] font-black uppercase text-emerald-400 tracking-widest mb-3"><i class="fa-solid fa-trophy mr-1"></i>Strengths</p>
                            <ul id="ai-strengths" class="space-y-2"></ul>
                        </div>
                        <div class="bg-white/5 rounded-2xl p-4">
                            <p class="text-[9px] font-black uppercase text-amber-400 tracking-widest mb-3"><i class="fa-solid fa-crosshairs mr-1"></i>Focus Areas</p>
                            <ul id="ai-focus" class="space-y-2"></ul>
                        </div>
                    </div>

                    <div class="bg-indigo-500/10 border border-indigo-500/20 rounded-2xl p-4 mb-4">
                        <p class="text-[9px] font-black uppercase text-indigo-300 tracking-widest mb-1"><i class="fa-solid fa-lightbulb mr-1"></i>This Week's Action</p>
                        <p id="ai-recommendation" class="text-slate-200 text-sm"></p>
                    </div>

                    <div class="bg-white/5 rounded-2xl p-4 mb-4" id="ai-mastery-bars-section">
                        <p class="text-[9px] font-black uppercase text-rose-300 tracking-widest mb-3"><i class="fa-solid fa-chart-simple mr-1"></i>Live Skill Mastery</p>
                        <div id="ai-mastery-bars" class="space-y-3">
                            <?php foreach ($masteryData as $skill):
                                $lvl     = floatval($skill['mastery_level']);
                                $display = max(2, min(100, $lvl));
                                $grad    = $lvl >= 70 ? 'linear-gradient(90deg,#34d399,#059669)' : ($lvl >= 40 ? 'linear-gradient(90deg,#818cf8,#6366f1)' : 'linear-gradient(90deg,#f87171,#e11d48)');
                                $col     = $lvl >= 70 ? 'text-emerald-400' : ($lvl >= 40 ? 'text-indigo-300' : 'text-red-400');
                            ?>
                            <div>
                                <div class="flex justify-between mb-1">
                                    <span class="text-[10px] text-slate-400"><?php echo htmlspecialchars($skill['skill_name']); ?></span>
                                    <span class="text-[10px] font-black <?php echo $col; ?>"><?php echo $lvl; ?>%</span>
                                </div>
                                <div class="h-1.5 bg-white/10 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full ai-panel-mastery-fill"
                                         style="background:<?php echo $grad; ?>; width:0%; transition: width 1.2s cubic-bezier(0.22,1,0.36,1);"
                                         data-target="<?php echo $display; ?>"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-white/5 rounded-2xl p-4">
                            <p class="text-[9px] font-black uppercase text-violet-400 tracking-widest mb-1"><i class="fa-solid fa-flag-checkered mr-1"></i>Weekly Challenge</p>
                            <p id="ai-challenge" class="text-slate-300 text-xs leading-relaxed"></p>
                        </div>
                        <div class="bg-white/5 rounded-2xl p-4">
                            <p class="text-[9px] font-black uppercase text-sky-400 tracking-widest mb-1"><i class="fa-solid fa-road mr-1"></i>Career Alignment</p>
                            <p id="ai-career" class="text-slate-300 text-xs leading-relaxed"></p>
                        </div>
                    </div>
                </div>

                <div id="ai-error-state" class="hidden text-center py-4">
                    <i class="fa-solid fa-triangle-exclamation text-amber-400 text-2xl mb-2"></i>
                    <p class="text-slate-400 text-xs">AI Advisor is unavailable right now.</p>
                    <button onclick="loadAIAnalysis()" class="mt-3 text-[9px] font-black uppercase text-indigo-400 hover:text-indigo-300 tracking-widest">
                        <i class="fa-solid fa-rotate-right mr-1"></i>Retry
                    </button>
                </div>
            </div>

            <?php
            $quiz_query = null;
            if (!empty($enrolled_ids)) {
                // Scope unit IDs to the selected course only
                $unit_scope_sql = $view_course_id
                    ? "SELECT unit_id FROM unit_registrations ur
                       JOIN course_units cu ON cu.id = ur.unit_id
                       WHERE ur.student_id = $user_id AND cu.course_id = $view_course_id"
                    : "SELECT unit_id FROM unit_registrations WHERE student_id = $user_id";

                $reg_unit_ids_res = mysqli_query($conn, $unit_scope_sql);
                $reg_unit_ids = [];
                while ($ruid = mysqli_fetch_assoc($reg_unit_ids_res)) $reg_unit_ids[] = intval($ruid['unit_id']);
                $unit_ids_sql = !empty($reg_unit_ids) ? implode(',', $reg_unit_ids) : '0';

                $course_scope_sql = $view_course_id ? "$view_course_id" : implode(',', $enrolled_ids);

                $quiz_query = mysqli_query($conn,
                    "SELECT q.id, q.title, q.difficulty, q.skill_name, q.unit_id,
                            c.title as course_title, cu.title AS unit_title, cu.unit_code,
                            COUNT(DISTINCT qu.id) as question_count,
                            (SELECT COUNT(*) FROM results r2 WHERE r2.quiz_id = q.id AND r2.student_id = $user_id) as my_attempts,
                            (SELECT r3.score FROM results r3 WHERE r3.quiz_id = q.id AND r3.student_id = $user_id ORDER BY r3.id DESC LIMIT 1) as last_score,
                            (SELECT r4.action_taken FROM results r4 WHERE r4.quiz_id = q.id AND r4.student_id = $user_id ORDER BY r4.id DESC LIMIT 1) as last_action
                     FROM quizzes q
                     JOIN courses c ON c.id = q.course_id
                     LEFT JOIN course_units cu ON cu.id = q.unit_id
                     LEFT JOIN questions qu ON qu.quiz_id = q.id
                     WHERE q.is_active = 1
                       AND (
                             (q.unit_id IS NOT NULL AND q.unit_id IN ($unit_ids_sql))
                             OR (q.unit_id IS NULL AND q.course_id IN ($course_scope_sql))
                           )
                     GROUP BY q.id
                     ORDER BY q.created_at DESC"
                );
            }
            $has_quizzes = $quiz_query && mysqli_num_rows($quiz_query) > 0;
            ?>
            <div class="mt-8">
                <div class="flex items-center justify-between mb-5">
                    <h4 class="font-black text-slate-800 text-sm uppercase tracking-wide">
                        <i class="fa-solid fa-circle-question text-indigo-500 mr-2"></i>Available Quizzes
                        <?php if ($view_course_name): ?>
                        <span class="ml-2 text-[9px] font-black uppercase text-blue-500 bg-blue-50 border border-blue-200 px-2 py-0.5 rounded-lg tracking-widest"><?php echo htmlspecialchars($view_course_name); ?></span>
                        <?php endif; ?>
                    </h4>
                    <span class="text-[9px] font-black uppercase text-slate-400 tracking-widest">AI-Generated Assessments</span>
                </div>
                <?php if ($has_quizzes): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php
                    $diffBadgeQ = [
                        'beginner'     => 'bg-emerald-100 text-emerald-700 border-emerald-200',
                        'intermediate' => 'bg-amber-100 text-amber-700 border-amber-200',
                        'advanced'     => 'bg-rose-100 text-rose-700 border-rose-200',
                    ];
                    while ($qz = mysqli_fetch_assoc($quiz_query)):
                        $myAttempts = intval($qz['my_attempts']);
                        $lastScore  = $qz['last_score'];
                        $lastAction = $qz['last_action'];
                        $btnLabel   = $myAttempts > 0 ? 'Retry Quiz' : 'Take Quiz';
                        $btnIcon    = $myAttempts > 0 ? 'fa-rotate' : 'fa-play';
                        $btnColor   = $lastAction === 'advance'
                                      ? 'bg-emerald-600 hover:bg-emerald-700'
                                      : 'bg-indigo-600 hover:bg-indigo-700';
                        $dBadge = $diffBadgeQ[$qz['difficulty']] ?? 'bg-slate-100 text-slate-600 border-slate-200';
                    ?>
                    <div class="dashboard-card p-5 rounded-2xl">
                        <div class="flex items-center space-x-2 mb-3 flex-wrap gap-1.5">
                            <span class="<?php echo $dBadge; ?> border text-[9px] font-black uppercase px-2 py-0.5 rounded-lg tracking-widest">
                                <?php echo ucfirst($qz['difficulty']); ?>
                            </span>
                            <?php if ($qz['skill_name']): ?>
                            <span class="bg-indigo-50 text-indigo-700 border border-indigo-100 text-[9px] font-black uppercase px-2 py-0.5 rounded-lg tracking-widest">
                                <?php echo htmlspecialchars($qz['skill_name']); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <h5 class="font-extrabold text-slate-900 text-sm mb-1"><?php echo htmlspecialchars($qz['title']); ?></h5>
                        <p class="text-slate-400 text-[10px] mb-4">
                            <i class="fa-solid fa-book mr-1"></i>
                            <?php if ($qz['unit_title']): ?>
                            <?php echo htmlspecialchars($qz['unit_title']); ?>
                            <span class="text-slate-300">·</span> <?php echo htmlspecialchars($qz['course_title']); ?>
                            <?php else: ?>
                            <?php echo htmlspecialchars($qz['course_title']); ?>
                            <?php endif; ?>
                            &nbsp;·&nbsp; <?php echo intval($qz['question_count']); ?> questions
                        </p>
                        <?php if ($myAttempts > 0 && $lastScore !== null): ?>
                        <div class="flex items-center justify-between mb-3 bg-slate-50 rounded-xl px-3 py-2">
                            <span class="text-[9px] font-black uppercase text-slate-400 tracking-widest">Last attempt</span>
                            <span class="font-black text-sm <?php echo intval($lastScore)>=80?'text-emerald-600':(intval($lastScore)>=50?'text-amber-600':'text-red-600'); ?>">
                                <?php echo intval($lastScore); ?>%
                            </span>
                        </div>
                        <?php endif; ?>
                        <a href="student_quiz.php?quiz_id=<?php echo $qz['id']; ?>"
                           class="block text-center py-3 <?php echo $btnColor; ?> text-white font-black text-[10px] uppercase tracking-widest rounded-xl shadow-md transition-all active:scale-95">
                            <i class="fa-solid <?php echo $btnIcon; ?> mr-2"></i><?php echo $btnLabel; ?>
                        </a>
                    </div>
                    <?php endwhile; ?>
                </div>
                <?php else: ?>
                <div class="bg-white rounded-2xl border border-slate-200 p-8 text-center">
                    <div class="w-12 h-12 bg-slate-50 rounded-xl flex items-center justify-center mx-auto mb-3">
                        <i class="fa-solid fa-circle-question text-slate-300 text-xl"></i>
                    </div>
                    <p class="text-slate-500 text-sm font-bold">No quizzes published yet.</p>
                    <p class="text-slate-400 text-xs mt-1">Your lecturers will publish AI-generated quizzes here.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="view-schedule" class="view-section">
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h2 class="text-2xl font-black text-slate-900 uppercase italic">Class Schedule</h2>
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-widest">Upcoming Sessions for Enrolled Courses</p>
                </div>
            </div>

            <?php 
            if (!empty($enrolled_ids)) {
                $ids_str = implode(',', $enrolled_ids);
                $sch_res = mysqli_query($conn, 
                    "SELECT s.*, c.title as course_title, u.full_name as lecturer_name 
                     FROM schedules s
                     JOIN courses c ON s.course_id = c.id
                     JOIN users u ON s.lecturer_id = u.id
                     WHERE s.course_id IN ($ids_str)
                     ORDER BY s.meet_date ASC, s.meet_time ASC"
                );
                
                if ($sch_res && mysqli_num_rows($sch_res) > 0): ?>
                    <div class="space-y-4">
                        <?php while($s = mysqli_fetch_assoc($sch_res)): 
                            $date = new DateTime($s['meet_date']);
                        ?>
                        <div class="dashboard-card p-5 rounded-2xl flex items-center justify-between">
                            <div class="flex items-center space-x-5">
                                <div class="w-14 h-14 bg-blue-50 rounded-2xl flex flex-col items-center justify-center text-blue-600">
                                    <span class="text-lg font-black leading-none"><?php echo $date->format('d'); ?></span>
                                    <span class="text-[9px] font-black uppercase"><?php echo $date->format('M'); ?></span>
                                </div>
                                <div>
                                    <h4 class="font-black text-slate-900 text-sm mb-1"><?php echo htmlspecialchars($s['title']); ?></h4>
                                    <p class="text-slate-400 text-[10px] uppercase font-bold">
                                        <i class="fa-solid fa-clock mr-1 text-blue-500"></i> <?php echo date('h:i A', strtotime($s['meet_time'])); ?>
                                        &nbsp;·&nbsp; <i class="fa-solid fa-user-tie mr-1 text-blue-500"></i> <?php echo htmlspecialchars($s['lecturer_name']); ?>
                                    </p>
                                    <p class="text-blue-600 text-[9px] font-black uppercase mt-1"><?php echo htmlspecialchars($s['course_title']); ?></p>
                                </div>
                            </div>
                            <?php if (!empty($s['meet_link'])): ?>
                                <a href="<?php echo htmlspecialchars($s['meet_link']); ?>" target="_blank"
                                   class="flex items-center space-x-2 px-5 py-2.5 <?php echo !empty($s['zoom_meeting_id']) ? 'bg-blue-600 shadow-blue-100' : 'bg-indigo-600 shadow-indigo-100'; ?> text-white text-[10px] font-black uppercase rounded-xl shadow-lg hover:scale-105 transition-all">
                                    <i class="fa-solid fa-video text-xs"></i>
                                    <span><?php echo !empty($s['zoom_meeting_id']) ? 'Join Zoom' : 'Join Class'; ?></span>
                                </a>
                            <?php endif; ?>
                        </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="bg-white rounded-3xl border border-slate-100 p-12 text-center">
                        <i class="fa-solid fa-calendar-xmark text-slate-200 text-4xl mb-4"></i>
                        <p class="text-slate-500 text-sm font-bold uppercase">No upcoming sessions scheduled.</p>
                    </div>
                <?php endif; 
            } else { ?>
                <div class="bg-white rounded-3xl border border-slate-100 p-12 text-center">
                    <p class="text-slate-500 text-sm font-bold uppercase">Enroll in a course to see its schedule.</p>
                </div>
            <?php } ?>
        </div>

        <div id="view-all-courses" class="view-section">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <?php mysqli_data_seek($available_query, 0); while($row = mysqli_fetch_assoc($available_query)): ?>
                    <div class="dashboard-card p-6 rounded-3xl">
                        <h5 class="text-slate-900 font-black uppercase text-xs mb-4"><?php echo htmlspecialchars($row['title']); ?></h5>
                        <form method="POST">
                            <input type="hidden" name="course_id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="enroll_course" class="w-full py-3 bg-blue-600 text-white rounded-xl font-black text-[10px] uppercase shadow-lg shadow-blue-100">Enroll</button>
                        </form>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>

        <div id="view-my-courses" class="view-section">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <?php mysqli_data_seek($enrolled_query, 0); while($course = mysqli_fetch_assoc($enrolled_query)): ?>
                    <div class="dashboard-card p-6 rounded-3xl">
                        <div class="flex justify-between items-start mb-6">
                            <h5 class="text-slate-900 font-black uppercase text-sm italic"><?php echo htmlspecialchars($course['title']); ?></h5>
                            <form method="POST" onsubmit="return confirm('Drop this course?')">
                                <input type="hidden" name="course_id" value="<?php echo $course['id']; ?>">
                                <button type="submit" name="unenroll_course" class="text-red-400"><i class="fa-solid fa-circle-minus"></i></button>
                            </form>
                        </div>
                        <button onclick="openCourseUnits(<?php echo $course['id']; ?>, '<?php echo addslashes($course['title']); ?>')" class="w-full py-3 border border-blue-600 text-blue-600 rounded-xl font-black text-[10px] uppercase hover:bg-blue-600 hover:text-white transition-all">
                            <i class="fa-solid fa-layer-group mr-1"></i>View Units
                        </button>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>

        <div id="view-assignments" class="view-section">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h2 class="text-2xl font-black text-slate-900 uppercase italic">My Assignments</h2>
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-widest">Submit work · View plagiarism analysis</p>
                </div>
            </div>
            <?php if (empty($assignments_for_student)): ?>
            <div class="bg-white rounded-3xl border border-slate-100 p-12 text-center">
                <div class="w-12 h-12 bg-slate-50 rounded-xl flex items-center justify-center mx-auto mb-3">
                    <i class="fa-solid fa-file-pen text-slate-300 text-xl"></i>
                </div>
                <p class="text-slate-500 text-sm font-bold uppercase">No assignments posted yet.</p>
                <p class="text-slate-400 text-xs mt-1">Your lecturers will post assignments here.</p>
            </div>
            <?php else: ?>
            <div class="space-y-4">
            <?php foreach ($assignments_for_student as $asgn):
                $submitted  = !empty($asgn['submission_id']);
                $isOverdue  = $asgn['due_date'] && strtotime($asgn['due_date']) < time();
                $verdict    = $asgn['verdict'] ?? '';
                $vBg = match($verdict) {
                    'HIGH RISK'   => 'bg-red-50 text-red-600 border-red-100',
                    'MEDIUM RISK' => 'bg-yellow-50 text-yellow-700 border-yellow-100',
                    default       => 'bg-emerald-50 text-emerald-700 border-emerald-100'
                };
                $vIcon = match($verdict) {
                    'HIGH RISK'   => 'fa-triangle-exclamation',
                    'MEDIUM RISK' => 'fa-circle-exclamation',
                    default       => 'fa-circle-check'
                };
            ?>
            <div class="dashboard-card p-5 rounded-2xl">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1">
                        <div class="flex flex-wrap gap-2 mb-1.5">
                            <span class="text-[9px] font-black uppercase text-blue-700 bg-blue-50 border border-blue-100 px-2 py-0.5 rounded-lg"><?php echo htmlspecialchars($asgn['course_title']); ?></span>
                            <?php if ($submitted): ?>
                            <span class="text-[9px] font-black uppercase text-emerald-700 bg-emerald-50 border border-emerald-100 px-2 py-0.5 rounded-lg"><i class="fa-solid fa-check mr-1"></i>Submitted</span>
                            <?php endif; ?>
                            <?php if ($isOverdue && !$submitted): ?>
                            <span class="text-[9px] font-black uppercase text-red-600 bg-red-50 border border-red-100 px-2 py-0.5 rounded-lg">Overdue</span>
                            <?php endif; ?>
                        </div>
                        <h5 class="font-extrabold text-slate-900 text-sm mb-1"><?php echo htmlspecialchars($asgn['title']); ?></h5>
                        <div class="flex flex-wrap gap-3 text-[10px] font-bold text-slate-400 uppercase">
                            <?php if ($asgn['due_date']): ?>
                            <span><i class="fa-solid fa-calendar-days mr-1 <?php echo $isOverdue ? 'text-red-500' : 'text-blue-500'; ?>"></i>Due: <?php echo date('d M Y', strtotime($asgn['due_date'])); ?></span>
                            <?php endif; ?>
                            <span><i class="fa-solid fa-align-left mr-1 text-blue-500"></i><?php echo number_format($asgn['max_words']); ?> words</span>
                            <?php if ($submitted && $verdict): ?>
                            <span class="border <?php echo $vBg; ?> rounded-lg px-2 py-0.5">
                                <i class="fa-solid <?php echo $vIcon; ?> mr-1"></i><?php echo $verdict; ?> (<?php echo number_format($asgn['overall_score'], 1); ?>%)
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <a href="assignment_submit.php?submit_id=<?php echo $asgn['id']; ?>"
                       class="flex-shrink-0 px-4 py-2.5 <?php echo $submitted ? 'bg-slate-700 hover:bg-slate-800' : 'bg-indigo-600 hover:bg-indigo-700'; ?> text-white font-black text-[9px] uppercase rounded-xl tracking-widest transition-all active:scale-95">
                        <i class="fa-solid <?php echo $submitted ? 'fa-rotate' : 'fa-file-pen'; ?> mr-1"></i>
                        <?php echo $submitted ? 'Resubmit' : 'Submit'; ?>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- ═══════════════════ UNIT MATERIALS VIEW ═══════════════════ -->
        <div id="view-details" class="view-section">
            <!-- Back button -->
            <button onclick="switchView('my-units')"
                class="mb-5 flex items-center gap-2 text-[10px] font-black uppercase text-indigo-600 hover:text-indigo-800 transition-all">
                <i class="fa-solid fa-arrow-left"></i> Back to My Units
            </button>

            <!-- Unit header banner (populated by JS) -->
            <div class="bg-gradient-to-r from-indigo-600 to-purple-600 rounded-[1.75rem] px-6 py-5 mb-6 shadow-lg shadow-indigo-100">
                <p id="det-course" class="text-indigo-200 text-[9px] font-black uppercase tracking-widest mb-0.5"></p>
                <h2 id="det-title" class="text-white font-extrabold text-xl">Unit Materials</h2>
                <p id="det-code" class="text-indigo-200 text-xs mt-0.5"></p>
            </div>

            <!-- Filter tabs (shown when > 1 type exists) -->
            <div id="det-filters" class="hidden flex-wrap gap-2 mb-5">
                <button class="det-ftab px-4 py-2 rounded-xl text-[9px] font-black uppercase border transition-all bg-slate-900 text-white border-slate-900" data-filter="all">All</button>
                <button class="det-ftab px-4 py-2 rounded-xl text-[9px] font-black uppercase border transition-all bg-white text-slate-600 border-slate-200" data-filter="pdf">
                    <i class="fa-solid fa-file-pdf text-red-400 mr-1"></i>Notes / PDFs
                </button>
                <button class="det-ftab px-4 py-2 rounded-xl text-[9px] font-black uppercase border transition-all bg-white text-slate-600 border-slate-200" data-filter="video">
                    <i class="fa-solid fa-circle-play text-blue-400 mr-1"></i>Videos
                </button>
                <button class="det-ftab px-4 py-2 rounded-xl text-[9px] font-black uppercase border transition-all bg-white text-slate-600 border-slate-200" data-filter="word">
                    <i class="fa-solid fa-file-word text-sky-500 mr-1"></i>Documents
                </button>
            </div>

            <!-- Materials rendered here by JS -->
            <div id="materials-list" class="space-y-3"></div>
        </div>
        <!-- ═══════════════════ END UNIT MATERIALS VIEW ══════════════════ -->

        <!-- ═══════════════════════════════════════════════════════════
             MY UNITS — register/deregister units per enrolled course
        ═══════════════════════════════════════════════════════════ -->
        <div id="view-my-units" class="view-section">
            <?php
            // ── ensure unit tables ────────────────────────────────
            foreach ([
                "CREATE TABLE IF NOT EXISTS `course_units` (`id` INT AUTO_INCREMENT PRIMARY KEY, `course_id` INT NOT NULL, `title` VARCHAR(255) NOT NULL, `unit_code` VARCHAR(50) DEFAULT NULL, `description` TEXT DEFAULT NULL, `lecturer_id` INT DEFAULT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY(`course_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS `unit_registrations` (`id` INT AUTO_INCREMENT PRIMARY KEY, `student_id` INT NOT NULL, `unit_id` INT NOT NULL, `registered_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY(`student_id`,`unit_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            ] as $tsql_u) mysqli_query($conn, $tsql_u);
            // active_course_id is set by JS via hidden input
            $active_course_id_filter = 0; // JS controls display; PHP fetches all

            // Get all enrolled courses + available units + my registrations
            $enrolled_courses_res = mysqli_query($conn,
                "SELECT c.id, c.title FROM enrollments e
                 JOIN courses c ON c.id = e.course_id
                 WHERE e.student_id = '$user_id'
                 ORDER BY c.title ASC"
            );
            $enrolled_courses_for_units = [];
            while ($ec = mysqli_fetch_assoc($enrolled_courses_res)) $enrolled_courses_for_units[] = $ec;

            // My registered unit IDs
            $my_unit_ids_res = mysqli_query($conn,
                "SELECT unit_id FROM unit_registrations WHERE student_id = '$user_id'"
            );
            $my_unit_ids = [];
            while ($muid = mysqli_fetch_assoc($my_unit_ids_res)) $my_unit_ids[] = intval($muid['unit_id']);
            ?>

            <div class="mb-6 flex items-center justify-between flex-wrap gap-3">
                <div>
                    <h2 class="text-2xl font-black text-slate-900" id="units-page-title">My Units</h2>
                    <p class="text-slate-500 text-sm mt-1" id="units-page-sub">Register for the units you want to take within each enrolled course.</p>
                </div>
                <button onclick="showAllCourseUnits()" id="units-show-all-btn"
                    class="hidden px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 font-black text-[9px] uppercase rounded-xl tracking-widest transition-all">
                    <i class="fa-solid fa-list mr-1"></i>Show All Courses
                </button>
            </div>

            <?php if (empty($enrolled_courses_for_units)): ?>
            <div class="bg-white rounded-[2rem] border border-dashed border-slate-200 p-14 text-center">
                <i class="fa-solid fa-graduation-cap text-slate-200 text-4xl mb-3"></i>
                <p class="text-slate-600 font-bold">You are not enrolled in any courses yet.</p>
                <p class="text-slate-400 text-xs mt-1">Enrol in a course first, then return here to register your units.</p>
                <button onclick="switchView('all-courses')" class="mt-5 px-6 py-3 bg-blue-600 text-white font-black text-xs uppercase rounded-2xl shadow-lg">
                    Browse Courses
                </button>
            </div>
            <?php else: ?>
            <div class="space-y-8" id="units-list-container">
            <?php foreach ($enrolled_courses_for_units as $ec):
                $avail_units = [];
                $ur = mysqli_query($conn,
                    "SELECT cu.id, cu.title, cu.unit_code, cu.description, u.full_name AS lecturer_name,
                            (SELECT COUNT(*) FROM unit_registrations WHERE unit_id = cu.id) AS reg_count
                     FROM course_units cu
                     LEFT JOIN users u ON u.id = cu.lecturer_id
                     WHERE cu.course_id = {$ec['id']}
                     ORDER BY cu.title ASC"
                );
                while ($av = mysqli_fetch_assoc($ur)) $avail_units[] = $av;
            ?>
            <div class="bg-white rounded-[2rem] border border-slate-100 overflow-hidden shadow-sm course-unit-block" data-course-id="<?php echo $ec['id']; ?>">
                <div class="px-7 py-5 bg-gradient-to-r from-slate-50 to-blue-50/30 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h3 class="font-black text-slate-900"><?php echo htmlspecialchars($ec['title']); ?></h3>
                        <p class="text-[10px] font-bold text-slate-400 uppercase mt-0.5">
                            <?php echo count($avail_units); ?> unit(s) available &nbsp;·&nbsp;
                            <?php echo count(array_filter($avail_units, fn($u) => in_array($u['id'], $my_unit_ids))); ?> registered
                        </p>
                    </div>
                    <?php if (!empty($avail_units)): ?>
                    <form action="unit_actions.php" method="POST">
                        <input type="hidden" name="action" value="register_all_units">
                        <input type="hidden" name="course_id" value="<?php echo $ec['id']; ?>">
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-black text-[9px] uppercase rounded-xl tracking-widest transition-all">
                            <i class="fa-solid fa-check-double mr-1"></i>Register All
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

                <?php if (empty($avail_units)): ?>
                <div class="px-7 py-8 text-center">
                    <p class="text-slate-400 text-sm italic">No units have been added to this course yet.</p>
                    <p class="text-slate-300 text-xs mt-1">Your admin will add them soon.</p>
                </div>
                <?php else: ?>
                <div class="divide-y divide-slate-50">
                <?php foreach ($avail_units as $av):
                    $is_registered = in_array($av['id'], $my_unit_ids);
                ?>
                <div class="px-7 py-4 flex items-center justify-between group">
                    <div class="flex items-center space-x-4">
                        <div class="w-10 h-10 rounded-2xl flex items-center justify-center flex-shrink-0 <?php echo $is_registered ? 'bg-emerald-100' : 'bg-slate-100'; ?>">
                            <i class="fa-solid fa-book <?php echo $is_registered ? 'text-emerald-600' : 'text-slate-400'; ?>"></i>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <p class="font-black text-slate-900 text-sm"><?php echo htmlspecialchars($av['title']); ?></p>
                                <?php if ($av['unit_code']): ?>
                                <span class="text-[9px] font-black uppercase bg-blue-50 text-blue-600 px-1.5 py-0.5 rounded-md"><?php echo htmlspecialchars($av['unit_code']); ?></span>
                                <?php endif; ?>
                                <?php if ($is_registered): ?>
                                <span class="text-[9px] font-black uppercase bg-emerald-50 text-emerald-600 px-2 py-0.5 rounded-lg"><i class="fa-solid fa-check mr-0.5"></i>Registered</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($av['description']): ?>
                            <p class="text-xs text-slate-400 mt-0.5"><?php echo htmlspecialchars($av['description']); ?></p>
                            <?php endif; ?>
                            <p class="text-[9px] text-slate-400 font-bold mt-0.5">
                                <?php if ($av['lecturer_name']): ?><i class="fa-solid fa-chalkboard-teacher mr-1"></i><?php echo htmlspecialchars($av['lecturer_name']); ?> &nbsp;·&nbsp; <?php endif; ?>
                                <i class="fa-solid fa-users mr-1"></i><?php echo $av['reg_count']; ?> student(s)
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <?php if ($is_registered): ?>
                        <button onclick="loadMaterials(<?php echo $ec['id']; ?>, '<?php echo addslashes($av['title']); ?>', <?php echo $av['id']; ?>, '<?php echo addslashes($ec['title']); ?>', '<?php echo addslashes($av['unit_code'] ?? ''); ?>')"
                            class="px-4 py-2 bg-indigo-50 hover:bg-indigo-600 text-indigo-600 hover:text-white border border-indigo-100 font-black text-[9px] uppercase rounded-xl tracking-widest transition-all">
                            <i class="fa-solid fa-folder-open mr-1"></i>Materials
                        </button>
                        <?php endif; ?>
                        <form action="unit_actions.php" method="POST">
                            <?php if ($is_registered): ?>
                            <input type="hidden" name="action" value="deregister_unit">
                            <input type="hidden" name="unit_id" value="<?php echo $av['id']; ?>">
                            <button type="submit"
                                class="px-4 py-2 bg-red-50 hover:bg-red-600 text-red-500 hover:text-white border border-red-100 font-black text-[9px] uppercase rounded-xl tracking-widest transition-all">
                                <i class="fa-solid fa-minus mr-1"></i>Drop
                            </button>
                            <?php else: ?>
                            <input type="hidden" name="action" value="register_unit">
                            <input type="hidden" name="unit_id" value="<?php echo $av['id']; ?>">
                            <button type="submit"
                                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-black text-[9px] uppercase rounded-xl tracking-widest transition-all shadow-sm">
                                <i class="fa-solid fa-plus mr-1"></i>Register
                            </button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <!-- END MY UNITS -->

        <!-- ═══════════════════════════════════════════════════════════
             MY RESULTS — per unit, per assessment component breakdown
        ═══════════════════════════════════════════════════════════ -->
        <div id="view-my-results" class="view-section">
            <?php
            // ── Ensure tables ─────────────────────────────────────
            foreach ([
                "CREATE TABLE IF NOT EXISTS `unit_assessments` (`id` INT AUTO_INCREMENT PRIMARY KEY, `unit_id` INT NOT NULL, `name` VARCHAR(100) NOT NULL, `type` ENUM('coursework','exam') NOT NULL DEFAULT 'coursework', `max_mark` DECIMAL(6,2) NOT NULL DEFAULT 100.00, `sort_order` TINYINT DEFAULT 0, `created_by` INT DEFAULT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY(`unit_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS `unit_marks` (`id` INT AUTO_INCREMENT PRIMARY KEY, `assessment_id` INT NOT NULL, `student_id` INT NOT NULL, `mark` DECIMAL(6,2) DEFAULT NULL, `remarks` VARCHAR(255) DEFAULT NULL, `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY(`assessment_id`,`student_id`), KEY(`student_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS `course_units` (`id` INT AUTO_INCREMENT PRIMARY KEY, `course_id` INT NOT NULL, `title` VARCHAR(255) NOT NULL, `unit_code` VARCHAR(50) DEFAULT NULL, `description` TEXT DEFAULT NULL, `lecturer_id` INT DEFAULT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY(`course_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS `unit_registrations` (`id` INT AUTO_INCREMENT PRIMARY KEY, `student_id` INT NOT NULL, `unit_id` INT NOT NULL, `registered_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY(`student_id`,`unit_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            ] as $tsql_r) mysqli_query($conn, $tsql_r);

            // Fetch all units this student is registered for
            $my_results_res = mysqli_query($conn,
                "SELECT cu.id AS unit_id, cu.title AS unit_title, cu.unit_code,
                        c.title AS course_title,
                        lect.full_name AS lecturer_name
                 FROM unit_registrations ur
                 JOIN course_units cu ON cu.id = ur.unit_id
                 JOIN courses c ON c.id = cu.course_id
                 LEFT JOIN users lect ON lect.id = cu.lecturer_id
                 WHERE ur.student_id = '$user_id'
                 ORDER BY c.title ASC, cu.title ASC"
            );
            $my_units_results = [];
            while ($r = mysqli_fetch_assoc($my_results_res)) $my_units_results[] = $r;

            // For each unit fetch assessments + my marks
            $units_with_marks = [];
            foreach ($my_units_results as $ur2) {
                $uid = intval($ur2['unit_id']);
                $assess_res2 = mysqli_query($conn,
                    "SELECT ua.id, ua.name, ua.type, ua.max_mark,
                            um.mark, um.remarks, um.updated_at
                     FROM unit_assessments ua
                     LEFT JOIN unit_marks um ON um.assessment_id = ua.id AND um.student_id = $user_id
                     WHERE ua.unit_id = $uid
                     ORDER BY ua.sort_order ASC, ua.id ASC"
                );
                $assessments2 = [];
                while ($a2 = mysqli_fetch_assoc($assess_res2)) $assessments2[] = $a2;

                $sum_marks = 0; $sum_max = 0; $any_marks = false;
                foreach ($assessments2 as $a2) {
                    $sum_max += floatval($a2['max_mark']);
                    if ($a2['mark'] !== null) { $sum_marks += floatval($a2['mark']); $any_marks = true; }
                }
                $pct2 = ($sum_max > 0 && $any_marks) ? round($sum_marks / $sum_max * 100, 1) : null;

                $units_with_marks[] = array_merge($ur2, [
                    'assessments' => $assessments2,
                    'sum_marks'   => $sum_marks,
                    'sum_max'     => $sum_max,
                    'pct'         => $pct2,
                    'any_marks'   => $any_marks,
                ]);
            }

            $grade_fn_s = function($pct) {
                if ($pct >= 70) return ['A', 'bg-emerald-100 text-emerald-700 border-emerald-200'];
                if ($pct >= 60) return ['B', 'bg-blue-100 text-blue-700 border-blue-200'];
                if ($pct >= 50) return ['C', 'bg-indigo-100 text-indigo-700 border-indigo-200'];
                if ($pct >= 40) return ['D', 'bg-amber-100 text-amber-700 border-amber-200'];
                return ['F', 'bg-red-100 text-red-700 border-red-200'];
            };

            $graded_units2 = array_filter($units_with_marks, fn($u) => $u['any_marks']);
            $overall_avg2  = count($graded_units2) > 0
                ? round(array_sum(array_column($graded_units2,'pct'))/count($graded_units2),1)
                : null;
            ?>

            <div class="mb-8">
                <h2 class="text-2xl font-black text-slate-900">My Academic Results</h2>
                <p class="text-slate-500 text-sm mt-1">Unit-by-unit breakdown with all assessment components.</p>
            </div>

            <?php if (!empty($graded_units2)): ?>
            <!-- Summary banner -->
            <div class="grid grid-cols-3 gap-4 mb-8">
                <div class="bg-white rounded-2xl border border-slate-100 p-5 text-center shadow-sm">
                    <p class="text-[9px] font-black uppercase text-slate-400 mb-1">Units Graded</p>
                    <p class="text-3xl font-black text-slate-900"><?php echo count($graded_units2); ?></p>
                </div>
                <div class="bg-white rounded-2xl border border-slate-100 p-5 text-center shadow-sm">
                    <p class="text-[9px] font-black uppercase text-slate-400 mb-1">Overall Avg</p>
                    <p class="text-3xl font-black <?php echo $overall_avg2 >= 50 ? 'text-emerald-600' : 'text-red-600'; ?>"><?php echo $overall_avg2; ?><span class="text-base font-bold text-slate-400">%</span></p>
                </div>
                <div class="bg-white rounded-2xl border border-slate-100 p-5 text-center shadow-sm">
                    <?php [$ov_g, $ov_c] = $grade_fn_s($overall_avg2 ?? 0); ?>
                    <p class="text-[9px] font-black uppercase text-slate-400 mb-1">Overall Grade</p>
                    <p class="text-3xl font-black <?php echo explode(' ',$ov_c)[1]; ?>"><?php echo $overall_avg2 !== null ? $ov_g : '—'; ?></p>
                </div>
            </div>
            <?php endif; ?>

            <?php if (empty($my_units_results)): ?>
            <div class="bg-white rounded-2xl border border-slate-100 p-14 text-center shadow-sm">
                <div class="w-16 h-16 bg-blue-50 rounded-2xl flex items-center justify-center mx-auto mb-4">
                    <i class="fa-solid fa-layer-group text-blue-300 text-2xl"></i>
                </div>
                <p class="text-slate-600 font-bold">You haven't registered for any units yet.</p>
                <button onclick="switchView('my-units')" class="mt-4 px-6 py-3 bg-blue-600 text-white font-black text-xs uppercase rounded-2xl shadow-lg hover:bg-blue-700 transition-all">
                    <i class="fa-solid fa-layer-group mr-2"></i>Go to My Units
                </button>
            </div>

            <?php else: ?>
            <div class="space-y-6">
            <?php foreach ($units_with_marks as $uwm):
                $has_m = $uwm['any_marks'];
                $pct3  = $uwm['pct'];
                [$grade3, $gClass3] = $pct3 !== null ? $grade_fn_s($pct3) : ['—','text-slate-300 bg-slate-50 border-slate-100'];
            ?>
            <div class="bg-white rounded-2xl border <?php echo $has_m ? 'border-slate-100' : 'border-dashed border-slate-200'; ?> overflow-hidden shadow-sm">
                <!-- Unit header -->
                <div class="px-6 py-4 flex items-center justify-between bg-gradient-to-r from-slate-50 to-blue-50/30 border-b border-slate-100">
                    <div>
                        <div class="flex items-center gap-2 mb-0.5">
                            <h3 class="font-black text-slate-900 text-sm"><?php echo htmlspecialchars($uwm['unit_title']); ?></h3>
                            <?php if ($uwm['unit_code']): ?>
                            <span class="text-[9px] font-black bg-blue-50 text-blue-600 px-1.5 py-0.5 rounded-md"><?php echo htmlspecialchars($uwm['unit_code']); ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="text-[10px] text-slate-400 font-bold">
                            <?php echo htmlspecialchars($uwm['course_title']); ?>
                            <?php if ($uwm['lecturer_name']): ?> &nbsp;·&nbsp; <i class="fa-solid fa-chalkboard-teacher mr-0.5"></i><?php echo htmlspecialchars($uwm['lecturer_name']); ?><?php endif; ?>
                        </p>
                    </div>
                    <?php if ($has_m): ?>
                    <div class="flex items-center space-x-3">
                        <div class="text-right">
                            <p class="text-[9px] font-black uppercase text-slate-400">Score</p>
                            <p class="text-2xl font-black <?php echo $pct3 >= 40 ? 'text-slate-900' : 'text-red-600'; ?>"><?php echo $pct3; ?><span class="text-sm font-bold text-slate-400">%</span></p>
                        </div>
                        <span class="border text-lg font-black px-4 py-2 rounded-xl <?php echo $gClass3; ?>"><?php echo $grade3; ?></span>
                    </div>
                    <?php else: ?>
                    <span class="text-[9px] font-black uppercase text-slate-400 bg-slate-100 border border-slate-200 px-3 py-1 rounded-xl">
                        <i class="fa-solid fa-hourglass-half mr-1"></i>Pending
                    </span>
                    <?php endif; ?>
                </div>

                <?php if (empty($uwm['assessments'])): ?>
                <div class="px-6 py-5 text-center">
                    <p class="text-slate-400 text-sm italic">No assessments defined for this unit yet.</p>
                </div>

                <?php else: ?>
                <!-- Assessment breakdown -->
                <div class="px-6 py-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-4">
                    <?php foreach ($uwm['assessments'] as $a3):
                        $isExam    = $a3['type'] === 'exam';
                        $has_mark3 = $a3['mark'] !== null;
                        $bg = $isExam ? 'bg-red-50/60 border-red-100' : 'bg-blue-50/60 border-blue-100';
                        $ic = $isExam ? 'fa-file-signature text-red-500' : 'fa-book-open text-blue-500';
                        $tc = $isExam ? 'text-red-700' : 'text-blue-700';
                        $fill_c = $isExam ? 'bg-red-400' : 'bg-blue-400';
                        $bar_pct = ($has_mark3 && floatval($a3['max_mark']) > 0) ? min(100, round(floatval($a3['mark']) / floatval($a3['max_mark']) * 100)) : 0;
                    ?>
                    <div class="border rounded-2xl p-4 <?php echo $bg; ?>">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center space-x-2">
                                <div class="w-7 h-7 rounded-lg <?php echo $isExam ? 'bg-red-100' : 'bg-blue-100'; ?> flex items-center justify-center">
                                    <i class="fa-solid <?php echo $ic; ?> text-xs"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-black <?php echo $tc; ?>"><?php echo htmlspecialchars($a3['name']); ?></p>
                                    <p class="text-[9px] font-bold opacity-60"><?php echo ucfirst($a3['type']); ?></p>
                                </div>
                            </div>
                            <span class="text-sm font-black <?php echo $tc; ?>">
                                <?php echo $has_mark3 ? number_format(floatval($a3['mark']),1) : '—'; ?>
                                <span class="text-[9px] font-bold opacity-60">/ <?php echo number_format(floatval($a3['max_mark']),0); ?></span>
                            </span>
                        </div>
                        <?php if ($has_mark3): ?>
                        <div class="h-1.5 <?php echo $isExam ? 'bg-red-100' : 'bg-blue-100'; ?> rounded-full overflow-hidden">
                            <div class="h-full <?php echo $fill_c; ?> rounded-full" style="width:<?php echo $bar_pct; ?>%"></div>
                        </div>
                        <p class="text-[9px] opacity-60 font-bold mt-1"><?php echo $bar_pct; ?>%</p>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    </div>

                    <?php if ($has_m): ?>
                    <!-- Final score bar -->
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="text-[10px] font-black uppercase text-slate-500">Final Score</span>
                        <span class="text-[10px] font-black text-slate-600"><?php echo number_format($uwm['sum_marks'],1); ?> / <?php echo number_format($uwm['sum_max'],1); ?> &nbsp;(<?php echo $pct3; ?>%)</span>
                    </div>
                    <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                        <div class="h-full rounded-full transition-all duration-1000
                            <?php echo $pct3 >= 70 ? 'bg-emerald-500' : ($pct3 >= 50 ? 'bg-blue-500' : ($pct3 >= 40 ? 'bg-amber-500' : 'bg-red-500')); ?>"
                            style="width:<?php echo min(100,$pct3); ?>%"></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <!-- END MY RESULTS -->

        <!-- ── MY ATTENDANCE ──────────────────────────────────────────── -->
        <div id="view-my-attendance" class="view-section p-4 lg:p-8">
            <style>
            .att-course-tab { display:inline-flex; align-items:center; gap:7px; padding:8px 16px; border-radius:10px; font-size:12px; font-weight:700; cursor:pointer; border:1.5px solid #e2e8f0; background:#fff; color:#64748b; transition:all .2s; white-space:nowrap; }
            .att-course-tab:hover { border-color:#3b82f6; color:#3b82f6; background:#eff6ff; }
            .att-course-tab.active { background:#3b82f6; color:#fff; border-color:#3b82f6; box-shadow:0 4px 12px rgba(59,130,246,.3); }
            .att-unit-card { background:#fff; border-radius:18px; border:1.5px solid #e2e8f0; overflow:hidden; margin-bottom:14px; transition:box-shadow .2s; }
            .att-unit-card:hover { box-shadow:0 6px 20px rgba(0,0,0,.07); }
            .att-unit-card.barred { border-color:#fca5a5; background:#fff8f8; }
            @keyframes attIn { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:none} }
            .att-unit-card { animation:attIn .35s ease forwards; }
            </style>

            <!-- Header -->
            <div class="mb-5">
                <h2 class="text-xl font-black text-slate-900 lg:text-2xl">My Attendance</h2>
                <p class="text-slate-400 text-xs mt-1">Click a course tab to view its unit attendance. Absences above 33.33% = exam barred.</p>
            </div>

            <?php if ($barred_count > 0): ?>
            <div class="mb-5 bg-red-50 border border-red-200 rounded-2xl p-4 flex items-start gap-3">
                <div class="w-9 h-9 bg-red-100 rounded-xl flex items-center justify-center flex-shrink-0">
                    <i class="fa-solid fa-triangle-exclamation text-red-500 text-sm"></i>
                </div>
                <div>
                    <p class="font-black text-red-700 text-sm">⚠️ Exam Bar Warning</p>
                    <p class="text-red-500 text-xs mt-1">You exceed 33.33% absence in <strong><?php echo $barred_count; ?></strong> unit(s) and may not sit the exam for those units.</p>
                </div>
            </div>
            <?php endif; ?>

            <?php if (empty($student_attendance)): ?>
            <div class="bg-white rounded-2xl border border-dashed border-slate-200 p-12 text-center">
                <i class="fa-solid fa-calendar-xmark text-slate-200 text-5xl mb-4 block"></i>
                <p class="text-slate-500 font-bold">No attendance records yet.</p>
                <p class="text-slate-400 text-sm mt-1">Your lecturer hasn't recorded any sessions yet.</p>
            </div>
            <?php else: ?>

            <?php
            // Group attendance records by course
            $att_by_course = [];
            foreach ($student_attendance as $att) {
                $ct = $att['course_title'] ?? 'General';
                if (!isset($att_by_course[$ct])) $att_by_course[$ct] = [];
                $att_by_course[$ct][] = $att;
            }
            $course_keys = array_keys($att_by_course);
            $first_course_id = 'att-course-0';
            ?>

            <!-- Course filter tabs -->
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;overflow-x:auto;padding-bottom:4px;">
                <button class="att-course-tab active" onclick="filterAttCourse('all',this)">
                    <i class="fa-solid fa-layer-group"></i> All Units
                    <span style="background:rgba(255,255,255,.25);padding:1px 7px;border-radius:10px;font-size:10px;"><?php echo count($student_attendance); ?></span>
                </button>
                <?php foreach ($course_keys as $ci => $cname): ?>
                <button class="att-course-tab" onclick="filterAttCourse('course-<?php echo $ci; ?>',this)">
                    <i class="fa-solid fa-book-open"></i>
                    <?php echo htmlspecialchars($cname); ?>
                    <span style="background:#e2e8f0;color:#475569;padding:1px 7px;border-radius:10px;font-size:10px;"><?php echo count($att_by_course[$cname]); ?></span>
                </button>
                <?php endforeach; ?>
            </div>

            <!-- Unit cards (each tagged with course group) -->
            <div id="att-cards-container">
                <?php foreach ($student_attendance as $ai => $att):
                    $apct   = floatval($att['absence_pct'] ?? 0);
                    $ppct   = $att['total_sessions'] > 0 ? round(100 - $apct, 1) : 0;
                    $barred = $att['barred'];
                    $color  = $barred ? '#ef4444' : ($apct > 20 ? '#f59e0b' : '#10b981');
                    $border = $barred ? 'barred' : '';
                    // find which course index this unit belongs to
                    $ci_key = array_search($att['course_title'] ?? 'General', $course_keys);
                ?>
                <div class="att-unit-card <?php echo $border; ?>"
                     data-course="course-<?php echo $ci_key; ?>"
                     style="animation-delay:<?php echo ($ai * 0.04); ?>s">

                    <!-- Unit header -->
                    <div style="padding:14px 20px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;background:linear-gradient(135deg,#f8fafc,#eff6ff20);">
                        <div>
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                <span style="font-weight:800;font-size:14px;color:#0f172a;"><?php echo htmlspecialchars($att['unit_title']); ?></span>
                                <?php if ($att['unit_code']): ?>
                                <span style="background:#dbeafe;color:#1e40af;font-size:9px;font-weight:800;padding:2px 8px;border-radius:6px;letter-spacing:.05em;"><?php echo htmlspecialchars($att['unit_code']); ?></span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:11px;color:#94a3b8;margin-top:3px;display:flex;align-items:center;gap:5px;">
                                <i class="fa-solid fa-book" style="font-size:9px;"></i>
                                <?php echo htmlspecialchars($att['course_title']); ?>
                            </div>
                        </div>
                        <?php if ($att['total_sessions'] == 0): ?>
                        <span style="font-size:10px;font-weight:800;color:#94a3b8;border:1.5px solid #e2e8f0;padding:5px 12px;border-radius:10px;">No Sessions Yet</span>
                        <?php elseif ($barred): ?>
                        <span style="font-size:10px;font-weight:800;color:#dc2626;background:#fee2e2;border:1.5px solid #fca5a5;padding:5px 12px;border-radius:10px;animation:pulse 2s infinite;">
                            <i class="fa-solid fa-ban"></i> EXAM BARRED
                        </span>
                        <?php else: ?>
                        <span style="font-size:10px;font-weight:800;color:#059669;background:#d1fae5;border:1.5px solid #6ee7b7;padding:5px 12px;border-radius:10px;">
                            <i class="fa-solid fa-circle-check"></i> Exam Eligible
                        </span>
                        <?php endif; ?>
                    </div>

                    <!-- Unit body -->
                    <div style="padding:16px 20px;">
                        <?php if ($att['total_sessions'] == 0): ?>
                        <p style="color:#94a3b8;font-size:12px;text-align:center;padding:10px 0;font-style:italic;">No attendance sessions recorded yet.</p>
                        <?php else: ?>
                        <!-- Stats -->
                        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px;">
                            <div style="text-align:center;background:#f8fafc;border-radius:12px;padding:12px 8px;">
                                <div style="font-size:22px;font-weight:900;color:#0f172a;"><?php echo intval($att['total_sessions']); ?></div>
                                <div style="font-size:9px;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:.06em;margin-top:2px;">Sessions</div>
                            </div>
                            <div style="text-align:center;background:#f0fdf4;border-radius:12px;padding:12px 8px;">
                                <div style="font-size:22px;font-weight:900;color:#10b981;"><?php echo intval($att['attended']); ?></div>
                                <div style="font-size:9px;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:.06em;margin-top:2px;">Attended</div>
                            </div>
                            <div style="text-align:center;background:<?php echo $barred?'#fef2f2':($apct>20?'#fffbeb':'#f8fafc'); ?>;border-radius:12px;padding:12px 8px;">
                                <div style="font-size:22px;font-weight:900;color:<?php echo $color; ?>;"><?php echo intval($att['absences']); ?></div>
                                <div style="font-size:9px;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:.06em;margin-top:2px;">Absent</div>
                            </div>
                        </div>
                        <!-- Progress bar -->
                        <div>
                            <div style="display:flex;justify-content:space-between;font-size:10px;font-weight:800;text-transform:uppercase;margin-bottom:5px;">
                                <span style="color:#10b981;">Present: <?php echo $ppct; ?>%</span>
                                <span style="color:<?php echo $color; ?>;">Absent: <?php echo $apct; ?>%<?php if($barred): ?> ⚠<?php endif; ?></span>
                            </div>
                            <div style="height:10px;background:#f1f5f9;border-radius:9999px;overflow:hidden;display:flex;">
                                <div style="width:<?php echo $ppct; ?>%;background:#10b981;height:100%;border-radius:9999px 0 0 9999px;transition:width 1s ease;"></div>
                                <div style="width:<?php echo min(100,$apct); ?>%;background:<?php echo $color; ?>;height:100%;transition:width 1s ease;"></div>
                            </div>
                            <!-- 33.33% marker -->
                            <div style="position:relative;margin-top:4px;">
                                <div style="position:absolute;left:33.33%;width:2px;height:8px;background:#ef4444;opacity:.5;border-radius:1px;top:-12px;"></div>
                                <div style="font-size:9px;color:#ef4444;opacity:.7;padding-left:calc(33.33% - 20px);">33.33% limit</div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php endif; ?>

            <script>
            function filterAttCourse(group, btn) {
                // Update active tab
                document.querySelectorAll('.att-course-tab').forEach(t => t.classList.remove('active'));
                btn.classList.add('active');

                // Show/hide cards
                document.querySelectorAll('#att-cards-container .att-unit-card').forEach(card => {
                    if (group === 'all' || card.dataset.course === group) {
                        card.style.display = '';
                        // Re-trigger animation
                        card.style.animation = 'none';
                        card.offsetHeight;
                        card.style.animation = '';
                    } else {
                        card.style.display = 'none';
                    }
                });
            }
            </script>
        </div>
        <!-- END MY ATTENDANCE -->


    <!-- ══════════════════════════════════════════════════════════════ -->
    <!-- FEES & PAYMENTS SECTION -->
    <!-- ══════════════════════════════════════════════════════════════ -->
    <div id="view-my-fees" class="view-section">
    <style>
    @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700&display=swap');

    /* ── Wrapper ──────────────────────────────────────────────── */
    .fp-wrap { padding:16px; max-width:1100px; margin:0 auto; }
    @media(min-width:640px){ .fp-wrap { padding:24px; } }
    @media(min-width:1024px){ .fp-wrap { padding:32px 36px; } }

    /* ── KPI grid ─────────────────────────────────────────────── */
    .fp-kpi-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:20px; }
    @media(min-width:768px){ .fp-kpi-grid { grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:24px; } }

    .fp-kpi { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:14px 16px; position:relative; overflow:hidden; }
    .fp-kpi .bar { position:absolute; top:0;left:0;right:0;height:3px; }
    .fp-kpi-label { font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.1em; color:#94a3b8; }
    .fp-kpi-val { font-family:'JetBrains Mono',monospace; font-size:19px; font-weight:900; margin:6px 0 3px; line-height:1.1; }
    @media(min-width:640px){ .fp-kpi-val { font-size:22px; } }
    .fp-kpi-sub { font-size:10px; margin-top:3px; }
    .fp-prog { height:6px; border-radius:9999px; background:#f1f5f9; overflow:hidden; margin-top:6px; }
    .fp-prog-fill { height:100%; border-radius:9999px; transition:width 1.4s cubic-bezier(.22,1,.36,1); }

    /* ── Cleared banner ───────────────────────────────────────── */
    .fp-cleared { background:linear-gradient(135deg,#ecfdf5,#d1fae5); border:2px solid #6ee7b7; border-radius:18px; padding:24px 20px; text-align:center; margin-bottom:20px; }
    @media(min-width:640px){ .fp-cleared { padding:28px 40px; } }
    .fp-cleared-icon { width:60px;height:60px;background:linear-gradient(135deg,#10b981,#34d399);border-radius:18px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;box-shadow:0 8px 20px rgba(16,185,129,.3); }
    .fp-cleared-title { font-size:22px;font-weight:900;color:#065f46;letter-spacing:-.5px; }
    .fp-cleared-sub { font-size:13px;color:#059669;margin-top:4px; }
    .fp-cleared-stamp { display:inline-flex;align-items:center;gap:6px;background:#10b981;color:#fff;font-size:12px;font-weight:800;padding:7px 18px;border-radius:30px;margin-top:12px; }

    /* ── Section card ─────────────────────────────────────────── */
    .fp-card { background:#fff; border:1px solid #e2e8f0; border-radius:18px; overflow:hidden; margin-bottom:18px; }
    .fp-card-head { padding:14px 18px; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; gap:10px; }
    @media(min-width:640px){ .fp-card-head { padding:16px 22px; } }
    .fp-card-icon { width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
    .fp-card-title { font-size:14px;font-weight:800;color:#0f172a; }
    .fp-card-sub { font-size:11px;color:#94a3b8;margin-top:1px; }

    /* ── Reminder banner ──────────────────────────────────────── */
    .fp-reminder { background:linear-gradient(135deg,#fffbeb,#fef3c7); border:1.5px solid #fcd34d; border-radius:14px; padding:14px 18px; margin-bottom:12px; display:flex; align-items:flex-start; gap:12px; }

    /* ── Status badges ────────────────────────────────────────── */
    .fp-badge { display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap; }
    .fp-badge-cleared { background:#d1fae5; color:#065f46; }
    .fp-badge-paid    { background:#d1fae5; color:#065f46; }
    .fp-badge-partial { background:#fef3c7; color:#92400e; }
    .fp-badge-pending { background:#f1f5f9; color:#475569; }
    .fp-badge-overdue { background:#fee2e2; color:#991b1b; }
    .fp-badge-waived  { background:#ede9fe; color:#4c1d95; }

    /* ── Desktop table ────────────────────────────────────────── */
    .fp-tbl-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
    .fp-tbl { width:100%; border-collapse:collapse; min-width:600px; }
    .fp-tbl th { background:#f8fafc; padding:9px 14px; text-align:left; font-size:10px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:#64748b; border-bottom:1px solid #e2e8f0; white-space:nowrap; }
    .fp-tbl td { padding:12px 14px; border-bottom:1px solid #f8fafc; font-size:13px; vertical-align:middle; }
    .fp-tbl tr:last-child td { border-bottom:none; }
    .fp-tbl tr:hover td { background:#fafafa; }
    .fp-mono { font-family:'JetBrains Mono',monospace; }

    /* ── Mobile fee cards ─────────────────────────────────────── */
    .fp-mob-list { display:none; padding:12px; }
    .fp-mob-item { border:1px solid #e2e8f0; border-radius:14px; padding:14px 16px; margin-bottom:10px; }
    .fp-mob-item:last-child { margin-bottom:0; }
    .fp-mob-top { display:flex; align-items:flex-start; justify-content:space-between; gap:8px; margin-bottom:10px; }
    .fp-mob-name { font-weight:700; font-size:13px; color:#0f172a; }
    .fp-mob-row { display:flex; justify-content:space-between; align-items:center; padding:6px 0; border-bottom:1px solid #f8fafc; }
    .fp-mob-row:last-child { border-bottom:none; padding-bottom:0; }
    .fp-mob-key { font-size:11px; font-weight:600; color:#94a3b8; }
    .fp-mob-val { font-size:12px; font-weight:700; color:#0f172a; text-align:right; }

    /* ── Mobile payment cards ─────────────────────────────────── */
    .fp-pay-mob-list { display:none; padding:12px; }
    .fp-pay-mob-item { border:1px solid #e2e8f0; border-radius:14px; padding:14px 16px; margin-bottom:10px; }

    /* ── Switch show/hide at 640px ────────────────────────────── */
    @media(max-width:640px){
        .fp-tbl-wrap  { display:none !important; }
        .fp-mob-list  { display:block !important; }
        .fp-pay-tbl   { display:none !important; }
        .fp-pay-mob-list { display:block !important; }
    }

    @keyframes fpSlide { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:none} }
    .fp-anim { animation:fpSlide .4s ease forwards; }
    </style>

    <div class="fp-wrap fp-anim">

        <!-- ── Page header ──────────────────────────────────── -->
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:20px;">
            <div>
                <h1 style="font-size:20px;font-weight:900;color:#0f172a;letter-spacing:-.4px;margin:0;">Fees &amp; Payments</h1>
                <p style="font-size:12px;color:#64748b;margin:3px 0 0;"><?php echo date('F Y'); ?> &nbsp;·&nbsp; Your financial account</p>
            </div>
            <?php if ($unread_reminders > 0): ?>
            <form method="POST" style="flex-shrink:0;">
                <input type="hidden" name="mark_reminders_read" value="1">
                <button type="submit" style="display:flex;align-items:center;gap:7px;background:#fef3c7;border:1.5px solid #fcd34d;color:#92400e;padding:8px 14px;border-radius:10px;font-size:12px;font-weight:700;cursor:pointer;">
                    <i class="fa-solid fa-bell"></i>
                    <?php echo $unread_reminders; ?> New Reminder<?php echo $unread_reminders>1?'s':''; ?>
                </button>
            </form>
            <?php endif; ?>
        </div>

        <!-- ── Reminders ────────────────────────────────────── -->
        <?php foreach (array_filter($fee_reminders, fn($r)=>!$r['is_read']) as $rem): ?>
        <div class="fp-reminder">
            <div style="width:32px;height:32px;border-radius:9px;background:#f59e0b;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fa-solid fa-bell" style="color:#fff;font-size:12px;"></i>
            </div>
            <div>
                <div style="font-size:10px;font-weight:800;color:#92400e;text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px;">
                    Finance Office · <?php echo date('d M Y', strtotime($rem['created_at'])); ?>
                </div>
                <div style="font-size:12px;color:#78350f;white-space:pre-line;line-height:1.6;"><?php echo htmlspecialchars($rem['message']); ?></div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- ── CLEARED banner (show only when fully paid) ───── -->
        <?php if ($total_fees_assigned > 0 && $total_fee_balance <= 0): ?>
        <div class="fp-cleared">
            <div class="fp-cleared-icon">
                <i class="fa-solid fa-check" style="color:#fff;font-size:26px;"></i>
            </div>
            <div class="fp-cleared-title">All Fees Cleared!</div>
            <div class="fp-cleared-sub">Your account is fully settled for this period. Great work!</div>
            <div class="fp-cleared-stamp">
                <i class="fa-solid fa-shield-check"></i> CLEARED — KES <?php echo number_format($total_fees_paid,0); ?> paid
            </div>
        </div>
        <?php endif; ?>

        <!-- ── KPI cards ────────────────────────────────────── -->
        <div class="fp-kpi-grid">
            <!-- Total Fees -->
            <div class="fp-kpi">
                <div class="bar" style="background:#0f172a;"></div>
                <div class="fp-kpi-label">Total Fees</div>
                <div class="fp-kpi-val" style="color:#0f172a;">KES <?php echo number_format($total_fees_assigned,0); ?></div>
                <div class="fp-kpi-sub" style="color:#94a3b8;"><?php echo count($fee_assignments); ?> fee<?php echo count($fee_assignments)!=1?'s':''; ?> assigned</div>
            </div>
            <!-- Paid -->
            <div class="fp-kpi">
                <div class="bar" style="background:#10b981;"></div>
                <div class="fp-kpi-label">Amount Paid</div>
                <div class="fp-kpi-val" style="color:#10b981;">KES <?php echo number_format($total_fees_paid,0); ?></div>
                <div class="fp-prog"><div class="fp-prog-fill" style="width:<?php echo $fee_collection_pct; ?>%;background:#10b981;"></div></div>
                <div class="fp-kpi-sub" style="color:#94a3b8;"><?php echo $fee_collection_pct; ?>% of total</div>
            </div>
            <!-- Balance -->
            <?php
            $bal_color = $total_fee_balance <= 0 ? '#10b981' : ($has_overdue_fees ? '#ef4444' : '#f59e0b');
            $bal_text  = $total_fee_balance <= 0 ? '✓ Cleared' : 'KES '.number_format($total_fee_balance,0);
            $bal_sub   = $total_fee_balance <= 0 ? 'All fees paid!' : ($has_overdue_fees ? '⚠ Overdue — visit Finance' : 'Balance remaining');
            ?>
            <div class="fp-kpi">
                <div class="bar" style="background:<?php echo $bal_color; ?>;"></div>
                <div class="fp-kpi-label">Balance</div>
                <div class="fp-kpi-val" style="color:<?php echo $bal_color; ?>;"><?php echo $bal_text; ?></div>
                <div class="fp-kpi-sub" style="color:<?php echo $bal_color; ?>;"><?php echo $bal_sub; ?></div>
            </div>
            <!-- Transactions -->
            <div class="fp-kpi">
                <div class="bar" style="background:#3b82f6;"></div>
                <div class="fp-kpi-label">Transactions</div>
                <div class="fp-kpi-val" style="color:#3b82f6;"><?php echo count($fee_payments_history); ?></div>
                <div class="fp-kpi-sub" style="color:#94a3b8;">payments recorded</div>
            </div>
        </div>

        <!-- ── Fee Schedule ──────────────────────────────────── -->
        <div class="fp-card">
            <div class="fp-card-head">
                <div class="fp-card-icon" style="background:#d1fae5;">
                    <i class="fa-solid fa-file-invoice-dollar" style="color:#10b981;font-size:14px;"></i>
                </div>
                <div>
                    <div class="fp-card-title">Fee Schedule</div>
                    <div class="fp-card-sub">All fees assigned to your account</div>
                </div>
            </div>

            <?php if (empty($fee_assignments)): ?>
            <div style="text-align:center;padding:40px 20px;">
                <i class="fa-solid fa-circle-check" style="font-size:36px;color:#10b981;display:block;margin-bottom:12px;opacity:.5;"></i>
                <div style="font-weight:700;color:#0f172a;font-size:14px;">No fees assigned yet</div>
                <div style="font-size:12px;color:#94a3b8;margin-top:4px;">The Finance Office hasn't assigned any fees to your account yet.</div>
            </div>
            <?php else: ?>

            <?php
            $cat_col = [
                'tuition'=>['#dbeafe','#1e40af'],'examination'=>['#fce7f3','#831843'],
                'library'=>['#d1fae5','#065f46'],'accommodation'=>['#ede9fe','#4c1d95'],
                'transport'=>['#fef3c7','#92400e'],'medical'=>['#fee2e2','#991b1b'],
                'activity'=>['#e0f2fe','#075985'],'other'=>['#f3f4f6','#374151'],
            ];
            ?>

            <!-- DESKTOP table -->
            <div class="fp-tbl-wrap">
            <table class="fp-tbl">
                <thead>
                    <tr>
                        <th>Fee</th><th>Category</th><th>Period</th>
                        <th>Due Date</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($fee_assignments as $fa):
                    $fa_bal  = floatval($fa['balance']);
                    $fa_paid = floatval($fa['amount_paid']);
                    $fa_net  = floatval($fa['net_amount']);
                    $fa_pct  = $fa_net > 0 ? round(($fa_paid/$fa_net)*100) : 0;
                    [$cbg,$cfg] = $cat_col[$fa['fee_category']] ?? ['#f3f4f6','#374151'];
                    $is_overdue = !empty($fa['due_date']) && strtotime($fa['due_date']) < time() && $fa_bal > 0;
                    // Determine display status
                    if ($fa_bal <= 0)           { $disp_status='cleared'; $disp_label='✓ Cleared'; }
                    elseif ($fa['status']==='waived')  { $disp_status='waived';  $disp_label='Waived'; }
                    elseif ($is_overdue)                { $disp_status='overdue'; $disp_label='Overdue'; }
                    elseif ($fa_paid > 0)               { $disp_status='partial'; $disp_label='Partial'; }
                    else                                { $disp_status='pending'; $disp_label='Pending'; }
                ?>
                <tr>
                    <td>
                        <div style="font-weight:700;font-size:13px;color:#0f172a;"><?php echo htmlspecialchars($fa['fee_name']); ?></div>
                        <?php if (floatval($fa['discount_amount']) > 0): ?>
                        <div style="font-size:10px;color:#10b981;margin-top:2px;">
                            <i class="fa-solid fa-tag"></i> Discount: KES <?php echo number_format($fa['discount_amount'],0); ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td><span class="fp-badge" style="background:<?php echo $cbg;?>;color:<?php echo $cfg;?>;"><?php echo ucfirst($fa['fee_category']); ?></span></td>
                    <td style="font-size:12px;color:#64748b;white-space:nowrap;"><?php echo htmlspecialchars($fa['semester']); ?><br><span style="opacity:.6;"><?php echo htmlspecialchars($fa['academic_year']); ?></span></td>
                    <td style="font-size:12px;color:<?php echo $is_overdue?'#ef4444':'#64748b'; ?>;white-space:nowrap;">
                        <?php echo $fa['due_date'] ? date('d M Y', strtotime($fa['due_date'])) : '—'; ?>
                        <?php if ($is_overdue): ?><div style="background:#fee2e2;color:#991b1b;font-size:9px;font-weight:800;padding:1px 5px;border-radius:4px;display:inline-block;margin-top:2px;">OVERDUE</div><?php endif; ?>
                    </td>
                    <td class="fp-mono" style="white-space:nowrap;">KES <?php echo number_format($fa_net,0); ?></td>
                    <td>
                        <div class="fp-mono" style="color:#10b981;font-weight:700;white-space:nowrap;">KES <?php echo number_format($fa_paid,0); ?></div>
                        <div class="fp-prog" style="width:64px;"><div class="fp-prog-fill" style="width:<?php echo $fa_pct; ?>%;background:#10b981;"></div></div>
                    </td>
                    <td class="fp-mono" style="font-weight:700;color:<?php echo $fa_bal<=0?'#10b981':($is_overdue?'#ef4444':'#f59e0b'); ?>;white-space:nowrap;">
                        <?php echo $fa_bal <= 0 ? '✓ 0' : 'KES '.number_format($fa_bal,0); ?>
                    </td>
                    <td>
                        <span class="fp-badge fp-badge-<?php echo $disp_status; ?>">
                            <?php if ($disp_status==='cleared'): ?><i class="fa-solid fa-shield-check"></i><?php endif; ?>
                            <?php echo $disp_label; ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <!-- MOBILE cards -->
            <div class="fp-mob-list">
                <?php foreach ($fee_assignments as $fa):
                    $fa_bal  = floatval($fa['balance']);
                    $fa_paid = floatval($fa['amount_paid']);
                    $fa_net  = floatval($fa['net_amount']);
                    $fa_pct  = $fa_net > 0 ? round(($fa_paid/$fa_net)*100) : 0;
                    [$cbg,$cfg] = $cat_col[$fa['fee_category']] ?? ['#f3f4f6','#374151'];
                    $is_overdue = !empty($fa['due_date']) && strtotime($fa['due_date']) < time() && $fa_bal > 0;
                    if ($fa_bal <= 0)          { $disp_status='cleared'; $disp_label='✓ Cleared'; }
                    elseif ($fa['status']==='waived') { $disp_status='waived'; $disp_label='Waived'; }
                    elseif ($is_overdue)               { $disp_status='overdue'; $disp_label='⚠ Overdue'; }
                    elseif ($fa_paid > 0)              { $disp_status='partial'; $disp_label='Partial'; }
                    else                               { $disp_status='pending'; $disp_label='Pending'; }
                    $border = $fa_bal<=0?'#6ee7b7':($is_overdue?'#fca5a5':'#e2e8f0');
                ?>
                <div class="fp-mob-item" style="border-color:<?php echo $border; ?>;">
                    <div class="fp-mob-top">
                        <div>
                            <div class="fp-mob-name"><?php echo htmlspecialchars($fa['fee_name']); ?></div>
                            <span class="fp-badge" style="margin-top:5px;background:<?php echo $cbg;?>;color:<?php echo $cfg;?>;"><?php echo ucfirst($fa['fee_category']); ?></span>
                        </div>
                        <span class="fp-badge fp-badge-<?php echo $disp_status; ?>"><?php echo $disp_label; ?></span>
                    </div>
                    <div class="fp-mob-row"><span class="fp-mob-key">Period</span><span class="fp-mob-val"><?php echo $fa['semester'].' · '.$fa['academic_year']; ?></span></div>
                    <div class="fp-mob-row"><span class="fp-mob-key">Total</span><span class="fp-mob-val fp-mono">KES <?php echo number_format($fa_net,0); ?></span></div>
                    <div class="fp-mob-row"><span class="fp-mob-key">Paid</span><span class="fp-mob-val fp-mono" style="color:#10b981;">KES <?php echo number_format($fa_paid,0); ?></span></div>
                    <div class="fp-mob-row">
                        <span class="fp-mob-key">Balance</span>
                        <span class="fp-mob-val fp-mono" style="color:<?php echo $fa_bal<=0?'#10b981':($is_overdue?'#ef4444':'#f59e0b'); ?>;">
                            <?php echo $fa_bal <= 0 ? '✓ Cleared' : 'KES '.number_format($fa_bal,0); ?>
                        </span>
                    </div>
                    <?php if ($fa['due_date']): ?>
                    <div class="fp-mob-row">
                        <span class="fp-mob-key">Due</span>
                        <span class="fp-mob-val" style="color:<?php echo $is_overdue?'#ef4444':'#64748b'; ?>;">
                            <?php echo date('d M Y', strtotime($fa['due_date'])); ?>
                            <?php if ($is_overdue): ?> <span style="background:#fee2e2;color:#991b1b;font-size:9px;font-weight:800;padding:1px 5px;border-radius:4px;">OVERDUE</span><?php endif; ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    <div style="margin-top:10px;">
                        <div class="fp-prog"><div class="fp-prog-fill" style="width:<?php echo $fa_pct; ?>%;background:#10b981;"></div></div>
                        <div style="font-size:10px;color:#94a3b8;margin-top:3px;"><?php echo $fa_pct; ?>% paid</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php endif; ?>
        </div>

        <!-- ── Payment History ───────────────────────────────── -->
        <div class="fp-card">
            <div class="fp-card-head">
                <div class="fp-card-icon" style="background:#dbeafe;">
                    <i class="fa-solid fa-receipt" style="color:#3b82f6;font-size:14px;"></i>
                </div>
                <div>
                    <div class="fp-card-title">Payment History</div>
                    <div class="fp-card-sub"><?php echo count($fee_payments_history); ?> transaction<?php echo count($fee_payments_history)!=1?'s':''; ?> recorded</div>
                </div>
            </div>

            <?php if (empty($fee_payments_history)): ?>
            <div style="text-align:center;padding:32px;color:#94a3b8;font-size:13px;">
                <i class="fa-solid fa-receipt" style="font-size:28px;opacity:.25;display:block;margin-bottom:10px;"></i>
                No payments recorded yet.
            </div>
            <?php else: ?>

            <?php
            $meth_meta = ['cash'=>['fa-money-bill-wave','#10b981'],'mpesa'=>['fa-mobile-screen','#22c55e'],
                'bank_transfer'=>['fa-building-columns','#3b82f6'],'cheque'=>['fa-file-lines','#8b5cf6'],
                'online'=>['fa-globe','#06b6d4'],'scholarship'=>['fa-graduation-cap','#f59e0b']];
            ?>

            <!-- DESKTOP payment table -->
            <div class="fp-tbl-wrap fp-pay-tbl">
            <table class="fp-tbl">
                <thead>
                    <tr><th>Receipt</th><th>Fee</th><th>Amount</th><th>Method</th><th>Reference</th><th>Date</th><th>By</th></tr>
                </thead>
                <tbody>
                <?php foreach ($fee_payments_history as $ph):
                    [$mic,$mcol] = $meth_meta[$ph['payment_method']] ?? ['fa-circle','#94a3b8'];
                ?>
                <tr>
                    <td><span style="background:#f1f5f9;padding:3px 8px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:700;"><?php echo htmlspecialchars($ph['receipt_number']); ?></span></td>
                    <td style="font-size:12px;color:#64748b;"><?php echo htmlspecialchars($ph['fee_name']); ?></td>
                    <td class="fp-mono" style="color:#10b981;font-weight:700;white-space:nowrap;">KES <?php echo number_format($ph['amount_paid'],0); ?></td>
                    <td><span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:<?php echo $mcol; ?>;"><i class="fa-solid <?php echo $mic; ?>"></i><?php echo ucfirst(str_replace('_',' ',$ph['payment_method'])); ?></span></td>
                    <td class="fp-mono" style="font-size:11px;color:#94a3b8;"><?php echo htmlspecialchars($ph['transaction_ref']?:'—'); ?></td>
                    <td style="font-size:12px;color:#64748b;white-space:nowrap;"><?php echo date('d M Y',strtotime($ph['payment_date'])); ?></td>
                    <td style="font-size:12px;color:#94a3b8;"><?php echo htmlspecialchars($ph['recorded_by_name']); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <!-- MOBILE payment cards -->
            <div class="fp-pay-mob-list">
                <?php foreach ($fee_payments_history as $ph):
                    [$mic,$mcol] = $meth_meta[$ph['payment_method']] ?? ['fa-circle','#94a3b8'];
                ?>
                <div class="fp-pay-mob-item">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                        <span style="background:#f1f5f9;padding:4px 10px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:11px;font-weight:700;"><?php echo htmlspecialchars($ph['receipt_number']); ?></span>
                        <span class="fp-mono" style="color:#10b981;font-weight:800;font-size:14px;">KES <?php echo number_format($ph['amount_paid'],0); ?></span>
                    </div>
                    <div style="font-size:12px;color:#64748b;margin-bottom:8px;"><?php echo htmlspecialchars($ph['fee_name']); ?></div>
                    <div style="display:flex;justify-content:space-between;font-size:12px;">
                        <span style="display:inline-flex;align-items:center;gap:5px;font-weight:600;color:<?php echo $mcol; ?>;"><i class="fa-solid <?php echo $mic; ?>"></i><?php echo ucfirst(str_replace('_',' ',$ph['payment_method'])); ?></span>
                        <span style="color:#94a3b8;"><?php echo date('d M Y',strtotime($ph['payment_date'])); ?></span>
                    </div>
                    <?php if ($ph['transaction_ref']): ?>
                    <div style="font-size:10px;color:#94a3b8;font-family:'JetBrains Mono',monospace;margin-top:5px;">Ref: <?php echo htmlspecialchars($ph['transaction_ref']); ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <?php endif; ?>
        </div>

    </div><!-- /.fp-wrap -->
    </div>
    <!-- END FEES SECTION -->


    
        <div id="chatbot-container">
        <div id="chatbot-window">
            <div id="chatbot-header">
                <div class="flex items-center space-x-2">
                    <i class="fa-solid fa-robot"></i>
                    <span class="text-sm tracking-tight uppercase">Smart Assistant</span>
                </div>
                <button onclick="toggleChatbot()" class="text-white hover:text-slate-200"><i class="fa-solid fa-minus"></i></button>
            </div>
            <div id="chatbot-messages" class="flex flex-col">
                <div class="message bot">
                    Hello! I'm your AI academic voice. Ask me about your performance, schedule, or career advice!
                </div>
            </div>
            <div id="chatbot-input-container">
                <input type="text" id="chatbot-input" placeholder="Ask about your score, schedule, or career...">
                <div id="chatbot-send" onclick="sendChatbotMessage()"><i class="fa-solid fa-paper-plane text-xs"></i></div>
            </div>
        </div>
        <div id="chatbot-button" onclick="toggleChatbot()">
            <i class="fa-solid fa-comment-dots text-2xl"></i>
        </div>
    </div>

    <script>
        // ── Navigation ──
        function toggleDropdown() {
            document.getElementById('course-dropdown').classList.toggle('dropdown-active');
            document.getElementById('drop-icon').style.transform =
                document.getElementById('course-dropdown').classList.contains('dropdown-active') ? 'rotate(180deg)' : 'rotate(0)';
        }

        function switchView(viewId) {
            document.querySelectorAll('.view-section').forEach(v => v.classList.remove('active'));
            document.getElementById('view-' + viewId).classList.add('active');
            
            // Remove active state from all items
            document.querySelectorAll('.sidebar-item').forEach(i => i.classList.remove('sidebar-active'));
            
            // Add active state to selected item
            if (viewId === 'overview') document.getElementById('nav-overview').classList.add('sidebar-active');
            if (viewId === 'schedule') document.getElementById('nav-schedule').classList.add('sidebar-active');
            if (viewId === 'assignments') document.getElementById('nav-assignments').classList.add('sidebar-active');
            if (viewId === 'my-units') document.getElementById('nav-my-units').classList.add('sidebar-active');
            if (viewId === 'my-results') document.getElementById('nav-my-results').classList.add('sidebar-active');
            if (viewId === 'my-attendance') document.getElementById('nav-my-attendance').classList.add('sidebar-active');
            if (viewId === 'my-fees') { const n = document.getElementById('nav-my-fees'); if(n) n.classList.add('sidebar-active'); }
            
            document.getElementById('page-title').innerText = viewId.replace('-', ' ').toUpperCase();
        }

        // ── Unit filtering: called when student clicks "View Units" on a course card ──
        function openCourseUnits(courseId, courseName) {
            switchView('my-units');
            // Update title
            document.getElementById('units-page-title').textContent = courseName + ' — Units';
            document.getElementById('units-page-sub').textContent   = 'Units available for ' + courseName + '. Register for the ones you want to take.';
            document.getElementById('units-show-all-btn').classList.remove('hidden');
            // Show only this course's blocks
            document.querySelectorAll('.course-unit-block').forEach(function(el) {
                if (parseInt(el.dataset.courseId) === courseId) {
                    el.style.display = '';
                    el.style.animation = 'fadeIn 0.3s ease';
                } else {
                    el.style.display = 'none';
                }
            });
        }

        function showAllCourseUnits() {
            document.getElementById('units-page-title').textContent = 'My Units';
            document.getElementById('units-page-sub').textContent   = 'Register for the units you want to take within each enrolled course.';
            document.getElementById('units-show-all-btn').classList.add('hidden');
            document.querySelectorAll('.course-unit-block').forEach(function(el) {
                el.style.display = '';
            });
        }

        // ── Unit materials loader (called when student clicks Materials) ──
        const matTypeConf = {
            pdf:   { icon:'fa-file-pdf',    bg:'bg-red-100',  text:'text-red-600',  badge:'bg-red-50 text-red-600 border-red-200',   label:'PDF',   btnIcon:'fa-eye',  btnLabel:'Preview' },
            video: { icon:'fa-circle-play', bg:'bg-blue-100', text:'text-blue-600', badge:'bg-blue-50 text-blue-600 border-blue-200', label:'Video', btnIcon:'fa-play', btnLabel:'Play'    },
            word:  { icon:'fa-file-word',   bg:'bg-sky-100',  text:'text-sky-700',  badge:'bg-sky-50 text-sky-700 border-sky-200',    label:'Word',  btnIcon:'fa-download', btnLabel:'Download'},
            file:  { icon:'fa-file',        bg:'bg-slate-100',text:'text-slate-500',badge:'bg-slate-50 text-slate-500 border-slate-200',label:'File', btnIcon:'fa-download', btnLabel:'Download'},
        };

        function getExt(path) { return (path||'').split('.').pop().toLowerCase(); }
        function resolveType(m) {
            if (m.type && matTypeConf[m.type]) return m.type;
            const ext = getExt(m.file_path);
            if (ext === 'pdf') return 'pdf';
            if (['mp4','mov','avi','webm'].includes(ext)) return 'video';
            if (['doc','docx'].includes(ext)) return 'word';
            return 'file';
        }

        async function loadMaterials(courseId, unitTitle, unitId, courseTitle, unitCode) {
            // Switch to the details view
            switchView('details');

            // Populate banner
            document.getElementById('det-title').textContent  = unitTitle || 'Unit Materials';
            document.getElementById('det-course').textContent = courseTitle || '';
            document.getElementById('det-code').textContent   = unitCode   || '';

            const list = document.getElementById('materials-list');
            // Show spinner
            list.innerHTML = `
                <div class="flex flex-col items-center justify-center py-16 gap-3">
                    <div class="w-10 h-10 border-4 border-indigo-100 border-t-indigo-500 rounded-full animate-spin"></div>
                    <p class="text-slate-400 text-xs font-bold">Loading resources…</p>
                </div>`;

            try {
                const url  = unitId ? `get_materials.php?unit_id=${unitId}` : `get_materials.php?course_id=${courseId}`;
                const resp = await fetch(url);
                const mats = await resp.json();

                // Show / hide filter bar
                const filterBar = document.getElementById('det-filters');
                const types = [...new Set(mats.map(m => resolveType(m)))];
                if (types.length > 1) {
                    filterBar.classList.remove('hidden');
                    filterBar.classList.add('flex');
                } else {
                    filterBar.classList.add('hidden');
                    filterBar.classList.remove('flex');
                }
                // Reset filter tabs
                document.querySelectorAll('.det-ftab').forEach(b => {
                    b.classList.remove('bg-slate-900','text-white','border-slate-900');
                    b.classList.add('bg-white','text-slate-600','border-slate-200');
                });
                const allTab = document.querySelector('.det-ftab[data-filter="all"]');
                if (allTab) { allTab.classList.add('bg-slate-900','text-white','border-slate-900'); allTab.classList.remove('bg-white','text-slate-600','border-slate-200'); }

                // Empty state
                if (!mats.length) {
                    list.innerHTML = `
                        <div class="bg-white rounded-2xl border border-dashed border-slate-200 p-14 text-center">
                            <div class="w-14 h-14 bg-slate-50 rounded-2xl flex items-center justify-center mx-auto mb-4">
                                <i class="fa-solid fa-folder-open text-slate-200 text-3xl"></i>
                            </div>
                            <p class="text-slate-500 font-black text-sm">No resources uploaded yet for this unit.</p>
                            <p class="text-slate-400 text-xs mt-1">Your lecturer will upload notes and videos here soon.</p>
                        </div>`;
                    return;
                }

                // Render materials
                list.innerHTML = '';
                mats.forEach((m, idx) => {
                    const mtype  = resolveType(m);
                    const cfg    = matTypeConf[mtype] || matTypeConf.file;
                    const ext    = getExt(m.file_path);
                    const isVid  = mtype === 'video';
                    const isPdf  = mtype === 'pdf';
                    const prevId = `sprev_${idx}`;
                    const hasInlinePreview = isVid || isPdf;

                    const previewBtn = hasInlinePreview ? `
                        <button onclick="toggleStudentPrev('${prevId}')"
                            class="px-3 py-1.5 bg-slate-50 border border-slate-200 text-slate-500
                                   hover:bg-indigo-50 hover:border-indigo-300 hover:text-indigo-600
                                   rounded-xl text-[9px] font-black uppercase tracking-widest transition-all">
                            <i class="fa-solid ${cfg.btnIcon} mr-1"></i>${cfg.btnLabel}
                        </button>` : '';

                    const dlBtn = `
                        <a href="${m.file_path}" target="_blank"
                           class="w-8 h-8 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-center
                                  text-slate-500 hover:bg-indigo-600 hover:text-white hover:border-indigo-600 transition-all"
                           title="${isVid ? 'Open video' : 'Download'}">
                            <i class="fa-solid ${isVid ? 'fa-arrow-up-right-from-square' : 'fa-download'} text-xs"></i>
                        </a>`;

                    const previewPanel = isVid ? `
                        <div id="${prevId}" class="hidden border-t border-slate-100">
                            <div class="p-4 bg-black rounded-b-2xl">
                                <video controls class="w-full max-h-72 rounded-xl" preload="metadata">
                                    <source src="${m.file_path}"
                                            type="video/${ext==='webm'?'webm':ext==='mov'?'quicktime':'mp4'}">
                                    Your browser does not support video.
                                </video>
                            </div>
                        </div>` : isPdf ? `
                        <div id="${prevId}" class="hidden border-t border-slate-100">
                            <div class="p-4 bg-slate-50">
                                <iframe src="${m.file_path}"
                                        class="w-full h-80 rounded-xl border border-slate-200 bg-white"
                                        title="${m.title}"></iframe>
                                <a href="${m.file_path}" target="_blank"
                                   class="mt-2 block text-center text-[9px] font-black uppercase text-indigo-600 hover:underline">
                                    Open full screen <i class="fa-solid fa-arrow-up-right-from-square ml-1"></i>
                                </a>
                            </div>
                        </div>` : '';

                    const card = document.createElement('div');
                    card.className = 'bg-white rounded-2xl border border-slate-100 overflow-hidden shadow-sm mat-item';
                    card.dataset.type = mtype;
                    card.style.animation = `fadeUp .25s ease ${idx * 0.06}s both`;
                    card.innerHTML = `
                        <div class="px-5 py-4 flex items-center justify-between">
                            <div class="flex items-center gap-4 flex-1 min-w-0">
                                <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 ${cfg.bg}">
                                    <i class="fa-solid ${cfg.icon} text-xl ${cfg.text}"></i>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-black text-slate-900 truncate">${m.title}</p>
                                    <div class="flex items-center gap-2 mt-0.5">
                                        <span class="inline-flex items-center gap-1 border text-[8px] font-black uppercase px-2 py-0.5 rounded-md ${cfg.badge}">
                                            <i class="fa-solid ${cfg.icon} text-[8px]"></i>${cfg.label}
                                        </span>
                                        <span class="text-[9px] text-slate-300 font-bold">.${ext}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-1.5 ml-3 flex-shrink-0">
                                ${previewBtn}
                                ${dlBtn}
                            </div>
                        </div>
                        ${previewPanel}`;
                    list.appendChild(card);
                });

                // Bind filter tabs to new cards
                document.querySelectorAll('.det-ftab').forEach(btn => {
                    btn.onclick = function() {
                        document.querySelectorAll('.det-ftab').forEach(b => {
                            b.classList.remove('bg-slate-900','text-white','border-slate-900');
                            b.classList.add('bg-white','text-slate-600','border-slate-200');
                        });
                        this.classList.add('bg-slate-900','text-white','border-slate-900');
                        this.classList.remove('bg-white','text-slate-600','border-slate-200');
                        const f = this.dataset.filter;
                        document.querySelectorAll('.mat-item').forEach(c => {
                            c.style.display = (f === 'all' || c.dataset.type === f) ? '' : 'none';
                        });
                    };
                });

            } catch(err) {
                list.innerHTML = '<p class="text-red-500 text-xs p-4 font-bold">Failed to load resources. Please try again.</p>';
                console.error(err);
            }
        }

        function toggleStudentPrev(id) {
            document.getElementById(id)?.classList.toggle('hidden');
        }

        // ── Logout ──
        function confirmLogout() {
            if (confirm("Log out?")) window.location.href = "logout.php";
        }

        // ══════════════════════════════════════════════════
        //  AI ANALYSIS LOADER
        // ══════════════════════════════════════════════════
        let currentAIAnalysis = null; // Store globally for chatbot access

        async function loadAIAnalysis() {
            const panel      = document.getElementById('ai-panel');
            const loadingEl  = document.getElementById('ai-loading-state');
            const resultEl   = document.getElementById('ai-result-state');
            const errorEl    = document.getElementById('ai-error-state');

            // Reset to loading
            loadingEl.classList.remove('hidden');
            resultEl.classList.add('hidden');
            errorEl.classList.add('hidden');
            panel.classList.add('ai-loading');

            try {
                const res  = await fetch('ai_analysis.php?course_id=<?php echo $view_course_id; ?>');
                const json = await res.json();

                if (!json.success || !json.data) throw new Error(json.error || 'No data');

                const d = json.data;
                currentAIAnalysis = d; // Feed the chatbot

                // Populate fields
                document.getElementById('ai-greeting').textContent       = d.greeting         || '';
                document.getElementById('ai-summary').textContent        = d.performance_summary || '';
                document.getElementById('ai-recommendation').textContent = d.recommendation    || '';
                document.getElementById('ai-challenge').textContent      = d.weekly_challenge  || '';
                document.getElementById('ai-career').textContent         = d.career_alignment  || '';

                // Score ring
                const score = parseInt(d.motivation_score) || 0;
                document.getElementById('score-val').textContent = score;
                document.getElementById('score-ring').style.setProperty('--pct', score + '%');

                // Strengths list
                const strengthsEl = document.getElementById('ai-strengths');
                strengthsEl.innerHTML = '';
                (d.strengths || []).forEach(s => {
                    strengthsEl.innerHTML += `<li class="flex items-start space-x-2 text-xs text-slate-300">
                        <i class="fa-solid fa-check text-emerald-400 mt-0.5 text-[9px]"></i><span>${s}</span></li>`;
                });

                // Focus areas list
                const focusEl = document.getElementById('ai-focus');
                focusEl.innerHTML = '';
                (d.focus_areas || []).forEach(f => {
                    focusEl.innerHTML += `<li class="flex items-start space-x-2 text-xs text-slate-300">
                        <i class="fa-solid fa-arrow-right text-amber-400 mt-0.5 text-[9px]"></i><span>${f}</span></li>`;
                });

                // Show result
                loadingEl.classList.add('hidden');
                resultEl.classList.remove('hidden');
                panel.classList.remove('ai-loading');

            } catch (err) {
                console.error('AI load error:', err);
                loadingEl.classList.add('hidden');
                errorEl.classList.remove('hidden');
                panel.classList.remove('ai-loading');
            }
        }

        // --- Chatbot Logic ---
        function toggleChatbot() {
            const chatWindow = document.getElementById('chatbot-window');
            chatWindow.style.display = (chatWindow.style.display === 'flex') ? 'none' : 'flex';
        }

        async function sendChatbotMessage() {
            const input       = document.getElementById('chatbot-input');
            const message     = input.value.trim();
            if (!message) return;

            const chatMessages = document.getElementById('chatbot-messages');

            // Show user message
            chatMessages.innerHTML += `<div class="message user">${message}</div>`;
            input.value = '';
            chatMessages.scrollTop = chatMessages.scrollHeight;

            // Typing indicator
            const typingEl = document.createElement('div');
            typingEl.className = 'message bot';
            typingEl.id = 'chat-typing-indicator';
            typingEl.innerHTML = '<span style="display:inline-flex;gap:3px;align-items:center">'
                + '<span class="dot-1" style="font-size:8px">●</span>'
                + '<span class="dot-2" style="font-size:8px">●</span>'
                + '<span class="dot-3" style="font-size:8px">●</span></span>';
            chatMessages.appendChild(typingEl);
            chatMessages.scrollTop = chatMessages.scrollHeight;

            try {
                const form = new FormData();
                form.append('message', message);
                const res  = await fetch('chatbot.php', { method: 'POST', body: form });
                const data = await res.json();
                document.getElementById('chat-typing-indicator')?.remove();
                const reply = (data.reply || 'Sorry, I could not process that.')
                    .replace(/\n/g, '<br>');
                chatMessages.innerHTML += `<div class="message bot">${reply}</div>`;
            } catch (e) {
                document.getElementById('chat-typing-indicator')?.remove();
                chatMessages.innerHTML += `<div class="message bot">Connection error. Please try again.</div>`;
            }
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        // Trigger on page load
        window.addEventListener('DOMContentLoaded', () => {
            loadAIAnalysis();

            // Animate mastery progress bars: start at 0%, ease to data-target
            setTimeout(() => {
                // Main skill mastery card bars
                document.querySelectorAll('.mastery-fill[data-target]').forEach(bar => {
                    bar.style.width = bar.dataset.target + '%';
                });
                // AI panel skill mastery bars
                document.querySelectorAll('.ai-panel-mastery-fill[data-target]').forEach(bar => {
                    bar.style.width = bar.dataset.target + '%';
                });
            }, 350); // slight delay so transition is visible after paint
        });

        // Chatbot: send on enter
        document.getElementById('chatbot-input').addEventListener('keypress', function (e) {
            if (e.key === 'Enter') sendChatbotMessage();
        });
    </script>
</body>
</html>