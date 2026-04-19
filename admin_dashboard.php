<?php 
include 'config.php'; 
checkRole('admin'); 

// --- 1. DATA AGGREGATION QUERIES ---
// Basic Counts
$studentCount = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM users WHERE role='student'"))['total'] ?? 0;
$lecturerCount = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM users WHERE role='lecturer'"))['total'] ?? 0;
$courseCount = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM courses"))['total'] ?? 0;

// Performance Metrics
$excellingCount = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT student_id) as total FROM student_mastery WHERE mastery_level >= 75"))['total'] ?? 0;
$riskCount = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT student_id) as total FROM student_mastery WHERE mastery_level < 40"))['total'] ?? 0;
$avgMastery = mysqli_fetch_assoc(mysqli_query($conn, "SELECT AVG(mastery_level) as avg FROM student_mastery"))['avg'] ?? 0;

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