<?php
/**
 * recommendation_history.php — "Recommendation History" for a student.
 * Shows every AI recommendation the adaptive engine has generated for
 * the logged-in student, with the reason (quiz + score) behind it and
 * whether the student marked it helpful.
 */

include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['student', 'lecturer', 'admin'])) {
    header("Location: index.php"); exit();
}

// Students see their own history; lecturers/admins can view a specific student's via ?student_id=
$student_id = $role === 'student' ? intval($_SESSION['user_id']) : intval($_GET['student_id'] ?? 0);
if (!$student_id) { echo "Student not specified."; exit(); }

@mysqli_query($conn, "ALTER TABLE results ADD COLUMN IF NOT EXISTS helpful ENUM('yes','no') DEFAULT NULL");

$student = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT full_name, admission_number FROM users WHERE id = $student_id AND role='student' LIMIT 1"
));
if (!$student) { echo "Student not found."; exit(); }

$rows_res = mysqli_query($conn,
    "SELECT r.id, r.score, r.recommendation, r.action_taken, r.performance_band,
            r.predicted_grade, r.attempt_no, r.created_at, r.helpful,
            q.title AS quiz_title, q.skill_name
     FROM results r
     LEFT JOIN quizzes q ON q.id = r.quiz_id
     WHERE r.student_id = $student_id
     ORDER BY r.created_at DESC"
);
$rows = [];
while ($r = mysqli_fetch_assoc($rows_res)) $rows[] = $r;

$total    = count($rows);
$helpful  = count(array_filter($rows, fn($r) => $r['helpful'] === 'yes'));
$notHelp  = count(array_filter($rows, fn($r) => $r['helpful'] === 'no'));
$rated    = $helpful + $notHelp;
$accPct   = $rated > 0 ? round($helpful / $rated * 100, 1) : 0;

$backLink = $role === 'student' ? 'student_dashboard.php' : ($role === 'lecturer' ? 'lecturer_dashboard.php' : 'admin_dashboard.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Recommendation History — <?php echo htmlspecialchars($student['full_name']); ?></title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  * { box-sizing: border-box; font-family: 'DM Sans', sans-serif; }
  body { background: #f8fafc; color: #1e293b; margin: 0; padding: 40px 20px; }
  .wrap { max-width: 900px; margin: 0 auto; }
  .back { font-size: 11px; font-weight: 800; text-transform: uppercase; color: #94a3b8; text-decoration: none; }
  h1 { font-size: 24px; font-weight: 900; margin: 8px 0 4px; }
  .sub { color: #64748b; font-size: 13px; margin-bottom: 24px; }
  .stats { display: flex; gap: 14px; margin-bottom: 24px; flex-wrap: wrap; }
  .stat { background: white; border: 1px solid #e2e8f0; border-radius: 16px; padding: 16px 20px; flex: 1; min-width: 140px; }
  .stat .num { font-size: 22px; font-weight: 900; }
  .stat .lbl { font-size: 10px; text-transform: uppercase; font-weight: 800; color: #94a3b8; letter-spacing: 0.05em; }
  table { width: 100%; border-collapse: collapse; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
  th { text-align: left; font-size: 10px; text-transform: uppercase; font-weight: 800; color: #94a3b8; padding: 12px 16px; border-bottom: 1px solid #f1f5f9; }
  td { padding: 14px 16px; border-bottom: 1px solid #f8fafc; font-size: 12px; vertical-align: top; }
  .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 9px; font-weight: 800; text-transform: uppercase; }
  .badge-advance { background: #d1fae5; color: #047857; }
  .badge-retry { background: #fef3c7; color: #b45309; }
  .badge-remedial { background: #fee2e2; color: #b91c1c; }
  .helpful-yes { color: #059669; font-weight: 800; font-size: 10px; }
  .helpful-no { color: #dc2626; font-weight: 800; font-size: 10px; }
  .helpful-none { color: #cbd5e1; font-weight: 700; font-size: 10px; }
  .empty { text-align: center; padding: 40px; color: #94a3b8; }
  .rate-btn { border: 1px solid #e2e8f0; background: white; border-radius: 8px; padding: 4px 10px; font-size: 10px; font-weight: 800; text-transform: uppercase; cursor: pointer; margin-right: 4px; font-family: inherit; }
  .rate-btn.yes:hover, .rate-btn.yes.on { background: #d1fae5; border-color: #34d399; color: #047857; }
  .rate-btn.no:hover,  .rate-btn.no.on  { background: #fee2e2; border-color: #f87171; color: #b91c1c; }
  .rate-note { font-size: 9px; color: #94a3b8; margin-top: 4px; }
</style>
</head>
<body>
<div class="wrap">
  <a href="<?php echo $backLink; ?>" class="back"><i class="fa-solid fa-arrow-left"></i> Back</a>
  <h1>Recommendation History</h1>
  <p class="sub"><?php echo htmlspecialchars($student['full_name']); ?><?php echo $student['admission_number'] ? ' · ' . htmlspecialchars($student['admission_number']) : ''; ?></p>

  <div class="stats">
    <div class="stat"><div class="num"><?php echo $total; ?></div><div class="lbl">Recommendations Generated</div></div>
    <div class="stat"><div class="num"><?php echo $helpful; ?> / <?php echo $rated; ?></div><div class="lbl">Marked Helpful</div></div>
    <div class="stat"><div class="num"><?php echo $accPct; ?>%</div><div class="lbl">Acceptance Rate</div></div>
  </div>

  <?php if (empty($rows)): ?>
    <div class="empty">No recommendations have been generated for this student yet.</div>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Recommendation</th>
        <th>Reason</th>
        <th>Helpful?</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r):
          $badgeClass = 'badge-' . ($r['action_taken'] ?: 'retry');
          $reason = ($r['skill_name'] ?: 'General') . ' — ' . ($r['quiz_title'] ?: 'Quiz') . ' — Score ' . rtrim(rtrim(number_format($r['score'], 1), '0'), '.') . '%'
                  . ($r['attempt_no'] > 1 ? ' (attempt ' . $r['attempt_no'] . ')' : '');
      ?>
      <tr>
        <td><?php echo date('d M Y', strtotime($r['created_at'])); ?></td>
        <td>
          <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars(ucfirst($r['action_taken'] ?: '—')); ?></span>
          <div style="margin-top:6px;"><?php echo htmlspecialchars($r['recommendation']); ?></div>
        </td>
        <td><?php echo htmlspecialchars($reason); ?></td>
        <td id="rate-cell-<?php echo (int)$r['id']; ?>">
          <?php if ($role === 'student'): ?>
            <button type="button" class="rate-btn yes <?php echo $r['helpful']==='yes' ? 'on' : ''; ?>" onclick="rateRec(<?php echo (int)$r['id']; ?>,'yes')"><i class="fa-solid fa-thumbs-up"></i> Yes</button><button type="button" class="rate-btn no <?php echo $r['helpful']==='no' ? 'on' : ''; ?>" onclick="rateRec(<?php echo (int)$r['id']; ?>,'no')"><i class="fa-solid fa-thumbs-down"></i> No</button>
            <div class="rate-note" id="rate-note-<?php echo (int)$r['id']; ?>"><?php echo $r['helpful'] ? 'Your vote is saved — click to change it.' : 'Was this recommendation helpful?'; ?></div>
          <?php elseif ($r['helpful'] === 'yes'): ?>
            <span class="helpful-yes"><i class="fa-solid fa-thumbs-up"></i> Yes</span>
          <?php elseif ($r['helpful'] === 'no'): ?>
            <span class="helpful-no"><i class="fa-solid fa-thumbs-down"></i> No</span>
          <?php else: ?>
            <span class="helpful-none">Not rated</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<script>
function rateRec(id, vote) {
  fetch('record_feedback.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'result_id=' + encodeURIComponent(id) + '&helpful=' + encodeURIComponent(vote)
  }).then(r => r.json()).then(d => {
    const cell = document.getElementById('rate-cell-' + id);
    const note = document.getElementById('rate-note-' + id);
    if (d.ok) {
      cell.querySelectorAll('.rate-btn').forEach(b => b.classList.remove('on'));
      cell.querySelector('.rate-btn.' + vote).classList.add('on');
      note.textContent = 'Thanks — your vote is saved.';
    } else { note.textContent = 'Could not save: ' + (d.error || 'try again'); }
  }).catch(() => { document.getElementById('rate-note-' + id).textContent = 'Network error — try again.'; });
}
</script>
</body>
</html>
