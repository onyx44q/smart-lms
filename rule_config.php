<?php
/**
 * rule_config.php — "Rule Configuration" / Rule Information page.
 * Read-only view of the live thresholds the rule-based AI engine
 * (Ai_engine.php) runs on, for demonstration purposes.
 */

include 'config.php';
include 'ai_engine.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'lecturer'])) {
    header("Location: index.php"); exit();
}

$rules    = getRuleConfiguration();
$backLink = $role === 'admin' ? 'admin_dashboard.php' : 'lecturer_dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Rule Configuration — SmartLMS Adaptive Engine</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  * { box-sizing: border-box; font-family: 'DM Sans', sans-serif; }
  body { background: #f8fafc; color: #1e293b; margin: 0; padding: 40px 20px; }
  .wrap { max-width: 820px; margin: 0 auto; }
  .back { font-size: 11px; font-weight: 800; text-transform: uppercase; color: #94a3b8; text-decoration: none; }
  h1 { font-size: 24px; font-weight: 900; margin: 8px 0 4px; }
  .sub { color: #64748b; font-size: 13px; margin-bottom: 28px; }
  .section-title { font-size: 12px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.05em; color: #4f46e5; margin: 28px 0 12px; display: flex; align-items: center; gap: 8px; }
  table { width: 100%; border-collapse: collapse; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
  th { text-align: left; font-size: 10px; text-transform: uppercase; font-weight: 800; color: #94a3b8; padding: 12px 16px; border-bottom: 1px solid #f1f5f9; }
  td { padding: 13px 16px; border-bottom: 1px solid #f8fafc; font-size: 12.5px; }
  tr:last-child td { border-bottom: none; }
  .cond { font-weight: 800; color: #1e293b; }
  .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 9px; font-weight: 900; text-transform: uppercase; background: #eef2ff; color: #4338ca; }
  .note { font-size: 11px; color: #94a3b8; margin-top: 6px; }
  .footer-note { margin-top: 28px; padding: 16px 20px; background: #eef2ff; border-radius: 14px; font-size: 12px; color: #4338ca; }
</style>
</head>
<body>
<div class="wrap">
  <a href="<?php echo $backLink; ?>" class="back"><i class="fa-solid fa-arrow-left"></i> Back</a>
  <h1>Rule Configuration</h1>
  <p class="sub">SmartLMS's adaptive learning engine is 100% rule-based — no external AI API. These are the exact thresholds currently driving every recommendation.</p>

  <div class="section-title"><i class="fa-solid fa-gauge-high"></i> Quiz Score Rules</div>
  <table>
    <thead><tr><th>Condition</th><th>Resulting Action</th><th>Effect</th></tr></thead>
    <tbody>
      <?php foreach ($rules['quiz_rules'] as $r): ?>
      <tr>
        <td class="cond"><?php echo htmlspecialchars($r['condition']); ?></td>
        <td><span class="badge"><?php echo htmlspecialchars($r['action']); ?></span></td>
        <td><?php echo htmlspecialchars($r['detail']); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="section-title"><i class="fa-solid fa-chart-simple"></i> Performance Band Rules</div>
  <table>
    <thead><tr><th>Condition</th><th>Band</th></tr></thead>
    <tbody>
      <?php foreach ($rules['performance_bands'] as $r): ?>
      <tr>
        <td class="cond"><?php echo htmlspecialchars($r['condition']); ?></td>
        <td><span class="badge"><?php echo htmlspecialchars($r['band']); ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="section-title"><i class="fa-solid fa-triangle-exclamation"></i> Other Automated Rules</div>
  <table>
    <thead><tr><th>Condition</th><th>Action</th></tr></thead>
    <tbody>
      <?php foreach ($rules['other_rules'] as $r): ?>
      <tr>
        <td class="cond"><?php echo htmlspecialchars($r['condition']); ?></td>
        <td><?php echo htmlspecialchars($r['action']); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="note">Mastery is bounded between <?php echo $rules['mastery_bounds']['floor']; ?>% and <?php echo $rules['mastery_bounds']['cap']; ?>% for every skill.</p>

  <div class="footer-note">
    <i class="fa-solid fa-circle-info"></i>
    This page is read-only by design — it exists to make the intelligent component's logic transparent and easy to demonstrate. Rules are defined as constants in <code>Ai_engine.php</code>.
  </div>
</div>
</body>
</html>
