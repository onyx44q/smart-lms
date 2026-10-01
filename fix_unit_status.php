<?php
// ═══════════════════════════════════════════════════
// SMART LMS — FIX: "Unit status stuck at In Progress"
// Upload to project root → visit in browser once → done
// Safe to run multiple times (checks before altering).
// ═══════════════════════════════════════════════════
$conn = new mysqli("localhost", "root", "", "smart_lms");
$log  = [];
$ok   = true;

function step($conn, &$log, &$ok, $label, $sql) {
    $r = mysqli_query($conn, $sql);
    if ($r) {
        $log[] = ['ok', $label];
    } else {
        $log[] = ['fail', $label . ': ' . mysqli_error($conn)];
        $ok = false;
    }
}

function columnExists($conn, $table, $column) {
    $table  = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);
    $r = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $r && mysqli_num_rows($r) > 0;
}

if ($conn->connect_error) {
    die("DB connection failed: " . $conn->connect_error);
}

// 1. Add `status` column if missing
if (!columnExists($conn, 'unit_registrations', 'status')) {
    step($conn, $log, $ok, "Added 'status' column",
        "ALTER TABLE unit_registrations
         ADD COLUMN status ENUM('active','passed','failed') NOT NULL DEFAULT 'active' AFTER unit_id");
} else {
    $log[] = ['ok', "'status' column already exists — skipped"];
}

// 2. Add `attempt_no` column if missing
if (!columnExists($conn, 'unit_registrations', 'attempt_no')) {
    step($conn, $log, $ok, "Added 'attempt_no' column",
        "ALTER TABLE unit_registrations
         ADD COLUMN attempt_no INT(11) NOT NULL DEFAULT 1 AFTER status");
} else {
    $log[] = ['ok', "'attempt_no' column already exists — skipped"];
}

// 3. Add the unique key the app relies on (student_id, unit_id, attempt_no),
//    so future re-attempts (after a fail) are tracked as separate rows.
$r = mysqli_query($conn, "SHOW KEYS FROM unit_registrations WHERE Key_name = 'unique_reg_attempt'");
if (!$r || mysqli_num_rows($r) === 0) {
    step($conn, $log, $ok, "Added unique key (student_id, unit_id, attempt_no)",
        "ALTER TABLE unit_registrations ADD UNIQUE KEY unique_reg_attempt (student_id, unit_id, attempt_no)");
} else {
    $log[] = ['ok', "Unique key already exists — skipped"];
}

// 4. Sanity check: show current row count / sample
$cnt = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM unit_registrations"))['c'];
$log[] = ['ok', "unit_registrations now has $cnt row(s) — status changes will persist from now on"];

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Fix: Unit Status Stuck</title>
<style>
  body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;padding:40px;max-width:700px;margin:0 auto;}
  h1{font-size:20px;} .ok{color:#4ade80;} .fail{color:#f87171;}
  .row{padding:8px 12px;border-radius:8px;margin:6px 0;background:#1e293b;}
  .banner{padding:14px 18px;border-radius:10px;margin-bottom:20px;font-weight:700;}
  .banner.good{background:#052e1c;color:#4ade80;border:1px solid #14532d;}
  .banner.bad{background:#450a0a;color:#f87171;border:1px solid #7f1d1d;}
</style>
</head>
<body>
<h1>Smart LMS — Unit Status Fix</h1>
<div class="banner <?php echo $ok ? 'good' : 'bad'; ?>">
  <?php echo $ok ? '✓ Fix applied successfully. Go back and set a unit to Passed/Failed — it will now stick.' : '✗ Some steps failed — see details below.'; ?>
</div>
<?php foreach ($log as [$status, $msg]): ?>
  <div class="row <?php echo $status; ?>"><?php echo $status === 'ok' ? '✓' : '✗'; ?> <?php echo htmlspecialchars($msg); ?></div>
<?php endforeach; ?>
<p style="margin-top:24px;color:#94a3b8;font-size:13px;">
  You can delete this file after running it once.
</p>
</body>
</html>
