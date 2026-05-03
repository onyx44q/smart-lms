<?php 
include 'config.php'; 
checkRole('admin'); 

// --- 1. DATA AGGREGATION QUERIES ---
// Basic Counts
$studentCount = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM users WHERE role='student'"))['total'] ?? 0;
$lecturerCount = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM users WHERE role='lecturer'"))['total'] ?? 0;
$courseCount = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM courses"))['total'] ?? 0;

// Ensure mastery is accurate for every student that has quiz results
include_once __DIR__ . '/recalculate_mastery.php';
$all_result_students = mysqli_query($conn, "SELECT DISTINCT student_id FROM results");
while ($rs = mysqli_fetch_assoc($all_result_students)) {
    recalculate_mastery_for_student(intval($rs['student_id']), $conn);
}

// Performance Metrics — computed as per-student AVERAGE mastery (accurate)
$masteryStats = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT
        COUNT(CASE WHEN avg_m >= 75 THEN 1 END) AS excelling,
        COUNT(CASE WHEN avg_m < 40  THEN 1 END) AS at_risk,
        AVG(avg_m) AS global_avg
     FROM (
         SELECT student_id, AVG(mastery_level) AS avg_m
         FROM student_mastery
         GROUP BY student_id
     ) t"
));
$excellingCount = intval($masteryStats['excelling'] ?? 0);
$riskCount      = intval($masteryStats['at_risk']   ?? 0);
$avgMastery     = round(floatval($masteryStats['global_avg'] ?? 0), 1);

$view = $_GET['view'] ?? 'dashboard'; 
$filter = $_GET['filter'] ?? 'all';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <title>Admin Dashboard | SmartLMS</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap');
        body { font-family: 'Plus+Jakarta+Sans', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 flex">

    <nav class="w-64 h-screen bg-slate-900 text-white p-6 space-y-8 sticky top-0 flex flex-col">
        <div class="flex items-center space-x-3 px-2">
            <div class="w-10 h-10 bg-blue-600 rounded-xl flex items-center justify-center shadow-lg shadow-blue-500/20">
                <i class="fa-solid fa-shield-halved text-white text-lg"></i>
            </div>
            <div>
                <h1 class="font-black text-sm tracking-tight leading-none">SmartLMS</h1>
                <p class="text-[10px] text-slate-500 font-bold uppercase mt-1">Admin Portal</p>
            </div>
        </div>

        <div class="space-y-1">
            <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-3 mb-2">Main Menu</p>
            <a href="?view=dashboard" class="flex items-center space-x-3 p-3 rounded-xl transition-all <?php echo $view == 'dashboard' ? 'bg-blue-600 shadow-lg shadow-blue-600/30' : 'hover:bg-slate-800 text-slate-400'; ?>">
                <i class="fa-solid fa-chart-pie"></i>
                <span class="text-sm font-bold">General Insights</span>
            </a>
            <a href="?view=users" class="flex items-center space-x-3 p-3 rounded-xl transition-all <?php echo $view == 'users' ? 'bg-blue-600 shadow-lg shadow-blue-600/30' : 'hover:bg-slate-800 text-slate-400'; ?>">
                <i class="fa-solid fa-user-gear"></i>
                <span class="text-sm font-bold">Manage Users</span>
            </a>
            <a href="?view=courses" class="flex items-center space-x-3 p-3 rounded-xl transition-all <?php echo $view == 'courses' ? 'bg-blue-600 shadow-lg shadow-blue-600/30' : 'hover:bg-slate-800 text-slate-400'; ?>">
                <i class="fa-solid fa-book-open"></i>
                <span class="text-sm font-bold">Course Catalog</span>
            </a>
            <a href="?view=marks" class="flex items-center space-x-3 p-3 rounded-xl transition-all <?php echo $view == 'marks' ? 'bg-blue-600 shadow-lg shadow-blue-600/30' : 'hover:bg-slate-800 text-slate-400'; ?>">
                <i class="fa-solid fa-clipboard-list"></i>
                <span class="text-sm font-bold">Student Marks</span>
            </a>
        </div>

        <div class="space-y-1 pt-4 border-t border-slate-800">
            <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-3 mb-2">Student Health</p>
            
            <a href="?view=performance&filter=excelling" class="flex items-center justify-between p-3 rounded-xl hover:bg-slate-800 group transition-all">
                <div class="flex items-center space-x-3">
                    <i class="fa-solid fa-crown text-emerald-500"></i>
                    <span class="text-sm font-medium text-slate-300">Doing Well</span>
                </div>
                <span class="text-[10px] bg-emerald-500/10 text-emerald-400 px-2 py-0.5 rounded-full font-bold"><?php echo $excellingCount; ?></span>
            </a>

            <a href="?view=performance&filter=risk" class="flex items-center justify-between p-3 rounded-xl hover:bg-slate-800 group transition-all">
                <div class="flex items-center space-x-3">
                    <i class="fa-solid fa-triangle-exclamation text-rose-500"></i>
                    <span class="text-sm font-medium text-slate-300">At Risk</span>
                </div>
                <span class="text-[10px] bg-rose-500/10 text-rose-400 px-2 py-0.5 rounded-full font-bold"><?php echo $riskCount; ?></span>
            </a>
        </div>

        <div class="mt-auto">
            <a href="logout.php" class="flex items-center space-x-3 p-3 rounded-xl hover:bg-rose-500/10 text-rose-400 transition-all font-bold text-sm">
                <i class="fa-solid fa-power-off"></i>
                <span>Sign Out</span>
            </a>
        </div>
    </nav>

    <main class="flex-1 p-10 overflow-y-auto">
        <header class="flex justify-between items-center mb-10">
            <div>
                <h2 class="text-2xl font-black text-slate-800">Admin Overview</h2>
                <p class="text-slate-500 text-sm font-medium">Real-time institutional performance metrics.</p>
            </div>
            <div class="flex space-x-3">
                <button onclick="toggleModal('lecturerModal')" class="bg-white border border-slate-200 text-slate-700 px-6 py-3 rounded-2xl font-bold text-sm hover:shadow-md transition-all">
                    + Add Lecturer
                </button>
                <button onclick="toggleModal('courseModal')" class="bg-blue-600 text-white px-6 py-3 rounded-2xl font-bold text-sm shadow-lg shadow-blue-600/20 hover:scale-105 transition-all">
                    Create Course
                </button>
            </div>
        </header>

        <?php if ($view == 'dashboard'): ?>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[10px] font-black uppercase text-slate-400 mb-1">Global Mastery</p>
                    <h3 class="text-3xl font-black text-slate-800"><?php echo number_format($avgMastery, 1); ?>%</h3>
                    <div class="w-full bg-slate-100 h-1.5 rounded-full mt-3">
                        <div class="bg-blue-500 h-full rounded-full" style="width: <?php echo $avgMastery; ?>%"></div>
                    </div>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[10px] font-black uppercase text-slate-400 mb-1">Students</p>
                    <h3 class="text-3xl font-black text-slate-800"><?php echo $studentCount; ?></h3>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[10px] font-black uppercase text-slate-400 mb-1">Lecturers</p>
                    <h3 class="text-3xl font-black text-slate-800"><?php echo $lecturerCount; ?></h3>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[10px] font-black uppercase text-slate-400 mb-1">Courses</p>
                    <h3 class="text-3xl font-black text-slate-800"><?php echo $courseCount; ?></h3>
                </div>
            </div>

            <div class="bg-white rounded-[2.5rem] p-8 border border-slate-100 shadow-sm">
                <h3 class="text-lg font-black text-slate-800 mb-6">Course Enrollment & Success Rates</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="text-[10px] font-black uppercase text-slate-400 border-b border-slate-50">
                                <th class="pb-4">Course Details</th>
                                <th class="pb-4">Active Students</th>
                                <th class="pb-4">Average Score</th>
                                <th class="pb-4">System Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php
                            $coursesQuery = mysqli_query($conn, "
                                SELECT c.title, c.skill_category,
                                       (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) as enrollment_count,
                                       (SELECT AVG(r.score) FROM results r JOIN quizzes q ON r.quiz_id = q.id WHERE q.course_id = c.id) as avg_score
                                FROM courses c
                            ");
                            while($row = mysqli_fetch_assoc($coursesQuery)): 
                                $avg = $row['avg_score'] ?? 0;
                                $statusColor = $avg >= 70 ? 'text-emerald-500 bg-emerald-50' : ($avg >= 50 ? 'text-blue-500 bg-blue-50' : 'text-rose-500 bg-rose-50');
                            ?>
                            <tr class="group">
                                <td class="py-5">
                                    <div class="font-bold text-slate-700"><?php echo $row['title']; ?></div>
                                    <div class="text-[10px] text-slate-400 uppercase font-black"><?php echo $row['skill_category'] ?? 'General'; ?></div>
                                </td>
                                <td class="py-5">
                                    <div class="flex items-center space-x-2">
                                        <span class="text-sm font-bold text-slate-600"><?php echo $row['enrollment_count']; ?></span>
                                        <span class="text-[10px] text-slate-400 font-medium">Enrolled</span>
                                    </div>
                                </td>
                                <td class="py-5 text-sm font-black text-slate-800"><?php echo number_format($avg, 1); ?>%</td>
                                <td class="py-5">
                                    <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase <?php echo $statusColor; ?>">
                                        <?php echo $avg >= 50 ? 'Optimal' : 'Needs Review'; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($view == 'courses'):
        // ── Ensure all unit tables exist ─────────────────────────
        foreach ([
            "CREATE TABLE IF NOT EXISTS `course_units` (`id` INT AUTO_INCREMENT PRIMARY KEY, `course_id` INT NOT NULL, `title` VARCHAR(255) NOT NULL, `unit_code` VARCHAR(50) DEFAULT NULL, `description` TEXT DEFAULT NULL, `lecturer_id` INT DEFAULT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY(`course_id`), KEY(`lecturer_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `unit_registrations` (`id` INT AUTO_INCREMENT PRIMARY KEY, `student_id` INT NOT NULL, `unit_id` INT NOT NULL, `registered_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY(`student_id`,`unit_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `unit_assessments` (`id` INT AUTO_INCREMENT PRIMARY KEY, `unit_id` INT NOT NULL, `name` VARCHAR(100) NOT NULL, `type` ENUM('coursework','exam') DEFAULT 'coursework', `max_mark` DECIMAL(6,2) DEFAULT 100.00, `sort_order` TINYINT DEFAULT 0, `created_by` INT DEFAULT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY(`unit_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `unit_marks` (`id` INT AUTO_INCREMENT PRIMARY KEY, `assessment_id` INT NOT NULL, `student_id` INT NOT NULL, `mark` DECIMAL(6,2) DEFAULT NULL, `remarks` VARCHAR(255) DEFAULT NULL, `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY(`assessment_id`,`student_id`), KEY(`student_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ] as $t) mysqli_query($conn, $t);

        // ── All lecturers ─────────────────────────────────────────
        $all_lecturers = [];
        $lr = mysqli_query($conn, "SELECT id, full_name FROM users WHERE role='lecturer' ORDER BY full_name ASC");
        while ($l = mysqli_fetch_assoc($lr)) $all_lecturers[] = $l;

        // ── All courses with unit/student counts ──────────────────
        $all_courses_q = mysqli_query($conn,
            "SELECT c.id, c.title, c.lecturer_id, ul.full_name AS course_lecturer,
                    (SELECT COUNT(*) FROM course_units cu WHERE cu.course_id = c.id) AS unit_count,
                    (SELECT COUNT(DISTINCT e.student_id) FROM enrollments e WHERE e.course_id = c.id) AS student_count
             FROM courses c
             LEFT JOIN users ul ON ul.id = c.lecturer_id
             ORDER BY c.id ASC"
        );
        $all_courses_arr = [];
        while ($ac = mysqli_fetch_assoc($all_courses_q)) $all_courses_arr[] = $ac;

        // ── Units for ALL courses keyed by course_id ──────────────
        $all_units_by_course = [];
        $au_res = mysqli_query($conn,
            "SELECT cu.*, ul.full_name AS lecturer_name,
                    (SELECT COUNT(*) FROM unit_registrations ur WHERE ur.unit_id = cu.id) AS reg_count
             FROM course_units cu
             LEFT JOIN users ul ON ul.id = cu.lecturer_id
             ORDER BY cu.course_id ASC, cu.created_at ASC"
        );
        while ($au = mysqli_fetch_assoc($au_res))
            $all_units_by_course[$au['course_id']][] = $au;

        $sts = $_GET['status'] ?? '';
        $sts_course = intval($_GET['sts_course'] ?? 0);
        ?>

        <!-- Page header -->
        <div class="flex items-center justify-between mb-8">
            <div>
                <h2 class="text-2xl font-black text-slate-900">Course Catalog</h2>
                <p class="text-slate-500 text-sm mt-1">Manage courses, add units, and assign lecturers per unit.</p>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-[9px] font-black uppercase text-slate-400 tracking-widest"><?php echo count($all_courses_arr); ?> course(s)</span>
            </div>
        </div>

        <?php if ($sts): ?>
        <div class="mb-5 px-5 py-3 rounded-2xl text-xs font-black uppercase border flex items-center gap-2 <?php echo str_contains($sts,'error') ? 'bg-red-50 text-red-600 border-red-200' : 'bg-emerald-50 text-emerald-700 border-emerald-200'; ?>">
            <i class="fa-solid <?php echo str_contains($sts,'error') ? 'fa-triangle-exclamation' : 'fa-circle-check'; ?>"></i>
            <?php echo match($sts) {
                'unit_added'   => 'Unit added successfully.',
                'unit_deleted' => 'Unit deleted.',
                'assigned'     => 'Lecturer assigned to unit.',
                'added'        => 'Course added.',
                'deleted'      => 'Course deleted.',
                'error'        => 'An error occurred.',
                default        => ucfirst(str_replace('_',' ',$sts))
            }; ?>
        </div>
        <?php endif; ?>

        <!-- Course Cards -->
        <div class="space-y-4" id="courseList">
        <?php foreach ($all_courses_arr as $course):
            $course_units_list = $all_units_by_course[$course['id']] ?? [];
            $isOpen = ($sts_course === $course['id'] || count($all_courses_arr) === 1);
        ?>
        <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden course-card"
             id="course-card-<?php echo $course['id']; ?>">

            <!-- Course Header Row -->
            <div class="px-6 py-5 flex items-center justify-between cursor-pointer hover:bg-slate-50/60 transition-colors"
                 onclick="toggleCourse(<?php echo $course['id']; ?>)">
                <div class="flex items-center gap-4">
                    <div class="w-11 h-11 rounded-2xl bg-gradient-to-tr from-blue-500 to-indigo-600 flex items-center justify-center flex-shrink-0 shadow-sm shadow-indigo-100">
                        <i class="fa-solid fa-graduation-cap text-white text-sm"></i>
                    </div>
                    <div>
                        <h3 class="font-black text-slate-900"><?php echo htmlspecialchars($course['title']); ?></h3>
                        <div class="flex items-center gap-3 mt-0.5">
                            <span class="text-[9px] font-bold text-slate-400">
                                <i class="fa-solid fa-layer-group mr-1 text-indigo-400"></i><?php echo $course['unit_count']; ?> unit(s)
                            </span>
                            <span class="text-[9px] font-bold text-slate-400">
                                <i class="fa-solid fa-users mr-1 text-emerald-400"></i><?php echo $course['student_count']; ?> enrolled
                            </span>
                            <?php if ($course['course_lecturer']): ?>
                            <span class="text-[9px] font-bold text-slate-400">
                                <i class="fa-solid fa-chalkboard-teacher mr-1 text-amber-400"></i><?php echo htmlspecialchars($course['course_lecturer']); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-[9px] font-black uppercase tracking-widest text-indigo-600 bg-indigo-50 border border-indigo-100 px-3 py-1 rounded-xl hidden sm:block">
                        <i class="fa-solid fa-layer-group mr-1"></i>Manage Units
                    </span>
                    <div class="w-8 h-8 rounded-xl bg-slate-100 flex items-center justify-center transition-transform duration-200"
                         id="chevron-<?php echo $course['id']; ?>">
                        <i class="fa-solid fa-chevron-down text-slate-400 text-xs <?php echo $isOpen ? 'rotate-180' : ''; ?>"
                           style="transition:transform 0.2s"></i>
                    </div>
                    <a href="admin_actions.php?delete_course=<?php echo $course['id']; ?>"
                       onclick="return confirm('Delete course and ALL its units, marks and data?')"
                       class="w-8 h-8 rounded-xl bg-red-50 text-red-400 hover:bg-red-600 hover:text-white flex items-center justify-center transition-all flex-shrink-0"
                       title="Delete course">
                        <i class="fa-solid fa-trash text-xs"></i>
                    </a>
                </div>
            </div>

            <!-- Expandable Units Panel -->
            <div id="units-panel-<?php echo $course['id']; ?>"
                 class="<?php echo $isOpen ? '' : 'hidden'; ?> border-t border-slate-100">

                <div class="grid grid-cols-1 lg:grid-cols-5 divide-y lg:divide-y-0 lg:divide-x divide-slate-100">

                    <!-- LEFT: Add Unit Form -->
                    <div class="lg:col-span-2 p-6 bg-slate-50/40">
                        <h4 class="text-[9px] font-black uppercase text-indigo-600 tracking-widest mb-4">
                            <i class="fa-solid fa-plus mr-1"></i>Add Unit to <?php echo htmlspecialchars($course['title']); ?>
                        </h4>
                        <form action="unit_actions.php" method="POST" class="space-y-3">
                            <input type="hidden" name="action" value="add_unit">
                            <input type="hidden" name="course_id" value="<?php echo $course['id']; ?>">

                            <div>
                                <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest block mb-1">Unit Title *</label>
                                <input type="text" name="title" required placeholder="e.g. Artificial Intelligence"
                                    class="w-full p-2.5 bg-white border border-slate-200 rounded-xl text-sm font-semibold outline-none focus:ring-2 focus:ring-indigo-400 transition-all">
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest block mb-1">Unit Code</label>
                                    <input type="text" name="unit_code" placeholder="e.g. DSC-101"
                                        class="w-full p-2.5 bg-white border border-slate-200 rounded-xl text-sm font-semibold outline-none focus:ring-2 focus:ring-indigo-400 transition-all">
                                </div>
                                <div>
                                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest block mb-1">Assign Lecturer</label>
                                    <select name="lecturer_id"
                                        class="w-full p-2.5 bg-white border border-slate-200 rounded-xl text-xs font-semibold outline-none focus:ring-2 focus:ring-indigo-400 transition-all">
                                        <option value="">— None —</option>
                                        <?php foreach ($all_lecturers as $lec): ?>
                                        <option value="<?php echo $lec['id']; ?>"><?php echo htmlspecialchars($lec['full_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest block mb-1">Description</label>
                                <textarea name="description" rows="2" placeholder="Brief description of this unit…"
                                    class="w-full p-2.5 bg-white border border-slate-200 rounded-xl text-xs font-semibold outline-none focus:ring-2 focus:ring-indigo-400 transition-all resize-none"></textarea>
                            </div>

                            <button type="submit"
                                class="w-full py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-black text-[9px] uppercase tracking-widest transition-all active:scale-95 shadow-md shadow-indigo-100">
                                <i class="fa-solid fa-plus mr-1"></i>Add Unit
                            </button>
                        </form>
                    </div>

                    <!-- RIGHT: Existing Units List -->
                    <div class="lg:col-span-3 p-6">
                        <h4 class="text-[9px] font-black uppercase text-slate-500 tracking-widest mb-4 flex items-center justify-between">
                            <span><i class="fa-solid fa-layer-group mr-1 text-indigo-400"></i>Units (<?php echo count($course_units_list); ?>)</span>
                            <span class="text-slate-300 font-medium normal-case">Students register these from their dashboard</span>
                        </h4>

                        <?php if (empty($course_units_list)): ?>
                        <div class="bg-slate-50 rounded-2xl border border-dashed border-slate-200 p-8 text-center">
                            <i class="fa-solid fa-layer-group text-slate-200 text-2xl mb-2"></i>
                            <p class="text-slate-400 text-xs font-bold">No units yet — add one using the form.</p>
                        </div>
                        <?php else: ?>
                        <div class="space-y-2">
                        <?php foreach ($course_units_list as $unit): ?>
                        <div class="bg-white border border-slate-100 rounded-2xl overflow-hidden shadow-sm">
                            <!-- Unit info row -->
                            <div class="px-4 py-3 flex items-center justify-between">
                                <div class="flex items-center gap-3 flex-1 min-w-0">
                                    <div class="w-8 h-8 bg-indigo-50 rounded-xl flex items-center justify-center flex-shrink-0">
                                        <i class="fa-solid fa-book text-indigo-500 text-xs"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="font-black text-slate-900 text-sm"><?php echo htmlspecialchars($unit['title']); ?></span>
                                            <?php if ($unit['unit_code']): ?>
                                            <span class="text-[8px] font-black uppercase bg-blue-50 text-blue-600 border border-blue-100 px-1.5 py-0.5 rounded-md"><?php echo htmlspecialchars($unit['unit_code']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex items-center gap-3 mt-0.5">
                                            <?php if ($unit['lecturer_name']): ?>
                                            <span class="text-[9px] font-bold text-emerald-600">
                                                <i class="fa-solid fa-circle-check mr-0.5"></i><?php echo htmlspecialchars($unit['lecturer_name']); ?>
                                            </span>
                                            <?php else: ?>
                                            <span class="text-[9px] font-bold text-amber-500">
                                                <i class="fa-solid fa-triangle-exclamation mr-0.5"></i>No lecturer assigned
                                            </span>
                                            <?php endif; ?>
                                            <span class="text-[9px] font-bold text-slate-400">
                                                <i class="fa-solid fa-users mr-0.5"></i><?php echo $unit['reg_count']; ?> registered
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-1.5 flex-shrink-0 ml-2">
                                    <!-- Toggle assign lecturer form -->
                                    <button onclick="toggleAssign('assign-<?php echo $unit['id']; ?>')"
                                        class="px-3 py-1.5 bg-slate-50 hover:bg-indigo-50 border border-slate-200 hover:border-indigo-200 text-slate-500 hover:text-indigo-600 rounded-xl text-[9px] font-black uppercase tracking-widest transition-all">
                                        <i class="fa-solid fa-user-gear mr-1"></i>Assign
                                    </button>
                                    <a href="unit_actions.php?action=delete_unit&unit_id=<?php echo $unit['id']; ?>&course_id=<?php echo $course['id']; ?>"
                                       onclick="return confirm('Delete unit \'<?php echo addslashes($unit['title']); ?>\'? All registrations and marks will be lost.')"
                                       class="w-7 h-7 rounded-xl bg-red-50 text-red-400 hover:bg-red-600 hover:text-white flex items-center justify-center transition-all">
                                        <i class="fa-solid fa-trash-can text-[10px]"></i>
                                    </a>
                                </div>
                            </div>

                            <!-- Assign Lecturer Drawer (hidden by default) -->
                            <div id="assign-<?php echo $unit['id']; ?>" class="hidden border-t border-slate-100 bg-indigo-50/30 px-4 py-3">
                                <form action="unit_actions.php" method="POST" class="flex items-center gap-2">
                                    <input type="hidden" name="action" value="assign_unit_lecturer">
                                    <input type="hidden" name="unit_id" value="<?php echo $unit['id']; ?>">
                                    <input type="hidden" name="course_id" value="<?php echo $course['id']; ?>">
                                    <i class="fa-solid fa-chalkboard-teacher text-indigo-300 text-sm flex-shrink-0"></i>
                                    <select name="lecturer_id"
                                        class="flex-1 p-2 bg-white border border-slate-200 rounded-xl text-xs font-semibold outline-none focus:ring-2 focus:ring-indigo-400">
                                        <option value="">— Remove Assignment —</option>
                                        <?php foreach ($all_lecturers as $lec): ?>
                                        <option value="<?php echo $lec['id']; ?>" <?php echo $unit['lecturer_id'] == $lec['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($lec['full_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit"
                                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-black text-[9px] uppercase rounded-xl tracking-widest transition-all whitespace-nowrap">
                                        <i class="fa-solid fa-check mr-1"></i>Save
                                    </button>
                                    <button type="button" onclick="toggleAssign('assign-<?php echo $unit['id']; ?>')"
                                        class="px-3 py-2 bg-white border border-slate-200 text-slate-400 hover:text-slate-600 font-black text-[9px] rounded-xl transition-all">
                                        Cancel
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>

        <?php if (empty($all_courses_arr)): ?>
        <div class="bg-white rounded-[2rem] border border-dashed border-slate-200 p-16 text-center">
            <i class="fa-solid fa-graduation-cap text-slate-200 text-5xl mb-4"></i>
            <p class="text-slate-500 font-bold">No courses added yet.</p>
            <p class="text-slate-400 text-xs mt-1">Add a course first using the Add Course button above.</p>
        </div>
        <?php endif; ?>

        <!-- JS for expand/collapse and assign drawer -->
        <script>
        function toggleCourse(id) {
            const panel   = document.getElementById('units-panel-' + id);
            const chevron = document.getElementById('chevron-' + id).querySelector('i');
            const isHidden = panel.classList.contains('hidden');
            // Close all first
            document.querySelectorAll('[id^="units-panel-"]').forEach(p => p.classList.add('hidden'));
            document.querySelectorAll('[id^="chevron-"]').forEach(c => c.querySelector('i').style.transform = '');
            // Open clicked if it was closed
            if (isHidden) {
                panel.classList.remove('hidden');
                chevron.style.transform = 'rotate(180deg)';
            }
        }
        function toggleAssign(id) {
            const el = document.getElementById(id);
            el.classList.toggle('hidden');
        }
        // Auto-open course that just had a unit added/assigned
        <?php if ($sts_course > 0): ?>
        document.addEventListener('DOMContentLoaded', () => {
            const panel   = document.getElementById('units-panel-<?php echo $sts_course; ?>');
            const chevron = document.getElementById('chevron-<?php echo $sts_course; ?>');
            if (panel)   panel.classList.remove('hidden');
            if (chevron) chevron.querySelector('i').style.transform = 'rotate(180deg)';
        });
        <?php endif; ?>
        </script>

        <?php endif; /* end view==courses */ ?>

        <?php if ($view == 'users'): ?>
        <div class="bg-white rounded-[2.5rem] p-8 border border-slate-100 shadow-sm">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-[10px] font-black uppercase text-slate-400 border-b border-slate-50">
                        <th class="pb-4">Full Name</th>
                        <th class="pb-4">Email</th>
                        <th class="pb-4">Role</th>
                        <th class="pb-4 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php
                    $users = mysqli_query($conn, "SELECT * FROM users WHERE role != 'admin' ORDER BY id DESC");
                    while($u = mysqli_fetch_assoc($users)): ?>
                    <tr>
                        <td class="py-4 font-bold text-slate-700"><?php echo $u['full_name']; ?></td>
                        <td class="py-4 text-sm text-slate-500"><?php echo $u['email']; ?></td>
                        <td class="py-4">
                            <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase bg-slate-100 text-slate-500"><?php echo $u['role']; ?></span>
                        </td>
                        <td class="py-4">
                            <div class="flex justify-center space-x-2">
                                <a href="edit.php?type=lecturer&id=<?php echo $u['id']; ?>" class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center hover:bg-indigo-600 hover:text-white transition-all"><i class="fa-solid fa-pen text-xs"></i></a>
                                <a href="admin_actions.php?delete_user=<?php echo $u['id']; ?>" onclick="return confirm('Delete this user?')" class="w-8 h-8 rounded-lg bg-rose-50 text-rose-500 flex items-center justify-center hover:bg-rose-600 hover:text-white transition-all"><i class="fa-solid fa-trash text-xs"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($view == 'marks'):
        // ── ensure tables ─────────────────────────────────────────
        foreach (["CREATE TABLE IF NOT EXISTS `unit_assessments` (`id` INT AUTO_INCREMENT PRIMARY KEY, `unit_id` INT NOT NULL, `name` VARCHAR(100) NOT NULL, `type` ENUM('coursework','exam') NOT NULL DEFAULT 'coursework', `max_mark` DECIMAL(6,2) NOT NULL DEFAULT 100, `sort_order` TINYINT DEFAULT 0, `created_by` INT DEFAULT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY(`unit_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                   "CREATE TABLE IF NOT EXISTS `unit_marks` (`id` INT AUTO_INCREMENT PRIMARY KEY, `assessment_id` INT NOT NULL, `student_id` INT NOT NULL, `mark` DECIMAL(6,2) DEFAULT NULL, `remarks` VARCHAR(255) DEFAULT NULL, `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY(`assessment_id`,`student_id`), KEY(`student_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                   "CREATE TABLE IF NOT EXISTS `course_units` (`id` INT AUTO_INCREMENT PRIMARY KEY, `course_id` INT NOT NULL, `title` VARCHAR(255) NOT NULL, `unit_code` VARCHAR(50) DEFAULT NULL, `description` TEXT DEFAULT NULL, `lecturer_id` INT DEFAULT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY(`course_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                  ] as $tsql) mysqli_query($conn, $tsql);

        $filter_course  = intval($_GET['fcourse'] ?? 0);
        $filter_unit    = intval($_GET['funit'] ?? 0);
        $filter_grade   = strtoupper(trim($_GET['fgrade'] ?? ''));
        $filter_student = mysqli_real_escape_string($conn, trim($_GET['fstudent'] ?? ''));

        $grade_fn = function($pct) {
            if ($pct >= 70) return ['A','bg-emerald-100 text-emerald-700'];
            if ($pct >= 60) return ['B','bg-blue-100 text-blue-700'];
            if ($pct >= 50) return ['C','bg-indigo-100 text-indigo-700'];
            if ($pct >= 40) return ['D','bg-amber-100 text-amber-700'];
            return ['F','bg-red-100 text-red-700'];
        };

        // Build query — aggregate marks per student per unit
        $having = '';
        $student_where = '';
        if ($filter_student) $student_where = "AND (u.full_name LIKE '%$filter_student%' OR u.email LIKE '%$filter_student%')";

        $unit_where = '';
        if ($filter_unit > 0)   $unit_where   .= " AND cu.id = $filter_unit";
        if ($filter_course > 0) $unit_where   .= " AND cu.course_id = $filter_course";

        $marks_rows = [];
        $marks_qry = mysqli_query($conn,
            "SELECT u.id AS student_id, u.full_name, u.email,
                    cu.id AS unit_id, cu.title AS unit_title,
                    c.title AS course_title, c.id AS course_id,
                    lecturer.full_name AS lecturer_name,
                    SUM(um.mark) AS total_mark,
                    SUM(ua.max_mark) AS total_max,
                    ROUND(COALESCE(SUM(um.mark)/NULLIF(SUM(ua.max_mark),0)*100,0),1) AS pct,
                    COUNT(ua.id) AS assessment_count,
                    COUNT(um.id) AS graded_count,
                    /* exam: explicit type=exam OR name contains exam/final/end-of-term keywords */
                    ROUND(
                        SUM(CASE WHEN (ua.type='exam' OR LOWER(ua.name) REGEXP 'exam|final|end.?term|end.?year|end.?sem') THEN um.mark ELSE NULL END)
                        / NULLIF(SUM(CASE WHEN (ua.type='exam' OR LOWER(ua.name) REGEXP 'exam|final|end.?term|end.?year|end.?sem') THEN ua.max_mark ELSE NULL END), 0)
                        * 100
                    , 1) AS exam_pct,
                    /* coursework: explicit type=coursework AND name does NOT match exam keywords */
                    ROUND(
                        SUM(CASE WHEN ua.type='coursework' AND NOT (LOWER(ua.name) REGEXP 'exam|final|end.?term|end.?year|end.?sem') THEN um.mark ELSE NULL END)
                        / NULLIF(SUM(CASE WHEN ua.type='coursework' AND NOT (LOWER(ua.name) REGEXP 'exam|final|end.?term|end.?year|end.?sem') THEN ua.max_mark ELSE NULL END), 0)
                        * 100
                    , 1) AS cw_pct,
                    COUNT(CASE WHEN (ua.type='exam' OR LOWER(ua.name) REGEXP 'exam|final|end.?term|end.?year|end.?sem') AND um.id IS NOT NULL THEN 1 END) AS exam_graded_count,
                    COUNT(CASE WHEN ua.type='coursework' AND NOT (LOWER(ua.name) REGEXP 'exam|final|end.?term|end.?year|end.?sem') AND um.id IS NOT NULL THEN 1 END) AS cw_graded_count
             FROM enrollments e
             JOIN users u ON u.id = e.student_id
             JOIN course_units cu ON cu.course_id = e.course_id $unit_where
             JOIN courses c ON c.id = e.course_id
             LEFT JOIN users lecturer ON lecturer.id = cu.lecturer_id
             LEFT JOIN unit_assessments ua ON ua.unit_id = cu.id
             LEFT JOIN unit_marks um ON um.assessment_id = ua.id AND um.student_id = u.id
             WHERE 1=1 $student_where
             GROUP BY u.id, cu.id
             ORDER BY c.title ASC, cu.title ASC, u.full_name ASC"
        );
        while ($mr = mysqli_fetch_assoc($marks_qry)) {
            if ($filter_grade) {
                [$g] = $grade_fn(floatval($mr['pct']));
                if ($g !== $filter_grade && !($filter_grade === 'F' && floatval($mr['pct']) < 40)) continue;
                if ($filter_grade !== 'F' && $g !== $filter_grade) continue;
            }
            $marks_rows[] = $mr;
        }

        // ── Fallback: fill exam_pct from student_marks for rows that have no
        //    unit_assessments of type 'exam' (e.g. units that only have coursework).
        //    Build a course-level exam pct map from student_marks once, then apply.
        if (!empty($marks_rows)) {
            $fb_student_ids = array_unique(array_column($marks_rows, 'student_id'));
            $fb_course_ids  = array_unique(array_column($marks_rows, 'course_id'));
            $fb_sids_str    = implode(',', array_map('intval', $fb_student_ids));
            $fb_cids_str    = implode(',', array_map('intval', $fb_course_ids));
            $fb_res = mysqli_query($conn,
                "SELECT student_id, course_id,
                        exam_mark, exam_max, coursework_mark, coursework_max
                 FROM student_marks
                 WHERE student_id IN ($fb_sids_str) AND course_id IN ($fb_cids_str)"
            );
            $fb_exam = [];  // [student_id][course_id] = exam_pct
            while ($fb = mysqli_fetch_assoc($fb_res)) {
                $fs  = intval($fb['student_id']);
                $fc  = intval($fb['course_id']);
                $ep  = floatval($fb['exam_max'])       > 0
                    ? round(floatval($fb['exam_mark'])       / floatval($fb['exam_max'])       * 100, 1)
                    : null;
                if ($ep !== null) $fb_exam[$fs][$fc] = $ep;
            }
            foreach ($marks_rows as &$mr) {
                if (intval($mr['exam_graded_count']) === 0) {
                    $fs = intval($mr['student_id']);
                    $fc = intval($mr['course_id']);
                    if (isset($fb_exam[$fs][$fc])) {
                        $mr['exam_pct']          = $fb_exam[$fs][$fc];
                        $mr['exam_graded_count'] = 1;   // mark as having data
                    }
                }
            }
            unset($mr);
        }

        $graded_rows  = array_filter($marks_rows, fn($r) => intval($r['graded_count']) > 0);
        $total_graded = count($graded_rows);
        $avg_pct      = $total_graded > 0 ? round(array_sum(array_column($graded_rows,'pct'))/$total_graded,1) : 0;
        $fail_count   = count(array_filter($marks_rows, fn($r) => intval($r['graded_count']) > 0 && floatval($r['pct']) < 40));

        $all_courses_q = mysqli_query($conn, "SELECT id, title FROM courses ORDER BY title ASC");
        $units_for_filter = $filter_course > 0
            ? mysqli_query($conn, "SELECT id, title FROM course_units WHERE course_id = $filter_course ORDER BY title ASC")
            : null;
        ?>

        <div class="flex items-center justify-between mb-8">
            <div>
                <h2 class="text-2xl font-black text-slate-900">Student Marks — All Units</h2>
                <p class="text-slate-500 text-sm mt-1">View marks entered by lecturers across all course units.</p>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-white rounded-2xl border border-slate-100 p-5 text-center shadow-sm">
                <p class="text-[9px] font-black uppercase text-slate-400 mb-1">Total Records</p>
                <p class="text-3xl font-black text-slate-900"><?php echo count($marks_rows); ?></p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-100 p-5 text-center shadow-sm">
                <p class="text-[9px] font-black uppercase text-slate-400 mb-1">Graded</p>
                <p class="text-3xl font-black text-blue-600"><?php echo $total_graded; ?></p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-100 p-5 text-center shadow-sm">
                <p class="text-[9px] font-black uppercase text-slate-400 mb-1">Avg Score</p>
                <p class="text-3xl font-black <?php echo $avg_pct >= 50 ? 'text-emerald-600' : 'text-red-500'; ?>"><?php echo $avg_pct; ?>%</p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-100 p-5 text-center shadow-sm">
                <p class="text-[9px] font-black uppercase text-slate-400 mb-1">Failing</p>
                <p class="text-3xl font-black <?php echo $fail_count > 0 ? 'text-red-600' : 'text-emerald-600'; ?>"><?php echo $fail_count; ?></p>
            </div>
        </div>

        <!-- Filters -->
        <form method="GET" class="bg-white rounded-2xl border border-slate-100 p-5 mb-6 shadow-sm">
            <input type="hidden" name="view" value="marks">
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                <div>
                    <label class="text-[9px] font-black uppercase text-slate-400 mb-1 block">Course</label>
                    <select name="fcourse" onchange="this.form.submit()" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold outline-none">
                        <option value="0">All Courses</option>
                        <?php while ($acr = mysqli_fetch_assoc($all_courses_q)): ?>
                        <option value="<?php echo $acr['id']; ?>" <?php echo $filter_course == $acr['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($acr['title']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div>
                    <label class="text-[9px] font-black uppercase text-slate-400 mb-1 block">Unit</label>
                    <select name="funit" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold outline-none">
                        <option value="0">All Units</option>
                        <?php if ($units_for_filter): while ($uf = mysqli_fetch_assoc($units_for_filter)): ?>
                        <option value="<?php echo $uf['id']; ?>" <?php echo $filter_unit == $uf['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($uf['title']); ?></option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>
                <div>
                    <label class="text-[9px] font-black uppercase text-slate-400 mb-1 block">Grade</label>
                    <select name="fgrade" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold outline-none">
                        <option value="">All</option>
                        <?php foreach (['A','B','C','D','F'] as $g): ?>
                        <option value="<?php echo $g; ?>" <?php echo $filter_grade===$g?'selected':''; ?>><?php echo $g; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-[9px] font-black uppercase text-slate-400 mb-1 block">Student</label>
                    <input type="text" name="fstudent" value="<?php echo htmlspecialchars($filter_student); ?>" placeholder="Search…"
                        class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold outline-none">
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="flex-1 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-black text-[9px] uppercase rounded-xl tracking-widest transition-all">
                        <i class="fa-solid fa-filter mr-1"></i>Filter
                    </button>
                    <a href="?view=marks" class="flex-1 text-center py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 font-black text-[9px] uppercase rounded-xl transition-all">Reset</a>
                </div>
            </div>
        </form>

        <?php if (empty($marks_rows)): ?>
        <div class="bg-white rounded-2xl border border-slate-100 p-14 text-center shadow-sm">
            <i class="fa-solid fa-layer-group text-slate-200 text-4xl mb-3"></i>
            <p class="text-slate-500 font-bold">No marks records found.</p>
            <p class="text-slate-400 text-xs mt-1">Marks appear here once lecturers enter them via their Marks Manager, and students register for units.</p>
        </div>
        <?php else: ?>
        <div class="bg-white rounded-2xl border border-slate-100 overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead class="border-b border-slate-100 bg-slate-50">
                    <tr>
                        <th class="text-[9px] font-black uppercase text-slate-400 tracking-widest px-5 py-3">Student</th>
                        <th class="text-[9px] font-black uppercase text-slate-400 tracking-widest px-4 py-3">Unit</th>
                        <th class="text-[9px] font-black uppercase text-slate-400 tracking-widest px-4 py-3">Course</th>
                        <th class="text-[9px] font-black uppercase text-slate-400 tracking-widest px-4 py-3">Lecturer</th>
                        <th class="text-[9px] font-black uppercase text-slate-400 tracking-widest px-4 py-3 text-center">Total Marks</th>
                        <th class="text-[9px] font-black uppercase text-slate-400 tracking-widest px-4 py-3 text-center">Score %</th>
                        <th class="text-[9px] font-black uppercase text-red-400 tracking-widest px-4 py-3 text-center">Exam %</th>
                        <th class="text-[9px] font-black uppercase text-blue-400 tracking-widest px-4 py-3 text-center">C/Work %</th>
                        <th class="text-[9px] font-black uppercase text-slate-400 tracking-widest px-4 py-3 text-center">Grade</th>
                        <th class="text-[9px] font-black uppercase text-slate-400 tracking-widest px-4 py-3 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                <?php foreach ($marks_rows as $mr):
                    $has_marks   = intval($mr['graded_count']) > 0;
                    $has_exam    = intval($mr['exam_graded_count'] ?? 0) > 0;
                    $has_cw      = intval($mr['cw_graded_count']  ?? 0) > 0;
                    $pct         = floatval($mr['pct']);
                    $exam_pct    = floatval($mr['exam_pct'] ?? 0);
                    $cw_pct      = floatval($mr['cw_pct']  ?? 0);
                    [$grade_l, $grade_c] = $grade_fn($pct);
                ?>
                <tr class="hover:bg-slate-50/60 transition-colors">
                    <td class="px-5 py-3">
                        <p class="text-sm font-black text-slate-800"><?php echo htmlspecialchars($mr['full_name']); ?></p>
                        <p class="text-[9px] text-slate-400"><?php echo htmlspecialchars($mr['email']); ?></p>
                    </td>
                    <td class="px-4 py-3">
                        <span class="text-xs font-bold text-indigo-700 bg-indigo-50 px-2 py-1 rounded-lg"><?php echo htmlspecialchars($mr['unit_title']); ?></span>
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-500 font-semibold"><?php echo htmlspecialchars($mr['course_title']); ?></td>
                    <td class="px-4 py-3 text-xs text-slate-500 font-semibold">
                        <?php echo $mr['lecturer_name'] ? htmlspecialchars($mr['lecturer_name']) : '<span class="text-slate-300">—</span>'; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-sm font-black text-slate-800">
                        <?php if ($has_marks): ?>
                        <?php echo number_format($mr['total_mark'],1); ?> / <?php echo number_format($mr['total_max'],1); ?>
                        <?php else: ?><span class="text-slate-300">—</span><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($has_marks): ?>
                        <span class="text-base font-black <?php echo $pct >= 40 ? 'text-slate-900' : 'text-red-600'; ?>"><?php echo $pct; ?>%</span>
                        <?php else: ?><span class="text-slate-300">—</span><?php endif; ?>
                    </td>
                    <!-- Exam % column -->
                    <td class="px-4 py-3 text-center">
                        <?php if ($has_exam): ?>
                        <span class="inline-flex items-center gap-1 text-sm font-black <?php echo $exam_pct >= 40 ? 'text-red-700' : 'text-red-400'; ?>">
                            <?php echo $exam_pct; ?>%
                        </span>
                        <?php else: ?><span class="text-slate-300 text-xs">—</span><?php endif; ?>
                    </td>
                    <!-- Coursework % column -->
                    <td class="px-4 py-3 text-center">
                        <?php if ($has_cw): ?>
                        <span class="text-sm font-black <?php echo $cw_pct >= 40 ? 'text-blue-700' : 'text-blue-400'; ?>">
                            <?php echo $cw_pct; ?>%
                        </span>
                        <?php else: ?><span class="text-slate-300 text-xs">—</span><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($has_marks): ?>
                        <span class="inline-block px-3 py-1 rounded-xl text-xs font-black <?php echo $grade_c; ?>"><?php echo $grade_l; ?></span>
                        <?php else: ?><span class="text-slate-300">—</span><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if (!$has_marks): ?>
                        <span class="text-[9px] font-black uppercase bg-amber-50 text-amber-600 px-2 py-1 rounded-lg">Pending</span>
                        <?php elseif (intval($mr['graded_count']) < intval($mr['assessment_count'])): ?>
                        <span class="text-[9px] font-black uppercase bg-blue-50 text-blue-600 px-2 py-1 rounded-lg">Partial</span>
                        <?php else: ?>
                        <span class="text-[9px] font-black uppercase bg-emerald-50 text-emerald-600 px-2 py-1 rounded-lg">Complete</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <div class="px-5 py-3 border-t border-slate-100 bg-slate-50 text-[10px] font-bold text-slate-400 uppercase">
                <?php echo count($marks_rows); ?> record(s)
                <?php if ($filter_course || $filter_unit || $filter_grade || $filter_student): ?>
                · <a href="?view=marks" class="text-blue-500 hover:text-blue-700">Clear filters</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>


        <?php endif; /* end view==marks */ ?>

        <?php if ($view == 'performance'):
            $is_excelling  = ($filter === 'excelling');
            $having_clause = $is_excelling ? 'HAVING avg_m >= 75' : 'HAVING avg_m < 40';
            $order_clause  = $is_excelling ? 'ORDER BY avg_m DESC' : 'ORDER BY avg_m ASC';
            $page_title    = $is_excelling ? 'Excelling Students' : 'At-Risk Students';
            $page_sub      = $is_excelling
                ? 'Students with an average mastery of 75% or above across all skills.'
                : 'Students with an average mastery below 40% — require immediate attention.';
            $accent        = $is_excelling ? 'emerald' : 'rose';
            $icon          = $is_excelling ? 'fa-crown' : 'fa-triangle-exclamation';

            // Filtered students
            $perf_res = mysqli_query($conn,
                "SELECT u.id AS student_id, u.full_name, u.email,
                        u.career_path, ROUND(t.avg_m, 1) AS avg_mastery
                 FROM users u
                 JOIN (
                     SELECT student_id, AVG(mastery_level) AS avg_m
                     FROM student_mastery
                     GROUP BY student_id
                     $having_clause
                 ) t ON t.student_id = u.id
                 $order_clause"
            );
            $perf_students = [];
            while ($ps = mysqli_fetch_assoc($perf_res)) $perf_students[] = $ps;

            // Course / Unit / Lecturer details per student
            $perf_details = [];
            if (!empty($perf_students)) {
                $perf_ids = implode(',', array_column($perf_students, 'student_id'));
                $det_res  = mysqli_query($conn,
                    "SELECT e.student_id,
                            c.id   AS course_id,   c.title AS course_title,
                            cu.id  AS unit_id,     cu.title AS unit_title,   cu.unit_code,
                            lec.full_name AS lecturer_name
                     FROM enrollments e
                     JOIN courses c ON c.id = e.course_id
                     LEFT JOIN unit_registrations ur ON ur.student_id = e.student_id
                     LEFT JOIN course_units cu ON cu.id = ur.unit_id AND cu.course_id = c.id
                     LEFT JOIN users lec ON lec.id = cu.lecturer_id
                     WHERE e.student_id IN ($perf_ids)
                     ORDER BY e.student_id ASC, c.title ASC, cu.title ASC"
                );
                while ($d = mysqli_fetch_assoc($det_res)) {
                    $perf_details[intval($d['student_id'])][] = $d;
                }
            }
        ?>
        <!-- ── PERFORMANCE VIEW ── -->
        <div class="flex items-center justify-between mb-8">
            <div>
                <a href="?view=dashboard" class="text-[10px] font-black uppercase text-slate-400 hover:text-blue-500 mb-2 block">
                    <i class="fa-solid fa-arrow-left mr-1"></i> Back to Overview
                </a>
                <h2 class="text-2xl font-black text-slate-900 flex items-center gap-3">
                    <i class="fa-solid <?php echo $icon; ?> text-<?php echo $accent; ?>-500"></i>
                    <?php echo $page_title; ?>
                </h2>
                <p class="text-slate-500 text-sm mt-1"><?php echo $page_sub; ?></p>
            </div>
            <div class="flex gap-3">
                <a href="?view=performance&filter=excelling"
                   class="px-4 py-2 rounded-xl text-xs font-black uppercase transition-all <?php echo $is_excelling ? 'bg-emerald-600 text-white shadow-lg' : 'bg-white border border-slate-200 text-slate-500 hover:border-emerald-400'; ?>">
                    <i class="fa-solid fa-crown mr-1"></i>Doing Well (<?php echo $excellingCount; ?>)
                </a>
                <a href="?view=performance&filter=risk"
                   class="px-4 py-2 rounded-xl text-xs font-black uppercase transition-all <?php echo !$is_excelling ? 'bg-rose-600 text-white shadow-lg' : 'bg-white border border-slate-200 text-slate-500 hover:border-rose-400'; ?>">
                    <i class="fa-solid fa-triangle-exclamation mr-1"></i>At Risk (<?php echo $riskCount; ?>)
                </a>
            </div>
        </div>

        <?php if (empty($perf_students)): ?>
        <div class="bg-white rounded-[2rem] border border-dashed border-slate-200 p-16 text-center">
            <i class="fa-solid <?php echo $icon; ?> text-slate-200 text-5xl mb-4"></i>
            <p class="text-slate-500 font-bold">No students in this category yet.</p>
            <p class="text-slate-400 text-xs mt-1">Students appear here once they have completed quizzes and their mastery is calculated.</p>
        </div>
        <?php else: ?>
        <div class="space-y-4">
        <?php foreach ($perf_students as $ps):
            $sid      = intval($ps['student_id']);
            $details  = $perf_details[$sid] ?? [];
            $mastery  = floatval($ps['avg_mastery']);
            $bar_color = $mastery >= 75 ? 'bg-emerald-500' : ($mastery >= 40 ? 'bg-amber-500' : 'bg-rose-500');

            // Group details by course
            $by_course = [];
            foreach ($details as $d) {
                $cid = $d['course_id'];
                if (!isset($by_course[$cid])) {
                    $by_course[$cid] = ['title' => $d['course_title'], 'units' => []];
                }
                if ($d['unit_id']) {
                    $uid = $d['unit_id'];
                    if (!isset($by_course[$cid]['units'][$uid])) {
                        $by_course[$cid]['units'][$uid] = [
                            'title'    => $d['unit_title'],
                            'code'     => $d['unit_code'],
                            'lecturer' => $d['lecturer_name'],
                        ];
                    }
                }
            }
        ?>
        <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
            <!-- Student header -->
            <div class="px-6 py-5 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr <?php echo $is_excelling ? 'from-emerald-400 to-teal-500' : 'from-rose-400 to-pink-500'; ?> flex items-center justify-center shadow-sm flex-shrink-0">
                        <span class="text-white font-black text-sm"><?php echo strtoupper(mb_substr($ps['full_name'], 0, 2)); ?></span>
                    </div>
                    <div>
                        <p class="font-black text-slate-900"><?php echo htmlspecialchars($ps['full_name']); ?></p>
                        <p class="text-[10px] text-slate-400 mt-0.5"><?php echo htmlspecialchars($ps['email']); ?>
                            <?php if ($ps['career_path']): ?>
                            &nbsp;·&nbsp; <span class="text-indigo-500 font-bold"><?php echo htmlspecialchars($ps['career_path']); ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="flex flex-col items-end gap-1">
                    <span class="text-2xl font-black <?php echo $mastery >= 75 ? 'text-emerald-600' : ($mastery >= 40 ? 'text-amber-600' : 'text-rose-600'); ?>">
                        <?php echo $mastery; ?>%
                    </span>
                    <p class="text-[9px] font-black uppercase text-slate-400">Avg Mastery</p>
                    <div class="w-28 h-1.5 bg-slate-100 rounded-full overflow-hidden mt-1">
                        <div class="h-full <?php echo $bar_color; ?> rounded-full" style="width:<?php echo min(100,$mastery); ?>%"></div>
                    </div>
                </div>
            </div>

            <!-- Course / Unit / Lecturer breakdown -->
            <?php if (!empty($by_course)): ?>
            <div class="border-t border-slate-100 divide-y divide-slate-50">
                <?php foreach ($by_course as $course_row): ?>
                <div class="px-6 py-3">
                    <p class="text-[9px] font-black uppercase text-slate-500 tracking-widest mb-2">
                        <i class="fa-solid fa-graduation-cap mr-1 text-indigo-400"></i>
                        <?php echo htmlspecialchars($course_row['title']); ?>
                    </p>
                    <?php if (!empty($course_row['units'])): ?>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($course_row['units'] as $u): ?>
                        <div class="flex items-center gap-2 bg-slate-50 border border-slate-100 rounded-xl px-3 py-2">
                            <div class="w-6 h-6 bg-indigo-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-book text-indigo-500 text-[9px]"></i>
                            </div>
                            <div>
                                <p class="text-xs font-black text-slate-800">
                                    <?php echo htmlspecialchars($u['title']); ?>
                                    <?php if ($u['code']): ?>
                                    <span class="text-[8px] font-bold text-blue-500 bg-blue-50 px-1.5 py-0.5 rounded ml-1"><?php echo htmlspecialchars($u['code']); ?></span>
                                    <?php endif; ?>
                                </p>
                                <?php if ($u['lecturer']): ?>
                                <p class="text-[9px] text-emerald-600 font-bold mt-0.5">
                                    <i class="fa-solid fa-chalkboard-teacher mr-0.5"></i><?php echo htmlspecialchars($u['lecturer']); ?>
                                </p>
                                <?php else: ?>
                                <p class="text-[9px] text-amber-500 font-bold mt-0.5">
                                    <i class="fa-solid fa-circle-exclamation mr-0.5"></i>No lecturer assigned
                                </p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-[9px] text-slate-400 italic">No units registered in this course.</p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="border-t border-slate-100 px-6 py-3">
                <p class="text-[9px] text-slate-400 italic">Not enrolled in any course yet.</p>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php endif; /* end view==performance */ ?>
    </main>

    <div id="lecturerModal" style="display:none;" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-6">
        <div class="bg-white w-full max-w-md p-8 rounded-[2.5rem] shadow-2xl">
            <h3 class="text-xl font-black text-slate-800 mb-6">Register New Lecturer</h3>
            <form action="admin_actions.php" method="POST" class="space-y-4">
                <input type="hidden" name="action_type" value="add_lecturer">
                <input type="text" name="full_name" placeholder="Full Name" required class="w-full p-4 bg-slate-50 rounded-2xl border-none outline-none focus:ring-2 focus:ring-blue-500 font-medium">
                <input type="email" name="email" placeholder="Email Address" required class="w-full p-4 bg-slate-50 rounded-2xl border-none outline-none focus:ring-2 focus:ring-blue-500 font-medium">
                <select name="course_id" class="w-full p-4 bg-slate-50 rounded-2xl border-none outline-none font-medium">
                    <option value="">Assign Initial Course (Optional)</option>
                    <?php 
                    $clist = mysqli_query($conn, "SELECT id, title FROM courses");
                    while($c = mysqli_fetch_assoc($clist)) echo "<option value='{$c['id']}'>{$c['title']}</option>";
                    ?>
                </select>
                <input type="password" name="password" placeholder="Temporary Password" required class="w-full p-4 bg-slate-50 rounded-2xl border-none outline-none focus:ring-2 focus:ring-blue-500 font-medium">
                <div class="flex space-x-3 pt-4">
                    <button type="button" onclick="toggleModal('lecturerModal')" class="flex-1 bg-slate-100 text-slate-500 py-4 rounded-2xl font-black uppercase text-[10px] tracking-widest">Cancel</button>
                    <button type="submit" class="flex-1 bg-blue-600 text-white py-4 rounded-2xl font-black uppercase text-[10px] tracking-widest shadow-lg shadow-blue-600/20">Create Account</button>
                </div>
            </form>
        </div>
    </div>

    <div id="courseModal" style="display:none;" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-6">
        <div class="bg-white w-full max-w-md p-8 rounded-[2.5rem] shadow-2xl">
            <h3 class="text-xl font-black text-slate-800 mb-6">Create New Course</h3>
            <form action="admin_actions.php" method="POST" class="space-y-4">
                <input type="hidden" name="action_type" value="add_course">
                <input type="text" name="title" placeholder="Course Title (e.g. Advanced AI)" required class="w-full p-4 bg-slate-50 rounded-2xl border-none outline-none focus:ring-2 focus:ring-blue-500 font-medium">
                <div class="flex space-x-3 pt-4">
                    <button type="button" onclick="toggleModal('courseModal')" class="flex-1 bg-slate-100 text-slate-500 py-4 rounded-2xl font-black uppercase text-[10px] tracking-widest">Cancel</button>
                    <button type="submit" class="flex-1 bg-blue-600 text-white py-4 rounded-2xl font-black uppercase text-[10px] tracking-widest shadow-lg shadow-blue-600/20">Confirm</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleModal(id) {
            const modal = document.getElementById(id);
            modal.style.display = (modal.style.display === "none") ? "flex" : "none";
        }
    </script>
</body>
</html>