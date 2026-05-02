<?php
/**
 * assignment_create.php — SmartLMS Lecturer Assignment Management
 *
 * Lecturers can:
 *   - Create new assignments (per course they teach)
 *   - View all their assignments with submission counts
 *   - Delete assignments
 *   - View all student submissions for a specific assignment
 *   - Review plagiarism reports per submission
 */

include 'config.php';
checkRole('lecturer');

$lecturer_id  = intval($_SESSION['user_id']);
$lecturerName = $_SESSION['user_name'] ?? 'Lecturer';

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

// ── Lecturer's courses ──────────────────────────────────────────────────────
$courses_res = mysqli_query($conn,
    "SELECT DISTINCT id, title FROM courses
     WHERE lecturer_id = $lecturer_id
        OR id = (SELECT course_id FROM users WHERE id = $lecturer_id AND course_id IS NOT NULL LIMIT 1)
     ORDER BY title ASC"
);
$lecturer_courses = [];
while ($c = mysqli_fetch_assoc($courses_res)) $lecturer_courses[] = $c;
$course_ids = array_column($lecturer_courses, 'id');

// ── CREATE ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $course_id   = intval($_POST['course_id'] ?? 0);
    $title       = mysqli_real_escape_string($conn, trim($_POST['title'] ?? ''));
    $description = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));
    $due_date    = mysqli_real_escape_string($conn, $_POST['due_date'] ?? '');
    $max_words   = max(50, intval($_POST['max_words'] ?? 1000));

    $courseOwned = in_array($course_id, $course_ids);
    if ($courseOwned && $title) {
        $dueSql = $due_date ? "'$due_date'" : 'NULL';
        mysqli_query($conn,
            "INSERT INTO assignments (course_id, lecturer_id, title, description, due_date, max_words, created_at)
             VALUES ($course_id, $lecturer_id, '$title', '$description', $dueSql, $max_words, NOW())"
        );
        header("Location: assignment_create.php?msg=created"); exit();
    }
    header("Location: assignment_create.php?msg=error"); exit();
}

// ── DELETE ──────────────────────────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $del_id = intval($_GET['delete']);
    $owns = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM assignments WHERE id = $del_id AND lecturer_id = $lecturer_id"
    ));
    if ($owns) {
        mysqli_query($conn, "DELETE FROM assignments WHERE id = $del_id");
    }
    header("Location: assignment_create.php?msg=deleted"); exit();
}

// ── VIEW: SUBMISSIONS for one assignment ────────────────────────────────────
$view_mode   = $_GET['view'] ?? 'list';
$selected    = null;
$submissions = [];

if ($view_mode === 'submissions' && isset($_GET['id'])) {
    $assign_id = intval($_GET['id']);
    $selected  = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT a.*, c.title AS course_title
         FROM assignments a JOIN courses c ON c.id = a.course_id
         WHERE a.id = $assign_id AND a.lecturer_id = $lecturer_id"
    ));
    if ($selected) {
        $subs_res = mysqli_query($conn,
            "SELECT s.id, s.submission_text, s.word_count, s.submitted_at, s.file_path,
                    u.full_name AS student_name, u.email AS student_email,
                    pr.overall_score, pr.student_similarity_score, pr.internet_similarity_score,
                    pr.verdict, pr.matched_students, pr.flags, pr.analysed_at
             FROM assignment_submissions s
             JOIN users u ON u.id = s.student_id
             LEFT JOIN plagiarism_reports pr ON pr.submission_id = s.id
             WHERE s.assignment_id = $assign_id
             ORDER BY pr.overall_score DESC, s.submitted_at ASC"
        );
        while ($sub = mysqli_fetch_assoc($subs_res)) {
            $sub['flags_arr']   = json_decode($sub['flags'] ?? '[]', true) ?: [];
            $sub['matched_arr'] = json_decode($sub['matched_students'] ?? '[]', true) ?: [];
            $submissions[] = $sub;
        }
    }
}

// ── ALL ASSIGNMENTS list ─────────────────────────────────────────────────────
$assignments = [];
if (!empty($course_ids)) {
    $ids = implode(',', $course_ids);
    $aRes = mysqli_query($conn,
        "SELECT a.*, c.title AS course_title,
                COUNT(DISTINCT s.id) AS submission_count,
                ROUND(AVG(pr.overall_score), 1) AS avg_plagiarism,
                SUM(CASE WHEN pr.verdict = 'HIGH RISK' THEN 1 ELSE 0 END) AS high_risk_count
         FROM assignments a
         JOIN courses c ON c.id = a.course_id
         LEFT JOIN assignment_submissions s ON s.assignment_id = a.id
         LEFT JOIN plagiarism_reports pr ON pr.submission_id = s.id
         WHERE a.lecturer_id = $lecturer_id
         GROUP BY a.id ORDER BY a.created_at DESC"
    );
    while ($a = mysqli_fetch_assoc($aRes)) $assignments[] = $a;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignments | SmartLMS Lecturer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .verdict-low    { background:#dcfce7; color:#15803d; border-color:#bbf7d0; }
        .verdict-medium { background:#fef9c3; color:#92400e; border-color:#fde68a; }
        .verdict-high   { background:#fee2e2; color:#b91c1c; border-color:#fca5a5; }
        .card { background:#fff; border:1px solid #e2e8f0; }
    </style>
</head>
<body class="bg-slate-950 text-white font-sans min-h-screen flex">

    <!-- Sidebar — matches lecturer_dashboard.php exactly -->
    <nav class="w-20 lg:w-64 h-screen bg-slate-900 text-white flex flex-col items-center lg:items-start p-6 sticky top-0 z-40 transition-all duration-300">
        <div class="flex items-center space-x-3 mb-12">
            <div class="h-10 w-10 bg-indigo-500 rounded-xl flex items-center justify-center shadow-lg shadow-indigo-500/40">
                <i class="fa-solid fa-bolt text-white"></i>
            </div>
            <h2 class="text-xl font-extrabold tracking-tight hidden lg:block">Smart<span class="text-indigo-400">LMS</span></h2>
        </div>
        <div class="flex-1 w-full space-y-2">
            <a href="lecturer_dashboard.php" class="flex items-center space-x-4 p-3 rounded-xl text-slate-400 hover:bg-slate-800 transition group">
                <i class="fa-solid fa-house-chimney text-lg group-hover:text-white"></i>
                <span class="font-medium hidden lg:block group-hover:text-white">Overview</span>
            </a>
            <a href="quiz_panel.php" class="flex items-center space-x-4 p-3 rounded-xl text-slate-400 hover:bg-slate-800 transition group">
                <i class="fa-solid fa-brain text-lg group-hover:text-white"></i>
                <span class="font-medium hidden lg:block group-hover:text-white">Quiz Manager</span>
            </a>
            <a href="assignment_create.php" class="flex items-center space-x-4 p-3 rounded-xl bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 group">
                <i class="fa-solid fa-file-pen text-lg"></i>
                <span class="font-semibold hidden lg:block">Assignments</span>
            </a>
        </div>
        <div class="w-full pt-6 border-t border-slate-800">
            <div class="flex items-center space-x-3 p-2">
                <div class="h-8 w-8 rounded-full bg-gradient-to-tr from-indigo-500 to-purple-500"></div>
                <div class="hidden lg:block">
                    <p class="text-xs font-bold"><?php echo htmlspecialchars($lecturerName); ?></p>
                    <p class="text-[10px] text-slate-500 mt-1 uppercase tracking-widest font-bold">Academic Staff</p>
                </div>
            </div>
            <a href="logout.php" class="block mt-4 text-red-400 hover:text-red-300 transition text-xs font-bold">
                <i class="fa-solid fa-power-off lg:mr-2"></i><span class="hidden lg:inline uppercase tracking-tighter">Sign Out</span>
            </a>
        </div>
    </nav>

    <!-- Main content -->
    <main class="flex-1 bg-slate-50 min-h-screen text-slate-900">

        <!-- Page header -->
        <div class="bg-white border-b border-slate-200 px-8 py-6 flex items-center justify-between">
            <div>
                <?php if ($view_mode === 'submissions' && $selected): ?>
                    <a href="assignment_create.php" class="text-[10px] font-black uppercase text-indigo-600 hover:text-indigo-800 italic mb-1 block">
                        <i class="fa-solid fa-arrow-left mr-1"></i> All Assignments
                    </a>
                    <h1 class="text-xl font-black text-slate-900 uppercase italic"><?php echo htmlspecialchars($selected['title']); ?></h1>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest"><?php echo htmlspecialchars($selected['course_title']); ?> · <?php echo count($submissions); ?> submission<?php echo count($submissions) !== 1 ? 's' : ''; ?></p>
                <?php else: ?>
                    <h1 class="text-xl font-black text-slate-900 uppercase italic"><i class="fa-solid fa-file-pen text-indigo-500 mr-2"></i>Assignment Manager</h1>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Create and review student assignments</p>
                <?php endif; ?>
            </div>
            <?php if (isset($_GET['msg'])): ?>
            <?php $isErr = $_GET['msg'] === 'error'; ?>
            <div class="<?php echo $isErr ? 'bg-red-50 text-red-600 border-red-200' : 'bg-emerald-50 text-emerald-700 border-emerald-200'; ?> border rounded-xl px-4 py-2 text-xs font-black uppercase">
                <i class="fa-solid <?php echo $isErr ? 'fa-triangle-exclamation' : 'fa-check'; ?> mr-1"></i>
                <?php echo match($_GET['msg']) {
                    'created' => 'Assignment created.',
                    'deleted' => 'Assignment deleted.',
                    'error'   => 'Action failed. Check course ownership.',
                    default   => htmlspecialchars($_GET['msg'])
                }; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="max-w-6xl mx-auto px-6 py-8">

        <?php if ($view_mode === 'submissions' && $selected): ?>
        <!-- ══ SUBMISSIONS VIEW ══ -->
        <div class="mb-6 grid grid-cols-3 gap-4">
            <div class="card rounded-2xl p-4 text-center">
                <p class="text-[9px] font-black uppercase text-slate-400 mb-1">Submissions</p>
                <p class="text-2xl font-black text-slate-900"><?php echo count($submissions); ?></p>
            </div>
            <?php
            $avgOvr   = count($submissions) > 0 ? round(array_sum(array_column($submissions, 'overall_score')) / count($submissions), 1) : 0;
            $highCount = count(array_filter($submissions, fn($s) => $s['verdict'] === 'HIGH RISK'));
            ?>
            <div class="card rounded-2xl p-4 text-center">
                <p class="text-[9px] font-black uppercase text-slate-400 mb-1">Avg Plagiarism Score</p>
                <p class="text-2xl font-black <?php echo $avgOvr >= 65 ? 'text-red-600' : ($avgOvr >= 35 ? 'text-amber-600' : 'text-emerald-600'); ?>"><?php echo $avgOvr; ?>%</p>
            </div>
            <div class="card rounded-2xl p-4 text-center">
                <p class="text-[9px] font-black uppercase text-slate-400 mb-1">High Risk</p>
                <p class="text-2xl font-black <?php echo $highCount > 0 ? 'text-red-600' : 'text-slate-900'; ?>"><?php echo $highCount; ?></p>
            </div>
        </div>

        <?php if (empty($submissions)): ?>
        <div class="card rounded-2xl p-12 text-center">
            <i class="fa-solid fa-inbox text-slate-200 text-4xl mb-3"></i>
            <p class="text-slate-500 font-bold">No submissions received yet.</p>
        </div>
        <?php else: ?>
        <div class="space-y-5">
        <?php foreach ($submissions as $sub):
            $verdict = $sub['verdict'] ?? 'LOW RISK';
            $vClass  = match($verdict) { 'HIGH RISK' => 'verdict-high', 'MEDIUM RISK' => 'verdict-medium', default => 'verdict-low' };
            $vIcon   = match($verdict) { 'HIGH RISK' => 'fa-triangle-exclamation', 'MEDIUM RISK' => 'fa-circle-exclamation', default => 'fa-circle-check' };
        ?>
        <div class="card rounded-3xl overflow-hidden">
            <!-- Submission header -->
            <div class="px-6 py-5 flex items-start justify-between">
                <div>
                    <p class="font-black text-slate-900 text-sm"><?php echo htmlspecialchars($sub['student_name']); ?></p>
                    <p class="text-[10px] text-slate-400 font-bold"><?php echo htmlspecialchars($sub['student_email']); ?></p>
                    <div class="flex gap-4 mt-1 text-[10px] font-bold text-slate-400 uppercase">
                        <span><i class="fa-solid fa-clock mr-1"></i><?php echo date('d M Y, h:i A', strtotime($sub['submitted_at'])); ?></span>
                        <span><i class="fa-solid fa-font mr-1"></i><?php echo number_format($sub['word_count']); ?> words</span>
                        <?php if ($sub['file_path']): ?>
                        <a href="<?php echo htmlspecialchars($sub['file_path']); ?>" target="_blank" class="text-indigo-500 hover:text-indigo-700">
                            <i class="fa-solid fa-paperclip mr-1"></i>File
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Verdict badge -->
                <div class="flex flex-col items-end gap-2">
                    <span class="inline-flex items-center space-x-1.5 border rounded-xl px-3 py-1.5 text-[10px] font-black uppercase <?php echo $vClass; ?>">
                        <i class="fa-solid <?php echo $vIcon; ?>"></i>
                        <span><?php echo $verdict; ?></span>
                    </span>
                    <span class="text-[10px] font-bold text-slate-400">Overall: <?php echo number_format($sub['overall_score'], 1); ?>%</span>
                </div>
            </div>

            <!-- Plagiarism score bars -->
            <?php if ($sub['verdict']): ?>
            <div class="px-6 pb-4 grid grid-cols-2 gap-4">
                <div>
                    <div class="flex justify-between mb-1">
                        <span class="text-[9px] font-black uppercase text-slate-400">Student Peer Match</span>
                        <span class="text-[9px] font-black <?php echo floatval($sub['student_similarity_score']) >= 55 ? 'text-red-600' : 'text-slate-600'; ?>">
                            <?php echo number_format($sub['student_similarity_score'], 1); ?>%
                        </span>
                    </div>
                    <div class="h-1.5 bg-slate-100 rounded-full overflow-hidden">
                        <div class="h-full rounded-full <?php echo floatval($sub['student_similarity_score']) >= 55 ? 'bg-red-500' : 'bg-emerald-500'; ?>"
                             style="width:<?php echo min(100, $sub['student_similarity_score']); ?>%"></div>
                    </div>
                </div>
                <div>
                    <div class="flex justify-between mb-1">
                        <span class="text-[9px] font-black uppercase text-slate-400">Internet Estimate</span>
                        <span class="text-[9px] font-black <?php echo floatval($sub['internet_similarity_score']) >= 50 ? 'text-amber-600' : 'text-slate-600'; ?>">
                            <?php echo number_format($sub['internet_similarity_score'], 1); ?>%
                        </span>
                    </div>
                    <div class="h-1.5 bg-slate-100 rounded-full overflow-hidden">
                        <div class="h-full rounded-full <?php echo floatval($sub['internet_similarity_score']) >= 50 ? 'bg-amber-500' : 'bg-blue-500'; ?>"
                             style="width:<?php echo min(100, $sub['internet_similarity_score']); ?>%"></div>
                    </div>
                </div>
            </div>

            <!-- Matched students -->
            <?php if (!empty($sub['matched_arr'])): ?>
            <div class="px-6 pb-4">
                <p class="text-[9px] font-black uppercase text-red-500 mb-2"><i class="fa-solid fa-users mr-1"></i>Similar Submissions Detected</p>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($sub['matched_arr'] as $match): ?>
                    <span class="bg-red-50 border border-red-100 text-red-700 text-[9px] font-black px-2.5 py-1 rounded-lg">
                        <?php echo htmlspecialchars($match['student_name']); ?> — <?php echo number_format($match['similarity'], 1); ?>% match
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Heuristic flags -->
            <?php if (!empty($sub['flags_arr'])): ?>
            <div class="px-6 pb-4">
                <button onclick="toggleEl('flags-<?php echo $sub['id']; ?>')"
                    class="text-[9px] font-black uppercase text-amber-600 hover:text-amber-800 mb-2 block">
                    <i class="fa-solid fa-flag mr-1"></i><?php echo count($sub['flags_arr']); ?> heuristic flag<?php echo count($sub['flags_arr']) !== 1 ? 's' : ''; ?>
                </button>
                <div id="flags-<?php echo $sub['id']; ?>" class="hidden bg-amber-50 border border-amber-100 rounded-2xl p-4">
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

            <!-- Submission text preview -->
            <div class="border-t border-slate-100 px-6 py-3 flex items-center justify-between">
                <button onclick="toggleEl('text-<?php echo $sub['id']; ?>')"
                    class="text-[10px] font-black uppercase text-slate-400 hover:text-slate-700 transition">
                    <i class="fa-solid fa-file-lines mr-1"></i>View Submission Text
                </button>
            </div>
            <div id="text-<?php echo $sub['id']; ?>" class="hidden px-6 pb-6">
                <div class="bg-slate-50 border border-slate-200 rounded-2xl p-5 text-sm text-slate-700 leading-relaxed max-h-60 overflow-y-auto">
                    <?php echo nl2br(htmlspecialchars($sub['submission_text'])); ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- ══ LIST VIEW + CREATE FORM ══ -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            <!-- Create form -->
            <div class="lg:col-span-1">
                <div class="card rounded-3xl p-6 sticky top-24">
                    <h3 class="text-xs font-black uppercase text-indigo-600 mb-5 italic"><i class="fa-solid fa-plus mr-1"></i>New Assignment</h3>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="create">
                        <div>
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest block mb-1">Course</label>
                            <select name="course_id" required class="w-full p-3 bg-slate-50 border rounded-xl text-sm text-slate-700">
                                <option value="">Select course…</option>
                                <?php foreach ($lecturer_courses as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['title']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest block mb-1">Title</label>
                            <input type="text" name="title" required placeholder="e.g. Literature Review" class="w-full p-3 bg-slate-50 border rounded-xl text-sm text-slate-800">
                        </div>
                        <div>
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest block mb-1">Description / Brief</label>
                            <textarea name="description" rows="4" placeholder="What should students address?" class="w-full p-3 bg-slate-50 border rounded-xl text-sm text-slate-800 resize-none"></textarea>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest block mb-1">Due Date</label>
                                <input type="date" name="due_date" class="w-full p-3 bg-slate-50 border rounded-xl text-sm text-slate-800">
                            </div>
                            <div>
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest block mb-1">Word Limit</label>
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
                <?php if (empty($assignments)): ?>
                <div class="card rounded-2xl p-12 text-center">
                    <i class="fa-solid fa-file-pen text-slate-200 text-4xl mb-3"></i>
                    <p class="text-slate-500 font-bold text-sm">No assignments yet. Create your first one.</p>
                </div>
                <?php else: ?>
                <?php foreach ($assignments as $a):
                    $highRisk  = intval($a['high_risk_count'] ?? 0);
                    $subCount  = intval($a['submission_count']);
                    $avgPlag   = floatval($a['avg_plagiarism'] ?? 0);
                    $isOverdue = $a['due_date'] && strtotime($a['due_date']) < time();
                ?>
                <div class="card rounded-2xl p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1">
                            <div class="flex items-center flex-wrap gap-2 mb-1">
                                <span class="text-[9px] font-black uppercase text-indigo-600 bg-indigo-50 border border-indigo-100 px-2 py-0.5 rounded-lg">
                                    <?php echo htmlspecialchars($a['course_title']); ?>
                                </span>
                                <?php if ($isOverdue): ?>
                                <span class="text-[9px] font-black uppercase text-red-600 bg-red-50 border border-red-100 px-2 py-0.5 rounded-lg">Closed</span>
                                <?php endif; ?>
                                <?php if ($highRisk > 0): ?>
                                <span class="text-[9px] font-black uppercase text-red-600 bg-red-50 border border-red-100 px-2 py-0.5 rounded-lg">
                                    <i class="fa-solid fa-triangle-exclamation mr-1"></i><?php echo $highRisk; ?> High Risk
                                </span>
                                <?php endif; ?>
                            </div>
                            <h4 class="font-black text-slate-900 text-sm mb-1"><?php echo htmlspecialchars($a['title']); ?></h4>
                            <div class="flex gap-4 text-[10px] font-bold text-slate-400 uppercase flex-wrap">
                                <?php if ($a['due_date']): ?>
                                <span><i class="fa-solid fa-calendar-days mr-1 <?php echo $isOverdue ? 'text-red-500' : 'text-blue-400'; ?>"></i><?php echo date('d M Y', strtotime($a['due_date'])); ?></span>
                                <?php endif; ?>
                                <span><i class="fa-solid fa-align-left mr-1 text-blue-400"></i><?php echo number_format($a['max_words']); ?> words</span>
                                <span><i class="fa-solid fa-users mr-1 text-blue-400"></i><?php echo $subCount; ?> submission<?php echo $subCount !== 1 ? 's' : ''; ?></span>
                                <?php if ($subCount > 0): ?>
                                <span class="<?php echo $avgPlag >= 65 ? 'text-red-600' : ($avgPlag >= 35 ? 'text-amber-600' : 'text-emerald-600'); ?>">
                                    <i class="fa-solid fa-shield-halved mr-1"></i>Avg plagiarism: <?php echo $avgPlag; ?>%
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="flex flex-col gap-2">
                            <a href="?view=submissions&id=<?php echo $a['id']; ?>"
                               class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-black text-[9px] uppercase rounded-xl tracking-widest transition-all text-center">
                                <i class="fa-solid fa-eye mr-1"></i>Submissions
                            </a>
                            <a href="?delete=<?php echo $a['id']; ?>"
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

        </div><!-- /max-w -->
    </main>

    <script>
        function toggleEl(id) {
            const el = document.getElementById(id);
            if (el) el.classList.toggle('hidden');
        }
    </script>
</body>
</html>