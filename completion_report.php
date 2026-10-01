<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include 'config.php';

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['student', 'admin', 'financial_accountant'])) {
    header("Location: index.php"); exit();
}
$sid = $role === 'student' ? intval($_SESSION['user_id'] ?? 0) : intval($_GET['student_id'] ?? 0);
if (!$sid) { echo "Student not specified."; exit(); }

$student = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT u.id, u.full_name, u.admission_number, u.career_path
     FROM users u WHERE u.id=$sid AND u.role='student' LIMIT 1"));
if (!$student) { echo "Student not found."; exit(); }

// ── Checklist 1: Fees fully cleared ──
$total_fees = floatval(mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(net_amount),0) t FROM student_fee_assignments WHERE student_id=$sid AND status != 'waived'"))['t']);
$total_paid = floatval(mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(amount_paid),0) t FROM fee_payments WHERE student_id=$sid"))['t']);
$fees_clear = $total_paid >= $total_fees;

// ── Checklist 2: No units barred for attendance ──
$barred_count = 0;
$att_res = mysqli_query($conn, "SELECT exam_status FROM v_student_attendance WHERE student_id=$sid");
if ($att_res) while ($a = mysqli_fetch_assoc($att_res)) { if ($a['exam_status'] === 'BARRED') $barred_count++; }
$attendance_clear = $barred_count === 0;

// ── Checklist 3: All registered units PASSED — using each unit's overall
//    performance (total marks vs total available), not just "has a mark
//    been typed in somewhere". This is what makes the status accurate for
//    a student who has genuinely passed but whose lecturer never clicked
//    a manual "mark as passed" button. ──
$units_res = mysqli_query($conn, "
    SELECT cu.id, cu.title
    FROM unit_registrations ur
    INNER JOIN course_units cu ON cu.id = ur.unit_id
    WHERE ur.student_id = $sid
    GROUP BY cu.id, cu.title
");
$total_units = 0; $fully_marked_units = 0; $unit_rows = [];
if ($units_res) while ($u = mysqli_fetch_assoc($units_res)) {
    $total_units++;
    $uc = unit_completion_status($conn, $sid, intval($u['id']));
    $complete = $uc['passed'];
    if ($complete) $fully_marked_units++;
    $unit_rows[] = ['title' => $u['title'], 'complete' => $complete, 'resolved' => $uc['resolved'], 'pct' => $uc['pct']];
}
$coursework_clear = $total_units > 0 && $fully_marked_units === $total_units;

$all_clear = $fees_clear && $attendance_clear && $coursework_clear;
$ref = 'COMP-' . date('Y') . '-' . str_pad($sid, 5, '0', STR_PAD_LEFT);
$gen = date('d M Y, H:i');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Completion Report — <?php echo htmlspecialchars($student['full_name']); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--navy:#0a1628;--gold:#c9a84c;--gold2:#f0d080;--cream:#faf8f3;--border:#e5e0d5;--green:#059669;--red:#dc2626;}
*{margin:0;padding:0;box-sizing:border-box;}
body{background:#f0ece4;font-family:'DM Sans',sans-serif;color:var(--navy);}
.print-bar{background:var(--navy);padding:13px 32px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;position:sticky;top:0;z-index:100;}
.print-bar span{color:rgba(255,255,255,.55);font-size:13px;}
.btn-print,.btn-back{display:inline-flex;align-items:center;gap:7px;padding:9px 20px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;border:none;text-decoration:none;}
.btn-print{background:var(--gold);color:var(--navy);}
.btn-back{background:rgba(255,255,255,.1);color:#fff;}
.doc{max-width:780px;margin:36px auto 60px;background:#fff;box-shadow:0 20px 60px rgba(10,22,40,.18);border-radius:4px;overflow:hidden;}
.doc-hd{background:var(--navy);padding:44px 48px 34px;position:relative;}
.doc-hd::after{content:'';position:absolute;bottom:0;left:48px;right:48px;height:2px;background:linear-gradient(90deg,var(--gold),transparent);}
.hd-top{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;}
.logo-mark{width:48px;height:48px;background:linear-gradient(135deg,var(--gold),var(--gold2));border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:900;color:var(--navy);font-family:'Playfair Display',serif;}
.logo-text h1{font-family:'Playfair Display',serif;font-size:18px;font-weight:900;color:#fff;}
.logo-text p{font-size:10px;color:rgba(255,255,255,.4);font-weight:600;letter-spacing:.08em;text-transform:uppercase;margin-top:2px;}
.hd-meta{text-align:right;}
.hd-meta .ref-lbl{font-size:9px;color:rgba(255,255,255,.4);font-weight:700;letter-spacing:.1em;text-transform:uppercase;}
.hd-meta .ref-val{font-family:'JetBrains Mono',monospace;font-size:14px;color:var(--gold);font-weight:700;margin:3px 0 7px;}
.hd-title-row{margin-top:24px;}
.hd-title-row h2{font-family:'Playfair Display',serif;font-size:26px;font-weight:900;color:#fff;}
.verdict-band{padding:30px 48px;text-align:center;}
.verdict-band .big{font-size:22px;font-weight:900;text-transform:uppercase;letter-spacing:.03em;}
.verdict-ok{background:#ecfdf5;border-bottom:2px solid #a7f3d0;} .verdict-ok .big{color:var(--green);}
.verdict-bad{background:#fef2f2;border-bottom:2px solid #fecaca;} .verdict-bad .big{color:var(--red);}
.checklist{padding:26px 48px;}
.check-item{display:flex;align-items:center;gap:14px;padding:14px 0;border-bottom:1px solid #f5f3ee;}
.check-item:last-child{border-bottom:none;}
.check-icon{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:14px;color:#fff;}
.check-ok{background:var(--green);} .check-bad{background:var(--red);}
.check-title{font-weight:700;font-size:13px;color:var(--navy);}
.check-sub{font-size:11px;color:#94a3b8;margin-top:2px;}
.unit-list{padding:0 48px 24px;}
.unit-row{display:flex;justify-content:space-between;padding:8px 0;font-size:12px;border-bottom:1px solid #f9f8f4;}
.doc-ft{background:var(--navy);padding:24px 48px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:14px;}
.ft-l p{font-size:11px;color:rgba(255,255,255,.35);line-height:1.7;}
.ft-r p{font-size:10px;color:rgba(255,255,255,.25);text-align:right;}
@media print{body{background:#fff;}.print-bar{display:none!important;}.doc{box-shadow:none;margin:0;border-radius:0;}}
@media(max-width:680px){.doc-hd{padding:28px 22px 24px;}.checklist,.unit-list,.verdict-band,.doc-ft{padding-left:18px;padding-right:18px;}}
</style>
</head>
<body>
<div class="print-bar">
    <div style="display:flex;gap:10px;align-items:center;">
        <?php if ($role==='student'): ?><a href="student_dashboard.php" class="btn-back">← Dashboard</a>
        <?php else: ?><a href="admin_dashboard.php" class="btn-back">← Admin Dashboard</a><?php endif; ?>
        <span>Completion Report — <?php echo htmlspecialchars($student['full_name']); ?></span>
    </div>
    <button onclick="window.print()" class="btn-print">🖨&nbsp; Print / Save PDF</button>
</div>

<div class="doc">
    <div class="doc-hd">
        <div class="hd-top">
            <div style="display:flex;gap:14px;align-items:center;">
                <div class="logo-mark">S</div>
                <div class="logo-text"><h1>SmartLMS</h1><p>Programme Completion Report</p></div>
            </div>
            <div class="hd-meta">
                <p class="ref-lbl">Report Ref</p>
                <p class="ref-val"><?php echo $ref; ?></p>
                <p style="font-size:11px;color:rgba(255,255,255,.4);">Generated <?php echo $gen; ?></p>
            </div>
        </div>
        <div class="hd-title-row"><h2><?php echo htmlspecialchars($student['full_name']); ?></h2></div>
    </div>

    <div class="verdict-band <?php echo $all_clear ? 'verdict-ok' : 'verdict-bad'; ?>">
        <p class="big"><?php echo $all_clear ? '✓ All Requirements Met' : '⚠ Requirements Outstanding'; ?></p>
        <p style="font-size:12px;color:#64748b;margin-top:6px;">
            <?php echo $all_clear
                ? 'This student has met all fee, attendance, and coursework requirements on record.'
                : 'One or more requirements below still need to be resolved before completion can be confirmed.'; ?>
        </p>
    </div>

    <div class="checklist">
        <div class="check-item">
            <div class="check-icon <?php echo $fees_clear?'check-ok':'check-bad'; ?>"><?php echo $fees_clear?'✓':'✕'; ?></div>
            <div>
                <p class="check-title">Fee Clearance</p>
                <p class="check-sub"><?php echo $fees_clear ? 'All assigned fees fully paid.' : 'Outstanding balance: KES ' . number_format(max(0,$total_fees-$total_paid), 2); ?></p>
            </div>
        </div>
        <div class="check-item">
            <div class="check-icon <?php echo $attendance_clear?'check-ok':'check-bad'; ?>"><?php echo $attendance_clear?'✓':'✕'; ?></div>
            <div>
                <p class="check-title">Attendance Requirement</p>
                <p class="check-sub"><?php echo $attendance_clear ? 'No units barred for attendance.' : $barred_count . ' unit(s) currently barred for attendance.'; ?></p>
            </div>
        </div>
        <div class="check-item">
            <div class="check-icon <?php echo $coursework_clear?'check-ok':'check-bad'; ?>"><?php echo $coursework_clear?'✓':'✕'; ?></div>
            <div>
                <p class="check-title">Coursework &amp; Assessment Marks</p>
                <p class="check-sub"><?php echo $fully_marked_units; ?> of <?php echo $total_units; ?> registered units passed (based on overall performance, min <?php echo UNIT_PASS_PERCENT; ?>%).</p>
            </div>
        </div>
    </div>

    <?php if (!empty($unit_rows)): ?>
    <div class="unit-list">
        <p style="font-size:10px;font-weight:800;color:#94a3b8;letter-spacing:.1em;text-transform:uppercase;margin-bottom:8px;">Unit Breakdown</p>
        <?php foreach ($unit_rows as $ur): ?>
        <div class="unit-row">
            <span><?php echo htmlspecialchars($ur['title']); ?><?php echo $ur['pct'] !== null ? ' <span style="color:#94a3b8;">(' . number_format($ur['pct'],1) . '%)</span>' : ''; ?></span>
            <span style="font-weight:700;color:<?php echo $ur['complete']?'var(--green)':'var(--red)'; ?>;"><?php echo $ur['complete'] ? 'Complete' : ($ur['resolved']==='failed' ? 'Failed' : 'Incomplete'); ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="doc-ft">
        <div class="ft-l">
            <p style="color:rgba(255,255,255,.7);font-weight:700;font-size:13px;margin-bottom:3px;">SmartLMS Registrar's Office</p>
            <p>This report is generated automatically from recorded fee, attendance, and marks data. It is not a substitute for an official transcript or degree certificate.</p>
        </div>
        <div class="ft-r"><p>Generated <?php echo $gen; ?></p></div>
    </div>
</div>
</body>
</html>
