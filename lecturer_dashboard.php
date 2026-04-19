<?php 
include 'config.php';
include 'ai_engine.php';   // Rule-based adaptive engine + getLecturerDecisionData()

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

checkRole('lecturer'); 

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

// Average mastery across enrolled students in lecturer's courses
$avgMasteryRes = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT AVG(sm.mastery_level) as avg_m
     FROM student_mastery sm
     JOIN enrollments e ON sm.student_id = e.student_id
     WHERE e.course_id IN ($lect_ids_str)"
));
$avgMastery = round(floatval($avgMasteryRes['avg_m'] ?? 0), 1);

// Per-skill mastery breakdown
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
        .skill-bar { height: 6px; border-radius: 9999px; background: rgba(255,255,255,0.06); overflow: hidden; }
        .skill-fill { height:100%; border-radius:9999px; background: linear-gradient(90deg,#7c3aed,#6366f1); }
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

        <?php else: ?>
        <!-- ═══════════════════════════════════════════════════
             MAIN OVERVIEW — with Stats, Skill Grid & AI Panel
        ═══════════════════════════════════════════════════ -->

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
                            $lvl = round(floatval($sk['avg_m']), 1);
                            $color = $lvl >= 70 ? 'bg-emerald-500' : ($lvl >= 40 ? 'bg-indigo-500' : 'bg-red-500');
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
                                <div class="h-full <?php echo $color; ?> rounded-full" style="width: <?php echo min(100,$lvl); ?>%"></div>
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

            // Engagement score: 100 minus % of at-risk students (simple rule)
            $engagementScore = $activeStudents > 0
                ? max(0, (int)(100 - ($totalAtRisk / $activeStudents) * 100))
                : 0;
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
                                <?php echo $cs['enrolled']; ?> enrolled &nbsp;·&nbsp; avg <?php echo $cs['avg_mastery']; ?>%
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
                                $barW    = min(100, $sk['average']);
                            ?>
                                <div>
                                    <div class="flex justify-between mb-1">
                                        <span class="text-[10px] text-slate-300"><?php echo htmlspecialchars($sk['skill']); ?></span>
                                        <span class="text-[10px] font-black <?php echo $skColor; ?>"><?php echo $sk['average']; ?>%</span>
                                    </div>
                                    <div class="skill-bar"><div class="skill-fill" style="width:<?php echo $barW; ?>%"></div></div>
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
                                    <span class="text-[9px] font-black <?php echo $riskColor; ?> uppercase"><?php echo $ar['avg_mastery']; ?>%</span>
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
                                    <span class="text-[9px] font-black text-emerald-400"><?php echo $tp['avg_mastery']; ?>%</span>
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
                    <h2 class="text-xl font-extrabold leading-tight mb-5">Publish New Course Material</h2>
                    <button onclick="toggleModal('uploadModal')" class="w-full py-4 bg-white text-indigo-600 rounded-2xl font-black text-xs uppercase hover:shadow-lg transition-all active:scale-95">
                        <i class="fa-solid fa-cloud-arrow-up mr-2"></i>Upload Resource
                    </button>
                </div>

                <div class="bg-white rounded-[2.5rem] p-8 border border-slate-200 shadow-sm">
                    <h3 class="font-extrabold text-slate-800 mb-6">Quick Tasks</h3>
                    <div class="space-y-3">
                        <button onclick="toggleModal('uploadModal')" class="w-full flex items-center p-4 bg-slate-50 rounded-2xl border border-slate-100 hover:border-indigo-200 hover:bg-white transition-all group">
                            <div class="h-10 w-10 bg-indigo-100 text-indigo-500 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform">
                                <i class="fa-solid fa-cloud-arrow-up"></i>
                            </div>
                            <span class="ml-4 font-bold text-slate-700 text-sm">Upload Handouts</span>
                        </button>
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
    </script>
</body>
</html>