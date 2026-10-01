<?php
/**
 * risk_dashboard.php — "Clear At-Risk Dashboard"
 * Shows STUDENT PERFORMANCE distribution (Excellent / Good / At Risk /
 * Critical) plus a detailed "View At-Risk Students" table with
 * attendance %, quiz average %, assignment % and risk level.
 *
 * Admins see the whole institution; lecturers see only their own courses.
 */

include 'config.php';
include 'ai_engine.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'lecturer'])) {
    header("Location: index.php"); exit();
}

$course_ids = null; // null = no scoping (admin sees everyone)
if ($role === 'lecturer') {
    $lecturer_id = intval($_SESSION['user_id']);
    $courses_res = mysqli_query($conn,
        "SELECT DISTINCT c.id FROM courses c
         WHERE c.lecturer_id = $lecturer_id
            OR c.id = (SELECT course_id FROM users WHERE id = $lecturer_id AND course_id IS NOT NULL LIMIT 1)
            OR c.id IN (SELECT cu.course_id FROM course_units cu WHERE cu.lecturer_id = $lecturer_id)"
    );
    $course_ids = [];
    while ($r = mysqli_fetch_assoc($courses_res)) $course_ids[] = intval($r['id']);
}

$data         = getRiskDashboardData($conn, $course_ids);
$distribution = $data['distribution'];
$students     = $data['students'];
$atRiskOnly   = array_values(array_filter($students, fn($s) => in_array($s['band'], ['at_risk', 'critical'])));

$showFilter = $_GET['filter'] ?? 'at_risk'; // 'at_risk' or 'all'
$rowsToShow = $showFilter === 'all' ? $students : $atRiskOnly;

$backLink = $role === 'admin' ? 'admin_dashboard.php' : 'lecturer_dashboard.php';

function pctCell($v) {
    if ($v === null) return '<span style="color:#cbd5e1;">—</span>';
    $color = $v >= 70 ? '#059669' : ($v >= 40 ? '#b45309' : '#dc2626');
    return '<span style="color:' . $color . ';font-weight:800;">' . $v . '%</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Student Performance — Risk Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  * { box-sizing: border-box; font-family: 'DM Sans', sans-serif; }
  body { background: #f8fafc; color: #1e293b; margin: 0; padding: 40px 20px; }
  .wrap { max-width: 1000px; margin: 0 auto; }
  .back { font-size: 11px; font-weight: 800; text-transform: uppercase; color: #94a3b8; text-decoration: none; }
  h1 { font-size: 24px; font-weight: 900; margin: 8px 0 4px; }
  .sub { color: #64748b; font-size: 13px; margin-bottom: 24px; }
  .dist-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 28px; }
  .dist-card { background: white; border-radius: 18px; padding: 20px; border: 1px solid #e2e8f0; text-align: center; }
  .dist-card .num { font-size: 32px; font-weight: 900; }
  .dist-card .lbl { font-size: 10px; text-transform: uppercase; font-weight: 800; letter-spacing: 0.05em; margin-top: 4px; }
  .c-excellent { color: #059669; } .c-good { color: #4f46e5; } .c-atrisk { color: #d97706; } .c-critical { color: #dc2626; }
  .toggle { display: flex; gap: 10px; margin-bottom: 16px; }
  .toggle a { padding: 8px 16px; border-radius: 10px; font-size: 11px; font-weight: 800; text-transform: uppercase; text-decoration: none; border: 1px solid #e2e8f0; color: #64748b; background: white; }
  .toggle a.active { background: #1e293b; color: white; border-color: #1e293b; }
  .search-wrap { position: relative; margin-bottom: 16px; }
  .search-wrap i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 12px; }
  .search-wrap input { width: 100%; padding: 10px 14px 10px 36px; border-radius: 12px; border: 1px solid #e2e8f0; font-size: 13px; font-family: 'DM Sans', sans-serif; background: white; outline: none; }
  .search-wrap input:focus { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,0.1); }
  table { width: 100%; border-collapse: collapse; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
  th { text-align: left; font-size: 10px; text-transform: uppercase; font-weight: 800; color: #94a3b8; padding: 12px 16px; border-bottom: 1px solid #f1f5f9; }
  td { padding: 12px 16px; border-bottom: 1px solid #f8fafc; font-size: 12px; }
  .risk-badge { padding: 3px 10px; border-radius: 999px; font-size: 9px; font-weight: 900; text-transform: uppercase; }
  .risk-HIGH { background: #fee2e2; color: #b91c1c; }
  .risk-MEDIUM { background: #fef3c7; color: #b45309; }
  .risk-LOW { background: #d1fae5; color: #047857; }
  .empty { text-align: center; padding: 40px; color: #94a3b8; }
</style>
</head>
<body>
<div class="wrap">
  <a href="<?php echo $backLink; ?>" class="back"><i class="fa-solid fa-arrow-left"></i> Back</a>
  <h1>Student Performance</h1>
  <p class="sub"><?php echo $role === 'admin' ? 'Institution-wide view' : 'Your enrolled students'; ?> — combining quiz mastery, attendance and assignment completion.</p>

  <div class="dist-grid">
    <div class="dist-card"><div class="num c-excellent"><?php echo $distribution['excellent']; ?></div><div class="lbl c-excellent">Excellent</div></div>
    <div class="dist-card"><div class="num c-good"><?php echo $distribution['good']; ?></div><div class="lbl c-good">Good</div></div>
    <div class="dist-card"><div class="num c-atrisk"><?php echo $distribution['at_risk']; ?></div><div class="lbl c-atrisk">At Risk</div></div>
    <div class="dist-card"><div class="num c-critical"><?php echo $distribution['critical']; ?></div><div class="lbl c-critical">Critical</div></div>
  </div>

  <div class="toggle">
    <a href="?filter=at_risk" class="<?php echo $showFilter === 'at_risk' ? 'active' : ''; ?>">View At-Risk Students (<?php echo count($atRiskOnly); ?>)</a>
    <a href="?filter=all" class="<?php echo $showFilter === 'all' ? 'active' : ''; ?>">View All Students (<?php echo count($students); ?>)</a>
  </div>

  <?php if (!empty($rowsToShow)): ?>
  <div class="search-wrap">
    <i class="fa-solid fa-magnifying-glass"></i>
    <input type="text" id="student-search" placeholder="Search by name or email…" autocomplete="off"
           onkeyup="filterStudentRows(this.value)">
  </div>
  <?php endif; ?>

  <?php if (empty($rowsToShow)): ?>
    <div class="empty">No students to show in this view.</div>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>Student</th>
        <th>Attendance</th>
        <th>Quiz Avg</th>
        <th>Assignment</th>
        <th>Risk</th>
      </tr>
    </thead>
    <tbody id="student-rows">
      <?php foreach ($rowsToShow as $s): ?>
      <tr data-search="<?php echo htmlspecialchars(strtolower($s['name'] . ' ' . $s['email'])); ?>">
        <td><strong><?php echo htmlspecialchars($s['name']); ?></strong><div style="color:#94a3b8;font-size:10px;"><?php echo htmlspecialchars($s['email']); ?></div></td>
        <td><?php echo pctCell($s['attendance']); ?></td>
        <td><?php echo pctCell($s['quiz_avg']); ?></td>
        <td><?php echo pctCell($s['assignment']); ?></td>
        <td><span class="risk-badge risk-<?php echo $s['risk']; ?>"><?php echo $s['risk']; ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="empty" id="no-search-results" style="display:none;">No students match your search.</p>
  <?php endif; ?>
</div>
<script>
function filterStudentRows(query) {
    query = query.trim().toLowerCase();
    const rows = document.querySelectorAll('#student-rows tr');
    let visibleCount = 0;
    rows.forEach(row => {
        const match = row.dataset.search.includes(query);
        row.style.display = match ? '' : 'none';
        if (match) visibleCount++;
    });
    document.getElementById('no-search-results').style.display = visibleCount === 0 ? 'block' : 'none';
}
</script>
</body>
</html>
