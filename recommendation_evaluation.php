<?php
/**
 * recommendation_evaluation.php — Aggregate report for the "Was this
 * recommendation helpful?" feedback captured on the student dashboard.
 * Reports: # recommendations generated, # accepted/helpful, acceptance %.
 */

include 'config.php';
include 'ai_engine.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'lecturer'])) {
    header("Location: index.php"); exit();
}

$course_ids = null;
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

$eval = getRecommendationEvaluation($conn, $course_ids);

// Breakdown by action type
$action_filter = '';
if ($course_ids !== null) {
    if (empty($course_ids)) {
        $action_rows = [];
    } else {
        $ids_str = implode(',', $course_ids);
        $action_filter = "JOIN quizzes q ON q.id = r.quiz_id AND q.course_id IN ($ids_str)";
    }
}
if (!isset($action_rows)) {
    @mysqli_query($conn, "ALTER TABLE results ADD COLUMN IF NOT EXISTS helpful ENUM('yes','no') DEFAULT NULL");
    $ar_res = mysqli_query($conn,
        "SELECT r.action_taken,
                COUNT(*) AS total,
                SUM(CASE WHEN r.helpful='yes' THEN 1 ELSE 0 END) AS helpful,
                SUM(CASE WHEN r.helpful='no'  THEN 1 ELSE 0 END) AS not_helpful
         FROM results r $action_filter
         GROUP BY r.action_taken"
    );
    $action_rows = [];
    while ($r = mysqli_fetch_assoc($ar_res)) $action_rows[] = $r;
}

$backLink = $role === 'admin' ? 'admin_dashboard.php' : 'lecturer_dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Recommendation Evaluation — SmartLMS</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  * { box-sizing: border-box; font-family: 'DM Sans', sans-serif; }
  body { background: #f8fafc; color: #1e293b; margin: 0; padding: 40px 20px; }
  .wrap { max-width: 820px; margin: 0 auto; }
  .back { font-size: 11px; font-weight: 800; text-transform: uppercase; color: #94a3b8; text-decoration: none; }
  h1 { font-size: 24px; font-weight: 900; margin: 8px 0 4px; }
  .sub { color: #64748b; font-size: 13px; margin-bottom: 24px; }
  .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 28px; }
  .stat { background: white; border-radius: 18px; padding: 20px; border: 1px solid #e2e8f0; text-align: center; }
  .stat .num { font-size: 26px; font-weight: 900; color: #1e293b; }
  .stat .lbl { font-size: 9.5px; text-transform: uppercase; font-weight: 800; color: #94a3b8; letter-spacing: 0.05em; margin-top: 4px; }
  table { width: 100%; border-collapse: collapse; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
  th { text-align: left; font-size: 10px; text-transform: uppercase; font-weight: 800; color: #94a3b8; padding: 12px 16px; border-bottom: 1px solid #f1f5f9; }
  td { padding: 12px 16px; border-bottom: 1px solid #f8fafc; font-size: 12.5px; }
  .bar-wrap { background: #f1f5f9; border-radius: 999px; height: 8px; width: 100%; overflow: hidden; }
  .bar-fill { background: linear-gradient(90deg,#34d399,#059669); height: 100%; }
</style>
</head>
<body>
<div class="wrap">
  <a href="<?php echo $backLink; ?>" class="back"><i class="fa-solid fa-arrow-left"></i> Back</a>
  <h1>Recommendation Evaluation</h1>
  <p class="sub">Measures whether students found the AI's recommendations useful — based on the "Was this recommendation helpful?" prompt shown after each quiz.</p>

  <div class="stats">
    <div class="stat"><div class="num"><?php echo $eval['generated']; ?></div><div class="lbl">Recommendations Generated</div></div>
    <div class="stat"><div class="num" style="color:#059669;"><?php echo $eval['helpful']; ?></div><div class="lbl">Marked Helpful</div></div>
    <div class="stat"><div class="num" style="color:#dc2626;"><?php echo $eval['not_helpful']; ?></div><div class="lbl">Marked Not Helpful</div></div>
    <div class="stat"><div class="num" style="color:#4f46e5;"><?php echo $eval['acceptance_pct']; ?>%</div><div class="lbl">Acceptance Rate</div></div>
  </div>

  <?php if (!empty($action_rows)): ?>
  <table>
    <thead><tr><th>Recommendation Type</th><th>Generated</th><th>Helpful</th><th>Acceptance</th></tr></thead>
    <tbody>
      <?php foreach ($action_rows as $ar):
          $t = intval($ar['total']); $h = intval($ar['helpful']); $n = intval($ar['not_helpful']);
          $rated = $h + $n;
          $pct = $rated > 0 ? round($h / $rated * 100, 1) : 0;
      ?>
      <tr>
        <td><strong><?php echo htmlspecialchars(ucfirst($ar['action_taken'] ?: 'Unspecified')); ?></strong></td>
        <td><?php echo $t; ?></td>
        <td><?php echo $h; ?> / <?php echo $rated; ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:8px;">
            <div class="bar-wrap"><div class="bar-fill" style="width:<?php echo $pct; ?>%;"></div></div>
            <span style="font-weight:800;font-size:11px;"><?php echo $pct; ?>%</span>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <p class="sub" style="margin-top:20px;"><?php echo $eval['unrated']; ?> recommendation<?php echo $eval['unrated'] != 1 ? 's have' : ' has'; ?> not yet been rated by the student.</p>
</div>
</body>
</html>
