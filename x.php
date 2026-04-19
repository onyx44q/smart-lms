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
    $course_id = mysqli_real_escape_string($conn, $_POST['course_id']);
    $check = mysqli_query($conn, "SELECT * FROM enrollments WHERE student_id = '$user_id' AND course_id = '$course_id'");
    if (mysqli_num_rows($check) == 0) {
        if(mysqli_query($conn, "INSERT INTO enrollments (student_id, course_id) VALUES ('$user_id', '$course_id')")) {
            header("Location: student_dashboard.php?status=success&msg=Enrolled Successfully!");
            exit();
        } else {
            header("Location: student_dashboard.php?status=error&msg=Enrollment Failed");
            exit();
        }
    }
}

if (isset($_POST['unenroll_course'])) {
    $course_id = mysqli_real_escape_string($conn, $_POST['course_id']);
    if(mysqli_query($conn, "DELETE FROM enrollments WHERE student_id = '$user_id' AND course_id = '$course_id'")) {
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

// Mastery data for overview display
$mastery_query = mysqli_query($conn, "SELECT skill_name, mastery_level FROM student_mastery WHERE student_id = '$user_id'");
$masteryData = [];
while ($m = mysqli_fetch_assoc($mastery_query)) {
    $masteryData[] = $m;
}
$avgMastery = count($masteryData) > 0 
    ? round(array_sum(array_column($masteryData, 'mastery_level')) / count($masteryData), 1) 
    : 0;

// Materials available in enrolled courses
$enrolled_ids_res = mysqli_query($conn, "SELECT course_id FROM enrollments WHERE student_id = '$user_id'");
$enrolled_ids = [];
while ($r = mysqli_fetch_assoc($enrolled_ids_res)) $enrolled_ids[] = intval($r['course_id']);
$totalMaterials = 0;
if (!empty($enrolled_ids)) {
    $ids_str = implode(',', $enrolled_ids);
    $matRes = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM materials WHERE course_id IN ($ids_str)"));
    $totalMaterials = $matRes['total'];
}

// Student career path
$studentInfo = mysqli_fetch_assoc(mysqli_query($conn, "SELECT career_path FROM users WHERE id = '$user_id'"));
$careerPath  = $studentInfo['career_path'] ?? 'General';
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
        .mastery-bar {
            height: 6px;
            border-radius: 9999px;
            background: rgba(255,255,255,0.08);
            overflow: hidden;
        }
        .mastery-fill {
            height: 100%;
            border-radius: 9999px;
            background: linear-gradient(90deg, #6366f1, #a78bfa);
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
        <header class="flex justify-between items-center mb-10">
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
                        <p class="text-[10px] font-black text-slate-400 uppercase">Avg. Mastery</p>
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
                    <h4 class="font-black text-sm uppercase text-slate-700 tracking-wide">Skill Mastery Breakdown</h4>
                    <span class="text-[9px] font-black uppercase text-slate-400">Career: <?php echo htmlspecialchars($careerPath); ?></span>
                </div>
                <div class="space-y-4">
                    <?php foreach ($masteryData as $skill): 
                        $level = floatval($skill['mastery_level']);
                        $colorClass = $level >= 70 ? 'from-emerald-400 to-emerald-600' : ($level >= 40 ? 'from-blue-400 to-indigo-600' : 'from-red-400 to-rose-600');
                    ?>
                    <div>
                        <div class="flex justify-between mb-1.5">
                            <span class="text-[10px] font-bold uppercase text-slate-600"><?php echo htmlspecialchars($skill['skill_name']); ?></span>
                            <span class="text-[10px] font-black <?php echo $level >= 70 ? 'text-emerald-600' : ($level >= 40 ? 'text-blue-600' : 'text-red-500'); ?>"><?php echo $level; ?>%</span>
                        </div>
                        <div class="h-2 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full bg-gradient-to-r <?php echo $colorClass; ?> rounded-full transition-all duration-1000" style="width: <?php echo min(100, $level); ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
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
                $ids_for_quiz = implode(',', $enrolled_ids);
                $quiz_query = mysqli_query($conn,
                    "SELECT q.id, q.title, q.difficulty, q.skill_name, c.title as course_title,
                            COUNT(DISTINCT qu.id) as question_count,
                            (SELECT COUNT(*) FROM results r2
                             WHERE r2.quiz_id = q.id AND r2.student_id = $user_id) as my_attempts,
                            (SELECT r3.score FROM results r3
                             WHERE r3.quiz_id = q.id AND r3.student_id = $user_id
                             ORDER BY r3.id DESC LIMIT 1) as last_score,
                            (SELECT r4.action_taken FROM results r4
                             WHERE r4.quiz_id = q.id AND r4.student_id = $user_id
                             ORDER BY r4.id DESC LIMIT 1) as last_action
                     FROM quizzes q
                     JOIN courses c ON c.id = q.course_id
                     LEFT JOIN questions qu ON qu.quiz_id = q.id
                     WHERE q.course_id IN ($ids_for_quiz) AND q.is_active = 1
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
                            <i class="fa-solid fa-book mr-1"></i><?php echo htmlspecialchars($qz['course_title']); ?>
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
                        <button onclick="loadMaterials('<?php echo $course['id']; ?>', '<?php echo addslashes($course['title']); ?>')" class="w-full py-3 border border-blue-600 text-blue-600 rounded-xl font-black text-[10px] uppercase hover:bg-blue-600 hover:text-white transition-all">Enter Course</button>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>

        <div id="view-details" class="view-section">
            <button onclick="switchView('my-courses')" class="mb-6 text-[10px] font-black uppercase text-blue-600 italic"><i class="fa-solid fa-arrow-left mr-2"></i> Return to List</button>
            <div class="bg-white p-10 rounded-[2.5rem] border border-slate-200 shadow-sm">
                <h2 id="det-title" class="text-2xl font-black text-slate-900 uppercase italic">Course</h2>
                <div id="materials-list" class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-8"></div>
            </div>
        </div>
    </main>

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
            
            document.getElementById('page-title').innerText = viewId.replace('-', ' ').toUpperCase();
        }

        // ── Material Loader ──
        async function loadMaterials(courseId, title) {
            document.getElementById('det-title').innerText = title;
            const list = document.getElementById('materials-list');
            list.innerHTML = '<p class="text-slate-400 text-xs italic">Loading materials…</p>';
            switchView('details');
            try {
                const response = await fetch(`get_materials.php?course_id=${courseId}`);
                const materials = await response.json();
                if (materials.length === 0) {
                    list.innerHTML = '<p class="text-slate-500 text-sm">No materials found for this course.</p>';
                    return;
                }
                list.innerHTML = '';
                materials.forEach(m => {
                    const isPdf = m.type === 'pdf';
                    const isVideo = m.type === 'video';
                    let actionHtml = isVideo
                        ? `<div class="mt-4"><video controls class="w-full rounded-xl shadow-inner bg-black"><source src="${m.file_path}" type="video/mp4">Your browser does not support video.</video></div>`
                        : `<a href="${m.file_path}" target="_blank" class="mt-4 block text-center py-2 bg-white border border-slate-200 rounded-xl text-[10px] font-black uppercase text-slate-600 hover:bg-blue-600 hover:text-white transition-all">Download PDF</a>`;
                    list.innerHTML += `
                        <div class="p-6 border border-slate-50 rounded-3xl bg-blue-50/30 transition-all">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center space-x-4">
                                    <i class="fa-solid ${isPdf ? 'fa-file-pdf text-red-500' : 'fa-video text-blue-500'} text-2xl"></i>
                                    <div>
                                        <p class="text-xs font-black uppercase">${m.title}</p>
                                        <p class="text-[9px] uppercase opacity-60">${m.type} Resource</p>
                                    </div>
                                </div>
                                <a href="${m.file_path}" target="_blank" class="text-blue-600 hover:text-blue-800">
                                    <i class="fa-solid fa-expand text-sm"></i>
                                </a>
                            </div>
                            ${actionHtml}
                        </div>`;
                });
            } catch (e) {
                list.innerHTML = '<p class="text-red-500 text-xs">Failed to load resources.</p>';
            }
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
                const res  = await fetch('ai_analysis.php');
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
            const input = document.getElementById('chatbot-input');
            const message = input.value.trim();
            if (!message) return;

            // Display user message
            const chatMessages = document.getElementById('chatbot-messages');
            chatMessages.innerHTML += `<div class="message user">${message}</div>`;
            input.value = '';
            chatMessages.scrollTop = chatMessages.scrollHeight;

            // Generate AI/LMS Response
            let botResponse = "I'm still learning! Could you rephrase your question about performance or schedule?";
            const msgLower = message.toLowerCase();

            // 1. Performance Questions
            if (msgLower.includes('score') || msgLower.includes('doing') || msgLower.includes('performance')) {
                if (currentAIAnalysis) {
                    botResponse = `Your average mastery is <?php echo $avgMastery; ?>%. ${currentAIAnalysis.performance_summary}`;
                } else {
                    botResponse = "Your average mastery is <?php echo $avgMastery; ?>%. Take more quizzes to improve!";
                }
            }
            // 2. Schedule Questions
            else if (msgLower.includes('class') || msgLower.includes('schedule') || msgLower.includes('session')) {
                botResponse = "You can view your detailed class list in the 'Schedule' tab. Be sure to check your upcoming dates!";
            }
            // 3. Career/AI Advice
            else if (msgLower.includes('career') || msgLower.includes('learn') || msgLower.includes('improve')) {
                if (currentAIAnalysis) {
                    botResponse = `For your career path in <?php echo $careerPath; ?>, you should focus on: ${currentAIAnalysis.focus_areas.join(', ')}. ${currentAIAnalysis.career_alignment}`;
                } else {
                    botResponse = "I recommend focusing on your core course modules and tracking your skill mastery.";
                }
            }

            // Simulated typing delay
            setTimeout(() => {
                chatMessages.innerHTML += `<div class="message bot">${botResponse}</div>`;
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }, 500);
        }

        // Trigger on page load
        window.addEventListener('DOMContentLoaded', () => {
            loadAIAnalysis();
        });

        // Chatbot: send on enter
        document.getElementById('chatbot-input').addEventListener('keypress', function (e) {
            if (e.key === 'Enter') sendChatbotMessage();
        });
    </script>
</body>
</html>