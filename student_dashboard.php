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

// ── Mastery data — scoped to the selected course ─────────────────────
// Compute directly from quiz RESULTS for this course so Smith's scores
// never bleed into Lenny's course view (or vice versa).
if ($view_course_id) {
    $mastery_query = mysqli_query($conn,
        "SELECT q.skill_name, ROUND(AVG(r.score), 1) AS mastery_level
         FROM results r
         JOIN quizzes q ON q.id = r.quiz_id
         WHERE r.student_id = $user_id AND q.course_id = $view_course_id
         GROUP BY q.skill_name"
    );
} else {
    // Fallback (no course selected): global view

    $mastery_query = mysqli_query($conn,
        "SELECT skill_name, MAX(mastery_level) AS mastery_level
         FROM student_mastery WHERE student_id = '$user_id' GROUP BY skill_name"
    );

    while ($m = mysqli_fetch_assoc($mastery_query)) $masteryData[] = $m;
}

=======
}
$masteryData = [];
while ($m = mysqli_fetch_assoc($mastery_query)) $masteryData[] = $m;
>>>>>>> b76dc847ee7dc9b03ce4891a2c3b9571d42e9084
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