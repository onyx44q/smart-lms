<?php
/**
 * assignment_submit.php — SmartLMS Student Assignment Submission
 *
 * Displays all assignments for courses the student is enrolled in.
 * Students can:
 *   - Read assignment title, description, due date, word limit
 *   - Write and submit their response text (+ optional file)
 *   - View their existing submission
 *   - See their own plagiarism report verdict and score
 */

include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php"); exit();
}

$student_id   = intval($_SESSION['user_id']);
$student_name = $_SESSION['user_name'] ?? 'Student';

// ── Auto-create tables if missing ──────────────────────────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `assignments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT, `course_id` INT(11) NOT NULL,
  `lecturer_id` INT(11) NOT NULL, `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL, `due_date` DATE DEFAULT NULL,
  `max_words` INT(11) NOT NULL DEFAULT 1000, `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
  `overall_score` DECIMAL(5,2) NOT NULL DEFAULT 0.00, `verdict` VARCHAR(20) NOT NULL DEFAULT 'LOW RISK',
  `matched_students` LONGTEXT DEFAULT NULL, `flags` LONGTEXT DEFAULT NULL,
  `analysed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `unique_report` (`submission_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Enrolled courses ────────────────────────────────────────────────────────
$enr_res = mysqli_query($conn, "SELECT course_id FROM enrollments WHERE student_id = $student_id");
$enrolled_ids = [];
while ($r = mysqli_fetch_assoc($enr_res)) $enrolled_ids[] = intval($r['course_id']);

// ── Assignments + student submission status ─────────────────────────────────
$assignments_list = [];
if (!empty($enrolled_ids)) {
    $ids_str = implode(',', $enrolled_ids);
    $aRes = mysqli_query($conn,
        "SELECT a.id, a.title, a.description, a.due_date, a.max_words,
                c.title AS course_title,
                s.id AS submission_id, s.submission_text, s.word_count, s.submitted_at,
                s.file_path AS submission_file,
                pr.overall_score, pr.student_similarity_score, pr.internet_similarity_score,
                pr.verdict, pr.flags, pr.matched_students
         FROM assignments a
         JOIN courses c ON c.id = a.course_id
         LEFT JOIN assignment_submissions s
               ON s.assignment_id = a.id AND s.student_id = $student_id
         LEFT JOIN plagiarism_reports pr ON pr.submission_id = s.id
         WHERE a.course_id IN ($ids_str)
         ORDER BY a.due_date ASC, a.created_at DESC"
    );
    while ($row = mysqli_fetch_assoc($aRes)) $assignments_list[] = $row;
}

// ── Currently selected assignment (for the submission modal) ────────────────
$modal_assignment = null;
if (isset($_GET['submit_id'])) {
    $sid = intval($_GET['submit_id']);
    foreach ($assignments_list as $a) {
        if ((int)$a['id'] === $sid) { $modal_assignment = $a; break; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignments | SmartLMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .card { background:#fff; border:1px solid #e2e8f0; transition:all .2s; }
        .card:hover { transform:translateY(-2px); box-shadow:0 10px 15px -3px rgba(0,0,0,.08); }
        .verdict-low    { background:#dcfce7; color:#15803d; border-color:#bbf7d0; }
        .verdict-medium { background:#fef9c3; color:#92400e; border-color:#fde68a; }
        .verdict-high   { background:#fee2e2; color:#b91c1c; border-color:#fca5a5; }
        .modal-bg { position:fixed;inset:0;z-index:60;background:rgba(15,23,42,.7);
                    display:flex;align-items:center;justify-content:center;padding:1rem; }
        #wordCount { transition:color .2s; }
        .over-limit { color:#dc2626!important; font-weight:900; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 font-sans min-h-screen">

    <!-- Toast notification -->
    <?php if (isset($_GET['status'])): ?>
    <?php
    $isError = $_GET['status'] === 'error';
    $verdict = htmlspecialchars($_GET['verdict'] ?? '');
    $score   = htmlspecialchars($_GET['score'] ?? '');
    $msg     = $isError
        ? htmlspecialchars($_GET['msg'] ?? 'An error occurred.')
        : ($verdict ? "Submitted! Plagiarism verdict: $verdict ($score%)" : htmlspecialchars($_GET['msg'] ?? 'Submitted.'));
    $toastColor = $isError ? 'bg-red-500' : 'bg-emerald-500';
    ?>
    <div id="toast" class="fixed top-6 right-6 z-[100] <?php echo $toastColor; ?> text-white px-6 py-4 rounded-2xl shadow-2xl flex items-center space-x-3 font-bold text-sm">
        <i class="fa-solid <?php echo $isError ? 'fa-triangle-exclamation' : 'fa-circle-check'; ?>"></i>
        <span><?php echo $msg; ?></span>
    </div>
    <script>setTimeout(()=>{ const t=document.getElementById('toast'); if(t){ t.style.opacity='0'; t.style.transition='opacity .5s'; setTimeout(()=>t.remove(),500); } },4000);</script>
    <?php endif; ?>

    <!-- Header -->
    <header class="bg-white border-b border-slate-200 px-8 py-5 flex items-center justify-between sticky top-0 z-40">
        <div class="flex items-center space-x-4">
            <a href="student_dashboard.php" class="text-slate-400 hover:text-blue-600 transition">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-lg font-black text-slate-900 uppercase tracking-tight italic">
                    <i class="fa-solid fa-brain text-blue-600 mr-2"></i>SmartLMS
                </h1>
                <p class="text-[10px] font-black uppercase text-slate-400 tracking-widest">My Assignments</p>
            </div>
        </div>
        <div class="text-right">
            <p class="text-xs font-black text-slate-900 uppercase"><?php echo htmlspecialchars($student_name); ?></p>
            <p class="text-[10px] text-blue-600 font-bold uppercase tracking-widest">Student</p>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-6 py-10">

        <?php if (empty($assignments_list)): ?>
        <div class="text-center py-24">
            <div class="w-16 h-16 bg-slate-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
                <i class="fa-solid fa-file-pen text-slate-300 text-2xl"></i>
            </div>
            <h3 class="font-black text-slate-700 text-lg uppercase">No assignments yet</h3>
            <p class="text-slate-400 text-sm mt-2">Your lecturers have not posted any assignments for your enrolled courses.</p>
            <a href="student_dashboard.php" class="inline-block mt-6 px-6 py-3 bg-blue-600 text-white font-black text-xs uppercase rounded-xl tracking-widest">Back to Dashboard</a>
        </div>
        <?php else: ?>

        <div class="mb-8">
            <h2 class="text-xl font-black text-slate-900 uppercase italic">Your Assignments</h2>
            <p class="text-slate-400 text-xs font-bold uppercase tracking-widest mt-1">
                <?php echo count($assignments_list); ?> assignment<?php echo count($assignments_list) !== 1 ? 's' : ''; ?> across your enrolled courses
            </p>
        </div>

        <div class="space-y-5">
        <?php foreach ($assignments_list as $a):
            $submitted  = !empty($a['submission_id']);
            $isOverdue  = $a['due_date'] && strtotime($a['due_date']) < time();
            $verdict    = $a['verdict'] ?? '';
            $vClass     = match($verdict) {
                'HIGH RISK'   => 'verdict-high',
                'MEDIUM RISK' => 'verdict-medium',
                default       => 'verdict-low',
            };
            $vIcon = match($verdict) {
                'HIGH RISK'   => 'fa-triangle-exclamation',
                'MEDIUM RISK' => 'fa-circle-exclamation',
                default       => 'fa-circle-check',
            };
            $flags   = json_decode($a['flags'] ?? '[]', true) ?: [];
            $matches = json_decode($a['matched_students'] ?? '[]', true) ?: [];
        ?>
        <div class="card rounded-3xl p-6">
            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                <div class="flex-1">
                    <!-- Assignment title + course badge -->
                    <div class="flex items-center flex-wrap gap-2 mb-2">
                        <span class="bg-blue-50 text-blue-700 border border-blue-100 text-[9px] font-black uppercase px-2 py-0.5 rounded-lg tracking-widest">
                            <?php echo htmlspecialchars($a['course_title']); ?>
                        </span>
                        <?php if ($isOverdue && !$submitted): ?>
                        <span class="bg-red-50 text-red-600 border border-red-100 text-[9px] font-black uppercase px-2 py-0.5 rounded-lg">Overdue</span>
                        <?php endif; ?>
                        <?php if ($submitted): ?>
                        <span class="bg-emerald-50 text-emerald-700 border border-emerald-100 text-[9px] font-black uppercase px-2 py-0.5 rounded-lg">
                            <i class="fa-solid fa-check mr-1"></i>Submitted
                        </span>
                        <?php endif; ?>
                    </div>
                    <h3 class="font-black text-slate-900 text-sm mb-1"><?php echo htmlspecialchars($a['title']); ?></h3>
                    <?php if ($a['description']): ?>
                    <p class="text-slate-500 text-xs leading-relaxed mb-3"><?php echo nl2br(htmlspecialchars($a['description'])); ?></p>
                    <?php endif; ?>
                    <div class="flex flex-wrap gap-4 text-[10px] font-bold text-slate-400 uppercase">
                        <?php if ($a['due_date']): ?>
                        <span><i class="fa-solid fa-calendar-days mr-1 <?php echo $isOverdue ? 'text-red-500' : 'text-blue-500'; ?>"></i>
                            Due: <?php echo date('d M Y', strtotime($a['due_date'])); ?>
                        </span>
                        <?php endif; ?>
                        <span><i class="fa-solid fa-align-left mr-1 text-blue-500"></i>Limit: <?php echo number_format($a['max_words']); ?> words</span>
                        <?php if ($submitted): ?>
                        <span><i class="fa-solid fa-clock mr-1 text-slate-400"></i>Submitted: <?php echo date('d M Y, h:i A', strtotime($a['submitted_at'])); ?></span>
                        <span><i class="fa-solid fa-font mr-1 text-slate-400"></i><?php echo number_format($a['word_count']); ?> words</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($submitted): ?>
                    <div class="mt-4 pt-4 border-t border-slate-100">
                        <p class="text-[9px] font-black uppercase text-slate-400 tracking-widest mb-2">
                            <i class="fa-solid fa-paperclip mr-1"></i>Attached File
                        </p>
                        <?php if (!empty($a['submission_file'])): 
                            $fExt  = strtolower(pathinfo($a['submission_file'], PATHINFO_EXTENSION));
                            $fName = basename($a['submission_file']);
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
                                <p class="text-[9px] text-slate-400 uppercase font-bold tracking-widest"><?php echo strtoupper($fExt); ?> &nbsp;·&nbsp; Your submitted file</p>
                            </div>
                            <a href="download_submission.php?sub_id=<?php echo $a['submission_id']; ?>"
                               class="flex-shrink-0 flex items-center space-x-2 px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-black text-[10px] uppercase rounded-xl tracking-widest transition-all active:scale-95">
                                <i class="fa-solid fa-download"></i>
                                <span>Download</span>
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="flex items-center space-x-3 px-4 py-3 border border-dashed border-slate-200 rounded-xl bg-slate-50">
                            <div class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-file-slash text-slate-300 text-lg"></i>
                            </div>
                            <p class="text-xs font-bold text-slate-400 italic">No file attached — you submitted text only</p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Action buttons -->
                <div class="flex flex-col gap-2 min-w-[140px]">
                    <?php if (!$submitted || !$isOverdue): ?>
                    <a href="?submit_id=<?php echo $a['id']; ?>"
                       class="block text-center py-3 px-4 <?php echo $submitted ? 'bg-slate-700 hover:bg-slate-800' : 'bg-blue-600 hover:bg-blue-700'; ?> text-white font-black text-[10px] uppercase rounded-xl tracking-widest transition-all active:scale-95">
                        <i class="fa-solid <?php echo $submitted ? 'fa-rotate' : 'fa-file-pen'; ?> mr-1"></i>
                        <?php echo $submitted ? 'Resubmit' : 'Submit'; ?>
                    </a>
                    <?php endif; ?>
                    <?php if ($submitted): ?>
                    <button onclick="toggleSection('preview-<?php echo $a['id']; ?>')"
                        class="text-center py-3 px-4 border border-slate-200 text-slate-600 hover:bg-slate-50 font-black text-[10px] uppercase rounded-xl tracking-widest transition-all">
                        <i class="fa-solid fa-eye mr-1"></i>Preview
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Plagiarism report section (only when submitted) -->
            <?php if ($submitted && $verdict): ?>
            <div class="mt-5 border-t border-slate-100 pt-5">
                <p class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-3">
                    <i class="fa-solid fa-magnifying-glass mr-1"></i>Plagiarism Analysis Report
                </p>
                <div class="grid grid-cols-3 gap-3 mb-4">
                    <div class="bg-slate-50 rounded-2xl p-3 text-center">
                        <p class="text-[9px] font-black uppercase text-slate-400 mb-1">Peer Match</p>
                        <p class="text-lg font-black <?php echo floatval($a['student_similarity_score']) >= 55 ? 'text-red-600' : 'text-slate-700'; ?>">
                            <?php echo number_format($a['student_similarity_score'], 1); ?>%
                        </p>
                    </div>
                    <div class="bg-slate-50 rounded-2xl p-3 text-center">
                        <p class="text-[9px] font-black uppercase text-slate-400 mb-1">Internet Est.</p>
                        <p class="text-lg font-black <?php echo floatval($a['internet_similarity_score']) >= 50 ? 'text-amber-600' : 'text-slate-700'; ?>">
                            <?php echo number_format($a['internet_similarity_score'], 1); ?>%
                        </p>
                    </div>
                    <div class="bg-slate-50 rounded-2xl p-3 text-center">
                        <p class="text-[9px] font-black uppercase text-slate-400 mb-1">Overall</p>
                        <p class="text-lg font-black text-slate-700"><?php echo number_format($a['overall_score'], 1); ?>%</p>
                    </div>
                </div>
                <div class="inline-flex items-center space-x-2 border rounded-xl px-3 py-2 text-xs font-black uppercase <?php echo $vClass; ?>">
                    <i class="fa-solid <?php echo $vIcon; ?>"></i>
                    <span>Verdict: <?php echo $verdict; ?></span>
                </div>
                <?php if (!empty($flags)): ?>
                <button onclick="toggleSection('flags-<?php echo $a['id']; ?>')"
                    class="ml-3 text-[9px] font-black uppercase text-slate-400 hover:text-slate-700 transition">
                    <i class="fa-solid fa-flag mr-1"></i><?php echo count($flags); ?> flag<?php echo count($flags) !== 1 ? 's' : ''; ?> detected
                </button>
                <div id="flags-<?php echo $a['id']; ?>" class="hidden mt-3 bg-amber-50 border border-amber-100 rounded-2xl p-4">
                    <p class="text-[9px] font-black uppercase text-amber-600 mb-2">Heuristic Flags</p>
                    <ul class="space-y-1">
                        <?php foreach ($flags as $flag): ?>
                        <li class="text-xs text-amber-800 flex items-start space-x-2">
                            <i class="fa-solid fa-circle text-amber-400 mt-1.5 text-[6px] flex-shrink-0"></i>
                            <span><?php echo htmlspecialchars($flag); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Submission text preview -->
            <?php if ($submitted): ?>
            <div id="preview-<?php echo $a['id']; ?>" class="hidden mt-4 bg-slate-50 border border-slate-200 rounded-2xl p-5">
                <p class="text-[9px] font-black uppercase text-slate-400 mb-3">Your Submitted Text</p>
                <div class="text-sm text-slate-700 leading-relaxed max-h-48 overflow-y-auto">
                    <?php echo nl2br(htmlspecialchars($a['submission_text'])); ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>

    <!-- Submission Modal -->
    <?php if ($modal_assignment): ?>
    <?php $ma = $modal_assignment; $wl = intval($ma['max_words']); ?>
    <div class="modal-bg" id="submitModal">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col">
            <div class="px-8 py-6 border-b border-slate-100 flex items-start justify-between">
                <div>
                    <span class="text-[9px] font-black uppercase text-blue-600 tracking-widest"><?php echo htmlspecialchars($ma['course_title']); ?></span>
                    <h2 class="text-lg font-black text-slate-900 mt-1"><?php echo htmlspecialchars($ma['title']); ?></h2>
                    <p class="text-[10px] text-slate-400 font-bold uppercase mt-0.5">Max <?php echo number_format($wl); ?> words</p>
                </div>
                <a href="assignment_submit.php" class="text-slate-400 hover:text-slate-700 mt-1"><i class="fa-solid fa-xmark text-lg"></i></a>
            </div>
            <div class="flex-1 overflow-y-auto px-8 py-6">
                <?php if ($ma['description']): ?>
                <div class="bg-blue-50 border border-blue-100 rounded-2xl p-4 mb-5 text-sm text-slate-700 leading-relaxed">
                    <p class="text-[9px] font-black uppercase text-blue-600 mb-1">Assignment Brief</p>
                    <?php echo nl2br(htmlspecialchars($ma['description'])); ?>
                </div>
                <?php endif; ?>
                <form action="process_assignment_submit.php" method="POST" enctype="multipart/form-data" class="space-y-5">
                    <input type="hidden" name="assignment_id" value="<?php echo $ma['id']; ?>">
                    <div>
                        <div class="flex justify-between mb-1.5">
                            <label class="text-[10px] font-black uppercase text-slate-500 tracking-widest">Your Response</label>
                            <span id="wordCount" class="text-[10px] font-black text-slate-400">0 / <?php echo number_format($wl); ?> words</span>
                        </div>
                        <textarea name="submission_text" id="responseText" rows="10"
                            placeholder="Write your response here…"
                            class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl text-sm text-slate-800 leading-relaxed outline-none focus:ring-2 focus:ring-blue-500 resize-none"
                            required><?php echo $ma['submission_text'] ? htmlspecialchars($ma['submission_text']) : ''; ?></textarea>
                    </div>
                    <div>
                        <label class="text-[10px] font-black uppercase text-slate-500 tracking-widest block mb-1.5">Supporting File <span class="text-slate-300 font-medium normal-case">(optional — PDF, DOC, DOCX, TXT)</span></label>
                        <div id="fileDropZone" class="relative border-2 border-dashed border-slate-200 hover:border-blue-400 rounded-2xl p-5 flex flex-col items-center bg-slate-50 hover:bg-blue-50 transition-all cursor-pointer">
                            <input type="file" id="submissionFileInput" name="submission_file" accept=".pdf,.doc,.docx,.txt,.xls,.xlsx" class="absolute inset-0 opacity-0 cursor-pointer" onchange="handleFileSelect(this)">
                            <div id="fileDropDefault">
                                <i class="fa-solid fa-cloud-arrow-up text-slate-300 text-2xl mb-2 block text-center"></i>
                                <p class="text-[9px] text-slate-400 font-bold uppercase text-center">Click to attach file</p>
                                <p class="text-[8px] text-slate-300 font-medium text-center mt-1">PDF, DOC, DOCX, TXT, XLS, XLSX</p>
                            </div>
                            <div id="fileDropSelected" class="hidden w-full">
                                <div class="flex items-center space-x-3">
                                    <div id="fileIconBox" class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 bg-blue-100">
                                        <i id="fileTypeIcon" class="fa-solid fa-file text-blue-600 text-lg"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p id="fileSelectedName" class="text-sm font-black text-slate-800 truncate"></p>
                                        <p id="fileSelectedSize" class="text-[9px] text-slate-400 font-bold uppercase tracking-widest"></p>
                                    </div>
                                    <button type="button" onclick="clearFile(event)" class="w-7 h-7 bg-red-50 hover:bg-red-500 text-red-400 hover:text-white rounded-lg flex items-center justify-center transition-all flex-shrink-0">
                                        <i class="fa-solid fa-xmark text-xs"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-amber-50 border border-amber-100 rounded-2xl p-4">
                        <p class="text-[9px] font-black uppercase text-amber-600 mb-1"><i class="fa-solid fa-shield-halved mr-1"></i>Plagiarism Notice</p>
                        <p class="text-xs text-amber-800 leading-relaxed">
                            Your submission will be automatically analysed by the SmartLMS plagiarism engine.
                            It checks for similarity against other students' submissions and for patterns of
                            copied/generic academic content. Results are visible to your lecturer.
                        </p>
                    </div>
                    <button type="submit" id="submitBtn"
                        class="w-full py-4 bg-blue-600 hover:bg-blue-700 text-white font-black text-xs uppercase tracking-widest rounded-2xl shadow-lg transition-all active:scale-95">
                        <i class="fa-solid fa-paper-plane mr-2"></i>Submit Assignment
                    </button>
                </form>
            </div>
        </div>
    </div>
    <script>
        const textarea   = document.getElementById('responseText');
        const counter    = document.getElementById('wordCount');
        const submitBtn  = document.getElementById('submitBtn');
        const wordLimit  = <?php echo $wl; ?>;

        function countWords(str) {
            return str.trim() === '' ? 0 : str.trim().split(/\s+/).length;
        }
        function updateCount() {
            const wc = countWords(textarea.value);
            counter.textContent = wc.toLocaleString() + ' / ' + wordLimit.toLocaleString() + ' words';
            if (wc > wordLimit) {
                counter.classList.add('over-limit');
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
            } else {
                counter.classList.remove('over-limit');
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            }
        }
        textarea.addEventListener('input', updateCount);
        updateCount();

        // ── File upload feedback ──────────────────────────────────────
        const fileIconMap = {
            'pdf'  : { icon: 'fa-file-pdf',        bg: 'bg-red-100',     color: 'text-red-500'     },
            'doc'  : { icon: 'fa-file-word',        bg: 'bg-blue-100',    color: 'text-blue-600'    },
            'docx' : { icon: 'fa-file-word',        bg: 'bg-blue-100',    color: 'text-blue-600'    },
            'xls'  : { icon: 'fa-file-excel',       bg: 'bg-emerald-100', color: 'text-emerald-600' },
            'xlsx' : { icon: 'fa-file-excel',       bg: 'bg-emerald-100', color: 'text-emerald-600' },
            'ppt'  : { icon: 'fa-file-powerpoint',  bg: 'bg-orange-100',  color: 'text-orange-500'  },
            'pptx' : { icon: 'fa-file-powerpoint',  bg: 'bg-orange-100',  color: 'text-orange-500'  },
            'txt'  : { icon: 'fa-file-lines',       bg: 'bg-slate-100',   color: 'text-slate-500'   },
        };

        function handleFileSelect(input) {
            if (!input.files || !input.files[0]) return;
            const file = input.files[0];
            const ext  = file.name.split('.').pop().toLowerCase();
            const info = fileIconMap[ext] || { icon: 'fa-file', bg: 'bg-indigo-100', color: 'text-indigo-500' };

            // Update icon
            const iconEl = document.getElementById('fileTypeIcon');
            iconEl.className = 'fa-solid ' + info.icon + ' ' + info.color + ' text-lg';
            document.getElementById('fileIconBox').className = 'w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 ' + info.bg;

            // Update name + size
            document.getElementById('fileSelectedName').textContent = file.name;
            const kb = (file.size / 1024).toFixed(1);
            const size = file.size > 1024 * 1024 ? (file.size / (1024*1024)).toFixed(1) + ' MB' : kb + ' KB';
            document.getElementById('fileSelectedSize').textContent = ext.toUpperCase() + ' · ' + size;

            // Show selected state
            document.getElementById('fileDropDefault').classList.add('hidden');
            document.getElementById('fileDropSelected').classList.remove('hidden');
            document.getElementById('fileDropZone').classList.remove('border-slate-200', 'bg-slate-50');
            document.getElementById('fileDropZone').classList.add('border-blue-400', 'bg-blue-50');
        }

        function clearFile(e) {
            e.stopPropagation();
            document.getElementById('submissionFileInput').value = '';
            document.getElementById('fileDropDefault').classList.remove('hidden');
            document.getElementById('fileDropSelected').classList.add('hidden');
            document.getElementById('fileDropZone').classList.add('border-slate-200', 'bg-slate-50');
            document.getElementById('fileDropZone').classList.remove('border-blue-400', 'bg-blue-50');
        }
    </script>
    <?php endif; ?>

    <script>
        function toggleSection(id) {
            const el = document.getElementById(id);
            if (el) el.classList.toggle('hidden');
        }
    </script>
</body>
</html>