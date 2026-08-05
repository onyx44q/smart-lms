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
    if (!$target_student_id) { header("Location: financial_dashboard.php"); exit(); }
}

$student = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT u.id, u.full_name, u.email, u.career_path, u.created_at,
            c.title AS course_name
     FROM users u
     LEFT JOIN courses c ON c.id = u.course_id
     WHERE u.id = $target_student_id AND u.role='student' LIMIT 1"));
if (!$student) { echo "<p style='font-family:sans-serif;padding:40px;'>Student not found.</p>"; exit(); }

// Fee rows
$fee_rows = [];
$fee_res = mysqli_query($conn,
    "SELECT sfa.*, fs.name AS fee_name, fs.fee_category, fs.description AS fee_desc,
            fs.academic_year, fs.semester
     FROM student_fee_assignments sfa
     JOIN fee_structures fs ON fs.id = sfa.fee_structure_id
     WHERE sfa.student_id = $target_student_id
     ORDER BY sfa.due_date ASC, sfa.assigned_at ASC");
if ($fee_res) while ($r = mysqli_fetch_assoc($fee_res)) $fee_rows[] = $r;

// Payment rows
$pay_rows = [];
$pay_res = mysqli_query($conn,
    "SELECT fp.*, fs.name AS fee_name
     FROM fee_payments fp
     LEFT JOIN student_fee_assignments sfa ON sfa.id = fp.fee_assignment_id
     LEFT JOIN fee_structures fs ON fs.id = sfa.fee_structure_id
     WHERE fp.student_id = $target_student_id
     ORDER BY fp.payment_date DESC, fp.created_at DESC");
if ($pay_res) while ($r = mysqli_fetch_assoc($pay_res)) $pay_rows[] = $r;

$total_fees  = array_sum(array_column($fee_rows, 'net_amount'));
$total_paid  = array_sum(array_column($pay_rows, 'amount_paid'));
$balance     = $total_fees - $total_paid;
$stmt_ref    = 'STMT-' . strtoupper(substr(md5($student['id'] . date('Ym')), 0, 8));
$gen_date    = date('d M Y, H:i');

$status_map = [
    'paid'    => ['Paid',    '#059669','#d1fae5'],
    'partial' => ['Partial', '#d97706','#fef3c7'],
    'pending' => ['Pending', '#64748b','#f1f5f9'],
    'overdue' => ['Overdue', '#dc2626','#fee2e2'],
    'waived'  => ['Waived',  '#7c3aed','#ede9fe'],
];
$cat_icons = [
    'tuition'=>'🎓','examination'=>'📝','library'=>'📚',
    'accommodation'=>'🏠','transport'=>'🚌','medical'=>'🏥',
    'activity'=>'⚽','other'=>'📋',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fee Statement — <?php echo htmlspecialchars($student['full_name']); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--navy:#0a1628;--gold:#c9a84c;--gold2:#f0d080;--cream:#faf8f3;--border:#e5e0d5;--green:#059669;--red:#dc2626;}
*{margin:0;padding:0;box-sizing:border-box;}
body{background:#f0ece4;font-family:'DM Sans',sans-serif;color:var(--navy);min-height:100vh;}
.print-bar{background:var(--navy);padding:13px 32px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;position:sticky;top:0;z-index:100;}
.print-bar span{color:rgba(255,255,255,.55);font-size:13px;}
.btn-print,.btn-back{display:inline-flex;align-items:center;gap:7px;padding:9px 20px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;border:none;text-decoration:none;transition:all .2s;}
.btn-print{background:var(--gold);color:var(--navy);}
.btn-print:hover{background:var(--gold2);}
.btn-back{background:rgba(255,255,255,.1);color:#fff;}
.btn-back:hover{background:rgba(255,255,255,.18);}
.doc{max-width:860px;margin:36px auto 60px;background:#fff;box-shadow:0 20px 60px rgba(10,22,40,.18);border-radius:4px;overflow:hidden;}
/* Header */
.doc-hd{background:var(--navy);padding:48px 52px 38px;position:relative;overflow:hidden;}
.doc-hd::before{content:'';position:absolute;inset:0;background:repeating-linear-gradient(45deg,rgba(201,168,76,.04) 0,rgba(201,168,76,.04) 1px,transparent 1px,transparent 28px);}
.doc-hd::after{content:'';position:absolute;bottom:0;left:52px;right:52px;height:2px;background:linear-gradient(90deg,var(--gold),transparent);}
.hd-top{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap;position:relative;z-index:1;}
.logo-mark{width:50px;height:50px;background:linear-gradient(135deg,var(--gold),var(--gold2));border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:900;color:var(--navy);font-family:'Playfair Display',serif;flex-shrink:0;}
.logo-text h1{font-family:'Playfair Display',serif;font-size:19px;font-weight:900;color:#fff;}
.logo-text p{font-size:10px;color:rgba(255,255,255,.4);font-weight:600;letter-spacing:.08em;text-transform:uppercase;margin-top:2px;}
.hd-meta{text-align:right;}
.hd-meta .ref-lbl{font-size:9px;color:rgba(255,255,255,.4);font-weight:700;letter-spacing:.1em;text-transform:uppercase;}
.hd-meta .ref-val{font-family:'JetBrains Mono',monospace;font-size:15px;color:var(--gold);font-weight:700;margin:3px 0 7px;}
.hd-meta .ref-date{font-size:11px;color:rgba(255,255,255,.4);}
.hd-title-row{margin-top:28px;display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;position:relative;z-index:1;}
.hd-title-row h2{font-family:'Playfair Display',serif;font-size:28px;font-weight:900;color:#fff;letter-spacing:-.4px;}
.fy-badge{background:rgba(201,168,76,.15);border:1px solid rgba(201,168,76,.3);color:var(--gold2);font-size:10px;font-weight:700;padding:5px 12px;border-radius:20px;letter-spacing:.04em;}
/* Info band */
.info-band{background:var(--cream);border-bottom:1px solid var(--border);padding:22px 52px;display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:18px;}
.info-lbl{font-size:9px;font-weight:700;color:#94a3b8;letter-spacing:.1em;text-transform:uppercase;margin-bottom:4px;}
.info-val{font-size:13px;font-weight:700;color:var(--navy);}
/* KPI */
.kpi-row{padding:26px 52px;display:grid;grid-template-columns:repeat(3,1fr);gap:14px;border-bottom:1px solid var(--border);}
.kpi{border-radius:10px;padding:15px 17px;}
.kpi.t{background:#f0f4ff;border:1px solid #c7d2fe;}
.kpi.p{background:#ecfdf5;border:1px solid #a7f3d0;}
.kpi.b{background:<?php echo $balance<=0?'#ecfdf5':'#fff7ed';?>;border:1px solid <?php echo $balance<=0?'#a7f3d0':'#fde68a';?>;}
.kpi-lbl{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-bottom:5px;}
.kpi-val{font-family:'JetBrains Mono',monospace;font-size:20px;font-weight:700;color:var(--navy);}
.kpi-chip{display:inline-flex;align-items:center;gap:4px;margin-top:5px;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;background:<?php echo $balance<=0?'#d1fae5':'#fef3c7';?>;color:<?php echo $balance<=0?'#065f46':'#92400e';?>;}
/* Section */
.sec-hd{padding:20px 52px 10px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.sec-hd h3{font-size:10px;font-weight:800;color:#94a3b8;letter-spacing:.1em;text-transform:uppercase;}
.sec-hd .cnt{font-size:10px;font-weight:700;color:var(--gold);background:rgba(201,168,76,.1);border:1px solid rgba(201,168,76,.22);padding:2px 8px;border-radius:10px;}
/* Tables */
.tbl-wrap{padding:0 52px 10px;overflow-x:auto;}
table.ft{width:100%;border-collapse:collapse;min-width:580px;}
table.ft thead th{padding:9px 10px;text-align:left;font-size:9px;font-weight:700;color:#94a3b8;letter-spacing:.07em;text-transform:uppercase;border-bottom:2px solid var(--border);white-space:nowrap;}
table.ft td{padding:12px 10px;font-size:12px;color:var(--navy);border-bottom:1px solid #f5f3ee;vertical-align:middle;}
table.ft tbody tr:last-child td{border-bottom:none;}
table.ft tbody tr:hover td{background:#faf8f3;}
.fee-name{font-weight:700;font-size:12px;}
.fee-cat{font-size:10px;color:#94a3b8;font-weight:600;margin-top:2px;text-transform:capitalize;}
.mono{font-family:'JetBrains Mono',monospace;font-weight:600;}
.sp{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;}
.rcpt{font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:700;color:var(--green);background:#ecfdf5;border:1px solid #a7f3d0;padding:2px 7px;border-radius:5px;}
.mth{font-size:10px;font-weight:700;background:#f1f5f9;color:#475569;border-radius:5px;padding:2px 7px;text-transform:uppercase;letter-spacing:.04em;}
/* Summary */
.sum-row{padding:16px 52px 26px;}
.sum-inner{background:#faf8f3;border:1px solid var(--border);border-radius:10px;padding:14px 18px;display:flex;justify-content:flex-end;gap:36px;flex-wrap:wrap;}
.sum-item{text-align:right;}
.sum-lbl{font-size:9px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.07em;}
.sum-val{font-family:'JetBrains Mono',monospace;font-size:15px;font-weight:700;color:var(--navy);margin-top:3px;}
.sum-item.final{border-left:2px solid var(--border);padding-left:32px;}
.sum-item.final .sum-val{font-size:20px;font-weight:800;}
/* Footer */
.doc-ft{background:var(--navy);padding:26px 52px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;}
.ft-l p{font-size:11px;color:rgba(255,255,255,.35);line-height:1.7;}
.ft-l .ft-title{color:rgba(255,255,255,.7);font-weight:700;font-size:13px;margin-bottom:3px;}
.ft-seal{width:56px;height:56px;border:2px dashed rgba(201,168,76,.35);border-radius:50%;display:flex;align-items:center;justify-content:center;margin-left:auto;font-size:26px;}
.ft-r p{font-size:10px;color:rgba(255,255,255,.25);margin-top:5px;text-align:right;}
.no-data{padding:30px 52px;text-align:center;color:#94a3b8;font-size:13px;font-style:italic;}
@media print{body{background:#fff;}.print-bar{display:none!important;}.doc{box-shadow:none;margin:0;border-radius:0;}}
@media(max-width:680px){.doc-hd{padding:28px 22px 24px;}.info-band,.kpi-row,.sec-hd,.tbl-wrap,.sum-row,.doc-ft{padding-left:18px;padding-right:18px;}.kpi-row{grid-template-columns:1fr;}}
</style>
</head>
<body>
<div class="print-bar">
    <div style="display:flex;gap:10px;align-items:center;">
        <?php if ($role==='student'): ?>
        <a href="student_dashboard.php" class="btn-back">← Dashboard</a>
        <?php else: ?>
        <a href="financial_dashboard.php" class="btn-back">← Financial Dashboard</a>
        <?php endif; ?>
        <span>Fee Statement — <?php echo htmlspecialchars($student['full_name']); ?></span>
    </div>
    <button onclick="window.print()" class="btn-print">🖨&nbsp; Print / Save PDF</button>
</div>

<div class="doc">
    <!-- Header -->
    <div class="doc-hd">
        <div class="hd-top">
            <div style="display:flex;align-items:center;gap:13px;">
                <div class="logo-mark">S</div>
                <div class="logo-text"><h1>SmartLMS</h1><p>Academic Finance Office</p></div>
            </div>
            <div class="hd-meta">
                <div class="ref-lbl">Statement Ref</div>
                <div class="ref-val"><?php echo $stmt_ref; ?></div>
                <div class="ref-date">Generated: <?php echo $gen_date; ?></div>
            </div>
        </div>
        <div class="hd-title-row">
            <h2>Fee Statement</h2>
            <span class="fy-badge">AY <?php echo date('Y').'/'. (date('Y')+1); ?></span>
        </div>
    </div>

    <!-- Student Info -->
    <div class="info-band">
        <div><div class="info-lbl">Student Name</div><div class="info-val"><?php echo htmlspecialchars($student['full_name']); ?></div></div>
        <div><div class="info-lbl">Student ID</div><div class="info-val" style="font-family:'JetBrains Mono',monospace;">STU-<?php echo str_pad($student['id'],5,'0',STR_PAD_LEFT); ?></div></div>
        <div><div class="info-lbl">Email</div><div class="info-val" style="font-size:12px;"><?php echo htmlspecialchars($student['email']); ?></div></div>
        <div><div class="info-lbl">Programme</div><div class="info-val" style="font-size:12px;"><?php echo htmlspecialchars($student['course_name'] ?? $student['career_path'] ?? '—'); ?></div></div>
        <div><div class="info-lbl">Admission Date</div><div class="info-val"><?php echo date('d M Y',strtotime($student['created_at'])); ?></div></div>
    </div>

    <!-- KPI -->
    <div class="kpi-row">
        <div class="kpi t">
            <div class="kpi-lbl">Total Fees Assigned</div>
            <div class="kpi-val">KES <?php echo number_format($total_fees,2); ?></div>
            <div style="font-size:10px;color:#64748b;margin-top:4px;"><?php echo count($fee_rows); ?> fee item<?php echo count($fee_rows)!=1?'s':''; ?></div>
        </div>
        <div class="kpi p">
            <div class="kpi-lbl">Total Paid</div>
            <div class="kpi-val" style="color:var(--green);">KES <?php echo number_format($total_paid,2); ?></div>
            <div style="font-size:10px;color:#64748b;margin-top:4px;"><?php echo count($pay_rows); ?> payment<?php echo count($pay_rows)!=1?'s':''; ?></div>
        </div>
        <div class="kpi b">
            <div class="kpi-lbl">Outstanding Balance</div>
            <div class="kpi-val" style="color:<?php echo $balance<=0?'var(--green)':'#d97706'; ?>;">KES <?php echo number_format(abs($balance),2); ?></div>
            <div class="kpi-chip"><?php echo $balance<=0?'✅ Fully Cleared':'⚠ Balance Remaining'; ?></div>
        </div>
    </div>

    <!-- Fee Schedule -->
    <div class="sec-hd" style="margin-top:10px;">
        <h3>Fee Schedule</h3><span class="cnt"><?php echo count($fee_rows); ?> items</span>
    </div>
    <?php if (empty($fee_rows)): ?>
    <div class="no-data">No fees have been assigned to this student account yet.</div>
    <?php else: ?>
    <div class="tbl-wrap">
        <table class="ft">
            <thead><tr><th>#</th><th>Fee Item</th><th>Category</th><th>Period</th><th>Amount</th><th>Discount</th><th>Net (KES)</th><th>Due Date</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($fee_rows as $i => $fee):
                $icon = $cat_icons[$fee['fee_category']] ?? '📋';
                [$slbl,$sclr,$sbg] = $status_map[$fee['status']] ?? ['Unknown','#64748b','#f1f5f9'];
            ?>
            <tr>
                <td style="color:#94a3b8;font-size:11px;"><?php echo $i+1; ?></td>
                <td><div class="fee-name"><?php echo $icon; ?> <?php echo htmlspecialchars($fee['fee_name']); ?></div><?php if($fee['fee_desc']): ?><div class="fee-cat"><?php echo htmlspecialchars($fee['fee_desc']); ?></div><?php endif; ?></td>
                <td style="font-size:11px;color:#64748b;text-transform:capitalize;"><?php echo str_replace('_',' ',$fee['fee_category']); ?></td>
                <td style="font-size:10px;color:#64748b;"><?php echo htmlspecialchars($fee['academic_year']); ?><br><?php echo htmlspecialchars($fee['semester']); ?></td>
                <td class="mono"><?php echo number_format($fee['total_amount'],2); ?></td>
                <td class="mono" style="color:var(--green);"><?php echo $fee['discount_amount']>0?'-'.number_format($fee['discount_amount'],2):'—'; ?></td>
                <td class="mono" style="font-weight:700;"><?php echo number_format($fee['net_amount'],2); ?></td>
                <td style="font-size:11px;color:#64748b;"><?php echo $fee['due_date']?date('d M Y',strtotime($fee['due_date'])):'—'; ?></td>
                <td><span class="sp" style="background:<?php echo $sbg; ?>;color:<?php echo $sclr; ?>;"><?php echo $slbl; ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Payment History -->
    <div class="sec-hd" style="margin-top:16px;">
        <h3>Payment History</h3><span class="cnt"><?php echo count($pay_rows); ?> payments</span>
    </div>
    <?php if (empty($pay_rows)): ?>
    <div class="no-data">No payments recorded on this account.</div>
    <?php else: ?>
    <div class="tbl-wrap">
        <table class="ft">
            <thead><tr><th>Receipt #</th><th>Date</th><th>Amount (KES)</th><th>Method</th><th>Ref / TXN ID</th><th>Notes</th></tr></thead>
            <tbody>
            <?php foreach ($pay_rows as $pay): ?>
            <tr>
                <td><span class="rcpt"><?php echo htmlspecialchars($pay['receipt_number']??'—'); ?></span></td>
                <td style="font-size:11px;white-space:nowrap;"><?php echo date('d M Y',strtotime($pay['payment_date'])); ?></td>
                <td class="mono" style="font-weight:700;color:var(--green);"><?php echo number_format($pay['amount_paid'],2); ?></td>
                <td><span class="mth"><?php echo str_replace('_',' ',$pay['payment_method']); ?></span></td>
                <td style="font-size:11px;font-family:'JetBrains Mono',monospace;color:#64748b;"><?php echo $pay['transaction_ref']?htmlspecialchars($pay['transaction_ref']):'—'; ?></td>
                <td style="font-size:11px;color:#94a3b8;"><?php echo $pay['notes']?htmlspecialchars($pay['notes']):'—'; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Summary totals -->
    <div class="sum-row">
        <div class="sum-inner">
            <div class="sum-item">
                <div class="sum-lbl">Total Fees</div>
                <div class="sum-val">KES <?php echo number_format($total_fees,2); ?></div>
            </div>
            <div class="sum-item">
                <div class="sum-lbl">Total Paid</div>
                <div class="sum-val" style="color:var(--green);">KES <?php echo number_format($total_paid,2); ?></div>
            </div>
            <div class="sum-item final">
                <div class="sum-lbl">Outstanding Balance</div>
                <div class="sum-val" style="color:<?php echo $balance<=0?'var(--green)':'#d97706'; ?>;">KES <?php echo number_format(abs($balance),2); ?></div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="doc-ft">
        <div class="ft-l">
            <div class="ft-title">SmartLMS Finance Office</div>
            <p>This is a computer-generated statement — no physical signature required.<br>
            For queries contact: finance@smartlms.com<br>
            Statement valid as of: <?php echo $gen_date; ?></p>
        </div>
        <div class="ft-r">
            <div class="ft-seal">🏛</div>
            <p>Official Document</p>
        </div>
    </div>
</div>
</body>
</html>
