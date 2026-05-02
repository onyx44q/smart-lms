<?php 
include 'config.php';
include 'ai_engine.php';   // Rule-based adaptive engine + getLecturerDecisionData()

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

checkRole('lecturer');

// ── Handle material deletion from the course-detail view ──────────────────
if (isset($_GET['delete_mat'])) {
    $mat_id = intval($_GET['delete_mat']);
    $mat    = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT file_path, course_id FROM materials WHERE id = $mat_id"
    ));
    if ($mat) {
        // Remove the physical file if it exists
        if (!empty($mat['file_path']) && file_exists($mat['file_path'])) {
            unlink($mat['file_path']);
        }
        mysqli_query($conn, "DELETE FROM materials WHERE id = $mat_id");
        $back_id = intval($mat['course_id']);
        header("Location: lecturer_dashboard.php?view_course=$back_id&deleted=1");
    } else {
        header("Location: lecturer_dashboard.php");
    }
    exit();
}

// ── Auto-create student_marks table if not yet present ────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `student_marks` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `student_id`       INT(11)      NOT NULL,
  `course_id`        INT(11)      NOT NULL,
  `lecturer_id`      INT(11)      NOT NULL,
  `exam_mark`        DECIMAL(5,2) DEFAULT NULL,
  `exam_max`         DECIMAL(5,2) NOT NULL DEFAULT 70.00,
  `coursework_mark`  DECIMAL(5,2) DEFAULT NULL,
  `coursework_max`   DECIMAL(5,2) NOT NULL DEFAULT 30.00,
  `remarks`          VARCHAR(255) DEFAULT NULL,
  `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_student_course` (`student_id`, `course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$user_id = intval($_SESSION['user_id']);

$user_query  = mysqli_query($conn, "SELECT full_name FROM users WHERE id = '$user_id'");
$user_data   = mysqli_fetch_assoc($user_query);
$lecturerName = $user_data['full_name'] ?? $_SESSION['full_name'] ?? 'Lecturer';

// Courses for sidebar and modal
// Checks BOTH relationships:
//   courses.lecturer_id  — set when admin assigns lecturer to course
//   users.course_id      — the course stored on the lecturer's own user record
$courses_query = mysqli_query($conn,
    "SELECT DISTINCT id, title FROM courses
     WHERE lecturer_id = '$user_id'
        OR id = (SELECT course_id FROM users WHERE id = '$user_id' AND course_id IS NOT NULL LIMIT 1)
     ORDER BY title ASC"
);

// ── Helper: get all course IDs belonging to this lecturer (both columns) ──
$lect_course_ids_res = mysqli_query($conn,
    "SELECT DISTINCT id FROM courses
     WHERE lecturer_id = '$user_id'
        OR id = (SELECT course_id FROM users WHERE id = '$user_id' AND course_id IS NOT NULL LIMIT 1)"
);
$lect_course_ids = [];
while ($r = mysqli_fetch_assoc($lect_course_ids_res)) $lect_course_ids[] = intval($r['id']);
$lect_ids_str = !empty($lect_course_ids) ? implode(',', $lect_course_ids) : '0';

// ── Overview Statistics ──
$activeStudents = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(DISTINCT e.student_id) as total
     FROM enrollments e
     WHERE e.course_id IN ($lect_ids_str)"
))['total'] ?? 0;

$totalMaterials = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as total FROM materials
     WHERE lecturer_id = '$user_id' OR course_id IN ($lect_ids_str)"
))['total'] ?? 0;

$totalCourses = count($lect_course_ids);

// Avg mastery set from decisionData after getLecturerDecisionData() call below
$avgMastery = 0; // placeholder — overwritten after $decisionData is populated
$avgMastery = round(floatval($avgMasteryRes['avg_m'] ?? 0), 1);

// Per-skill mastery breakdown
// ── Auto-heal: recalculate mastery for any enrolled student whose data is stale ──
include_once __DIR__ . '/recalculate_mastery.php';
if (!empty($lect_course_ids)) {
    $enrolled_students_res = mysqli_query($conn,
        "SELECT DISTINCT student_id FROM enrollments WHERE course_id IN ($lect_ids_str)"
    );
    while ($es = mysqli_fetch_assoc($enrolled_students_res)) {
        $sid = intval($es['student_id']);
        if (mastery_needs_recalculation($sid, $conn)) {
            recalculate_mastery_for_student($sid, $conn);
        }
    }
}

$skill_res = mysqli_query($conn,
    "SELECT sm.skill_name, AVG(sm.mastery_level) as avg_m, COUNT(DISTINCT sm.student_id) as students
     FROM student_mastery sm
     JOIN enrollments e ON sm.student_id = e.student_id
     WHERE e.course_id IN ($lect_ids_str)
     GROUP BY sm.skill_name"
);
$skillData = [];
while ($r = mysqli_fetch_assoc($skill_res)) $skillData[] = $r;

// Recent uploads
$recent_uploads = mysqli_query($conn,
    "SELECT m.title, m.type, c.title as course_title, m.upload_date
     FROM materials m JOIN courses c ON c.id = m.course_id
     WHERE m.lecturer_id = '$user_id' OR m.course_id IN ($lect_ids_str)
     ORDER BY m.upload_date DESC LIMIT 5"
);

// ── Rule-based decision data (from ai_engine.php) ──
$decisionData = getLecturerDecisionData($user_id, $conn);
$avgMastery = $decisionData['class_avg'] ?? 0; // combined quiz+exam avg from decision engine
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <title>Lecturer Command Center | SmartLMS</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; overflow-x: hidden; }
        .glass { background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(10px); }
        #uploadModal { display: none; }

        /* Course sidebar dropdown */
        #courseDropdown { max-height: 0; overflow: hidden; transition: max-height 0.3s ease-out; }
        #courseDropdown.show { max-height: 500px; }

        /* ── AI Panel ── */
        .ai-panel {
            background: linear-gradient(135deg, #0f172a 0%, #1a1040 55%, #0f172a 100%);
            border: 1px solid rgba(139,92,246,0.25);
            position: relative;
            overflow: hidden;
        }
        .ai-panel::before {
            content: '';
            position: absolute;
            top: -40%;
            right: -15%;
            width: 350px;
            height: 350px;
            background: radial-gradient(circle, rgba(139,92,246,0.1) 0%, transparent 70%);
            pointer-events: none;
        }
        .ai-tag {
            background: linear-gradient(90deg, #7c3aed, #6366f1);
            font-size: 9px;
            letter-spacing: 0.15em;
        }
        .score-ring {
            width: 68px; height: 68px;
            border-radius: 50%;
            background: conic-gradient(#7c3aed var(--pct), rgba(255,255,255,0.07) 0);
            display: flex; align-items: center; justify-content: center;
            position: relative;
        }
        .score-ring::before {
            content: '';
            position: absolute;
            width: 50px; height: 50px;
            border-radius: 50%;
            background: #1a1040;
        }
        .score-ring span { position: relative; z-index: 1; font-size: 13px; font-weight: 900; color: #c4b5fd; }
        @keyframes pulse-glow {
            0%,100% { box-shadow: 0 0 0 0 rgba(124,58,237,0); }
            50%      { box-shadow: 0 0 22px 5px rgba(124,58,237,0.22); }
        }
        .ai-loading { animation: pulse-glow 2s infinite; }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0.3} }
        .dot-1 { animation: blink 1.4s infinite 0s; }
        .dot-2 { animation: blink 1.4s infinite 0.2s; }
        .dot-3 { animation: blink 1.4s infinite 0.4s; }
        .skill-bar  { height: 6px; border-radius: 9999px; background: rgba(255,255,255,0.06); overflow: hidden; }
        .skill-fill { height:100%; border-radius:9999px; background: linear-gradient(90deg,#7c3aed,#6366f1); width:0%; transition: width 1.1s cubic-bezier(0.22,1,0.36,1); }
    </style>
</head>
<body class="bg-[#f8fafc] flex min-h-screen text-slate-900">

    <!-- ── SIDEBAR ── -->
    <nav class="w-20 lg:w-64 h-screen bg-slate-900 text-white flex flex-col items-center lg:items-start p-6 sticky top-0 z-40 transition-all duration-300">
        <div class="flex items-center space-x-3 mb-12">
            <div class="h-10 w-10 bg-indigo-500 rounded-xl flex items-center justify-center shadow-lg shadow-indigo-500/40">
                <i class="fa-solid fa-bolt text-white"></i>
            </div>
            <h2 class="text-xl font-extrabold tracking-tight hidden lg:block">Smart<span class="text-indigo-400">LMS</span></h2>
        </div>

        <div class="flex-1 w-full space-y-2">
            <a href="lecturer_dashboard.php" class="flex items-center space-x-4 p-3 rounded-xl bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 group">
                <i class="fa-solid fa-house-chimney text-lg"></i>
                <span class="font-semibold hidden lg:block">Overview</span>
            </a>

            <a href="quiz_panel.php" class="flex items-center space-x-4 p-3 rounded-xl text-slate-400 hover:bg-slate-800 transition group">
                <i class="fa-solid fa-brain text-lg group-hover:text-white"></i>
                <span class="font-medium hidden lg:block group-hover:text-white">Quiz Manager</span>
            </a>

            <!-- My Schedule — NEW -->
            <a href="?view=schedule" class="flex items-center space-x-4 p-3 rounded-xl <?php echo (($_GET['view'] ?? '') === 'schedule') ? 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20' : 'text-slate-400 hover:bg-slate-800'; ?> transition group">
                <i class="fa-solid fa-calendar-days text-lg group-hover:text-white"></i>
                <span class="font-medium hidden lg:block group-hover:text-white">My Schedule</span>
            </a>

            <!-- Marks Manager -->
            <a href="?view=marks" class="flex items-center space-x-4 p-3 rounded-xl <?php echo (($_GET['view'] ?? '') === 'marks') ? 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20' : 'text-slate-400 hover:bg-slate-800'; ?> transition group">
                <i class="fa-solid fa-clipboard-list text-lg group-hover:text-white"></i>
                <span class="font-medium hidden lg:block group-hover:text-white">Marks Manager</span>
            </a>

            <!-- Assignments — NEW -->
            <a href="?view=assignments" class="flex items-center space-x-4 p-3 rounded-xl <?php echo (($_GET['view'] ?? '') === 'assignments') ? 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20' : 'text-slate-400 hover:bg-slate-800'; ?> transition group">
                <i class="fa-solid fa-file-pen text-lg group-hover:text-white"></i>
                <span class="font-medium hidden lg:block group-hover:text-white">Assignments</span>
            </a>
            
            <div class="w-full">
                <button onclick="toggleCourseDropdown()" class="w-full flex items-center justify-between p-3 rounded-xl text-slate-400 hover:bg-slate-800 transition group">
                    <div class="flex items-center space-x-4">
                        <i class="fa-solid fa-graduation-cap text-lg group-hover:text-white"></i>
                        <span class="font-medium hidden lg:block group-hover:text-white">My Courses</span>
                    </div>
                    <i class="fa-solid fa-chevron-down text-[10px] hidden lg:block"></i>
                </button>
                <div id="courseDropdown" class="pl-12 space-y-1 mt-1">
                    <?php 
                    mysqli_data_seek($courses_query, 0);
                    while($row = mysqli_fetch_assoc($courses_query)): 
                    ?>
                        <a href="?view_course=<?php echo $row['id']; ?>" class="block py-2 text-sm text-slate-500 hover:text-indigo-400 transition-colors">
                            <?php echo htmlspecialchars($row['title']); ?>
                        </a>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>

        <div class="w-full pt-6 border-t border-slate-800">
            <div class="flex items-center space-x-3 p-2">
                <div class="h-8 w-8 rounded-full bg-gradient-to-tr from-indigo-500 to-purple-500 shadow-inner"></div>
                <div class="hidden lg:block">
                    <p class="text-xs font-bold leading-none"><?php echo htmlspecialchars($lecturerName); ?></p>
                    <p class="text-[10px] text-slate-500 mt-1 uppercase tracking-widest font-bold">Academic Staff</p>
                </div>
            </div>
            <a href="logout.php" class="block mt-4 text-center lg:text-left text-red-400 hover:text-red-300 transition text-xs font-bold">
                <i class="fa-solid fa-power-off lg:mr-2"></i> <span class="hidden lg:inline uppercase tracking-tighter">Sign Out</span>
            </a>
        </div>
    </nav>

    <!-- ── MAIN ── -->
    <main class="flex-1 p-6 lg:p-10 max-w-7xl mx-auto w-full">
        
        <header class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-10">
            <div>
                <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">Academic Pulse</h1>
                <p class="text-slate-500 font-medium">Welcome back, <span class="text-indigo-600 font-bold"><?php echo htmlspecialchars($lecturerName); ?></span>.</p>
            </div>
            <div class="flex items-center space-x-3">
                <?php if(isset($_GET['upload']) && $_GET['upload'] == 'success'): ?>
                    <div class="bg-emerald-500 text-white px-4 py-2 rounded-xl text-xs font-bold shadow-lg shadow-emerald-200 animate-pulse">
                        <i class="fa-solid fa-check-circle mr-1"></i> Upload Successful
                    </div>
                <?php endif; ?>
                <div class="px-4 py-2 bg-white border border-slate-200 rounded-xl text-slate-600 font-bold text-sm shadow-sm">
                    Term 2: 2026
                </div>
            </div>
        </header>

        <?php if(isset($_GET['view_course'])): 
        // ═══════════════════════════════════════════════════
        //  COURSE DETAIL VIEW  (unchanged from original)
        // ═══════════════════════════════════════════════════
            $c_id = mysqli_real_escape_string($conn, $_GET['view_course']);
            $c_info = mysqli_fetch_assoc(mysqli_query($conn, "SELECT title FROM courses WHERE id='$c_id'"));
            $materials_query = mysqli_query($conn, "SELECT * FROM materials WHERE course_id='$c_id' ORDER BY id DESC");
        ?>
            <div class="mb-10">
                <div class="flex items-center space-x-4 mb-6">
                    <a href="lecturer_dashboard.php" class="h-10 w-10 bg-white border border-slate-200 rounded-xl flex items-center justify-center text-slate-600 hover:bg-slate-50 transition">
                        <i class="fa-solid fa-arrow-left"></i>
                    </a>
                    <h2 class="text-2xl font-black text-slate-800"><?php echo htmlspecialchars($c_info['title']); ?> Resources</h2>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php if(mysqli_num_rows($materials_query) > 0): ?>
                        <?php while($m = mysqli_fetch_assoc($materials_query)): ?>
                            <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm hover:shadow-md transition-all group">
                                <div class="flex items-start justify-between mb-4">
                                    <div class="h-12 w-12 rounded-2xl flex items-center justify-center <?php echo $m['type']=='pdf' ? 'bg-red-50' : 'bg-blue-50'; ?>">
                                        <i class="fa-solid <?php echo $m['type']=='pdf' ? 'fa-file-pdf text-red-500' : 'fa-video text-blue-500'; ?> text-xl"></i>
                                    </div>
                                    <span class="text-[9px] font-black uppercase <?php echo $m['type']=='pdf' ? 'text-red-400 bg-red-50' : 'text-blue-400 bg-blue-50'; ?> px-2 py-1 rounded-lg"><?php echo $m['type']; ?></span>
                                </div>
                                <h3 class="font-extrabold text-slate-800 mb-1"><?php echo htmlspecialchars($m['title']); ?></h3>
                                <p class="text-slate-400 text-xs mb-4"><?php echo date('d M Y', strtotime($m['upload_date'])); ?></p>
                                <div class="flex space-x-2">
                                    <a href="edit_material.php?id=<?php echo $m['id']; ?>" class="flex-1 text-center py-2.5 bg-slate-50 text-slate-600 rounded-xl text-xs font-bold hover:bg-indigo-600 hover:text-white transition-all">Edit</a>
                                    <a href="?view_course=<?php echo $c_id; ?>&delete_mat=<?php echo $m['id']; ?>" onclick="return confirm('Delete?')" class="flex-1 text-center py-2.5 bg-red-50 text-red-500 rounded-xl text-xs font-bold hover:bg-red-600 hover:text-white transition-all">Delete</a>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="col-span-3 text-center py-16 text-slate-400">
                            <i class="fa-solid fa-folder-open text-4xl mb-3"></i>
                            <p class="font-semibold">No materials yet. Upload your first resource!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif(($_GET['view'] ?? '') === 'schedule'): ?>
        <!-- ── SCHEDULE VIEW (LECTURER) — own isolated page ── -->
        <div class="mb-10">
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h2 class="text-2xl font-extrabold text-slate-900">My Schedule</h2>
                    <p class="text-slate-500 text-sm mt-1">Create and manage class sessions for your students.</p>
                </div>
                <button onclick="document.getElementById('scheduleModal').style.display='flex'"
                    class="flex items-center space-x-2 bg-indigo-600 hover:bg-indigo-700 text-white font-black text-xs uppercase tracking-widest px-5 py-3 rounded-xl shadow-lg transition-all">
                    <i class="fa-solid fa-plus"></i><span>New Session</span>
                </button>
            </div>

            <?php
            // Fetch this lecturer's schedules
            $sch_res = mysqli_query($conn,
                "SELECT s.*, c.title AS course_title
                 FROM schedules s
                 LEFT JOIN courses c ON c.id = s.course_id
                 WHERE s.lecturer_id = $user_id
                 ORDER BY s.meet_date DESC, s.meet_time DESC"
            );
            $months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            $hasSchedules = $sch_res && mysqli_num_rows($sch_res) > 0;
            ?>

            <?php if ($hasSchedules): ?>
            <div class="space-y-4">
                <?php while ($sc = mysqli_fetch_assoc($sch_res)):
                    $d    = new DateTime($sc['meet_date'] . ' ' . $sc['meet_time']);
                    $day  = $d->format('j');
                    $mon  = $months[intval($d->format('n')) - 1];
                    $time = $d->format('g:i A');
                    $isPast = $d < new DateTime();
                ?>
                <div class="bg-white rounded-2xl border <?php echo $isPast ? 'border-slate-100 opacity-70' : 'border-slate-200'; ?> p-5 flex items-start justify-between shadow-sm">
                    <div class="flex items-start space-x-5">
                        <div class="flex-shrink-0 w-14 h-14 bg-indigo-50 rounded-2xl flex flex-col items-center justify-center">
                            <span class="text-indigo-600 font-black text-lg leading-none"><?php echo $day; ?></span>
                            <span class="text-indigo-400 text-[9px] font-black uppercase"><?php echo $mon; ?></span>
                        </div>
                        <div>
                            <div class="flex items-center space-x-2 mb-1">
                                <p class="font-black text-slate-900 text-sm"><?php echo htmlspecialchars($sc['title']); ?></p>
                                <?php if ($isPast): ?>
                                <span class="text-[9px] font-black uppercase text-slate-300 bg-slate-50 px-2 py-0.5 rounded-lg">Ended</span>
                                <?php else: ?>
                                <span class="text-[9px] font-black uppercase text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-lg">Upcoming</span>
                                <?php endif; ?>
                            </div>
                            <p class="text-slate-400 text-xs">
                                <i class="fa-solid fa-clock mr-1"></i><?php echo $time; ?>
                                <?php if ($sc['course_title']): ?>
                                &nbsp;·&nbsp; <i class="fa-solid fa-book mr-1"></i><?php echo htmlspecialchars($sc['course_title']); ?>
                                <?php endif; ?>
                            </p>
                            <?php if ($sc['description']): ?>
                            <p class="text-slate-500 text-xs mt-1"><?php echo htmlspecialchars($sc['description']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($sc['zoom_start_url'])): ?>
                            <!-- Zoom host link — only the lecturer sees this start_url -->
                            <div class="flex items-center space-x-2 mt-2">
                                <a href="<?php echo htmlspecialchars($sc['zoom_start_url']); ?>" target="_blank"
                                   class="inline-flex items-center text-[9px] font-black uppercase bg-blue-600 text-white px-3 py-1.5 rounded-lg hover:bg-blue-700 transition-all shadow-sm">
                                    <i class="fa-solid fa-video mr-1.5"></i>Start Zoom
                                </a>
                                <span class="text-[8px] font-bold text-slate-400 uppercase tracking-widest bg-blue-50 px-2 py-1 rounded-md">
                                    <i class="fa-solid fa-lock text-[7px] mr-0.5"></i>Host Link
                                </span>
                            </div>
                            <?php elseif (!empty($sc['meet_link'])): ?>
                            <!-- Fallback: manual link entered by lecturer -->
                            <a href="<?php echo htmlspecialchars($sc['meet_link']); ?>" target="_blank"
                               class="inline-flex items-center space-x-1 text-[9px] font-black uppercase text-indigo-600 hover:text-indigo-800 mt-2">
                                <i class="fa-solid fa-video mr-1"></i>Join Meeting
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- Delete -->
                    <form method="POST" action="schedule_api.php" onsubmit="return confirm('Delete this session?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="schedule_id" value="<?php echo $sc['id']; ?>">
                        <button type="submit" class="w-8 h-8 flex items-center justify-center rounded-xl bg-red-50 text-red-400 hover:bg-red-100 transition-all">
                            <i class="fa-solid fa-trash-can text-xs"></i>
                        </button>
                    </form>
                </div>
                <?php endwhile; ?>
            </div>
            <?php else: ?>
            <div class="bg-white rounded-2xl border border-slate-200 p-14 text-center">
                <div class="w-14 h-14 bg-indigo-50 rounded-2xl flex items-center justify-center mx-auto mb-4">
                    <i class="fa-solid fa-calendar-plus text-indigo-300 text-xl"></i>
                </div>
                <p class="text-slate-600 font-bold text-sm">No sessions created yet.</p>
                <p class="text-slate-400 text-xs mt-1 mb-5">Schedule a class or meeting and your students will see it on their schedule page.</p>
                <button onclick="document.getElementById('scheduleModal').style.display='flex'"
                    class="bg-indigo-600 text-white font-black text-xs uppercase tracking-widest px-5 py-3 rounded-xl hover:bg-indigo-700 transition-all">
                    <i class="fa-solid fa-plus mr-2"></i>Create First Session
                </button>
            </div>
            <?php endif; ?>
        </div>

        <!-- Schedule Add Modal -->
        <div id="scheduleModal" class="fixed inset-0 z-[100] items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4" style="display:none;">
            <div class="w-full max-w-md bg-white p-8 rounded-[2.5rem] shadow-2xl relative">
                <button onclick="document.getElementById('scheduleModal').style.display='none'"
                    class="absolute top-5 right-5 text-slate-400 hover:text-red-500 transition text-xl">
                    <i class="fa-solid fa-xmark"></i>
                </button>
                <h3 class="text-xl font-extrabold text-slate-800 mb-1">New Session</h3>
                <p class="text-slate-400 text-xs mb-6">Students enrolled in your course will see this on their schedule.</p>
                <form action="schedule_api.php" method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="add">
                    <div>
                        <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-1 block">Session Title</label>
                        <input type="text" name="title" required placeholder="e.g. Week 3 Lecture — Neural Networks"
                            class="w-full p-3.5 bg-slate-50 border border-slate-200 rounded-2xl text-sm font-semibold outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-1 block">Description (optional)</label>
                        <textarea name="description" rows="2" placeholder="What will be covered in this session?"
                            class="w-full p-3.5 bg-slate-50 border border-slate-200 rounded-2xl text-sm outline-none focus:ring-2 focus:ring-indigo-500 resize-none"></textarea>
                    </div>
                    <div>
                        <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-1 block">Course</label>
                        <select name="course_id" class="w-full p-3.5 bg-slate-50 border border-slate-200 rounded-2xl text-sm font-semibold outline-none focus:ring-2 focus:ring-indigo-500 appearance-none">
                            <option value="">— All students (no specific course) —</option>
                            <?php
                            $cq2 = mysqli_query($conn,
                                "SELECT DISTINCT id, title FROM courses
                                 WHERE lecturer_id = $user_id
                                    OR id = (SELECT course_id FROM users WHERE id = $user_id AND course_id IS NOT NULL LIMIT 1)
                                 ORDER BY title ASC"
                            );
                            while ($c2 = mysqli_fetch_assoc($cq2))
                                echo "<option value='{$c2['id']}'>" . htmlspecialchars($c2['title']) . "</option>";
                            ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-1 block">Date</label>
                            <input type="date" name="meet_date" required
                                min="<?php echo date('Y-m-d'); ?>"
                                class="w-full p-3.5 bg-slate-50 border border-slate-200 rounded-2xl text-sm font-semibold outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-1 block">Time</label>
                            <input type="time" name="meet_time" required
                                class="w-full p-3.5 bg-slate-50 border border-slate-200 rounded-2xl text-sm font-semibold outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                    </div>
                    <div>
                        <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-1 block">Meeting Link (optional)</label>
                        <input type="url" name="meet_link" placeholder="https://meet.google.com/xxx or Zoom link"
                            class="w-full p-3.5 bg-slate-50 border border-slate-200 rounded-2xl text-sm font-semibold outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div class="flex space-x-3 pt-2">
                        <button type="button" onclick="document.getElementById('scheduleModal').style.display='none'"
                            class="flex-1 py-3.5 bg-slate-100 text-slate-600 font-black text-xs uppercase rounded-2xl hover:bg-slate-200 transition-all">Cancel</button>
                        <button type="submit"
                            class="flex-1 py-3.5 bg-indigo-600 text-white font-black text-xs uppercase rounded-2xl hover:bg-indigo-700 transition-all shadow-lg">
                            <i class="fa-solid fa-paper-plane mr-2"></i>Schedule
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php elseif(($_GET['view'] ?? '') === 'assignments'):
        // ═══════════════════════════════════════════════════
        //  ASSIGNMENTS VIEW — create assignments + review submissions
        // ═══════════════════════════════════════════════════

        // ── Auto-create tables if not yet present ────────────────────
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `assignments` (
          `id` INT(11) NOT NULL AUTO_INCREMENT, `course_id` INT(11) NOT NULL,
          `lecturer_id` INT(11) NOT NULL, `title` VARCHAR(255) NOT NULL,
          `description` TEXT DEFAULT NULL, `due_date` DATE DEFAULT NULL,
          `max_words` INT(11) NOT NULL DEFAULT 1000,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `assignment_submissions` (
          `id` INT(11) NOT NULL AUTO_INCREMENT, `assignment_id` INT(11) NOT NULL,
          `student_id` INT(11) NOT NULL, `submission_text` LONGTEXT NOT NULL,
          `file_path` VARCHAR(500) DEFAULT NULL, `word_count` INT(11) NOT NULL DEFAULT 0,
          `submitted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`), UNIQUE KEY `unique_submission` (`assignment_id`,`student_id`)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `plagiarism_reports` (
          `id` INT(11) NOT NULL AUTO_INCREMENT, `submission_id` INT(11) NOT NULL,
          `student_similarity_score` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
          `internet_similarity_score` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
          `overall_score` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
          `verdict` VARCHAR(20) NOT NULL DEFAULT 'LOW RISK',
          `matched_students` LONGTEXT DEFAULT NULL, `flags` LONGTEXT DEFAULT NULL,
          `analysed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`), UNIQUE KEY `unique_report` (`submission_id`)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // ── Handle create ────────────────────────────────────────────
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_assignment') {
            $a_course_id   = intval($_POST['course_id'] ?? 0);
            $a_title       = mysqli_real_escape_string($conn, trim($_POST['title'] ?? ''));
            $a_description = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));
            $a_due_date    = mysqli_real_escape_string($conn, $_POST['due_date'] ?? '');
            $a_max_words   = max(50, intval($_POST['max_words'] ?? 1000));
            $due_sql       = $a_due_date ? "'$a_due_date'" : 'NULL';

            if (in_array($a_course_id, $lect_course_ids) && $a_title) {
                mysqli_query($conn,
                    "INSERT INTO assignments (course_id, lecturer_id, title, description, due_date, max_words, created_at)
                     VALUES ($a_course_id, $user_id, '$a_title', '$a_description', $due_sql, $a_max_words, NOW())"
                );
                header("Location: lecturer_dashboard.php?view=assignments&amsg=created"); exit();
            }
            header("Location: lecturer_dashboard.php?view=assignments&amsg=error"); exit();
        }

        // ── Handle delete ────────────────────────────────────────────
        if (isset($_GET['del_assign'])) {
            $del_a = intval($_GET['del_assign']);
            $owns  = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT id FROM assignments WHERE id = $del_a AND lecturer_id = $user_id"
            ));
            if ($owns) mysqli_query($conn, "DELETE FROM assignments WHERE id = $del_a");
            header("Location: lecturer_dashboard.php?view=assignments&amsg=deleted"); exit();
        }

        // ── Fetch assignments for this lecturer ──────────────────────
        $assign_list = [];
        if (!empty($lect_course_ids)) {
            $aRes = mysqli_query($conn,
                "SELECT a.*, c.title AS course_title,
                        COUNT(DISTINCT s.id) AS submission_count,
                        ROUND(AVG(pr.overall_score),1) AS avg_plagiarism,
                        SUM(CASE WHEN pr.verdict='HIGH RISK' THEN 1 ELSE 0 END) AS high_risk_count
                 FROM assignments a
                 JOIN courses c ON c.id = a.course_id
                 LEFT JOIN assignment_submissions s ON s.assignment_id = a.id
                 LEFT JOIN plagiarism_reports pr ON pr.submission_id = s.id
                 WHERE a.lecturer_id = $user_id
                 GROUP BY a.id ORDER BY a.created_at DESC"
            );
            while ($ar = mysqli_fetch_assoc($aRes)) $assign_list[] = $ar;
        }

        // ── Fetch submissions if viewing one assignment ──────────────
        $view_subs    = null;
        $submissions  = [];
        if (isset($_GET['view_assign'])) {
            $va_id    = intval($_GET['view_assign']);
            $view_subs = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT a.*, c.title AS course_title FROM assignments a
                 JOIN courses c ON c.id = a.course_id
                 WHERE a.id = $va_id AND a.lecturer_id = $user_id"
            ));
            if ($view_subs) {
                $sRes = mysqli_query($conn,
                    "SELECT s.id, s.submission_text, s.word_count, s.submitted_at, s.file_path,
                            u.full_name AS student_name, u.email AS student_email,
                            pr.overall_score, pr.student_similarity_score,
                            pr.internet_similarity_score, pr.verdict,
                            pr.matched_students, pr.flags
                     FROM assignment_submissions s
                     JOIN users u ON u.id = s.student_id
                     LEFT JOIN plagiarism_reports pr ON pr.submission_id = s.id
                     WHERE s.assignment_id = $va_id
                     ORDER BY pr.overall_score DESC, s.submitted_at ASC"
                );
                while ($sub = mysqli_fetch_assoc($sRes)) {
                    $sub['flags_arr']   = json_decode($sub['flags']            ?? '[]', true) ?: [];
                    $sub['matched_arr'] = json_decode($sub['matched_students'] ?? '[]', true) ?: [];
                    $submissions[] = $sub;
                }
            }
        }
        ?>

        <div class="mb-10">
            <!-- Page header -->
            <div class="flex items-center justify-between mb-8">
                <div>
                    <?php if ($view_subs): ?>
                        <a href="?view=assignments" class="text-[10px] font-black uppercase text-indigo-400 hover:text-indigo-200 mb-2 block">
                            <i class="fa-solid fa-arrow-left mr-1"></i> All Assignments
                        </a>
                        <h2 class="text-2xl font-extrabold text-slate-900"><?php echo htmlspecialchars($view_subs['title']); ?></h2>
                        <p class="text-slate-500 text-sm mt-1"><?php echo htmlspecialchars($view_subs['course_title']); ?> · <?php echo count($submissions); ?> submission(s)</p>
                    <?php else: ?>
                        <h2 class="text-2xl font-extrabold text-slate-900">Assignment Manager</h2>
                        <p class="text-slate-500 text-sm mt-1">Create assignments and review student submissions with plagiarism analysis.</p>
                    <?php endif; ?>
                </div>
                <?php if (isset($_GET['amsg'])): ?>
                <?php $isErr = $_GET['amsg'] === 'error'; ?>
                <div class="<?php echo $isErr ? 'bg-red-50 text-red-600 border-red-200' : 'bg-emerald-50 text-emerald-700 border-emerald-200'; ?> border rounded-xl px-4 py-2 text-xs font-black uppercase">
                    <i class="fa-solid <?php echo $isErr ? 'fa-triangle-exclamation' : 'fa-check'; ?> mr-1"></i>
                    <?php echo match($_GET['amsg']) { 'created'=>'Assignment created.', 'deleted'=>'Assignment deleted.', 'error'=>'Action failed.', default=>'' }; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($view_subs): ?>
            <!-- ── SUBMISSIONS VIEW ── -->
            <?php
            $avgOvr    = count($submissions) > 0 ? round(array_sum(array_column($submissions,'overall_score')) / count($submissions), 1) : 0;
            $highCount = count(array_filter($submissions, fn($s) => $s['verdict'] === 'HIGH RISK'));
            ?>
            <div class="grid grid-cols-3 gap-4 mb-6">
                <div class="bg-white rounded-2xl p-4 border border-slate-100 text-center">
                    <p class="text-[9px] font-black uppercase text-slate-400 mb-1">Submissions</p>
                    <p class="text-2xl font-black text-slate-900"><?php echo count($submissions); ?></p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-100 text-center">
                    <p class="text-[9px] font-black uppercase text-slate-400 mb-1">Avg Plagiarism</p>
                    <p class="text-2xl font-black <?php echo $avgOvr >= 65 ? 'text-red-600' : ($avgOvr >= 35 ? 'text-amber-600' : 'text-emerald-600'); ?>"><?php echo $avgOvr; ?>%</p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-100 text-center">
                    <p class="text-[9px] font-black uppercase text-slate-400 mb-1">High Risk</p>
                    <p class="text-2xl font-black <?php echo $highCount > 0 ? 'text-red-600' : 'text-slate-900'; ?>"><?php echo $highCount; ?></p>
                </div>
            </div>

            <?php if (empty($submissions)): ?>
            <div class="bg-white rounded-2xl border border-slate-100 p-12 text-center">
                <i class="fa-solid fa-inbox text-slate-200 text-4xl mb-3"></i>
                <p class="text-slate-500 font-bold">No submissions received yet.</p>
            </div>
            <?php else: ?>
            <div class="space-y-4">
            <?php foreach ($submissions as $sub):
                $verdict = $sub['verdict'] ?? 'LOW RISK';
                $vClass  = match($verdict) { 'HIGH RISK'=>'bg-red-50 text-red-600 border-red-200', 'MEDIUM RISK'=>'bg-yellow-50 text-yellow-700 border-yellow-200', default=>'bg-emerald-50 text-emerald-700 border-emerald-200' };
                $vIcon   = match($verdict) { 'HIGH RISK'=>'fa-triangle-exclamation', 'MEDIUM RISK'=>'fa-circle-exclamation', default=>'fa-circle-check' };
            ?>
            <div class="bg-white rounded-2xl border border-slate-100 overflow-hidden">
                <div class="px-6 py-5 flex items-start justify-between">
                    <div>
                        <p class="font-black text-slate-900 text-sm"><?php echo htmlspecialchars($sub['student_name']); ?></p>
                        <p class="text-[10px] text-slate-400 font-bold"><?php echo htmlspecialchars($sub['student_email']); ?></p>
                        <div class="flex gap-4 mt-1 text-[10px] font-bold text-slate-400 uppercase flex-wrap">
                            <span><i class="fa-solid fa-clock mr-1"></i><?php echo date('d M Y, h:i A', strtotime($sub['submitted_at'])); ?></span>
                            <span><i class="fa-solid fa-font mr-1"></i><?php echo number_format($sub['word_count']); ?> words</span>
                        </div>
                        <!-- ── Attached file — always shown ── -->
                        <div class="mt-3">
                            <p class="text-[9px] font-black uppercase text-slate-400 tracking-widest mb-2">
                                <i class="fa-solid fa-paperclip mr-1"></i>Attached File
                            </p>
                            <?php if (!empty($sub['file_path'])): 
                                $fExt  = strtolower(pathinfo($sub['file_path'], PATHINFO_EXTENSION));
                                $fName = basename($sub['file_path']);
                                $fIcon = match($fExt) {
                                    'pdf'         => ['fa-file-pdf',       'text-red-500',    'bg-red-50',    'border-red-200'],
                                    'doc','docx'  => ['fa-file-word',      'text-blue-600',   'bg-blue-50',   'border-blue-200'],
                                    'xls','xlsx'  => ['fa-file-excel',     'text-emerald-600','bg-emerald-50','border-emerald-200'],
                                    'ppt','pptx'  => ['fa-file-powerpoint','text-orange-500', 'bg-orange-50', 'border-orange-200'],
                                    'txt','csv'   => ['fa-file-lines',     'text-slate-500',  'bg-slate-50',  'border-slate-300'],
                                    default       => ['fa-file',           'text-indigo-500', 'bg-indigo-50', 'border-indigo-200'],
                                };
                            ?>
                            <div class="flex items-center space-x-3 px-4 py-3 bg-white border <?php echo $fIcon[3]; ?> rounded-xl shadow-sm">
                                <div class="w-10 h-10 <?php echo $fIcon[2]; ?> rounded-xl flex items-center justify-center flex-shrink-0">
                                    <i class="fa-solid <?php echo $fIcon[0]; ?> <?php echo $fIcon[1]; ?> text-xl"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-black text-slate-800 truncate"><?php echo htmlspecialchars($fName); ?></p>
                                    <p class="text-[9px] text-slate-400 uppercase font-bold tracking-widest"><?php echo strtoupper($fExt); ?> &nbsp;·&nbsp; Click download to save</p>
                                </div>
                                <a href="download_submission.php?sub_id=<?php echo $sub['id']; ?>"
                                   class="flex-shrink-0 flex items-center space-x-2 px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-black text-[10px] uppercase rounded-xl tracking-widest transition-all active:scale-95">
                                    <i class="fa-solid fa-download"></i>
                                    <span>Download</span>
                                </a>
                            </div>
                            <?php else: ?>
                            <div class="flex items-center space-x-3 px-4 py-3 border border-dashed border-slate-200 rounded-xl bg-slate-50">
                                <div class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center flex-shrink-0">
                                    <i class="fa-solid fa-file-slash text-slate-300 text-lg"></i>
                                </div>
                                <p class="text-xs font-bold text-slate-400 italic">No file attached — text submission only</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        <span class="inline-flex items-center space-x-1.5 border rounded-xl px-3 py-1.5 text-[10px] font-black uppercase <?php echo $vClass; ?>">
                            <i class="fa-solid <?php echo $vIcon; ?>"></i><span><?php echo $verdict; ?></span>
                        </span>
                        <span class="text-[10px] font-bold text-slate-400">Overall: <?php echo number_format($sub['overall_score'] ?? 0, 1); ?>%</span>
                    </div>
                </div>
                <?php if ($sub['verdict']): ?>
                <div class="px-6 pb-4 grid grid-cols-2 gap-4">
                    <div>
                        <div class="flex justify-between mb-1">
                            <span class="text-[9px] font-black uppercase text-slate-400">Peer Match</span>
                            <span class="text-[9px] font-black <?php echo floatval($sub['student_similarity_score']) >= 55 ? 'text-red-600' : 'text-slate-600'; ?>"><?php echo number_format($sub['student_similarity_score'] ?? 0, 1); ?>%</span>
                        </div>
                        <div class="h-1.5 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full rounded-full <?php echo floatval($sub['student_similarity_score']) >= 55 ? 'bg-red-500' : 'bg-emerald-500'; ?>" style="width:<?php echo min(100, $sub['student_similarity_score'] ?? 0); ?>%"></div>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between mb-1">
                            <span class="text-[9px] font-black uppercase text-slate-400">Internet Est.</span>
                            <span class="text-[9px] font-black <?php echo floatval($sub['internet_similarity_score']) >= 50 ? 'text-amber-600' : 'text-slate-600'; ?>"><?php echo number_format($sub['internet_similarity_score'] ?? 0, 1); ?>%</span>
                        </div>
                        <div class="h-1.5 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full rounded-full <?php echo floatval($sub['internet_similarity_score']) >= 50 ? 'bg-amber-500' : 'bg-blue-500'; ?>" style="width:<?php echo min(100, $sub['internet_similarity_score'] ?? 0); ?>%"></div>
                        </div>
                    </div>
                </div>
                <?php if (!empty($sub['matched_arr'])): ?>
                <div class="px-6 pb-3">
                    <p class="text-[9px] font-black uppercase text-red-500 mb-2"><i class="fa-solid fa-users mr-1"></i>Similar Submissions</p>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($sub['matched_arr'] as $match): ?>
                        <span class="bg-red-50 border border-red-100 text-red-700 text-[9px] font-black px-2.5 py-1 rounded-lg">
                            <?php echo htmlspecialchars($match['student_name']); ?> — <?php echo number_format($match['similarity'], 1); ?>% match
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($sub['flags_arr'])): ?>
                <div class="px-6 pb-4">
                    <button onclick="this.nextElementSibling.classList.toggle('hidden')" class="text-[9px] font-black uppercase text-amber-600 hover:text-amber-800 mb-2 block">
                        <i class="fa-solid fa-flag mr-1"></i><?php echo count($sub['flags_arr']); ?> heuristic flag(s)
                    </button>
                    <div class="hidden bg-amber-50 border border-amber-100 rounded-2xl p-4">
                        <ul class="space-y-1">
                            <?php foreach ($sub['flags_arr'] as $flag): ?>
                            <li class="text-[10px] text-amber-800 flex items-start space-x-2">
                                <i class="fa-solid fa-circle text-amber-400 mt-1.5 text-[5px] flex-shrink-0"></i>
                                <span><?php echo htmlspecialchars($flag); ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                <div class="border-t border-slate-100 px-6 py-3">
                    <button onclick="this.nextElementSibling.classList.toggle('hidden')" class="text-[10px] font-black uppercase text-slate-400 hover:text-slate-700 transition">
                        <i class="fa-solid fa-file-lines mr-1"></i>View Submission Text
                    </button>
                    <div class="hidden mt-3 bg-slate-50 border border-slate-200 rounded-2xl p-4 text-sm text-slate-700 leading-relaxed max-h-52 overflow-y-auto">
                        <?php echo nl2br(htmlspecialchars($sub['submission_text'])); ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <!-- ── LIST + CREATE FORM ── -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

                <!-- Create form -->
                <div class="lg:col-span-1">
                    <div class="bg-white rounded-2xl border border-slate-100 p-6 sticky top-24">
                        <h3 class="text-xs font-black uppercase text-indigo-600 mb-5 italic"><i class="fa-solid fa-plus mr-1"></i>New Assignment</h3>
                        <form method="POST" action="?view=assignments" class="space-y-4">
                            <input type="hidden" name="action" value="create_assignment">
                            <div>
                                <label class="text-[9px] font-black uppercase text-slate-500 tracking-widest block mb-1">Course</label>
                                <select name="course_id" required class="w-full p-3 bg-slate-50 border rounded-xl text-sm text-slate-800">
                                    <option value="">Select course…</option>
                                    <?php mysqli_data_seek($courses_query, 0); while ($cq = mysqli_fetch_assoc($courses_query)): ?>
                                    <option value="<?php echo $cq['id']; ?>"><?php echo htmlspecialchars($cq['title']); ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div>
                                <label class="text-[9px] font-black uppercase text-slate-500 tracking-widest block mb-1">Title</label>
                                <input type="text" name="title" required placeholder="e.g. Literature Review" class="w-full p-3 bg-slate-50 border rounded-xl text-sm text-slate-800">
                            </div>
                            <div>
                                <label class="text-[9px] font-black uppercase text-slate-500 tracking-widest block mb-1">Brief / Description</label>
                                <textarea name="description" rows="3" placeholder="What should students address?" class="w-full p-3 bg-slate-50 border rounded-xl text-sm text-slate-800 resize-none"></textarea>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-[9px] font-black uppercase text-slate-500 tracking-widest block mb-1">Due Date</label>
                                    <input type="date" name="due_date" class="w-full p-3 bg-slate-50 border rounded-xl text-sm text-slate-800">
                                </div>
                                <div>
                                    <label class="text-[9px] font-black uppercase text-slate-500 tracking-widest block mb-1">Word Limit</label>
                                    <input type="number" name="max_words" value="1000" min="50" max="10000" class="w-full p-3 bg-slate-50 border rounded-xl text-sm text-slate-800">
                                </div>
                            </div>
                            <button type="submit" class="w-full py-3.5 bg-indigo-600 hover:bg-indigo-700 text-white font-black text-xs uppercase tracking-widest rounded-xl shadow-lg transition-all active:scale-95">
                                <i class="fa-solid fa-plus mr-1"></i>Create Assignment
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Assignment list -->
                <div class="lg:col-span-2 space-y-4">
                    <?php if (empty($assign_list)): ?>
                    <div class="bg-white rounded-2xl border border-slate-100 p-12 text-center">
                        <i class="fa-solid fa-file-pen text-slate-200 text-4xl mb-3"></i>
                        <p class="text-slate-500 font-bold text-sm">No assignments yet. Create your first one.</p>
                    </div>
                    <?php else: ?>
                    <?php foreach ($assign_list as $al):
                        $isOverdue  = $al['due_date'] && strtotime($al['due_date']) < time();
                        $highRisk   = intval($al['high_risk_count'] ?? 0);
                        $subCount   = intval($al['submission_count']);
                        $avgPlag    = floatval($al['avg_plagiarism'] ?? 0);
                    ?>
                    <div class="bg-white rounded-2xl border border-slate-100 p-5">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1">
                                <div class="flex flex-wrap gap-2 mb-1">
                                    <span class="text-[9px] font-black uppercase text-indigo-600 bg-indigo-50 border border-indigo-100 px-2 py-0.5 rounded-lg"><?php echo htmlspecialchars($al['course_title']); ?></span>
                                    <?php if ($isOverdue): ?><span class="text-[9px] font-black uppercase text-red-600 bg-red-50 border border-red-100 px-2 py-0.5 rounded-lg">Closed</span><?php endif; ?>
                                    <?php if ($highRisk > 0): ?><span class="text-[9px] font-black uppercase text-red-600 bg-red-50 border border-red-100 px-2 py-0.5 rounded-lg"><i class="fa-solid fa-triangle-exclamation mr-1"></i><?php echo $highRisk; ?> High Risk</span><?php endif; ?>
                                </div>
                                <h4 class="font-black text-slate-900 text-sm mb-1"><?php echo htmlspecialchars($al['title']); ?></h4>
                                <div class="flex flex-wrap gap-4 text-[10px] font-bold text-slate-400 uppercase">
                                    <?php if ($al['due_date']): ?><span><i class="fa-solid fa-calendar-days mr-1 <?php echo $isOverdue ? 'text-red-500' : 'text-blue-400'; ?>"></i><?php echo date('d M Y', strtotime($al['due_date'])); ?></span><?php endif; ?>
                                    <span><i class="fa-solid fa-align-left mr-1 text-blue-400"></i><?php echo number_format($al['max_words']); ?> words</span>
                                    <span><i class="fa-solid fa-users mr-1 text-blue-400"></i><?php echo $subCount; ?> submission(s)</span>
                                    <?php if ($subCount > 0): ?><span class="<?php echo $avgPlag >= 65 ? 'text-red-600' : ($avgPlag >= 35 ? 'text-amber-600' : 'text-emerald-600'); ?>"><i class="fa-solid fa-shield-halved mr-1"></i>Avg plagiarism: <?php echo $avgPlag; ?>%</span><?php endif; ?>
                                </div>
                            </div>
                            <div class="flex flex-col gap-2">
                                <a href="?view=assignments&view_assign=<?php echo $al['id']; ?>"
                                   class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-black text-[9px] uppercase rounded-xl tracking-widest transition-all text-center">
                                    <i class="fa-solid fa-eye mr-1"></i>Submissions
                                </a>
                                <a href="?view=assignments&del_assign=<?php echo $al['id']; ?>"
                                   onclick="return confirm('Delete this assignment and all its submissions?')"
                                   class="px-4 py-2 bg-red-50 hover:bg-red-600 text-red-600 hover:text-white border border-red-100 font-black text-[9px] uppercase rounded-xl tracking-widest transition-all text-center">
                                    <i class="fa-solid fa-trash-can mr-1"></i>Delete
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php elseif(($_GET['view'] ?? '') === 'marks'):
        // ═══════════════════════════════════════════════════
        //  MARKS MANAGER (UNIT-BASED) — flexible components
        // ═══════════════════════════════════════════════════

        // ── Ensure tables exist ───────────────────────────────────
        foreach ([
            "CREATE TABLE IF NOT EXISTS `course_units` (`id` INT AUTO_INCREMENT PRIMARY KEY, `course_id` INT NOT NULL, `title` VARCHAR(255) NOT NULL, `unit_code` VARCHAR(50) DEFAULT NULL, `description` TEXT DEFAULT NULL, `lecturer_id` INT DEFAULT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY(`course_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `unit_registrations` (`id` INT AUTO_INCREMENT PRIMARY KEY, `student_id` INT NOT NULL, `unit_id` INT NOT NULL, `registered_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY(`student_id`,`unit_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `unit_assessments` (`id` INT AUTO_INCREMENT PRIMARY KEY, `unit_id` INT NOT NULL, `name` VARCHAR(100) NOT NULL, `type` ENUM('coursework','exam') NOT NULL DEFAULT 'coursework', `max_mark` DECIMAL(6,2) NOT NULL DEFAULT 100.00, `sort_order` TINYINT DEFAULT 0, `created_by` INT DEFAULT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY(`unit_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `unit_marks` (`id` INT AUTO_INCREMENT PRIMARY KEY, `assessment_id` INT NOT NULL, `student_id` INT NOT NULL, `mark` DECIMAL(6,2) DEFAULT NULL, `remarks` VARCHAR(255) DEFAULT NULL, `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY(`assessment_id`,`student_id`), KEY(`student_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ] as $tbl) mysqli_query($conn, $tbl);

        // ── Which unit is selected? ───────────────────────────────
        // Lecturer only sees units they are assigned to
        $my_units_res = mysqli_query($conn,
            "SELECT cu.id, cu.title, cu.unit_code, cu.course_id, c.title AS course_title
             FROM course_units cu
             JOIN courses c ON c.id = cu.course_id
             WHERE cu.lecturer_id = $user_id
             ORDER BY c.title ASC, cu.title ASC"
        );
        $my_units = [];
        while ($u = mysqli_fetch_assoc($my_units_res)) $my_units[] = $u;

        $sel_unit_id = isset($_GET['unit_id']) ? intval($_GET['unit_id']) : (count($my_units) > 0 ? $my_units[0]['id'] : 0);
        $sel_unit    = null;
        foreach ($my_units as $mu) { if ($mu['id'] === $sel_unit_id) { $sel_unit = $mu; break; } }

        // ── Assessment components for selected unit ───────────────
        $assessments = [];
        if ($sel_unit_id > 0) {
            $ar = mysqli_query($conn,
                "SELECT * FROM unit_assessments WHERE unit_id = $sel_unit_id ORDER BY sort_order ASC, id ASC"
            );
            while ($a = mysqli_fetch_assoc($ar)) $assessments[] = $a;
        }

        // ── Students registered for this unit ─────────────────────
        $reg_students = [];
        if ($sel_unit_id > 0) {
            $sr = mysqli_query($conn,
                "SELECT u.id, u.full_name, u.email
                 FROM unit_registrations ur
                 JOIN users u ON u.id = ur.student_id
                 WHERE ur.unit_id = $sel_unit_id
                 ORDER BY u.full_name ASC"
            );
            while ($s = mysqli_fetch_assoc($sr)) $reg_students[] = $s;
        }

        // ── Existing marks matrix: marks[assessment_id][student_id] ─
        $marks_matrix = [];
        if ($sel_unit_id > 0 && !empty($assessments) && !empty($reg_students)) {
            $assess_ids = implode(',', array_column($assessments, 'id'));
            $stud_ids   = implode(',', array_column($reg_students, 'id'));
            $mr2 = mysqli_query($conn,
                "SELECT assessment_id, student_id, mark, remarks
                 FROM unit_marks
                 WHERE assessment_id IN ($assess_ids) AND student_id IN ($stud_ids)"
            );
            while ($m = mysqli_fetch_assoc($mr2))
                $marks_matrix[$m['assessment_id']][$m['student_id']] = $m;
        }

        // ── Per-student final calculation ─────────────────────────
        $student_totals = [];
        foreach ($reg_students as $stu) {
            $sid = $stu['id'];
            $sum_marks = 0; $sum_max = 0; $any = false;
            foreach ($assessments as $ass) {
                $sum_max += floatval($ass['max_mark']);
                if (isset($marks_matrix[$ass['id']][$sid]) && $marks_matrix[$ass['id']][$sid]['mark'] !== null) {
                    $sum_marks += floatval($marks_matrix[$ass['id']][$sid]['mark']);
                    $any = true;
                }
            }
            $pct = ($sum_max > 0 && $any) ? round($sum_marks / $sum_max * 100, 1) : null;
            $student_totals[$sid] = ['sum_marks' => $sum_marks, 'sum_max' => $sum_max, 'pct' => $pct, 'any' => $any];
        }

        $grade_fn_l = function($pct) {
            if ($pct >= 70) return ['A', 'text-emerald-600 bg-emerald-50 border-emerald-200'];
            if ($pct >= 60) return ['B', 'text-blue-600 bg-blue-50 border-blue-200'];
            if ($pct >= 50) return ['C', 'text-indigo-600 bg-indigo-50 border-indigo-200'];
            if ($pct >= 40) return ['D', 'text-amber-600 bg-amber-50 border-amber-200'];
            return ['F', 'text-red-600 bg-red-50 border-red-200'];
        };

        $umsg  = $_GET['umsg'] ?? '';
        $ucount = intval($_GET['count'] ?? 0);

        // ── Summary stats ─────────────────────────────────────────
        $graded_stus = array_filter($student_totals, fn($t) => $t['any']);
        $avg_pct_u   = count($graded_stus) > 0 ? round(array_sum(array_column($graded_stus,'pct'))/count($graded_stus),1) : 0;
        $pass_stus   = count(array_filter($graded_stus, fn($t) => floatval($t['pct']) >= 40));
        ?>

        <div class="mb-10">
            <!-- Header + feedback -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h2 class="text-2xl font-extrabold text-slate-900">Marks Manager</h2>
                    <p class="text-slate-500 text-sm mt-1">Select a unit, define assessments (CAT 1, Exam, etc.), then enter marks per student.</p>
                </div>
                <?php if ($umsg): ?>
                <div class="border rounded-xl px-4 py-2.5 text-xs font-black uppercase <?php echo $umsg === 'saved' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : ($umsg === 'assess_added' ? 'bg-blue-50 text-blue-700 border-blue-200' : 'bg-red-50 text-red-600 border-red-200'); ?>">
                    <i class="fa-solid <?php echo $umsg === 'saved' ? 'fa-check' : ($umsg === 'assess_added' ? 'fa-list-check' : 'fa-triangle-exclamation'); ?> mr-1"></i>
                    <?php echo match($umsg) { 'saved'=>"Marks saved ($ucount entries).", 'assess_added'=>'Assessment added.', 'assess_deleted'=>'Assessment deleted.', 'unauthorized'=>'Access denied.', default=>'Done.' }; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Unit selector tabs -->
            <?php if (empty($my_units)): ?>
            <div class="bg-white rounded-2xl border border-slate-100 p-14 text-center">
                <i class="fa-solid fa-layer-group text-slate-200 text-4xl mb-3"></i>
                <p class="text-slate-600 font-bold text-sm">No units assigned to you yet.</p>
                <p class="text-slate-400 text-xs mt-1">Ask your admin to assign you to a unit in Course Catalog.</p>
            </div>
            <?php else: ?>

            <!-- Tabs: group by course -->
            <?php
            $tabs_by_course = [];
            foreach ($my_units as $mu) $tabs_by_course[$mu['course_title']][] = $mu;
            ?>
            <div class="mb-6 space-y-2">
                <?php foreach ($tabs_by_course as $cname => $cunits): ?>
                <div>
                    <p class="text-[9px] font-black uppercase text-slate-400 tracking-widest mb-2"><?php echo htmlspecialchars($cname); ?></p>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($cunits as $mu):
                            $isAct = $mu['id'] === $sel_unit_id;
                        ?>
                        <a href="?view=marks&unit_id=<?php echo $mu['id']; ?>"
                           class="flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-black uppercase tracking-widest transition-all
                                  <?php echo $isAct ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-200' : 'bg-white text-slate-600 border border-slate-200 hover:border-indigo-400 hover:text-indigo-600'; ?>">
                            <i class="fa-solid fa-book"></i>
                            <?php echo htmlspecialchars($mu['title']); ?>
                            <?php if ($mu['unit_code']): ?><span class="opacity-60 font-medium"><?php echo htmlspecialchars($mu['unit_code']); ?></span><?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (!$sel_unit): ?>
            <div class="bg-white rounded-2xl border border-slate-100 p-12 text-center">
                <p class="text-slate-500 font-bold text-sm">Select a unit above to manage marks.</p>
            </div>

            <?php else: ?>
            <div class="grid grid-cols-1 xl:grid-cols-4 gap-6">

                <!-- LEFT: Assessment Setup -->
                <div class="xl:col-span-1">
                    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden sticky top-6">
                        <div class="px-5 py-4 bg-gradient-to-r from-slate-50 to-indigo-50 border-b border-slate-100">
                            <h3 class="text-xs font-black uppercase text-indigo-700 tracking-widest">
                                <i class="fa-solid fa-list-check mr-1"></i>Assessment Setup
                            </h3>
                            <p class="text-[9px] text-slate-400 font-bold mt-0.5"><?php echo htmlspecialchars($sel_unit['title']); ?></p>
                        </div>

                        <!-- Existing assessments -->
                        <?php if (!empty($assessments)): ?>
                        <div class="divide-y divide-slate-50">
                            <?php foreach ($assessments as $ass):
                                $typeColor = $ass['type'] === 'exam' ? 'bg-red-50 text-red-600' : 'bg-blue-50 text-blue-600';
                            ?>
                            <div class="px-5 py-3 flex items-center justify-between group">
                                <div>
                                    <p class="text-sm font-black text-slate-800"><?php echo htmlspecialchars($ass['name']); ?></p>
                                    <div class="flex items-center gap-2 mt-0.5">
                                        <span class="text-[8px] font-black uppercase px-1.5 py-0.5 rounded-md <?php echo $typeColor; ?>"><?php echo $ass['type']; ?></span>
                                        <span class="text-[9px] font-bold text-slate-400">/ <?php echo number_format(floatval($ass['max_mark']),0); ?> marks</span>
                                    </div>
                                </div>
                                <a href="unit_actions.php?action=delete_assessment&assess_id=<?php echo $ass['id']; ?>&unit_id=<?php echo $sel_unit_id; ?>"
                                   onclick="return confirm('Delete this assessment? All entered marks will be lost.')"
                                   class="opacity-0 group-hover:opacity-100 w-7 h-7 flex items-center justify-center rounded-lg bg-red-50 text-red-400 hover:bg-red-500 hover:text-white transition-all">
                                    <i class="fa-solid fa-xmark text-[10px]"></i>
                                </a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="px-5 py-4 text-center text-slate-400 text-xs italic">No assessments yet.</div>
                        <?php endif; ?>

                        <!-- Total max -->
                        <?php if (!empty($assessments)): ?>
                        <div class="px-5 py-3 border-t border-slate-100 bg-slate-50 flex justify-between items-center">
                            <span class="text-[9px] font-black uppercase text-slate-400">Total Max</span>
                            <span class="text-sm font-black text-slate-800"><?php echo number_format(array_sum(array_column($assessments,'max_mark')),0); ?></span>
                        </div>
                        <?php endif; ?>

                        <!-- Add assessment form -->
                        <div class="px-5 py-4 border-t border-slate-100 bg-indigo-50/30">
                            <p class="text-[9px] font-black uppercase text-indigo-600 tracking-widest mb-3">+ Add Assessment</p>
                            <form action="unit_actions.php" method="POST" class="space-y-3">
                                <input type="hidden" name="action" value="add_assessment">
                                <input type="hidden" name="unit_id" value="<?php echo $sel_unit_id; ?>">
                                <input type="text" name="name" required placeholder="e.g. CAT 1, Final Exam"
                                    class="w-full p-2.5 bg-white border border-slate-200 rounded-xl text-xs font-semibold outline-none focus:ring-2 focus:ring-indigo-400">
                                <div class="grid grid-cols-2 gap-2">
                                    <select name="type" class="p-2.5 bg-white border border-slate-200 rounded-xl text-xs font-semibold outline-none focus:ring-2 focus:ring-indigo-400">
                                        <option value="coursework">Coursework</option>
                                        <option value="exam">Exam</option>
                                    </select>
                                    <input type="number" name="max_mark" value="30" min="1" step="0.5" placeholder="Max"
                                        class="p-2.5 bg-white border border-slate-200 rounded-xl text-xs font-semibold outline-none focus:ring-2 focus:ring-indigo-400">
                                </div>
                                <button type="submit"
                                    class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-black text-[9px] uppercase rounded-xl tracking-widest transition-all active:scale-95">
                                    <i class="fa-solid fa-plus mr-1"></i>Add
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: Marks Entry Table -->
                <div class="xl:col-span-3">

                    <?php if (empty($assessments)): ?>
                    <div class="bg-white rounded-2xl border border-dashed border-slate-200 p-14 text-center">
                        <i class="fa-solid fa-list-check text-slate-200 text-4xl mb-3"></i>
                        <p class="text-slate-600 font-bold text-sm">Add assessments first</p>
                        <p class="text-slate-400 text-xs mt-1">Use the panel on the left to define CAT 1, CAT 2, Exam, etc.</p>
                    </div>

                    <?php elseif (empty($reg_students)): ?>
                    <div class="bg-white rounded-2xl border border-dashed border-slate-200 p-14 text-center">
                        <i class="fa-solid fa-users text-slate-200 text-4xl mb-3"></i>
                        <p class="text-slate-600 font-bold text-sm">No students registered for <span class="text-indigo-600"><?php echo htmlspecialchars($sel_unit['title']); ?></span></p>
                        <p class="text-slate-400 text-xs mt-1">Students can register for units from their dashboard after enrolling in the course.</p>
                    </div>

                    <?php else: ?>

                    <!-- Summary row -->
                    <div class="grid grid-cols-4 gap-3 mb-5">
                        <div class="bg-white rounded-2xl border border-slate-100 p-4 text-center shadow-sm">
                            <p class="text-[9px] font-black uppercase text-slate-400 mb-1">Registered</p>
                            <p class="text-2xl font-black text-slate-900"><?php echo count($reg_students); ?></p>
                        </div>
                        <div class="bg-white rounded-2xl border border-slate-100 p-4 text-center shadow-sm">
                            <p class="text-[9px] font-black uppercase text-slate-400 mb-1">Graded</p>
                            <p class="text-2xl font-black text-indigo-600"><?php echo count($graded_stus); ?></p>
                        </div>
                        <div class="bg-white rounded-2xl border border-slate-100 p-4 text-center shadow-sm">
                            <p class="text-[9px] font-black uppercase text-slate-400 mb-1">Avg Score</p>
                            <p class="text-2xl font-black <?php echo $avg_pct_u >= 50 ? 'text-emerald-600' : 'text-red-500'; ?>"><?php echo $avg_pct_u; ?>%</p>
                        </div>
                        <div class="bg-white rounded-2xl border border-slate-100 p-4 text-center shadow-sm">
                            <p class="text-[9px] font-black uppercase text-slate-400 mb-1">Passing</p>
                            <p class="text-2xl font-black text-emerald-600"><?php echo $pass_stus; ?></p>
                        </div>
                    </div>

                    <!-- Marks form -->
                    <form method="POST" action="save_marks.php" class="bg-white rounded-2xl border border-slate-100 overflow-hidden shadow-sm">
                        <input type="hidden" name="action" value="save_unit_marks">
                        <input type="hidden" name="unit_id" value="<?php echo $sel_unit_id; ?>">

                        <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-indigo-50/40 flex items-center justify-between">
                            <div>
                                <h3 class="font-black text-slate-800 text-sm">
                                    <i class="fa-solid fa-book text-indigo-500 mr-2"></i>
                                    <?php echo htmlspecialchars($sel_unit['title']); ?>
                                    <?php if ($sel_unit['unit_code']): ?><span class="text-slate-400 font-medium text-xs ml-1">(<?php echo htmlspecialchars($sel_unit['unit_code']); ?>)</span><?php endif; ?>
                                </h3>
                                <p class="text-[10px] text-slate-400 font-bold mt-0.5">
                                    <?php echo htmlspecialchars($sel_unit['course_title']); ?> &nbsp;·&nbsp;
                                    <?php echo count($assessments); ?> assessment(s) &nbsp;·&nbsp;
                                    Total max: <?php echo array_sum(array_column($assessments,'max_mark')); ?>
                                </p>
                            </div>
                            <button type="submit"
                                class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-black text-[10px] uppercase rounded-xl tracking-widest transition-all active:scale-95 shadow-lg shadow-indigo-200">
                                <i class="fa-solid fa-floppy-disk mr-1"></i>Save All Marks
                            </button>
                        </div>

                        <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="border-b border-slate-100">
                                <tr>
                                    <th class="text-[9px] font-black uppercase text-slate-400 tracking-widest px-5 py-3 w-52">Student</th>
                                    <?php foreach ($assessments as $ass):
                                        $thColor = $ass['type'] === 'exam' ? 'text-red-500' : 'text-blue-500';
                                    ?>
                                    <th class="text-[9px] font-black uppercase text-slate-400 tracking-widest px-4 py-3 text-center min-w-[130px]">
                                        <div class="<?php echo $thColor; ?> font-black"><?php echo htmlspecialchars($ass['name']); ?></div>
                                        <div class="text-[8px] text-slate-300 normal-case font-bold">/ <?php echo number_format(floatval($ass['max_mark']),0); ?> &nbsp;(<?php echo $ass['type']; ?>)</div>
                                    </th>
                                    <?php endforeach; ?>
                                    <th class="text-[9px] font-black uppercase text-slate-400 tracking-widest px-4 py-3 text-center">Final %</th>
                                    <th class="text-[9px] font-black uppercase text-slate-400 tracking-widest px-4 py-3 text-center">Grade</th>
                                    <th class="text-[9px] font-black uppercase text-slate-400 tracking-widest px-4 py-3">Remarks</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50" id="marksTableBody">
                            <?php foreach ($reg_students as $i => $stu):
                                $sid = $stu['id'];
                                $tot = $student_totals[$sid];
                                $pct_val = $tot['pct'];
                                [$gLetter, $gClass] = $pct_val !== null ? $grade_fn_l($pct_val) : ['—', 'text-slate-300 bg-slate-50 border-slate-100'];
                                // get a remark from any assessment for this student
                                $remark_val = '';
                                foreach ($assessments as $ass) {
                                    if (isset($marks_matrix[$ass['id']][$sid]['remarks']) && $marks_matrix[$ass['id']][$sid]['remarks']) {
                                        $remark_val = $marks_matrix[$ass['id']][$sid]['remarks']; break;
                                    }
                                }
                            ?>
                            <tr class="hover:bg-slate-50/60 transition-colors" data-row="<?php echo $i; ?>" data-max="<?php echo array_sum(array_column($assessments,'max_mark')); ?>">
                                <td class="px-5 py-3">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-8 h-8 bg-gradient-to-tr from-indigo-400 to-purple-400 rounded-full flex items-center justify-center flex-shrink-0">
                                            <span class="text-white text-[9px] font-black"><?php echo strtoupper(mb_substr($stu['full_name'],0,2)); ?></span>
                                        </div>
                                        <div>
                                            <p class="text-xs font-black text-slate-800"><?php echo htmlspecialchars($stu['full_name']); ?></p>
                                            <p class="text-[9px] text-slate-400"><?php echo htmlspecialchars($stu['email']); ?></p>
                                        </div>
                                    </div>
                                </td>
                                <?php foreach ($assessments as $ass):
                                    $existing = $marks_matrix[$ass['id']][$sid]['mark'] ?? '';
                                    $existing_val = $existing !== null && $existing !== '' ? floatval($existing) : '';
                                ?>
                                <td class="px-4 py-3 text-center">
                                    <input type="number"
                                           name="marks[<?php echo $ass['id']; ?>][<?php echo $sid; ?>]"
                                           value="<?php echo $existing_val !== '' ? number_format($existing_val, 1, '.', '') : ''; ?>"
                                           min="0" max="<?php echo floatval($ass['max_mark']); ?>" step="0.5"
                                           placeholder="—"
                                           class="w-20 text-center p-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:ring-2 focus:ring-indigo-400 transition-all mark-input"
                                           data-row="<?php echo $i; ?>"
                                           data-max-cell="<?php echo floatval($ass['max_mark']); ?>"
                                           oninput="recalcRow(<?php echo $i; ?>)">
                                </td>
                                <?php endforeach; ?>
                                <!-- Final % -->
                                <td class="px-4 py-3 text-center">
                                    <span id="final-pct-<?php echo $i; ?>" class="text-base font-black <?php echo $pct_val !== null ? ($pct_val >= 40 ? 'text-slate-900' : 'text-red-600') : 'text-slate-300'; ?>">
                                        <?php echo $pct_val !== null ? $pct_val.'%' : '—'; ?>
                                    </span>
                                </td>
                                <!-- Grade -->
                                <td class="px-4 py-3 text-center">
                                    <span id="grade-<?php echo $i; ?>"
                                          class="inline-block border px-2.5 py-1 rounded-xl text-xs font-black <?php echo $gClass; ?>">
                                        <?php echo $pct_val !== null ? $gLetter : '—'; ?>
                                    </span>
                                </td>
                                <!-- Remarks -->
                                <td class="px-4 py-3">
                                    <input type="text"
                                           name="remarks[<?php echo $sid; ?>]"
                                           value="<?php echo htmlspecialchars($remark_val); ?>"
                                           placeholder="Optional note…"
                                           maxlength="120"
                                           class="w-36 p-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold outline-none focus:ring-2 focus:ring-indigo-300 transition-all">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>

                        <div class="px-6 py-4 border-t border-slate-100 bg-slate-50 flex justify-end">
                            <button type="submit"
                                class="px-8 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-black text-xs uppercase rounded-xl tracking-widest transition-all active:scale-95 shadow-lg shadow-indigo-200">
                                <i class="fa-solid fa-floppy-disk mr-2"></i>Save All Marks
                            </button>
                        </div>
                    </form>

                    <!-- Grade Distribution -->
                    <?php if (count($graded_stus) > 0):
                        $gdist = ['A'=>0,'B'=>0,'C'=>0,'D'=>0,'F'=>0];
                        $gdist_clr = ['A'=>'bg-emerald-50 text-emerald-700 border-emerald-200','B'=>'bg-blue-50 text-blue-700 border-blue-200','C'=>'bg-indigo-50 text-indigo-700 border-indigo-200','D'=>'bg-amber-50 text-amber-700 border-amber-200','F'=>'bg-red-50 text-red-700 border-red-200'];
                        foreach ($student_totals as $t) { if ($t['any']) { [$g2] = $grade_fn_l($t['pct']); $gdist[$g2]++; } }
                    ?>
                    <div class="mt-5 bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50">
                            <h3 class="font-black text-slate-800 text-sm"><i class="fa-solid fa-chart-bar text-indigo-400 mr-2"></i>Grade Distribution</h3>
                        </div>
                        <div class="px-6 py-5 grid grid-cols-5 gap-3">
                            <?php foreach ($gdist as $g3 => $cnt3): ?>
                            <div class="border rounded-2xl p-4 text-center <?php echo $gdist_clr[$g3]; ?>">
                                <p class="text-3xl font-black"><?php echo $cnt3; ?></p>
                                <p class="text-xs font-black uppercase mt-1">Grade <?php echo $g3; ?></p>
                                <?php if (count($graded_stus) > 0): ?><p class="text-[9px] opacity-60 mt-0.5"><?php echo round($cnt3/count($graded_stus)*100); ?>%</p><?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php endif; /* end empty assessments / empty students */ ?>
                </div>
            </div>

            <?php endif; /* end sel_unit */ ?>
            <?php endif; /* end empty my_units */ ?>
        </div>

        <!-- Live recalc JS -->
        <script>
        const gradeThresholds = [[70,'A','text-emerald-600 bg-emerald-50 border-emerald-200'],[60,'B','text-blue-600 bg-blue-50 border-blue-200'],[50,'C','text-indigo-600 bg-indigo-50 border-indigo-200'],[40,'D','text-amber-600 bg-amber-50 border-amber-200'],[0,'F','text-red-600 bg-red-50 border-red-200']];
        function getGrade(pct) { for(const [min,l,c] of gradeThresholds) if(pct>=min) return [l,c]; return ['F','text-red-600 bg-red-50 border-red-200']; }

        function recalcRow(rowIdx) {
            const row    = document.querySelector(`tr[data-row="${rowIdx}"]`);
            const inputs = row.querySelectorAll('.mark-input');
            const fEl    = document.getElementById('final-pct-' + rowIdx);
            const gEl    = document.getElementById('grade-' + rowIdx);
            let sumM = 0, sumMax = 0, hasAny = false;
            inputs.forEach(inp => {
                sumMax += parseFloat(inp.dataset.maxCell || 0);
                if (inp.value !== '') { sumM += parseFloat(inp.value || 0); hasAny = true; }
            });
            if (!hasAny || sumMax === 0) {
                fEl.textContent = '—'; fEl.className = 'text-base font-black text-slate-300';
                gEl.textContent = '—'; gEl.className = 'inline-block border px-2.5 py-1 rounded-xl text-xs font-black text-slate-300 bg-slate-50 border-slate-100';
                return;
            }
            const pct = parseFloat((sumM / sumMax * 100).toFixed(1));
            fEl.textContent = pct + '%';
            fEl.className = `text-base font-black ${pct >= 40 ? 'text-slate-900' : 'text-red-600'}`;
            const [letter, cls] = getGrade(pct);
            gEl.textContent = letter;
            gEl.className = `inline-block border px-2.5 py-1 rounded-xl text-xs font-black ${cls}`;
        }
        </script>

        <?php else: ?>

            <!-- Quick Stats -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
                <div class="bg-white rounded-2xl p-5 border border-slate-100 shadow-sm flex items-center space-x-4">
                    <div class="w-11 h-11 bg-indigo-50 rounded-xl flex items-center justify-center">
                        <i class="fa-solid fa-graduation-cap text-indigo-600"></i>
                    </div>
                    <div>
                        <p class="text-[9px] font-black uppercase text-slate-400">Courses</p>
                        <h3 class="text-2xl font-black text-slate-900"><?php echo $totalCourses; ?></h3>
                    </div>
                </div>
                <div class="bg-white rounded-2xl p-5 border border-slate-100 shadow-sm flex items-center space-x-4">
                    <div class="w-11 h-11 bg-emerald-50 rounded-xl flex items-center justify-center">
                        <i class="fa-solid fa-users text-emerald-600"></i>
                    </div>
                    <div>
                        <p class="text-[9px] font-black uppercase text-slate-400">Students</p>
                        <h3 class="text-2xl font-black text-slate-900"><?php echo $activeStudents; ?></h3>
                    </div>
                </div>
                <div class="bg-white rounded-2xl p-5 border border-slate-100 shadow-sm flex items-center space-x-4">
                    <div class="w-11 h-11 bg-amber-50 rounded-xl flex items-center justify-center">
                        <i class="fa-solid fa-file-circle-check text-amber-500"></i>
                    </div>
                    <div>
                        <p class="text-[9px] font-black uppercase text-slate-400">Materials</p>
                        <h3 class="text-2xl font-black text-slate-900"><?php echo $totalMaterials; ?></h3>
                    </div>
                </div>
                <div class="bg-white rounded-2xl p-5 border border-slate-100 shadow-sm flex items-center space-x-4">
                    <div class="w-11 h-11 bg-violet-50 rounded-xl flex items-center justify-center">
                        <i class="fa-solid fa-chart-bar text-violet-600"></i>
                    </div>
                    <div>
                        <p class="text-[9px] font-black uppercase text-slate-400">Avg Mastery</p>
                        <h3 class="text-2xl font-black text-slate-900"><?php echo $avgMastery; ?><span class="text-sm text-slate-400">%</span></h3>
                    </div>
                </div>
            </div>

            <!-- Two-column layout: Skill Breakdown + Recent Uploads -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">

                <!-- Skill Mastery Breakdown -->
                <?php if (!empty($skillData)): ?>
                <div class="bg-white rounded-2xl p-6 border border-slate-100 shadow-sm lg:col-span-2">
                    <h4 class="font-extrabold text-slate-800 text-sm mb-5 uppercase tracking-wide">Student Skill Mastery Across Your Courses</h4>
                    <div class="space-y-4">
                        <?php foreach ($skillData as $sk): 
                            $lvl     = round(floatval($sk['avg_m']), 1);
                            $display = max(2, min(100, $lvl)); /* min 2% so zero bars remain visible */
                            $color   = $lvl >= 70 ? 'bg-emerald-500' : ($lvl >= 40 ? 'bg-indigo-500' : 'bg-red-500');
                        ?>
                        <div>
                            <div class="flex justify-between mb-1.5">
                                <span class="text-xs font-bold text-slate-600"><?php echo htmlspecialchars($sk['skill_name']); ?></span>
                                <div class="flex items-center space-x-2">
                                    <span class="text-[9px] text-slate-400"><?php echo $sk['students']; ?> students</span>
                                    <span class="text-xs font-black <?php echo $lvl>=70?'text-emerald-600':($lvl>=40?'text-indigo-600':'text-red-500'); ?>"><?php echo $lvl; ?>%</span>
                                </div>
                            </div>
                            <div class="h-2 bg-slate-100 rounded-full overflow-hidden">
                                <!-- width starts at 0% — JS animates to data-target after load -->
                                <div class="h-full <?php echo $color; ?> rounded-full lect-mastery-fill"
                                     style="width:0%; transition: width 1.1s cubic-bezier(0.22,1,0.36,1);"
                                     data-target="<?php echo $display; ?>"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Recent Uploads -->
                <div class="bg-white rounded-2xl p-6 border border-slate-100 shadow-sm">
                    <div class="flex items-center justify-between mb-5">
                        <h4 class="font-extrabold text-slate-800 text-sm uppercase tracking-wide">Recent Uploads</h4>
                        <button onclick="toggleModal('uploadModal')" class="text-[9px] font-black uppercase text-indigo-600 hover:text-indigo-800">
                            <i class="fa-solid fa-plus mr-1"></i>Add
                        </button>
                    </div>
                    <div class="space-y-3">
                        <?php while ($u = mysqli_fetch_assoc($recent_uploads)): ?>
                        <div class="flex items-center space-x-3 p-3 bg-slate-50 rounded-xl">
                            <div class="w-8 h-8 rounded-lg <?php echo $u['type']=='pdf'?'bg-red-100':'bg-blue-100'; ?> flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid <?php echo $u['type']=='pdf'?'fa-file-pdf text-red-500':'fa-video text-blue-500'; ?> text-xs"></i>
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs font-bold text-slate-700 truncate"><?php echo htmlspecialchars($u['title']); ?></p>
                                <p class="text-[9px] text-slate-400"><?php echo htmlspecialchars($u['course_title']); ?></p>
                            </div>
                        </div>
                        <?php endwhile; ?>
                        <?php if ($totalMaterials === 0): ?>
                        <p class="text-slate-400 text-xs text-center py-4 italic">No uploads yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════
                 AI DECISION DASHBOARD (Rule-Based — ai_engine.php)
                 All figures are computed server-side from real DB data.
                 No external API — 100% rule-based adaptive engine.
            ══════════════════════════════════════════════════ -->
            <?php
            // Derive class-health summary from rule data
            $atRisk    = $decisionData['at_risk_students'];
            $topStud   = $decisionData['top_students'];
            $skillAvgs = $decisionData['skill_averages'];  // weakest first
            $classSumm = $decisionData['class_summary'];

            $totalAtRisk  = count($atRisk);
            $totalTop     = count($topStud);
            $weakestSkill = !empty($skillAvgs) ? $skillAvgs[0] : null;

            // Engagement score: class avg with at-risk penalty
            $class_avg_score = floatval($decisionData['class_avg'] ?? 0);
            $atRiskPenalty   = $activeStudents > 0 ? ($totalAtRisk / $activeStudents) * 30 : 0;
            $engagementScore = max(0, min(100, (int)($class_avg_score - $atRiskPenalty)));
            $engColor = $engagementScore >= 70 ? '#22c55e' : ($engagementScore >= 40 ? '#f59e0b' : '#ef4444');

            // Priority action rule
            if ($totalAtRisk > 0 && $weakestSkill) {
                $priorityAction = "Conduct a remedial session on \"{$weakestSkill['skill']}\" — "
                    . $totalAtRisk . " student(s) are below the performance threshold.";
            } elseif ($totalMaterials < 3) {
                $priorityAction = "Upload additional course materials. Students currently have access to only "
                    . $totalMaterials . " resource(s).";
            } else {
                $priorityAction = "Class performance is satisfactory. Consider scheduling an advanced assessment to challenge top performers.";
            }
            ?>

            <div class="ai-panel rounded-3xl p-7 mb-8">
                <!-- Header -->
                <div class="flex items-center justify-between mb-7">
                    <div class="flex items-center space-x-3">
                        <div class="w-9 h-9 bg-violet-500/20 rounded-xl flex items-center justify-center">
                            <i class="fa-solid fa-brain text-violet-400"></i>
                        </div>
                        <div>
                            <span class="ai-tag text-white font-black uppercase px-2 py-0.5 rounded-md">AI Decision Dashboard</span>
                            <p class="text-slate-400 text-[10px] mt-1 uppercase tracking-widest">Rule-based analysis — live from database</p>
                        </div>
                    </div>
                    <!-- Engagement Score Ring (PHP computed) -->
                    <div class="flex flex-col items-center">
                        <div class="score-ring" style="--pct: <?php echo $engagementScore; ?>%">
                            <span><?php echo $engagementScore; ?></span>
                        </div>
                        <p class="text-[9px] text-slate-500 uppercase mt-1 tracking-widest">Class Score</p>
                    </div>
                </div>

                <!-- Course Health Row -->
                <?php if (!empty($classSumm)): ?>
                <div class="mb-6">
                    <p class="text-[9px] font-black uppercase text-slate-400 tracking-widest mb-3">
                        <i class="fa-solid fa-graduation-cap mr-1 text-violet-400"></i>Course Health
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-<?php echo min(3, count($classSumm)); ?> gap-3">
                        <?php foreach ($classSumm as $cs):
                            $hColor = $cs['health'] === 'good' ? 'border-emerald-500/40 bg-emerald-500/5'
                                    : ($cs['health'] === 'moderate' ? 'border-amber-500/40 bg-amber-500/5'
                                    : 'border-red-500/40 bg-red-500/5');
                            $hLabel = $cs['health'] === 'good' ? 'text-emerald-400'
                                    : ($cs['health'] === 'moderate' ? 'text-amber-400' : 'text-red-400');
                        ?>
                        <div class="border <?php echo $hColor; ?> rounded-2xl p-4">
                            <p class="text-[9px] font-black uppercase <?php echo $hLabel; ?>"><?php echo $cs['health']; ?></p>
                            <p class="text-slate-200 font-bold text-sm mt-1"><?php echo htmlspecialchars($cs['title']); ?></p>
                            <p class="text-slate-400 text-[10px] mt-1">
                                <?php echo $cs['enrolled']; ?> enrolled &nbsp;·&nbsp; quiz avg <?php echo $cs['avg_mastery']; ?>%<?php if ($cs['avg_exam'] !== null): ?> &nbsp;·&nbsp; exam <?php echo $cs['avg_exam']; ?>%<?php endif; ?>
                            </p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Skill Weakness + At-Risk + Top Performers -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-6">

                    <!-- Weakest Skills -->
                    <div class="bg-white/5 rounded-2xl p-4">
                        <p class="text-[9px] font-black uppercase text-rose-400 tracking-widest mb-3">
                            <i class="fa-solid fa-chart-simple mr-1"></i>Skill Gaps (Lowest First)
                        </p>
                        <?php if (!empty($skillAvgs)): ?>
                            <div class="space-y-3">
                            <?php foreach (array_slice($skillAvgs, 0, 3) as $sk):
                                $skColor = $sk['status'] === 'strong' ? 'text-emerald-400'
                                         : ($sk['status'] === 'developing' ? 'text-amber-400' : 'text-red-400');
                                $barW    = max(2, min(100, $sk['average'])); /* min 2% so zero bars remain visible */
                            ?>
                                <div>
                                    <div class="flex justify-between mb-1">
                                        <span class="text-[10px] text-slate-300"><?php echo htmlspecialchars($sk['skill']); ?></span>
                                        <span class="text-[10px] font-black <?php echo $skColor; ?>"><?php echo $sk['average']; ?>%</span>
                                    </div>
                                    <!-- width starts at 0% (from .skill-fill CSS) — JS animates to data-target -->
                                    <div class="skill-bar"><div class="skill-fill advisor-mastery-fill" data-target="<?php echo $barW; ?>"></div></div>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-slate-500 text-xs italic">No mastery data recorded yet.</p>
                        <?php endif; ?>
                    </div>

                    <!-- At-Risk Students -->
                    <div class="bg-white/5 rounded-2xl p-4">
                        <p class="text-[9px] font-black uppercase text-amber-400 tracking-widest mb-3">
                            <i class="fa-solid fa-circle-exclamation mr-1"></i>
                            At-Risk Students
                            <?php if ($totalAtRisk > 0): ?>
                                <span class="bg-red-500 text-white text-[8px] px-1.5 py-0.5 rounded-full ml-1"><?php echo $totalAtRisk; ?></span>
                            <?php endif; ?>
                        </p>
                        <?php if (!empty($atRisk)): ?>
                            <div class="space-y-2">
                            <?php foreach (array_slice($atRisk, 0, 4) as $ar):
                                $riskColor = $ar['risk_level'] === 'critical' ? 'text-red-400' : 'text-amber-400';
                            ?>
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-[10px] font-bold text-slate-200"><?php echo htmlspecialchars($ar['name']); ?></p>
                                        <p class="text-[9px] text-slate-500"><?php echo htmlspecialchars($ar['career']); ?></p>
                                    </div>
                                    <span class="text-[9px] font-black <?php echo $riskColor; ?> uppercase"><?php echo isset($ar['combined_score']) ? $ar['combined_score'] : $ar['avg_mastery']; ?>%</span>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-slate-500 text-xs italic">No at-risk students detected.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Top Performers -->
                    <div class="bg-white/5 rounded-2xl p-4">
                        <p class="text-[9px] font-black uppercase text-emerald-400 tracking-widest mb-3">
                            <i class="fa-solid fa-trophy mr-1"></i>Top Performers
                        </p>
                        <?php if (!empty($topStud)): ?>
                            <div class="space-y-2">
                            <?php foreach (array_slice($topStud, 0, 4) as $tp): ?>
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-[10px] font-bold text-slate-200"><?php echo htmlspecialchars($tp['name']); ?></p>
                                        <p class="text-[9px] text-slate-500"><?php echo htmlspecialchars($tp['career']); ?></p>
                                    </div>
                                    <span class="text-[9px] font-black text-emerald-400"><?php echo isset($tp['combined_score']) ? $tp['combined_score'] : $tp['avg_mastery']; ?>%</span>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-slate-500 text-xs italic">No top performers yet.</p>
                        <?php endif; ?>
                    </div>

                </div>

                <!-- Priority Action (rule-derived) -->
                <div class="bg-violet-500/10 border border-violet-500/20 rounded-2xl p-5">
                    <p class="text-[9px] font-black uppercase text-violet-300 tracking-widest mb-2">
                        <i class="fa-solid fa-bolt mr-1"></i>Priority Action — This Week
                    </p>
                    <p class="text-slate-200 text-sm leading-relaxed"><?php echo htmlspecialchars($priorityAction); ?></p>
                </div>
            </div>
            <!-- ── END AI DECISION DASHBOARD ── -->

            <!-- Quick Tasks (retained from original) -->
            <div class="mt-8 grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-indigo-600 rounded-[2.5rem] p-8 text-white shadow-xl shadow-indigo-100 relative overflow-hidden">
                    <p class="text-indigo-200 text-[10px] font-black uppercase tracking-widest mb-2">Quick Action</p>
                    <h2 class="text-xl font-extrabold leading-tight mb-5">Publish Unit Materials</h2>
                    <a href="upload_material.php"
                       class="block w-full py-4 text-center bg-white text-indigo-600 rounded-2xl font-black text-xs uppercase hover:shadow-lg transition-all active:scale-95">
                        <i class="fa-solid fa-cloud-arrow-up mr-2"></i>Upload Resource
                    </a>
                </div>

                <div class="bg-white rounded-[2.5rem] p-8 border border-slate-200 shadow-sm">
                    <h3 class="font-extrabold text-slate-800 mb-6">Quick Tasks</h3>
                    <div class="space-y-3">
                        <a href="upload_material.php" class="w-full flex items-center p-4 bg-slate-50 rounded-2xl border border-slate-100 hover:border-indigo-200 hover:bg-white transition-all group">
                            <div class="h-10 w-10 bg-indigo-100 text-indigo-500 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform">
                                <i class="fa-solid fa-cloud-arrow-up"></i>
                            </div>
                            <span class="ml-4 font-bold text-slate-700 text-sm">Upload Unit Materials</span>
                        </a>
                        <button class="w-full flex items-center p-4 bg-slate-50 rounded-2xl border border-slate-100 hover:border-emerald-200 hover:bg-white transition-all group">
                            <div class="h-10 w-10 bg-emerald-100 text-emerald-500 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform">
                                <i class="fa-solid fa-check-double"></i>
                            </div>
                            <span class="ml-4 font-bold text-slate-700 text-sm">Attendance Check</span>
                        </button>
                    </div>
                </div>
            </div>

        <?php endif; ?>

    </main>

    <!-- ═══════════════════════════════════════
         CHATBOT WIDGET (Lecturer version)
    ═══════════════════════════════════════ -->
    <style>
    #chatbot-fab{position:fixed;bottom:28px;right:28px;z-index:9999;width:58px;height:58px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#4f46e5);box-shadow:0 8px 25px rgba(99,102,241,0.45);display:flex;align-items:center;justify-content:center;cursor:pointer;border:none;transition:transform .2s,box-shadow .2s;}
    #chatbot-fab:hover{transform:scale(1.1);}
    #chatbot-fab .notif-dot{position:absolute;top:4px;right:4px;width:10px;height:10px;background:#ef4444;border-radius:50%;border:2px solid white;animation:pulse-dot 2s infinite;}
    @keyframes pulse-dot{0%,100%{transform:scale(1)}50%{transform:scale(1.3)}}
    #chatbot-window{position:fixed;bottom:100px;right:28px;z-index:9998;width:360px;height:500px;background:#fff;border-radius:24px;box-shadow:0 20px 60px rgba(0,0,0,0.18);display:flex;flex-direction:column;transform:scale(0.85) translateY(20px);opacity:0;pointer-events:none;transition:all .25s cubic-bezier(0.34,1.56,0.64,1);overflow:hidden;}
    #chatbot-window.open{transform:scale(1) translateY(0);opacity:1;pointer-events:all;}
    #chat-header{background:linear-gradient(135deg,#4f46e5,#7c3aed);padding:16px 18px;display:flex;align-items:center;justify-content:space-between;}
    #chat-messages{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;scroll-behavior:smooth;}
    .chat-bubble{max-width:82%;padding:10px 14px;border-radius:18px;font-size:12px;line-height:1.5;white-space:pre-wrap;}
    .bubble-bot{background:#f1f5f9;color:#1e293b;border-bottom-left-radius:4px;align-self:flex-start;}
    .bubble-user{background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;border-bottom-right-radius:4px;align-self:flex-end;}
    .chat-typing{display:flex;gap:4px;padding:10px 14px;background:#f1f5f9;border-radius:18px;border-bottom-left-radius:4px;align-self:flex-start;align-items:center;}
    .chat-typing span{width:7px;height:7px;background:#94a3b8;border-radius:50%;animation:typing-dot 1.2s infinite;}
    .chat-typing span:nth-child(2){animation-delay:.2s;}.chat-typing span:nth-child(3){animation-delay:.4s;}
    @keyframes typing-dot{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-6px)}}
    #chat-input-row{padding:12px 14px;border-top:1px solid #f1f5f9;display:flex;gap:8px;align-items:center;}
    #chat-input{flex:1;border:1px solid #e2e8f0;border-radius:12px;padding:9px 13px;font-size:12px;outline:none;font-family:inherit;transition:border-color .2s;}
    #chat-input:focus{border-color:#6366f1;}
    #chat-send{width:36px;height:36px;border-radius:10px;background:#6366f1;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .2s;}
    #chat-send:hover{background:#4f46e5;}
    .quick-chips{display:flex;flex-wrap:wrap;gap:6px;padding:0 14px 10px;}
    .chip{font-size:10px;padding:4px 10px;border-radius:20px;background:#eff6ff;color:#3b82f6;cursor:pointer;border:1px solid #bfdbfe;font-weight:700;transition:background .15s;}
    .chip:hover{background:#dbeafe;}
    </style>

    <button id="chatbot-fab" onclick="toggleChat()" title="AI Assistant">
        <i class="fa-solid fa-comments text-white text-xl"></i>
        <span class="notif-dot"></span>
    </button>

    <div id="chatbot-window">
        <div id="chat-header">
            <div class="flex items-center space-x-3">
                <div class="w-9 h-9 bg-white/20 rounded-xl flex items-center justify-center">
                    <i class="fa-solid fa-brain text-white text-sm"></i>
                </div>
                <div>
                    <p class="text-white font-black text-sm">SmartLMS Assistant</p>
                    <p class="text-indigo-200 text-[10px] uppercase tracking-widest">Lecturer AI · Always Online</p>
                </div>
            </div>
            <button onclick="toggleChat()" class="text-white/70 hover:text-white transition">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>
        <div id="chat-messages">
            <div class="chat-bubble bubble-bot">Hello! I can help you with class performance, at-risk students, quiz generation, materials, and scheduling. What do you need?</div>
        </div>
        <div class="quick-chips" id="quick-chips">
            <span class="chip" onclick="sendChip('at risk')">At-Risk Students</span>
            <span class="chip" onclick="sendChip('class performance')">Class Stats</span>
            <span class="chip" onclick="sendChip('quiz')">Quiz Help</span>
            <span class="chip" onclick="sendChip('upload')">Materials</span>
            <span class="chip" onclick="sendChip('schedule')">Schedule</span>
        </div>
        <div id="chat-input-row">
            <input id="chat-input" type="text" placeholder="Ask me anything…"
                   onkeydown="if(event.key==='Enter'){event.preventDefault();sendMessage();}">
            <button id="chat-send" onclick="sendMessage()">
                <i class="fa-solid fa-paper-plane text-white text-xs"></i>
            </button>
        </div>
    </div>

    <script>
    let chatOpen = false;
    function toggleChat() {
        chatOpen = !chatOpen;
        document.getElementById('chatbot-window').classList.toggle('open', chatOpen);
        const dot = document.querySelector('#chatbot-fab .notif-dot');
        if (dot && chatOpen) dot.style.display = 'none';
        if (chatOpen) document.getElementById('chat-input').focus();
    }
    function sendChip(text) { document.getElementById('chat-input').value = text; sendMessage(); }
    async function sendMessage() {
        const input = document.getElementById('chat-input');
        const msg   = input.value.trim();
        if (!msg) return;
        input.value = '';
        appendChatMsg(msg, 'user');
        document.getElementById('quick-chips').style.display = 'none';
        const typing = document.createElement('div');
        typing.className = 'chat-typing'; typing.id = 'typing-ind';
        typing.innerHTML = '<span></span><span></span><span></span>';
        document.getElementById('chat-messages').appendChild(typing);
        scrollChat();
        try {
            const form = new FormData(); form.append('message', msg);
            const res  = await fetch('chatbot.php', {method:'POST', body:form});
            const data = await res.json();
            document.getElementById('typing-ind')?.remove();
            appendChatMsg(data.reply || 'Sorry, I could not process that.', 'bot');
        } catch(e) {
            document.getElementById('typing-ind')?.remove();
            appendChatMsg('Connection error. Please try again.', 'bot');
        }
    }
    function appendChatMsg(text, type) {
        const el = document.createElement('div');
        el.className = 'chat-bubble ' + (type==='user' ? 'bubble-user' : 'bubble-bot');
        el.textContent = text;
        document.getElementById('chat-messages').appendChild(el);
        scrollChat();
    }
    function scrollChat() {
        const m = document.getElementById('chat-messages');
        m.scrollTop = m.scrollHeight;
    }
    window.onclick = function(e) {
        const schedModal = document.getElementById('scheduleModal');
        if (schedModal && e.target === schedModal) schedModal.style.display = 'none';
    }
    </script>
    <div id="uploadModal" class="fixed inset-0 z-[100] items-center justify-center bg-slate-900/60 backdrop-blur-sm p-6" style="display: none;">
        <div class="w-full max-w-md bg-white p-8 rounded-[2.5rem] shadow-2xl relative">
            <button type="button" onclick="toggleModal('uploadModal')" class="absolute top-6 right-6 text-slate-400 hover:text-red-500 transition-colors text-2xl z-50">
                <i class="fa-solid fa-xmark"></i>
            </button>
            <h3 class="text-2xl font-extrabold text-slate-800 tracking-tight mb-2">Resource Uploader</h3>
            <p class="text-slate-400 text-xs mb-6 font-medium">Publish materials for your specific course.</p>
            <form action="process_upload.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                <div>
                    <label class="block text-[10px] font-black uppercase text-slate-400 mb-1 ml-1 tracking-widest">Select Course</label>
                    <select name="course_id" required class="w-full p-4 bg-slate-50 border border-slate-100 rounded-2xl outline-none focus:ring-2 focus:ring-indigo-500 text-sm font-semibold text-slate-700 appearance-none">
                        <?php 
                        mysqli_data_seek($courses_query, 0); 
                        if (mysqli_num_rows($courses_query) > 0) {
                            while ($course = mysqli_fetch_assoc($courses_query)) {
                                echo "<option value='".htmlspecialchars($course['id'])."'>".htmlspecialchars($course['title'])."</option>";
                            }
                        } else {
                            echo "<option disabled selected>No courses assigned</option>";
                        }
                        ?>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-black uppercase text-slate-400 mb-1 ml-1 tracking-widest">Title</label>
                    <input type="text" name="title" placeholder="e.g. Week 1 Lecture PDF" required 
                           class="w-full p-4 bg-slate-50 border border-slate-100 rounded-2xl outline-none focus:ring-2 focus:ring-indigo-500 text-sm font-semibold">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <label class="cursor-pointer">
                        <input type="radio" name="type" value="pdf" checked class="hidden peer">
                        <div class="p-3 text-center border-2 border-slate-100 rounded-xl peer-checked:border-indigo-500 peer-checked:bg-indigo-50 text-xs font-bold text-slate-500 peer-checked:text-indigo-600 transition-all">
                            <i class="fa-solid fa-file-pdf mr-1"></i> PDF
                        </div>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="type" value="video" class="hidden peer">
                        <div class="p-3 text-center border-2 border-slate-100 rounded-xl peer-checked:border-indigo-500 peer-checked:bg-indigo-50 text-xs font-bold text-slate-500 peer-checked:text-indigo-600 transition-all">
                            <i class="fa-solid fa-video mr-1"></i> Video
                        </div>
                    </label>
                </div>
                <div class="bg-slate-50 border-2 border-dashed border-slate-200 p-6 rounded-2xl text-center group hover:border-indigo-300 transition-colors relative">
                    <input type="file" name="resource_file" required id="fileInput" class="hidden" onchange="updateFileName()">
                    <label for="fileInput" class="cursor-pointer block">
                        <i class="fa-solid fa-file-arrow-up text-3xl text-indigo-400 mb-2 group-hover:scale-110 transition-transform"></i>
                        <p id="fileNameDisplay" class="text-xs font-bold text-slate-500">Tap to select your file</p>
                    </label>
                </div>
                <button type="submit" class="w-full py-4 bg-slate-900 text-white rounded-2xl font-black text-xs uppercase tracking-widest shadow-xl hover:bg-indigo-600 transition-all active:scale-95">
                    Publish Material
                </button>
            </form>
        </div>
    </div>

    <script>
        // ── UI helpers ──
        function toggleCourseDropdown() {
            document.getElementById('courseDropdown').classList.toggle('show');
        }

        function updateFileName() {
            const input   = document.getElementById('fileInput');
            const display = document.getElementById('fileNameDisplay');
            if (input.files && input.files.length > 0) {
                display.innerText = "Selected: " + input.files[0].name;
                display.classList.remove('text-slate-500');
                display.classList.add('text-indigo-600');
            }
        }

        function toggleModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal.style.display === "none" || modal.style.display === "") {
                modal.style.display = "flex";
            } else {
                modal.style.display = "none";
            }
        }

        window.onclick = function(event) {
            const modal = document.getElementById('uploadModal');
            if (event.target === modal) toggleModal('uploadModal');
        }

        // ══════════════════════════════════════════════════
        //  AI Decision Dashboard is server-rendered (PHP).
        //  No AJAX needed — all data comes from ai_engine.php
        //  via getLecturerDecisionData() at page load time.
        // ══════════════════════════════════════════════════

        // Animate skill mastery progress bars on page load
        document.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => {
                // Main skill mastery section bars
                document.querySelectorAll('.lect-mastery-fill[data-target]').forEach(bar => {
                    bar.style.width = bar.dataset.target + '%';
                });
                // AI Advisor skill gap bars
                document.querySelectorAll('.advisor-mastery-fill[data-target]').forEach(bar => {
                    bar.style.width = bar.dataset.target + '%';
                });
            }, 350);
        });
    </script>
</body>
</html>