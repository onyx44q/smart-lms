<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include 'config.php';

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['student', 'lecturer', 'admin', 'financial_accountant'])) {
    header("Location: index.php"); exit();
}
$sid = $role === 'student' ? intval($_SESSION['user_id'] ?? 0) : intval($_GET['student_id'] ?? 0);
if (!$sid) { echo "Student not specified."; exit(); }

$student = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT u.id, u.full_name, u.admission_number, u.email, u.career_path
     FROM users u WHERE u.id=$sid AND u.role='student' LIMIT 1"));
if (!$student) { echo "Student not found."; exit(); }

// ── Which courses is this student enrolled in? ──────────────────────────
$enrolled_courses = [];
$ec_res = mysqli_query($conn, "
    SELECT c.id, c.title FROM enrollments e
    JOIN courses c ON c.id = e.course_id
    WHERE e.student_id = $sid ORDER BY c.title ASC
");
while ($ec = mysqli_fetch_assoc($ec_res)) $enrolled_courses[] = $ec;

$requested_course_id = intval($_GET['course_id'] ?? 0);
$selected_course = null;
if ($requested_course_id) {
    foreach ($enrolled_courses as $ec) { if ($ec['id'] == $requested_course_id) { $selected_course = $ec; break; } }
} elseif (count($enrolled_courses) === 1) {
    $selected_course = $enrolled_courses[0];
}

// If the student has more than one course and none was specified, show a picker instead of the report.
if (!$selected_course) {
    $back_link = $role === 'student' ? 'student_dashboard.php' : 'admin_dashboard.php';
    ?>
    <!DOCTYPE html><html><head><meta charset="UTF-8"><title>Choose a Course — Progress Report</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
    body{font-family:'DM Sans',sans-serif;background:#f0ece4;margin:0;padding:40px 20px;}
    .wrap{max-width:560px;margin:0 auto;}
    .card{background:#fff;border-radius:16px;padding:32px;box-shadow:0 10px 40px rgba(10,22,40,.1);}
    h1{font-size:18px;font-weight:800;color:#0a1628;margin:0 0 6px;}
    p.sub{color:#94a3b8;font-size:13px;margin:0 0 24px;}
    a.course-link{display:flex;justify-content:space-between;align-items:center;padding:16px 18px;border:1.5px solid #e5e0d5;border-radius:12px;text-decoration:none;color:#0a1628;font-weight:700;font-size:14px;margin-bottom:10px;transition:all .15s;}
    a.course-link:hover{border-color:#c9a84c;background:#faf8f3;}
    a.back{display:inline-block;margin-top:16px;font-size:12px;color:#64748b;text-decoration:none;font-weight:600;}
    </style></head><body>
    <div class="wrap"><div class="card">
        <h1>Which course's progress report?</h1>
        <p class="sub"><?php echo htmlspecialchars($student['full_name']); ?> is enrolled in more than one course — pick one to keep each report focused on its own units.</p>
        <?php if (empty($enrolled_courses)): ?>
        <p style="color:#94a3b8;font-size:13px;">No enrolled courses found.</p>
        <?php else: foreach ($enrolled_courses as $ec): ?>
        <a class="course-link" href="?<?php echo $role !== 'student' ? 'student_id='.$sid.'&' : ''; ?>course_id=<?php echo $ec['id']; ?>">
            <?php echo htmlspecialchars($ec['title']); ?> <span>→</span>
        </a>
        <?php endforeach; endif; ?>
        <a class="back" href="<?php echo $back_link; ?>">← Back to Dashboard</a>
    </div></div>
    </body></html>
    <?php
    exit();
}

$course_id = $selected_course['id'];
$course_title = $selected_course['title'];

// ── Marks per unit — SCOPED TO THIS COURSE ONLY ─────────────────────────
$q = mysqli_query($conn, "
    SELECT cu.id AS unit_id, cu.title AS unit_title, cu.unit_code,
           ua.id AS aid, ua.max_mark AS amax, um.mark
    FROM unit_registrations ur
    INNER JOIN course_units cu ON cu.id = ur.unit_id
    LEFT  JOIN unit_assessments ua ON ua.unit_id = cu.id
    LEFT  JOIN unit_marks um       ON um.assessment_id = ua.id AND um.student_id = $sid
    WHERE ur.student_id = $sid AND cu.course_id = $course_id
    ORDER BY cu.title ASC
");
$units_marks = [];
if ($q) while ($r = mysqli_fetch_assoc($q)) {
    $uid = $r['unit_id'];
    if (!isset($units_marks[$uid])) $units_marks[$uid] = ['title'=>$r['unit_title'],'code'=>$r['unit_code'],'tm'=>0,'tx'=>0,'has_mark'=>false];
    if ($r['aid']) {
        $units_marks[$uid]['tx'] += floatval($r['amax']);
        if ($r['mark'] !== null) { $units_marks[$uid]['tm'] += floatval($r['mark']); $units_marks[$uid]['has_mark'] = true; }
    }
}
foreach ($units_marks as $uid => &$u) {
    $u['pct'] = ($u['tx'] > 0 && $u['has_mark']) ? round($u['tm']/$u['tx']*100, 1) : null;
}
unset($u);

// ── Retake detection: did this unit's CURRENT (latest) attempt pass, after ──
//    an earlier attempt had failed? Powers the ⭐ + "passed on retake" note. ──
$retake_passed_units = [];
foreach (array_keys($units_marks) as $uid) {
    $attempts = mysqli_query($conn,
        "SELECT status, attempt_no FROM unit_registrations WHERE student_id=$sid AND unit_id=$uid ORDER BY attempt_no ASC");
    $latest_status = null; $max_attempt = 1;
    while ($at = mysqli_fetch_assoc($attempts)) {
        $latest_status = $at['status'];                       // rows are ASC, so the last one seen is the latest attempt
        $max_attempt = max($max_attempt, intval($at['attempt_no']));
    }
    // attempt_no only ever increases via the "retake" action, which itself requires
    // the prior attempt to have been marked 'failed' — so max_attempt > 1 already
    // guarantees an earlier failed attempt preceded this one.
    if ($max_attempt > 1 && $latest_status === 'passed') {
        $retake_passed_units[$uid] = $max_attempt;
    }
}

// ── Attendance per unit (course-scoped via the units already in $units_marks) ──
$att_by_unit = [];
$att_res = mysqli_query($conn, "SELECT * FROM v_student_attendance WHERE student_id=$sid");
if ($att_res) while ($a = mysqli_fetch_assoc($att_res)) $att_by_unit[$a['unit_id']] = $a;

// ── Fees (system-wide — a student's balance isn't split per course) ────
$total_fees = floatval(mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(net_amount),0) t FROM student_fee_assignments WHERE student_id=$sid AND status != 'waived'"))['t']);
$total_paid = floatval(mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(amount_paid),0) t FROM fee_payments WHERE student_id=$sid"))['t']);
$fee_balance = max(0, $total_fees - $total_paid);
$fee_pct = $total_fees > 0 ? min(100, round($total_paid / $total_fees * 100)) : 100;

// ── Overall stats ──
$all_pcts = array_filter(array_column($units_marks, 'pct'), fn($v) => $v !== null);
$overall_avg = count($all_pcts) > 0 ? round(array_sum($all_pcts) / count($all_pcts), 1) : null;
$barred_count = count(array_filter($att_by_unit, fn($a) => $a['exam_status'] === 'BARRED'));

// ── Strathmore University's official degree grading scale ──────────────
// Source: Strathmore University Registrar's certification letter (2025) —
// each letter band is split into two finer sub-bands for GPA precision.
function strathmore_grade(float $pct): array {
    $bands = [
        ['min'=>75, 'letter'=>'A', 'gpa'=>4.00, 'class'=>'First Class Honours'],
        ['min'=>70, 'letter'=>'A', 'gpa'=>3.67, 'class'=>'First Class Honours'],
        ['min'=>65, 'letter'=>'B', 'gpa'=>3.33, 'class'=>'Second Class Honours (Upper)'],
        ['min'=>60, 'letter'=>'B', 'gpa'=>3.00, 'class'=>'Second Class Honours (Upper)'],
        ['min'=>55, 'letter'=>'C', 'gpa'=>2.67, 'class'=>'Second Class Honours (Lower)'],
        ['min'=>50, 'letter'=>'C', 'gpa'=>2.33, 'class'=>'Second Class Honours (Lower)'],
        ['min'=>45, 'letter'=>'D', 'gpa'=>2.00, 'class'=>'Pass'],
        ['min'=>40, 'letter'=>'D', 'gpa'=>1.67, 'class'=>'Pass'],
        ['min'=>25, 'letter'=>'E', 'gpa'=>1.00, 'class'=>'Fail'],
        ['min'=>0,  'letter'=>'E', 'gpa'=>0.00, 'class'=>'Fail'],
    ];
    foreach ($bands as $b) { if ($pct >= $b['min']) return $b; }
    return ['letter'=>'E','gpa'=>0.00,'class'=>'Fail'];
}

// Attach grade/GPA to every unit that has a computed percentage, and
// compute this course's overall GPA as the mean of those unit GPAs.
$unit_gpas = [];
foreach ($units_marks as $uid => &$um) {
    if ($um['pct'] !== null) {
        $g = strathmore_grade($um['pct']);
        $um['grade_letter'] = $g['letter'];
        $um['grade_gpa'] = $g['gpa'];
        $um['grade_class'] = $g['class'];
        $unit_gpas[] = $g['gpa'];
    } else {
        $um['grade_letter'] = null; $um['grade_gpa'] = null; $um['grade_class'] = null;
    }
}
unset($um);
$course_gpa = count($unit_gpas) > 0 ? round(array_sum($unit_gpas) / count($unit_gpas), 2) : null;
$course_gpa_class = ($course_gpa !== null && $overall_avg !== null) ? strathmore_grade($overall_avg)['class'] : null;

$ref = 'PR-' . strtoupper(substr(md5($sid . '-' . $course_id . '-pr'), 0, 8));
$gen = date('d M Y, H:i');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Progress Report — <?php echo htmlspecialchars($student['full_name']); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--navy:#0a1628;--gold:#c9a84c;--gold2:#f0d080;--cream:#faf8f3;--border:#e5e0d5;--green:#059669;--red:#dc2626;--amber:#d97706;}
*{margin:0;padding:0;box-sizing:border-box;}
body{background:#f0ece4;font-family:'DM Sans',sans-serif;color:var(--navy);}
.print-bar{background:var(--navy);padding:13px 32px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;position:sticky;top:0;z-index:100;}
.print-bar span{color:rgba(255,255,255,.55);font-size:13px;}
.btn-print,.btn-back{display:inline-flex;align-items:center;gap:7px;padding:9px 20px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;border:none;text-decoration:none;}
.btn-print{background:var(--gold);color:var(--navy);}
.btn-back{background:rgba(255,255,255,.1);color:#fff;}
.doc{max-width:900px;margin:36px auto 60px;background:#fff;box-shadow:0 20px 60px rgba(10,22,40,.18);border-radius:4px;overflow:hidden;}
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
.info-band{background:var(--cream);border-bottom:1px solid var(--border);padding:20px 48px;display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;}
.info-lbl{font-size:9px;font-weight:700;color:#94a3b8;letter-spacing:.1em;text-transform:uppercase;margin-bottom:4px;}
.info-val{font-size:13px;font-weight:700;color:var(--navy);}
.kpi-row{padding:24px 48px;display:grid;grid-template-columns:repeat(4,1fr);gap:14px;border-bottom:1px solid var(--border);}
.kpi{border-radius:10px;padding:15px 17px;background:#f8fafc;border:1px solid #e2e8f0;}
.kpi-lbl{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-bottom:5px;}
.kpi-val{font-family:'JetBrains Mono',monospace;font-size:20px;font-weight:700;color:var(--navy);}
.sec-hd{padding:20px 48px 10px;}
.sec-hd h3{font-size:10px;font-weight:800;color:#94a3b8;letter-spacing:.1em;text-transform:uppercase;}
.tbl-wrap{padding:0 48px 24px;overflow-x:auto;}
table.ft{width:100%;border-collapse:collapse;min-width:760px;}
table.ft thead th{padding:9px 10px;text-align:left;font-size:9px;font-weight:700;color:#94a3b8;letter-spacing:.07em;text-transform:uppercase;border-bottom:2px solid var(--border);white-space:nowrap;}
table.ft td{padding:12px 10px;font-size:12px;color:var(--navy);border-bottom:1px solid #f5f3ee;vertical-align:middle;}
.mono{font-family:'JetBrains Mono',monospace;font-weight:600;}
.sp{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;}
.sp-ok{background:#ecfdf5;color:var(--green);}
.sp-bad{background:#fef2f2;color:var(--red);}
.sp-pending{background:#f1f5f9;color:#64748b;}
.bar-track{width:100px;height:6px;background:#e2e8f0;border-radius:4px;overflow:hidden;}
.bar-fill{height:100%;background:var(--green);}
.doc-ft{background:var(--navy);padding:24px 48px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:14px;}
.ft-l p{font-size:11px;color:rgba(255,255,255,.35);line-height:1.7;}
.ft-r p{font-size:10px;color:rgba(255,255,255,.25);text-align:right;}
.no-data{padding:30px 48px;text-align:center;color:#94a3b8;font-size:13px;font-style:italic;}
@media print{body{background:#fff;}.print-bar{display:none!important;}.doc{box-shadow:none;margin:0;border-radius:0;}}
@media(max-width:680px){.doc-hd{padding:28px 22px 24px;}.info-band,.kpi-row,.tbl-wrap,.sec-hd,.doc-ft{padding-left:18px;padding-right:18px;}.kpi-row{grid-template-columns:1fr;}}
</style>
</head>
<body>
<div class="print-bar">
    <div style="display:flex;gap:10px;align-items:center;">
        <?php if ($role==='student'): ?><a href="student_dashboard.php" class="btn-back">← Dashboard</a>
        <?php else: ?><a href="admin_dashboard.php" class="btn-back">← Admin Dashboard</a><?php endif; ?>
        <span>Progress Report — <?php echo htmlspecialchars($student['full_name']); ?></span>
    </div>
    <button onclick="window.print()" class="btn-print">🖨&nbsp; Print / Save PDF</button>
</div>

<div class="doc">
    <div class="doc-hd">
        <div class="hd-top">
            <div style="display:flex;gap:14px;align-items:center;">
                <div class="logo-mark">S</div>
                <div class="logo-text"><h1>SmartLMS</h1><p>Academic Progress Report</p></div>
            </div>
            <div class="hd-meta">
                <p class="ref-lbl">Report Ref</p>
                <p class="ref-val"><?php echo $ref; ?></p>
                <p style="font-size:11px;color:rgba(255,255,255,.4);">Generated <?php echo $gen; ?></p>
            </div>
        </div>
        <div class="hd-title-row"><h2><?php echo htmlspecialchars($student['full_name']); ?></h2></div>
    </div>

    <div class="info-band">
        <div><p class="info-lbl">Admission No.</p><p class="info-val"><?php echo htmlspecialchars($student['admission_number'] ?? '—'); ?></p></div>
        <div><p class="info-lbl">Course</p><p class="info-val"><?php echo htmlspecialchars($course_title); ?></p></div>
        <div><p class="info-lbl">Units Registered</p><p class="info-val"><?php echo count($units_marks); ?></p></div>
    </div>
    <?php if (count($enrolled_courses) > 1): ?>
    <div style="background:var(--cream);border-bottom:1px solid var(--border);padding:10px 48px;font-size:11px;">
        <a href="?<?php echo $role !== 'student' ? 'student_id='.$sid : ''; ?>" style="color:var(--navy);font-weight:700;text-decoration:none;">← View a different course's report</a>
    </div>
    <?php endif; ?>

    <div class="kpi-row">
        <div class="kpi"><p class="kpi-lbl">Course Average</p><p class="kpi-val"><?php echo $overall_avg !== null ? $overall_avg.'%' : '—'; ?></p></div>
        <div class="kpi"><p class="kpi-lbl">Course GPA (4.0 scale)</p><p class="kpi-val" style="color:var(--gold-dark, #b8933a);"><?php echo $course_gpa !== null ? number_format($course_gpa, 2) : '—'; ?></p>
            <?php if ($course_gpa_class): ?><p style="font-size:10px;color:#94a3b8;margin-top:2px;"><?php echo htmlspecialchars($course_gpa_class); ?></p><?php endif; ?>
        </div>
        <div class="kpi"><p class="kpi-lbl">Units Barred</p><p class="kpi-val" style="color:<?php echo $barred_count>0?'var(--red)':'var(--green)';?>;"><?php echo $barred_count; ?></p></div>
        <div class="kpi"><p class="kpi-lbl">Fee Balance</p><p class="kpi-val" style="color:<?php echo $fee_balance>0?'var(--amber)':'var(--green)';?>;">KES <?php echo number_format($fee_balance,0); ?></p></div>
    </div>

    <?php if (!empty($retake_passed_units)): ?>
    <div style="margin:20px 48px 0;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;font-size:12px;color:#92400e;">
        <strong>⭐ Retake success:</strong>
        <?php
        $notes = [];
        foreach ($retake_passed_units as $ruid => $attempt_no) {
            $notes[] = htmlspecialchars($units_marks[$ruid]['title']) . ' (passed on attempt #' . $attempt_no . ')';
        }
        echo implode('; ', $notes);
        ?>
    </div>
    <?php endif; ?>

    <div class="sec-hd"><h3>Academic Performance &amp; Attendance — <?php echo htmlspecialchars($course_title); ?> Units</h3></div>
    <div class="tbl-wrap">
    <?php if (empty($units_marks)): ?>
        <p class="no-data">No registered units found for this course.</p>
    <?php else: ?>
    <table class="ft">
        <thead><tr><th>Unit</th><th>Marks Avg</th><th>Grade</th><th>GPA</th><th>Attendance</th><th>Exam Status</th></tr></thead>
        <tbody>
        <?php foreach ($units_marks as $uid => $u):
            $att = $att_by_unit[$uid] ?? null;
            $att_pct = $att && $att['total_sessions'] > 0 ? round(100 - floatval($att['absence_pct']), 1) : null;
            $exam_status = $att['exam_status'] ?? 'ELIGIBLE';
            $is_retake_pass = isset($retake_passed_units[$uid]);
        ?>
        <tr>
            <td style="font-weight:700;">
                <?php echo htmlspecialchars($u['title']); ?>
                <?php if ($is_retake_pass): ?><span title="Passed after a retake" style="color:#d97706;">⭐</span><?php endif; ?>
                <?php if ($u['code']): ?><br><span style="color:#94a3b8;font-size:10px;font-weight:600;"><?php echo htmlspecialchars($u['code']); ?></span><?php endif; ?>
                <?php if ($is_retake_pass): ?><div style="font-size:10px;color:#d97706;font-weight:700;margin-top:2px;">Passed on retake — attempt #<?php echo $retake_passed_units[$uid]; ?></div><?php endif; ?>
            </td>
            <td>
                <?php if ($u['pct'] !== null): ?>
                <span class="mono" style="font-weight:700;"><?php echo $u['pct']; ?>%</span>
                <?php else: ?>
                <span class="sp sp-pending">Pending</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($u['grade_letter'] !== null): ?>
                <span style="font-family:'Playfair Display',serif;font-weight:900;font-size:15px;color:var(--navy);"><?php echo htmlspecialchars($u['grade_letter']); ?></span>
                <?php else: ?>
                <span class="sp sp-pending">—</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($u['grade_gpa'] !== null): ?>
                <span class="mono" style="font-weight:700;"><?php echo number_format($u['grade_gpa'], 2); ?></span>
                <?php else: ?>
                <span class="sp sp-pending">—</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($att_pct !== null): ?>
                <div style="display:flex;align-items:center;gap:8px;">
                    <div class="bar-track"><div class="bar-fill" style="width:<?php echo $att_pct; ?>%;background:<?php echo $att_pct>=67?'var(--green)':'var(--red)'; ?>;"></div></div>
                    <span class="mono" style="font-size:11px;"><?php echo $att_pct; ?>%</span>
                </div>
                <?php else: ?>
                <span class="sp sp-pending">No sessions yet</span>
                <?php endif; ?>
            </td>
            <td><span class="sp <?php echo $exam_status==='ELIGIBLE'?'sp-ok':'sp-bad'; ?>"><?php echo $exam_status; ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p style="margin-top:14px;font-size:10.5px;color:#94a3b8;">Grades and GPA computed on Strathmore University's official degree grading scale (A: 70–100% → 4.00–3.67 · B: 60–69% → 3.33–3.00 · C: 50–59% → 2.67–2.33 · D: 40–49% → 2.00–1.67 · E: below 40% → 1.00–0.00).</p>
    <?php endif; ?>
    </div>

    <div class="doc-ft">
        <div class="ft-l">
            <p style="color:rgba(255,255,255,.7);font-weight:700;font-size:13px;margin-bottom:3px;">SmartLMS Registrar's Office</p>
            <p>This report combines coursework/exam marks, session attendance, and fee clearance for a full picture of academic standing.</p>
        </div>
        <div class="ft-r"><p>Generated <?php echo $gen; ?></p></div>
    </div>
</div>
</body>
</html>
