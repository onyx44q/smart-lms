<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include 'config.php';

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['student', 'financial_accountant', 'admin'])) {
    header("Location: index.php"); exit();
}

$target_student_id = 0;
if ($role === 'student') {
    $target_student_id = intval($_SESSION['user_id'] ?? 0);
} else {
    $target_student_id = intval($_GET['student_id'] ?? 0);
    if (!$target_student_id) { header("Location: admin_dashboard.php"); exit(); }
}

$student = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT u.id, u.full_name, u.admission_number, u.email, u.career_path
     FROM users u WHERE u.id = $target_student_id AND u.role='student' LIMIT 1"));
if (!$student) { echo "<p style='font-family:sans-serif;padding:40px;'>Student not found.</p>"; exit(); }

// Per-unit eligibility, pulled straight from the v_student_attendance view
$units = [];
$u_res = mysqli_query($conn, "SELECT * FROM v_student_attendance WHERE student_id=$target_student_id ORDER BY unit_title ASC");
if ($u_res) while ($r = mysqli_fetch_assoc($u_res)) $units[] = $r;

// Overall fee clearance
$total_fees = floatval(mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(net_amount),0) t FROM student_fee_assignments WHERE student_id=$target_student_id AND status != 'waived'"))['t']);
$total_paid = floatval(mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(amount_paid),0) t FROM fee_payments WHERE student_id=$target_student_id"))['t']);
$fee_cleared = $total_paid >= $total_fees;

$barred_units = array_filter($units, fn($u) => $u['exam_status'] === 'BARRED');
$overall_eligible = $fee_cleared && empty($barred_units);

// ── Next-semester unlock: per enrolled course, check whether the student has
//    concluded their current semester's units, has no barred units, and has
//    cleared fees — if so, surface the next semester's units to register for. ──
$semester_unlocks = [];
$courses_res = mysqli_query($conn, "
    SELECT c.id AS course_id, c.title AS course_title
    FROM enrollments e JOIN courses c ON c.id = e.course_id
    WHERE e.student_id = $target_student_id
");
while ($crow = mysqli_fetch_assoc($courses_res)) {
    $cid = intval($crow['course_id']);

    // Latest attempt per registered unit in this course
    $reg_res = mysqli_query($conn, "
        SELECT cu.id AS unit_id, cu.semester_number, ur.status
        FROM unit_registrations ur
        JOIN course_units cu ON cu.id = ur.unit_id
        INNER JOIN (
            SELECT unit_id, MAX(attempt_no) AS max_attempt FROM unit_registrations
            WHERE student_id = $target_student_id GROUP BY unit_id
        ) latest ON latest.unit_id = ur.unit_id AND latest.max_attempt = ur.attempt_no
        WHERE ur.student_id = $target_student_id AND cu.course_id = $cid
    ");
    $registered_unit_ids = []; $current_sem = 0; $sem_units = [];
    while ($rr = mysqli_fetch_assoc($reg_res)) {
        $registered_unit_ids[] = intval($rr['unit_id']);
        $sem = intval($rr['semester_number']);
        if ($sem > $current_sem) $current_sem = $sem;
        // Resolve via overall performance (not the raw column) so a unit that's
        // been fully and correctly marked counts as concluded even if no
        // lecturer has manually flipped its status.
        $resolved = unit_completion_status($conn, $target_student_id, intval($rr['unit_id']))['resolved'];
        $sem_units[$sem][] = $resolved;
    }
    if ($current_sem === 0) continue; // not registered in any unit of this course yet

    $current_sem_concluded = true;
    foreach (($sem_units[$current_sem] ?? []) as $st) { if ($st === 'pending') { $current_sem_concluded = false; break; } }

    $course_barred = false;
    foreach ($units as $u) {
        if (in_array(intval($u['unit_id']), $registered_unit_ids) && $u['exam_status'] === 'BARRED') { $course_barred = true; break; }
    }

    $next_sem = $current_sem + 1;
    $next_units = [];
    $nu_res = mysqli_query($conn, "SELECT id, title, unit_code FROM course_units WHERE course_id=$cid AND semester_number=$next_sem");
    while ($nu = mysqli_fetch_assoc($nu_res)) {
        if (!in_array(intval($nu['id']), $registered_unit_ids)) $next_units[] = $nu;
    }

    $reasons = [];
    if (!$fee_cleared) $reasons[] = 'Outstanding fee balance';
    if ($course_barred) $reasons[] = 'One or more current units barred for attendance';
    if (!$current_sem_concluded) $reasons[] = 'Semester ' . $current_sem . ' results not yet finalized';
    if (empty($next_units)) $reasons[] = 'No Semester ' . $next_sem . ' units have been added yet';

    $semester_unlocks[] = [
        'course_id' => $cid, 'course_title' => $crow['course_title'],
        'current_sem' => $current_sem, 'next_sem' => $next_sem,
        'unlocked' => empty($reasons), 'reasons' => $reasons, 'next_units' => $next_units,
    ];
}

$card_ref = 'EXC-' . date('Y') . '-' . str_pad($target_student_id, 5, '0', STR_PAD_LEFT);
$gen_date = date('d M Y, H:i');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Exam Card — <?php echo htmlspecialchars($student['full_name']); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--navy:#0a1628;--gold:#c9a84c;--gold2:#f0d080;--cream:#faf8f3;--border:#e5e0d5;--green:#059669;--red:#dc2626;}
*{margin:0;padding:0;box-sizing:border-box;}
body{background:#f0ece4;font-family:'DM Sans',sans-serif;color:var(--navy);min-height:100vh;}
.print-bar{background:var(--navy);padding:13px 32px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;position:sticky;top:0;z-index:100;}
.print-bar span{color:rgba(255,255,255,.55);font-size:13px;}
.btn-print,.btn-back{display:inline-flex;align-items:center;gap:7px;padding:9px 20px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;border:none;text-decoration:none;transition:all .2s;}
.btn-print{background:var(--gold);color:var(--navy);}
.btn-back{background:rgba(255,255,255,.1);color:#fff;}
.doc{max-width:820px;margin:36px auto 60px;background:#fff;box-shadow:0 20px 60px rgba(10,22,40,.18);border-radius:4px;overflow:hidden;}
.doc-hd{background:var(--navy);padding:44px 48px 34px;position:relative;overflow:hidden;}
.doc-hd::after{content:'';position:absolute;bottom:0;left:48px;right:48px;height:2px;background:linear-gradient(90deg,var(--gold),transparent);}
.hd-top{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap;position:relative;z-index:1;}
.logo-mark{width:48px;height:48px;background:linear-gradient(135deg,var(--gold),var(--gold2));border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:900;color:var(--navy);font-family:'Playfair Display',serif;flex-shrink:0;}
.logo-text h1{font-family:'Playfair Display',serif;font-size:18px;font-weight:900;color:#fff;}
.logo-text p{font-size:10px;color:rgba(255,255,255,.4);font-weight:600;letter-spacing:.08em;text-transform:uppercase;margin-top:2px;}
.hd-meta{text-align:right;}
.hd-meta .ref-lbl{font-size:9px;color:rgba(255,255,255,.4);font-weight:700;letter-spacing:.1em;text-transform:uppercase;}
.hd-meta .ref-val{font-family:'JetBrains Mono',monospace;font-size:14px;color:var(--gold);font-weight:700;margin:3px 0 7px;}
.hd-title-row{margin-top:24px;position:relative;z-index:1;}
.hd-title-row h2{font-family:'Playfair Display',serif;font-size:26px;font-weight:900;color:#fff;}
.info-band{background:var(--cream);border-bottom:1px solid var(--border);padding:20px 48px;display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;}
.info-lbl{font-size:9px;font-weight:700;color:#94a3b8;letter-spacing:.1em;text-transform:uppercase;margin-bottom:4px;}
.info-val{font-size:13px;font-weight:700;color:var(--navy);}
.verdict-band{padding:22px 48px;text-align:center;font-weight:900;font-size:18px;letter-spacing:.04em;text-transform:uppercase;}
.verdict-eligible{background:#ecfdf5;color:var(--green);border-bottom:2px solid #a7f3d0;}
.verdict-barred{background:#fef2f2;color:var(--red);border-bottom:2px solid #fecaca;}
.sec-hd{padding:20px 48px 10px;}
.sec-hd h3{font-size:10px;font-weight:800;color:#94a3b8;letter-spacing:.1em;text-transform:uppercase;}
.tbl-wrap{padding:0 48px 26px;overflow-x:auto;}
table.ft{width:100%;border-collapse:collapse;min-width:560px;}
table.ft thead th{padding:9px 10px;text-align:left;font-size:9px;font-weight:700;color:#94a3b8;letter-spacing:.07em;text-transform:uppercase;border-bottom:2px solid var(--border);white-space:nowrap;}
table.ft td{padding:12px 10px;font-size:12px;color:var(--navy);border-bottom:1px solid #f5f3ee;vertical-align:middle;}
.mono{font-family:'JetBrains Mono',monospace;font-weight:600;}
.sp{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;}
.sp-ok{background:#ecfdf5;color:var(--green);}
.sp-bad{background:#fef2f2;color:var(--red);}
.doc-ft{background:var(--navy);padding:24px 48px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;}
.ft-l p{font-size:11px;color:rgba(255,255,255,.35);line-height:1.7;}
.ft-r p{font-size:10px;color:rgba(255,255,255,.25);text-align:right;}
.no-data{padding:30px 48px;text-align:center;color:#94a3b8;font-size:13px;font-style:italic;}
@media print{body{background:#fff;}.print-bar{display:none!important;}.doc{box-shadow:none;margin:0;border-radius:0;}}
@media(max-width:680px){.doc-hd{padding:28px 22px 24px;}.info-band,.tbl-wrap,.sec-hd,.doc-ft,.verdict-band{padding-left:18px;padding-right:18px;}}
</style>
</head>
<body>
<div class="print-bar">
    <div style="display:flex;gap:10px;align-items:center;">
        <?php if ($role==='student'): ?>
        <a href="student_dashboard.php" class="btn-back">← Dashboard</a>
        <?php else: ?>
        <a href="admin_dashboard.php" class="btn-back">← Admin Dashboard</a>
        <?php endif; ?>
        <span>Exam Card — <?php echo htmlspecialchars($student['full_name']); ?></span>
    </div>
    <button onclick="window.print()" class="btn-print">🖨&nbsp; Print / Save PDF</button>
</div>

<div class="doc">
    <div class="doc-hd">
        <div class="hd-top">
            <div style="display:flex;gap:14px;align-items:center;">
                <div class="logo-mark">S</div>
                <div class="logo-text"><h1>SmartLMS</h1><p>Examination Card</p></div>
            </div>
            <div class="hd-meta">
                <p class="ref-lbl">Card Ref</p>
                <p class="ref-val"><?php echo $card_ref; ?></p>
                <p style="font-size:11px;color:rgba(255,255,255,.4);">Issued <?php echo $gen_date; ?></p>
            </div>
        </div>
        <div class="hd-title-row"><h2><?php echo htmlspecialchars($student['full_name']); ?></h2></div>
    </div>

    <div class="info-band">
        <div><p class="info-lbl">Admission No.</p><p class="info-val"><?php echo htmlspecialchars($student['admission_number'] ?? '—'); ?></p></div>
        <div><p class="info-lbl">Programme</p><p class="info-val"><?php echo htmlspecialchars($student['career_path'] ?? '—'); ?></p></div>
        <div><p class="info-lbl">Fee Clearance</p><p class="info-val" style="color:<?php echo $fee_cleared?'#059669':'#dc2626';?>;"><?php echo $fee_cleared ? 'Cleared' : 'Balance Outstanding'; ?></p></div>
    </div>

    <div class="verdict-band <?php echo $overall_eligible ? 'verdict-eligible' : 'verdict-barred'; ?>">
        <?php if ($overall_eligible): ?>
            ✓ Eligible for Examinations
        <?php else: ?>
            ⚠ Not Fully Eligible — See Details Below
        <?php endif; ?>
    </div>

    <div class="sec-hd"><h3>Per-Unit Eligibility</h3></div>
    <div class="tbl-wrap">
    <?php if (empty($units)): ?>
        <p class="no-data">No registered units found.</p>
    <?php else: ?>
    <table class="ft">
        <thead><tr><th>Unit</th><th>Code</th><th>Sessions</th><th>Absences</th><th>Absence %</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($units as $u): ?>
        <tr>
            <td style="font-weight:700;"><?php echo htmlspecialchars($u['unit_title']); ?></td>
            <td class="mono"><?php echo htmlspecialchars($u['unit_code'] ?? '—'); ?></td>
            <td class="mono"><?php echo intval($u['total_sessions']); ?></td>
            <td class="mono"><?php echo intval($u['absences']); ?></td>
            <td class="mono"><?php echo $u['absence_pct'] !== null ? number_format($u['absence_pct'],1).'%' : '—'; ?></td>
            <td><span class="sp <?php echo $u['exam_status']==='ELIGIBLE'?'sp-ok':'sp-bad'; ?>"><?php echo htmlspecialchars($u['exam_status']); ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (!$fee_cleared): ?>
    <p style="margin-top:14px;font-size:12px;color:#dc2626;font-weight:700;"><i>⚠ Outstanding fee balance may also affect exam eligibility — clear your balance via Fees &amp; Payments.</i></p>
    <?php endif; ?>
    <?php endif; ?>
    </div>

    <?php if (!empty($semester_unlocks)): ?>
    <div class="sec-hd"><h3>Next Semester Registration</h3></div>
    <div style="padding:0 48px 26px;">
        <?php foreach ($semester_unlocks as $su): ?>
        <div style="border:1px solid var(--border);border-radius:12px;padding:18px 20px;margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px;">
                <div>
                    <p style="font-weight:800;font-size:13px;color:var(--navy);"><?php echo htmlspecialchars($su['course_title']); ?></p>
                    <p style="font-size:11px;color:#94a3b8;">Semester <?php echo $su['current_sem']; ?> → Semester <?php echo $su['next_sem']; ?></p>
                </div>
                <?php if ($su['unlocked']): ?>
                <span class="sp sp-ok">Unlocked</span>
                <?php else: ?>
                <span class="sp sp-bad">Locked</span>
                <?php endif; ?>
            </div>

            <?php if ($su['unlocked']): ?>
                <?php if ($role === 'student'): ?>
                <form method="POST" action="unit_actions.php">
                    <input type="hidden" name="action" value="register_semester_units">
                    <input type="hidden" name="course_id" value="<?php echo $su['course_id']; ?>">
                    <input type="hidden" name="semester_number" value="<?php echo $su['next_sem']; ?>">
                    <p style="font-size:11px;color:#64748b;margin-bottom:8px;">
                        <?php echo count($su['next_units']); ?> unit(s) available:
                        <?php echo htmlspecialchars(implode(', ', array_column($su['next_units'], 'title'))); ?>
                    </p>
                    <button type="submit" style="background:var(--green);color:#fff;border:none;padding:9px 18px;border-radius:8px;font-size:11px;font-weight:700;cursor:pointer;">
                        Register for Semester <?php echo $su['next_sem']; ?> Units
                    </button>
                </form>
                <?php else: ?>
                <p style="font-size:11px;color:#64748b;">
                    <?php echo count($su['next_units']); ?> unit(s) available:
                    <?php echo htmlspecialchars(implode(', ', array_column($su['next_units'], 'title'))); ?>
                </p>
                <?php endif; ?>
            <?php else: ?>
                <ul style="margin:0;padding-left:18px;font-size:11px;color:#dc2626;">
                    <?php foreach ($su['reasons'] as $reason): ?>
                    <li><?php echo htmlspecialchars($reason); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="doc-ft">
        <div class="ft-l">
            <p style="color:rgba(255,255,255,.7);font-weight:700;font-size:13px;margin-bottom:3px;">SmartLMS Examinations Office</p>
            <p>Eligibility computed from recorded attendance (barred at &gt;33.3% absence) and fee clearance status.<br>This card must be presented alongside a valid student ID at all examination venues.</p>
        </div>
        <div class="ft-r"><p>Generated <?php echo $gen_date; ?></p></div>
    </div>
</div>
</body>
</html>
