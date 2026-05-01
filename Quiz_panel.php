<?php
/**
 * quiz_panel.php — Lecturer Quiz Management Panel
 *
 * Lecturers can:
 *   - Press "Generate Quiz" → AI creates MCQ questions instantly
 *   - Preview all questions before publishing
 *   - Press "Publish" to make quiz visible to enrolled students
 *   - Unpublish or delete any quiz
 *   - View per-quiz result statistics
 */

include 'config.php';
checkRole('lecturer');

if (session_status() === PHP_SESSION_NONE) session_start();

$lecturer_id = intval($_SESSION['user_id']);

// ── Quick Actions (POST) ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    $quiz_id = intval($_POST['quiz_id'] ?? 0);

    if ($_POST['action'] === 'publish') {
        mysqli_query($conn, "UPDATE quizzes SET is_active = 1 WHERE id = $quiz_id AND created_by = $lecturer_id");
        header("Location: quiz_panel.php?msg=published&quiz_id=$quiz_id");
        exit();
    }
    if ($_POST['action'] === 'unpublish') {
        mysqli_query($conn, "UPDATE quizzes SET is_active = 0 WHERE id = $quiz_id AND created_by = $lecturer_id");
        header("Location: quiz_panel.php?msg=unpublished");
        exit();
    }
    if ($_POST['action'] === 'delete') {
        mysqli_query($conn, "DELETE FROM questions WHERE quiz_id = $quiz_id");
        mysqli_query($conn, "DELETE FROM quizzes WHERE id = $quiz_id AND created_by = $lecturer_id");
        header("Location: quiz_panel.php?msg=deleted");
        exit();
    }
}

// ── Handle publish notification to students ──────────────────────────
if (isset($_GET['msg']) && $_GET['msg'] === 'published' && isset($_GET['quiz_id'])) {
    $quiz_id = intval($_GET['quiz_id']);
    $quiz_info = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT q.title, q.course_id FROM quizzes q WHERE q.id = $quiz_id"
    ));
    if ($quiz_info) {
        // Notify all enrolled students
        $enrolled = mysqli_query($conn,
            "SELECT u.id, u.email, u.full_name
             FROM users u
             JOIN enrollments e ON e.student_id = u.id
             WHERE e.course_id = " . intval($quiz_info['course_id'])
        );
        $notif_msg = mysqli_real_escape_string($conn,
            "A new quiz \"" . $quiz_info['title'] . "\" has been published. Log in to take it now."
        );
        while ($stu = mysqli_fetch_assoc($enrolled)) {
            $sid = intval($stu['id']);
            mysqli_query($conn,
                "INSERT INTO notifications (user_id, message, is_read, created_at)
                 VALUES ($sid, '$notif_msg', 0, NOW())"
            );
            // Send email
            if (!empty($stu['email'])) {
                $body = "Dear {$stu['full_name']},\n\n"
                      . "A new quiz has been published in your course: \"{$quiz_info['title']}\".\n\n"
                      . "Log in to SmartLMS to take the quiz now.\n\n"
                      . "SmartLMS Academic Team";
                @mail($stu['email'], "New Quiz Available: " . $quiz_info['title'], $body,
                    "From: noreply@smartlms.local\r\nContent-Type: text/plain; charset=UTF-8\r\n");
            }
        }
    }
}

// ── Fetch this lecturer's assigned units (with course context) ────────
$units_res = mysqli_query($conn,
    "SELECT cu.id, cu.title AS unit_title, cu.unit_code, c.id AS course_id, c.title AS course_title
     FROM course_units cu
     JOIN courses c ON c.id = cu.course_id
     WHERE cu.lecturer_id = $lecturer_id
     ORDER BY c.title ASC, cu.title ASC"
);
$lecturer_units   = [];
$lecturer_courses = []; // keep for backward compat
while ($u = mysqli_fetch_assoc($units_res)) {
    $lecturer_units[] = $u;
    // also collect unique courses
    $cid = $u['course_id'];
    if (!isset($lecturer_courses[$cid]))
        $lecturer_courses[$cid] = ['id' => $cid, 'title' => $u['course_title']];
}
$lecturer_courses = array_values($lecturer_courses);

// ── Fetch all quizzes created by this lecturer ───────────────────────
$quizzes_res = mysqli_query($conn,
    "SELECT q.*, c.title as course_title, cu.title AS unit_title, cu.unit_code,
            COUNT(DISTINCT qu.id) as question_count,
            COUNT(DISTINCT r.id) as attempt_count,
            ROUND(AVG(r.score), 1) as avg_score
     FROM quizzes q
     LEFT JOIN courses c ON c.id = q.course_id
     LEFT JOIN course_units cu ON cu.id = q.unit_id
     LEFT JOIN questions qu ON qu.quiz_id = q.id
     LEFT JOIN results r ON r.quiz_id = q.id
     WHERE q.created_by = $lecturer_id
     GROUP BY q.id
     ORDER BY q.created_at DESC"
);

$lecturerName = $_SESSION['user_name'] ?? 'Lecturer';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz Manager | SmartLMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; }

        /* AI Generate Panel */
        .gen-panel {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 60%, #0f172a 100%);
            border: 1px solid rgba(99,102,241,0.3);
        }
        .ai-badge {
            background: linear-gradient(90deg, #6366f1, #8b5cf6);
            font-size: 9px; letter-spacing: 0.15em;
        }

        /* Progress bar during generation */
        @keyframes progress-pulse {
            0%   { width: 5%; }
            30%  { width: 45%; }
            70%  { width: 75%; }
            100% { width: 100%; }
        }
        .generating-bar { animation: progress-pulse 8s ease-in-out forwards; }

        /* Question card */
        .q-card { border-left: 3px solid #6366f1; }

        /* Status badge */
        .badge-active   { background: #d1fae5; color: #065f46; }
        .badge-inactive { background: #fef3c7; color: #92400e; }

        /* Slide-in toast */
        @keyframes slide-in { from { transform: translateX(110%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .toast { animation: slide-in 0.4s ease-out forwards; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen">

    <!-- ── Toast notification ── -->
    <?php if (isset($_GET['msg'])): 
        $toastMap = [
            'published'   => ['Quiz published — students have been notified.', 'emerald'],
            'unpublished' => ['Quiz unpublished — hidden from students.',       'amber'],
            'deleted'     => ['Quiz deleted successfully.',                     'red'],
        ];
        $t = $toastMap[$_GET['msg']] ?? ['Action complete.', 'blue'];
    ?>
    <div id="toast" class="toast fixed top-5 right-5 z-[200] bg-white border border-slate-100 px-5 py-4 rounded-2xl shadow-2xl flex items-center space-x-3">
        <div class="w-8 h-8 bg-<?php echo $t[1]; ?>-100 rounded-xl flex items-center justify-center">
            <i class="fa-solid fa-circle-check text-<?php echo $t[1]; ?>-600 text-sm"></i>
        </div>
        <p class="text-sm font-bold text-slate-800"><?php echo htmlspecialchars($t[0]); ?></p>
    </div>
    <script>setTimeout(() => { const t = document.getElementById('toast'); if(t){ t.style.opacity='0'; t.style.transition='opacity 0.4s'; setTimeout(()=>t.remove(),400); } }, 4000);</script>
    <?php endif; ?>

    <!-- ── Top Bar ── -->
    <nav class="bg-slate-900 px-8 py-4 flex items-center justify-between sticky top-0 z-50">
        <div class="flex items-center space-x-4">
            <a href="lecturer_dashboard.php" class="text-slate-400 hover:text-white transition">
                <i class="fa-solid fa-arrow-left mr-2"></i>
            </a>
            <div class="flex items-center space-x-2">
                <div class="w-8 h-8 bg-indigo-500 rounded-lg flex items-center justify-center">
                    <i class="fa-solid fa-bolt text-white text-sm"></i>
                </div>
                <span class="text-white font-extrabold tracking-tight">Smart<span class="text-indigo-400">LMS</span></span>
            </div>
            <span class="text-slate-600 text-xs font-bold uppercase tracking-widest ml-2">/ Quiz Manager</span>
        </div>
        <div class="flex items-center space-x-3">
            <span class="text-slate-400 text-xs font-bold uppercase tracking-widest"><?php echo htmlspecialchars($lecturerName); ?></span>
            <div class="w-8 h-8 rounded-full bg-gradient-to-tr from-indigo-500 to-purple-500 flex items-center justify-center text-white font-bold text-sm">
                <?php echo substr($lecturerName, 0, 1); ?>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-6 py-10">

        <!-- ── Page Header ── -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-10">
            <div>
                <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">Quiz Manager</h1>
                <p class="text-slate-500 text-sm mt-1">Generate AI-powered quizzes and publish them to your students instantly.</p>
            </div>
            <button onclick="openGenerateModal()"
                class="flex items-center space-x-2 bg-indigo-600 hover:bg-indigo-700 text-white font-black text-xs uppercase tracking-widest px-6 py-3 rounded-xl shadow-lg shadow-indigo-200 transition-all active:scale-95">
                <i class="fa-solid fa-wand-magic-sparkles"></i>
                <span>Generate New Quiz</span>
            </button>
        </div>

        <!-- ── AI Generate Modal ── -->
        <div id="generateModal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-950/80 backdrop-blur-sm p-4">
            <div class="w-full max-w-lg gen-panel rounded-3xl p-8 relative">
                <button onclick="closeGenerateModal()" class="absolute top-5 right-5 text-slate-500 hover:text-white transition text-xl">
                    <i class="fa-solid fa-xmark"></i>
                </button>

                <!-- Header -->
                <div class="flex items-center space-x-3 mb-6">
                    <div class="w-10 h-10 bg-indigo-500/20 rounded-xl flex items-center justify-center">
                        <i class="fa-solid fa-brain text-indigo-400 text-lg"></i>
                    </div>
                    <div>
                        <span class="ai-badge text-white font-black uppercase px-2 py-0.5 rounded-md">AI Quiz Generator</span>
                        <p class="text-slate-400 text-[10px] mt-1 uppercase tracking-widest">Powered by Claude</p>
                    </div>
                </div>

                <!-- Form -->
                <div id="gen-form" class="space-y-4">
                    <!-- ① Unit selector -->
                    <div>
                        <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-1 block">
                            ① Unit
                        </label>
                        <select id="gen-unit"
                            onchange="loadUnitMaterials(this.value)"
                            class="w-full p-3.5 bg-slate-800 border border-slate-700 rounded-xl text-white text-sm font-semibold outline-none focus:ring-2 focus:ring-indigo-500 appearance-none">
                            <option value="">— Select a unit —</option>
                            <?php foreach ($lecturer_units as $u): ?>
                            <option value="<?php echo $u['id']; ?>" data-course="<?php echo $u['course_id']; ?>">
                                <?php echo htmlspecialchars($u['course_title']); ?> → <?php echo htmlspecialchars($u['unit_title']); ?>
                                <?php if ($u['unit_code']): ?>(<?php echo htmlspecialchars($u['unit_code']); ?>)<?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- ② Topic / Focus — loaded from unit's uploaded notes -->
                    <div>
                        <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-1 block">
                            ② Topic / Focus Area
                        </label>

                        <!-- Notes dropdown (shown when unit has materials) -->
                        <div id="topic-from-notes" class="hidden">
                            <div class="flex items-center gap-2 mb-2">
                                <i class="fa-solid fa-file-pdf text-red-400 text-xs"></i>
                                <span class="text-[9px] font-black uppercase text-emerald-400 tracking-widest">
                                    Unit notes found — select one as quiz source
                                </span>
                            </div>
                            <select id="gen-material-id"
                                class="w-full p-3.5 bg-slate-800 border border-indigo-500 rounded-xl text-white text-sm font-semibold outline-none focus:ring-2 focus:ring-indigo-500 appearance-none"
                                onchange="onMaterialSelect(this)">
                                <option value="">— Pick a note/document —</option>
                            </select>
                            <p class="text-[9px] text-slate-500 mt-1.5">
                                The AI will read the selected file and generate questions from its content.
                            </p>
                            <!-- Manual override toggle -->
                            <button type="button" onclick="toggleManualTopic()"
                                class="mt-2 text-[9px] font-black uppercase text-slate-500 hover:text-indigo-400 tracking-widest transition-all">
                                <i class="fa-solid fa-pen mr-1"></i>Or type a custom topic instead
                            </button>
                        </div>

                        <!-- Manual topic input (shown when no materials OR toggled) -->
                        <div id="topic-manual">
                            <input type="text" id="gen-topic"
                                placeholder="e.g. Machine Learning Fundamentals"
                                class="w-full p-3.5 bg-slate-800 border border-slate-700 rounded-xl text-white text-sm font-semibold outline-none focus:ring-2 focus:ring-indigo-500 placeholder-slate-600">
                            <div id="back-to-notes-wrap" class="hidden mt-1.5">
                                <button type="button" onclick="toggleManualTopic()"
                                    class="text-[9px] font-black uppercase text-indigo-400 hover:text-indigo-300 tracking-widest transition-all">
                                    <i class="fa-solid fa-file-pdf mr-1"></i>Use uploaded note instead
                                </button>
                            </div>
                            <p id="topic-no-notes-hint" class="text-[9px] text-slate-500 mt-1.5 hidden">
                                <i class="fa-solid fa-circle-info mr-1 text-amber-400"></i>
                                No notes uploaded for this unit yet. Upload PDFs in Resource Manager for AI-powered quizzes.
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-1 block">Difficulty</label>
                            <select id="gen-difficulty" class="w-full p-3.5 bg-slate-800 border border-slate-700 rounded-xl text-white text-sm font-semibold outline-none focus:ring-2 focus:ring-indigo-500 appearance-none">
                                <option value="beginner">Beginner</option>
                                <option value="intermediate" selected>Intermediate</option>
                                <option value="advanced">Advanced</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-1 block">Questions</label>
                            <select id="gen-num" class="w-full p-3.5 bg-slate-800 border border-slate-700 rounded-xl text-white text-sm font-semibold outline-none focus:ring-2 focus:ring-indigo-500 appearance-none">
                                <option value="3">3</option>
                                <option value="5" selected>5</option>
                                <option value="8">8</option>
                                <option value="10">10</option>
                                <option value="15">15</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-1 block">Skill Mapped</label>
                            <select id="gen-skill" class="w-full p-3.5 bg-slate-800 border border-slate-700 rounded-xl text-white text-sm font-semibold outline-none focus:ring-2 focus:ring-indigo-500 appearance-none">
                                <option value="General Aptitude">General Aptitude</option>
                                <option value="Core Theory" selected>Core Theory</option>
                                <option value="Practical Application">Practical Application</option>
                            </select>
                        </div>
                    </div>

                    <button onclick="startGeneration()"
                        class="w-full py-4 bg-indigo-600 hover:bg-indigo-700 text-white font-black text-xs uppercase tracking-widest rounded-xl shadow-lg transition-all active:scale-95">
                        <i class="fa-solid fa-wand-magic-sparkles mr-2"></i>Generate Quiz Now
                    </button>
                </div>

                <!-- Generating state -->
                <div id="gen-loading" class="hidden py-6">
                    <div class="mb-4">
                        <div class="flex justify-between mb-2">
                            <span class="text-[10px] font-black uppercase text-slate-400 tracking-widest">Generating questions…</span>
                            <span id="gen-status-text" class="text-[10px] text-indigo-400 font-bold">Contacting AI</span>
                        </div>
                        <div class="h-2 bg-slate-700 rounded-full overflow-hidden">
                            <div class="generating-bar h-full bg-gradient-to-r from-indigo-500 to-violet-500 rounded-full"></div>
                        </div>
                    </div>
                    <div class="space-y-2 mt-6">
                        <div class="h-3 bg-slate-800 rounded-full w-full animate-pulse"></div>
                        <div class="h-3 bg-slate-800 rounded-full w-3/4 animate-pulse"></div>
                        <div class="h-3 bg-slate-800 rounded-full w-5/6 animate-pulse"></div>
                    </div>
                </div>

                <!-- Error state -->
                <div id="gen-error" class="hidden text-center py-6">
                    <i class="fa-solid fa-triangle-exclamation text-red-400 text-3xl mb-3"></i>
                    <p id="gen-error-msg" class="text-red-300 text-sm mb-4"></p>
                    <button onclick="resetGenerateModal()" class="text-[10px] font-black uppercase text-indigo-400 hover:text-indigo-300 tracking-widest">
                        <i class="fa-solid fa-rotate-right mr-1"></i>Try Again
                    </button>
                </div>
            </div>
        </div>

        <!-- ── Preview Modal (shown after generation) ── -->
        <div id="previewModal" class="fixed inset-0 z-[110] hidden items-center justify-center bg-slate-950/90 backdrop-blur-sm p-4">
            <div class="w-full max-w-2xl bg-white rounded-3xl shadow-2xl flex flex-col max-h-[90vh]">
                <!-- Header -->
                <div class="p-6 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h3 class="font-extrabold text-slate-900 text-lg" id="preview-title">Quiz Preview</h3>
                        <p class="text-slate-500 text-xs mt-0.5" id="preview-subtitle"></p>
                    </div>
                    <button onclick="closePreviewModal()" class="text-slate-400 hover:text-slate-800 transition text-xl">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <!-- Questions scroll area -->
                <div id="preview-questions" class="flex-1 overflow-y-auto p-6 space-y-5"></div>
                <!-- Footer actions -->
                <div class="p-6 border-t border-slate-100 flex space-x-3">
                    <form method="POST" class="flex-1" id="publish-form">
                        <input type="hidden" name="action"  value="publish">
                        <input type="hidden" name="quiz_id" id="publish-quiz-id" value="">
                        <button type="submit"
                            class="w-full py-3.5 bg-indigo-600 hover:bg-indigo-700 text-white font-black text-xs uppercase tracking-widest rounded-xl shadow-lg transition-all active:scale-95">
                            <i class="fa-solid fa-paper-plane mr-2"></i>Publish to Students
                        </button>
                    </form>
                    <button onclick="closePreviewModal()"
                        class="px-6 py-3.5 bg-slate-100 text-slate-600 font-black text-xs uppercase tracking-widest rounded-xl hover:bg-slate-200 transition-all">
                        Save Draft
                    </button>
                </div>
            </div>
        </div>

        <!-- ── Quiz List ── -->
        <div class="space-y-4">
            <?php if (mysqli_num_rows($quizzes_res) === 0): ?>
            <div class="bg-white rounded-3xl border border-slate-200 p-16 text-center">
                <div class="w-16 h-16 bg-indigo-50 rounded-2xl flex items-center justify-center mx-auto mb-4">
                    <i class="fa-solid fa-brain text-indigo-400 text-2xl"></i>
                </div>
                <h3 class="font-extrabold text-slate-800 mb-2">No Quizzes Yet</h3>
                <p class="text-slate-400 text-sm mb-6">Generate your first AI-powered quiz by clicking the button above.</p>
                <button onclick="openGenerateModal()"
                    class="inline-flex items-center space-x-2 bg-indigo-600 text-white font-black text-xs uppercase tracking-widest px-6 py-3 rounded-xl hover:bg-indigo-700 transition-all">
                    <i class="fa-solid fa-wand-magic-sparkles"></i><span>Generate Quiz</span>
                </button>
            </div>
            <?php else: ?>

            <?php while ($quiz = mysqli_fetch_assoc($quizzes_res)):
                $isActive = intval($quiz['is_active']) === 1;
                $diffColors = [
                    'beginner'     => 'text-emerald-700 bg-emerald-50 border-emerald-200',
                    'intermediate' => 'text-amber-700 bg-amber-50 border-amber-200',
                    'advanced'     => 'text-rose-700 bg-rose-50 border-rose-200',
                ];
                $diffColor = $diffColors[$quiz['difficulty']] ?? 'text-slate-700 bg-slate-50 border-slate-200';
            ?>
            <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm hover:shadow-md transition-all">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">

                    <!-- Left: Quiz Info -->
                    <div class="flex-1">
                        <div class="flex items-center space-x-3 mb-2 flex-wrap gap-2">
                            <span class="<?php echo $isActive ? 'badge-active' : 'badge-inactive'; ?> text-[9px] font-black uppercase px-2 py-1 rounded-lg tracking-widest">
                                <?php echo $isActive ? 'Published' : 'Draft'; ?>
                            </span>
                            <span class="<?php echo $diffColor; ?> border text-[9px] font-black uppercase px-2 py-1 rounded-lg tracking-widest">
                                <?php echo ucfirst($quiz['difficulty']); ?>
                            </span>
                            <?php if ($quiz['skill_name']): ?>
                            <span class="bg-indigo-50 text-indigo-700 border border-indigo-200 text-[9px] font-black uppercase px-2 py-1 rounded-lg tracking-widest">
                                <?php echo htmlspecialchars($quiz['skill_name']); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <h3 class="font-extrabold text-slate-900 text-base"><?php echo htmlspecialchars($quiz['title']); ?></h3>
                        <p class="text-slate-400 text-xs mt-1">
                            <i class="fa-solid fa-book mr-1"></i><?php echo htmlspecialchars($quiz['unit_title'] ?? $quiz['course_title'] ?? '—'); ?>
                            &nbsp;·&nbsp;
                            <i class="fa-solid fa-circle-question mr-1"></i><?php echo intval($quiz['question_count']); ?> questions
                            &nbsp;·&nbsp;
                            <?php echo $quiz['attempt_count'] > 0
                                ? '<i class="fa-solid fa-users mr-1"></i>' . intval($quiz['attempt_count']) . ' attempts &nbsp;·&nbsp; avg ' . ($quiz['avg_score'] ?? '—') . '%'
                                : 'No attempts yet'; ?>
                            &nbsp;·&nbsp;
                            <i class="fa-solid fa-clock mr-1"></i><?php echo date('d M Y', strtotime($quiz['created_at'])); ?>
                        </p>
                    </div>

                    <!-- Right: Actions -->
                    <div class="flex items-center space-x-2 flex-shrink-0">
                        <!-- Preview -->
                        <button onclick="previewExistingQuiz(<?php echo $quiz['id']; ?>, '<?php echo addslashes(htmlspecialchars($quiz['title'])); ?>', <?php echo $quiz['question_count']; ?>)"
                            class="flex items-center space-x-1.5 px-4 py-2 bg-slate-50 border border-slate-200 text-slate-600 rounded-xl text-xs font-bold hover:bg-slate-100 transition-all">
                            <i class="fa-solid fa-eye"></i><span>Preview</span>
                        </button>

                        <?php if ($isActive): ?>
                        <!-- Unpublish -->
                        <form method="POST" class="inline">
                            <input type="hidden" name="action"  value="unpublish">
                            <input type="hidden" name="quiz_id" value="<?php echo $quiz['id']; ?>">
                            <button type="submit" class="flex items-center space-x-1.5 px-4 py-2 bg-amber-50 border border-amber-200 text-amber-700 rounded-xl text-xs font-bold hover:bg-amber-100 transition-all">
                                <i class="fa-solid fa-eye-slash"></i><span>Unpublish</span>
                            </button>
                        </form>
                        <?php else: ?>
                        <!-- Publish -->
                        <form method="POST" class="inline">
                            <input type="hidden" name="action"  value="publish">
                            <input type="hidden" name="quiz_id" value="<?php echo $quiz['id']; ?>">
                            <button type="submit" class="flex items-center space-x-1.5 px-4 py-2 bg-indigo-600 text-white rounded-xl text-xs font-bold hover:bg-indigo-700 transition-all shadow-md">
                                <i class="fa-solid fa-paper-plane"></i><span>Publish</span>
                            </button>
                        </form>
                        <?php endif; ?>

                        <!-- Delete -->
                        <form method="POST" onsubmit="return confirm('Permanently delete this quiz and all its questions?')">
                            <input type="hidden" name="action"  value="delete">
                            <input type="hidden" name="quiz_id" value="<?php echo $quiz['id']; ?>">
                            <button type="submit" class="flex items-center space-x-1.5 px-4 py-2 bg-red-50 border border-red-200 text-red-600 rounded-xl text-xs font-bold hover:bg-red-100 transition-all">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Result stats bar (if attempts exist) -->
                <?php if (intval($quiz['attempt_count']) > 0): 
                    $avg = floatval($quiz['avg_score']);
                    $barColor = $avg >= 70 ? 'bg-emerald-500' : ($avg >= 50 ? 'bg-amber-500' : 'bg-red-500');
                ?>
                <div class="mt-4 pt-4 border-t border-slate-50">
                    <div class="flex justify-between mb-1.5">
                        <span class="text-[9px] font-black uppercase text-slate-400 tracking-widest">Class Average Score</span>
                        <span class="text-[9px] font-black <?php echo $avg>=70?'text-emerald-600':($avg>=50?'text-amber-600':'text-red-600'); ?>"><?php echo $avg; ?>%</span>
                    </div>
                    <div class="h-1.5 bg-slate-100 rounded-full overflow-hidden">
                        <div class="h-full <?php echo $barColor; ?> rounded-full" style="width: <?php echo min(100,$avg); ?>%"></div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
            <?php endwhile; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // ── Modal open/close ──────────────────────────────────────────────
    function openGenerateModal() {
        document.getElementById('generateModal').style.display = 'flex';
        resetGenerateModal();
    }
    function closeGenerateModal() {
        document.getElementById('generateModal').style.display = 'none';
    }
    function closePreviewModal() {
        document.getElementById('previewModal').style.display = 'none';
    }
    function resetGenerateModal() {
        document.getElementById('gen-form').classList.remove('hidden');
        document.getElementById('gen-loading').classList.add('hidden');
        document.getElementById('gen-error').classList.add('hidden');
    }

    window.onclick = (e) => {
        if (e.target === document.getElementById('generateModal')) closeGenerateModal();
        if (e.target === document.getElementById('previewModal'))  closePreviewModal();
    };

    // ── AI Generation ─────────────────────────────────────────────────
    // ── Load materials for selected unit ──────────────────────────────
    let _usingMaterial = false;  // true = using dropdown, false = manual text

    async function loadUnitMaterials(unit_id) {
        const notesWrap   = document.getElementById('topic-from-notes');
        const manualWrap  = document.getElementById('topic-manual');
        const noNotesHint = document.getElementById('topic-no-notes-hint');
        const matSelect   = document.getElementById('gen-material-id');
        const backWrap    = document.getElementById('back-to-notes-wrap');

        // Reset
        matSelect.innerHTML = '<option value="">— Pick a note/document —</option>';
        notesWrap.classList.add('hidden');
        noNotesHint.classList.add('hidden');
        backWrap.classList.add('hidden');
        _usingMaterial = false;

        if (!unit_id) return;

        // Fetch materials for this unit (all types uploadable by lecturer)
        try {
            const res  = await fetch(`get_unit_materials_list.php?unit_id=${unit_id}`);
            const mats = await res.json();

            if (mats.length > 0) {
                // Populate dropdown
                mats.forEach(m => {
                    const opt = document.createElement('option');
                    opt.value = m.id;
                    const typeIcon = m.type === 'pdf' ? '📄' : m.type === 'video' ? '🎬' : '📝';
                    opt.textContent = `${typeIcon} ${m.title} (${m.type.toUpperCase()})`;
                    opt.dataset.title = m.title;
                    opt.dataset.type  = m.type;
                    matSelect.appendChild(opt);
                });
                // Show notes dropdown, hide manual input
                notesWrap.classList.remove('hidden');
                manualWrap.classList.add('hidden');
                _usingMaterial = true;
            } else {
                // No materials — show manual input with hint
                notesWrap.classList.add('hidden');
                manualWrap.classList.remove('hidden');
                noNotesHint.classList.remove('hidden');
                _usingMaterial = false;
            }
        } catch(e) {
            console.error('Failed to load materials:', e);
        }
    }

    function onMaterialSelect(sel) {
        // When a material is chosen, auto-fill the hidden topic field
        const opt = sel.options[sel.selectedIndex];
        if (opt && opt.dataset.title) {
            document.getElementById('gen-topic').value = opt.dataset.title;
        }
    }

    function toggleManualTopic() {
        const notesWrap  = document.getElementById('topic-from-notes');
        const manualWrap = document.getElementById('topic-manual');
        const backWrap   = document.getElementById('back-to-notes-wrap');
        const hasNotes   = document.getElementById('gen-material-id').options.length > 1;

        if (_usingMaterial) {
            // Switch to manual
            notesWrap.classList.add('hidden');
            manualWrap.classList.remove('hidden');
            document.getElementById('gen-topic').value = '';
            if (hasNotes) backWrap.classList.remove('hidden');
            _usingMaterial = false;
        } else {
            // Switch back to notes
            notesWrap.classList.remove('hidden');
            manualWrap.classList.add('hidden');
            _usingMaterial = true;
        }
    }

    async function startGeneration() {
        const unit_id       = document.getElementById('gen-unit').value;
        const difficulty    = document.getElementById('gen-difficulty').value;
        const num_questions = document.getElementById('gen-num').value;
        const skill_name    = document.getElementById('gen-skill').value;

        if (!unit_id) { alert('Please select a unit.'); return; }

        // Resolve topic and material_id
        let topic       = '';
        let material_id = '';

        if (_usingMaterial) {
            const matSel = document.getElementById('gen-material-id');
            material_id  = matSel.value;
            const opt    = matSel.options[matSel.selectedIndex];
            topic        = opt?.dataset?.title || '';
            if (!material_id) { alert('Please select a note or document from the dropdown.'); return; }
        } else {
            topic = document.getElementById('gen-topic').value.trim();
            if (!topic) { alert('Please enter a topic or focus area.'); return; }
        }

        // Switch to loading state
        document.getElementById('gen-form').classList.add('hidden');
        document.getElementById('gen-loading').classList.remove('hidden');
        document.getElementById('gen-error').classList.add('hidden');

        const statusTexts = [
            'Reading lecture notes…',
            'Analysing content…',
            'Drafting questions…',
            'Validating answers…',
            'Saving to database…'
        ];
        let si = 0;
        const statusEl = document.getElementById('gen-status-text');
        const statusInterval = setInterval(() => {
            si = (si + 1) % statusTexts.length;
            statusEl.textContent = statusTexts[si];
        }, 1600);

        try {
            const body = new FormData();
            body.append('unit_id',       unit_id);
            body.append('topic',         topic);
            body.append('material_id',   material_id);
            body.append('difficulty',    difficulty);
            body.append('num_questions', num_questions);
            body.append('skill_name',    skill_name);

            const res  = await fetch('Ai_quiz_generator.php', { method: 'POST', body });
            const json = await res.json();
            clearInterval(statusInterval);

            if (!json.success) throw new Error(json.error || 'Unknown error');

            let sourceMsg = '';
            if (json.source === 'lecture_notes') {
                sourceMsg = `<span class="text-emerald-400"><i class="fa-solid fa-file-pdf mr-1"></i>Generated from: <strong>${escHtml(json.source_title || 'uploaded note')}</strong></span>`;
            } else if (json.source === 'general_knowledge') {
                sourceMsg = `<span class="text-blue-400"><i class="fa-solid fa-brain mr-1"></i>Generated from general AI knowledge</span>`;
            } else {
                sourceMsg = `<span class="text-amber-400"><i class="fa-solid fa-gear mr-1"></i>Generated by local engine</span>`;
            }

            closeGenerateModal();
            openPreviewModal(json.quiz_id, json.quiz_title, json.questions, json.saved, sourceMsg);

        } catch (err) {
            clearInterval(statusInterval);
            document.getElementById('gen-loading').classList.add('hidden');
            document.getElementById('gen-error').classList.remove('hidden');
            document.getElementById('gen-error-msg').textContent = err.message;
        }
    }

    // ── Preview Modal (after generation) ─────────────────────────────
    function openPreviewModal(quiz_id, title, questions, count, sourceMsg = '') {
        document.getElementById('preview-title').textContent    = title;
        document.getElementById('preview-subtitle').innerHTML   =
            count + ' questions generated' +
            (sourceMsg ? ' &nbsp;·&nbsp; ' + sourceMsg : '');
        document.getElementById('publish-quiz-id').value        = quiz_id;
        renderQuestions(questions);
        document.getElementById('previewModal').style.display = 'flex';
    }

    // ── Preview Modal (existing quiz from DB) ─────────────────────────
    async function previewExistingQuiz(quiz_id, title, count) {
        document.getElementById('preview-title').textContent    = title;
        document.getElementById('preview-subtitle').textContent = count + ' questions';
        document.getElementById('publish-quiz-id').value        = quiz_id;
        document.getElementById('preview-questions').innerHTML  =
            '<p class="text-slate-400 text-xs italic animate-pulse">Loading questions…</p>';
        document.getElementById('previewModal').style.display = 'flex';

        try {
            const res  = await fetch('get_quiz_questions.php?quiz_id=' + quiz_id);
            const json = await res.json();
            renderQuestions(json.questions || []);
        } catch(e) {
            document.getElementById('preview-questions').innerHTML =
                '<p class="text-red-500 text-xs">Failed to load questions.</p>';
        }
    }

    function renderQuestions(questions) {
        const container = document.getElementById('preview-questions');
        if (!questions || questions.length === 0) {
            container.innerHTML = '<p class="text-slate-400 text-sm italic text-center">No questions found.</p>';
            return;
        }
        const optColors = { A: 'blue', B: 'violet', C: 'amber', D: 'emerald' };
        container.innerHTML = questions.map((q, i) => `
            <div class="q-card bg-slate-50 rounded-2xl p-5">
                <p class="text-xs font-black uppercase text-indigo-500 tracking-widest mb-1">Question ${i + 1}</p>
                <p class="font-bold text-slate-800 text-sm mb-4">${escHtml(q.question_text)}</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mb-3">
                    ${['A','B','C','D'].map(opt => {
                        const optKey = 'option_' + opt.toLowerCase();
                        const text   = q[optKey] || '';
                        const isCorrect = q.correct_option === opt;
                        const cls = isCorrect
                            ? 'bg-emerald-50 border-emerald-300 text-emerald-700'
                            : 'bg-white border-slate-200 text-slate-600';
                        return `<div class="flex items-start space-x-2 border ${cls} rounded-xl px-3 py-2">
                            <span class="font-black text-[10px] uppercase mt-0.5 flex-shrink-0">${opt}.</span>
                            <span class="text-xs leading-snug">${escHtml(text)}</span>
                            ${isCorrect ? '<i class="fa-solid fa-check text-emerald-500 text-[10px] ml-auto mt-0.5 flex-shrink-0"></i>' : ''}
                        </div>`;
                    }).join('')}
                </div>
                ${q.explanation ? `<div class="bg-indigo-50 border border-indigo-100 rounded-xl px-3 py-2">
                    <p class="text-[9px] font-black uppercase text-indigo-400 tracking-widest mb-0.5">Explanation</p>
                    <p class="text-xs text-indigo-700 leading-snug">${escHtml(q.explanation)}</p>
                </div>` : ''}
            </div>
        `).join('');
    }

    function escHtml(str) {
        return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    </script>
</body>
</html>