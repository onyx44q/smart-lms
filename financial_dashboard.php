<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'financial_accountant') {
    header("Location: index.php");
    exit();
}

$fa_id   = $_SESSION['user_id'];
$fa_name = $_SESSION['user_name'];

// ── Auto-generate receipt number helper ──────────────────────────────
function generateReceipt($conn) {
    $res = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM fee_payments"));
    return 'RCP-' . date('Y') . '-' . str_pad(($res['c'] + 1), 5, '0', STR_PAD_LEFT);
}

// ── HANDLE ACTIONS ───────────────────────────────────────────────────

// 1. Create Fee Structure
if (isset($_POST['action']) && $_POST['action'] === 'create_fee_structure') {
    $name     = mysqli_real_escape_string($conn, trim($_POST['name']));
    $desc     = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));
    $amount   = floatval($_POST['amount']);
    $cat      = mysqli_real_escape_string($conn, $_POST['fee_category']);
    $yr       = mysqli_real_escape_string($conn, $_POST['academic_year']);
    $sem      = mysqli_real_escape_string($conn, $_POST['semester']);
    $cid      = intval($_POST['course_id'] ?? 0) ?: 'NULL';
    $mand     = intval($_POST['is_mandatory'] ?? 1);
    mysqli_query($conn, "INSERT INTO fee_structures (name, description, amount, fee_category, academic_year, semester, course_id, is_mandatory, created_by)
        VALUES ('$name','$desc',$amount,'$cat','$yr','$sem'," . ($cid === 'NULL' ? 'NULL' : $cid) . ",$mand,$fa_id)");
    header("Location: financial_dashboard.php?tab=structures&msg=Fee+structure+created");
    exit();
}

// 2. Assign Fee to Student
if (isset($_POST['action']) && $_POST['action'] === 'assign_fee') {
    $student_id   = intval($_POST['student_id']);
    $fee_id       = intval($_POST['fee_structure_id']);
    $discount     = floatval($_POST['discount_amount'] ?? 0);
    $disc_reason  = mysqli_real_escape_string($conn, $_POST['discount_reason'] ?? '');
    $due_date     = mysqli_real_escape_string($conn, $_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days')));

    $fee_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM fee_structures WHERE id=$fee_id"));
    if ($fee_row) {
        $total  = floatval($fee_row['amount']);
        $net    = max(0, $total - $discount);
        $yr     = $fee_row['academic_year'];
        $sem    = $fee_row['semester'];
        mysqli_query($conn, "INSERT IGNORE INTO student_fee_assignments 
            (student_id, fee_structure_id, total_amount, discount_amount, discount_reason, net_amount, academic_year, semester, due_date, assigned_by)
            VALUES ($student_id,$fee_id,$total,$discount,'$disc_reason',$net,'$yr','$sem','$due_date',$fa_id)");
    }
    header("Location: financial_dashboard.php?tab=students&msg=Fee+assigned");
    exit();
}

// 3. Bulk Assign Fee to All Students (or all in a course)
if (isset($_POST['action']) && $_POST['action'] === 'bulk_assign') {
    $fee_id   = intval($_POST['fee_structure_id']);
    $course_f = intval($_POST['filter_course_id'] ?? 0);
    $due_date = mysqli_real_escape_string($conn, $_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days')));
    $fee_row  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM fee_structures WHERE id=$fee_id"));
    if ($fee_row) {
        $total = floatval($fee_row['amount']);
        $yr    = $fee_row['academic_year'];
        $sem   = $fee_row['semester'];
        $where = $course_f ? "WHERE u.course_id=$course_f OR u.id IN (SELECT student_id FROM enrollments WHERE course_id=$course_f)" : "";
        $stus  = mysqli_query($conn, "SELECT DISTINCT u.id FROM users u $where AND u.role='student'");
        while ($s = mysqli_fetch_assoc($stus)) {
            $sid = intval($s['id']);
            mysqli_query($conn, "INSERT IGNORE INTO student_fee_assignments
                (student_id,fee_structure_id,total_amount,discount_amount,net_amount,academic_year,semester,due_date,assigned_by)
                VALUES ($sid,$fee_id,$total,0,$total,'$yr','$sem','$due_date',$fa_id)");
        }
    }
    header("Location: financial_dashboard.php?tab=students&msg=Bulk+fee+assigned");
    exit();
}

// 4. Record Payment
if (isset($_POST['action']) && $_POST['action'] === 'record_payment') {
    $student_id   = intval($_POST['student_id']);
    $assign_id    = intval($_POST['fee_assignment_id'] ?? 0);
    $amount_paid  = floatval($_POST['amount_paid']);
    $method       = mysqli_real_escape_string($conn, $_POST['payment_method']);
    $txn_ref      = mysqli_real_escape_string($conn, trim($_POST['transaction_ref'] ?? ''));
    $notes        = mysqli_real_escape_string($conn, trim($_POST['notes'] ?? ''));
    $pay_date     = mysqli_real_escape_string($conn, $_POST['payment_date'] ?? date('Y-m-d'));
    $receipt      = generateReceipt($conn);
    $assign_val   = $assign_id ?: 'NULL';

    mysqli_query($conn, "INSERT INTO fee_payments 
        (student_id, fee_assignment_id, amount_paid, payment_method, transaction_ref, receipt_number, notes, payment_date, recorded_by)
        VALUES ($student_id," . ($assign_val === 'NULL' ? 'NULL' : $assign_val) . ",$amount_paid,'$method','$txn_ref','$receipt','$notes','$pay_date',$fa_id)");

    // ── After recording, redistribute ALL payments (linked+general) across all fee assignments ──
    $total_paid_global = floatval(mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(amount_paid),0) AS t FROM fee_payments WHERE student_id=$student_id"))['t']);
    $assignments_all = mysqli_query($conn,
        "SELECT id, net_amount, status FROM student_fee_assignments WHERE student_id=$student_id AND status != 'waived' ORDER BY due_date ASC, assigned_at ASC");
    $rem = $total_paid_global;
    while ($arow = mysqli_fetch_assoc($assignments_all)) {
        $net  = floatval($arow['net_amount']);
        $aid  = intval($arow['id']);
        if ($rem >= $net) {
            mysqli_query($conn, "UPDATE student_fee_assignments SET status='paid' WHERE id=$aid");
            $rem -= $net;
        } elseif ($rem > 0) {
            mysqli_query($conn, "UPDATE student_fee_assignments SET status='partial' WHERE id=$aid");
            $rem = 0;
        } else {
            $new_stat = (!empty($arow['due_date']) && strtotime($arow['due_date']) < time()) ? 'overdue' : 'pending';
            mysqli_query($conn, "UPDATE student_fee_assignments SET status='$new_stat' WHERE id=$aid");
        }
    }
    header("Location: financial_dashboard.php?tab=payments&msg=Payment+recorded&receipt=$receipt");
    exit();
}

// 5. Send Reminder
if (isset($_POST['action']) && $_POST['action'] === 'send_reminder') {
    $student_id = intval($_POST['student_id']);
    $msg        = mysqli_real_escape_string($conn, trim($_POST['message']));
    mysqli_query($conn, "INSERT INTO fee_reminders (student_id, message, sent_by) VALUES ($student_id,'$msg',$fa_id)");
    header("Location: financial_dashboard.php?tab=students&msg=Reminder+sent");
    exit();
}

// 6. Update Fee Status (manual override)
if (isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $assign_id = intval($_POST['assign_id']);
    $status    = mysqli_real_escape_string($conn, $_POST['status']);
    mysqli_query($conn, "UPDATE student_fee_assignments SET status='$status' WHERE id=$assign_id");
    header("Location: financial_dashboard.php?tab=students&msg=Status+updated");
    exit();
}

// 7. Delete Fee Structure
if (isset($_POST['action']) && $_POST['action'] === 'delete_structure') {
    $sid = intval($_POST['structure_id']);
    mysqli_query($conn, "DELETE FROM student_fee_assignments WHERE fee_structure_id=$sid");
    mysqli_query($conn, "DELETE FROM fee_structures WHERE id=$sid");
    header("Location: financial_dashboard.php?tab=structures&msg=Fee+structure+deleted");
    exit();
}

// ── DATA AGGREGATION ─────────────────────────────────────────────────

// Overview stats
$total_students  = intval(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM users WHERE role='student'"))['c']);
$total_invoiced  = floatval(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(net_amount),0) c FROM student_fee_assignments"))['c']);
$total_collected = floatval(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount_paid),0) c FROM fee_payments"))['c']);
$total_balance   = $total_invoiced - $total_collected;
$overdue_count   = intval(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM student_fee_assignments WHERE status='overdue' OR (due_date < CURDATE() AND status NOT IN ('paid','waived'))"))['c']);

// Monthly collections (last 6 months)
$monthly_data = [];
for ($i = 5; $i >= 0; $i--) {
    $month_start = date('Y-m-01', strtotime("-$i months"));
    $month_end   = date('Y-m-t', strtotime("-$i months"));
    $label       = date('M Y', strtotime("-$i months"));
    $res = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount_paid),0) AS total FROM fee_payments 
        WHERE payment_date BETWEEN '$month_start' AND '$month_end'"));
    $monthly_data[] = ['label' => $label, 'amount' => floatval($res['total'])];
}

// Fee structures — use status-based collected amounts (works with general payments)
$structures_res = mysqli_query($conn, "SELECT fs.*, c.title AS course_name,
    COUNT(DISTINCT sfa.id) AS assigned_count,
    COUNT(DISTINCT CASE WHEN sfa.status='paid' THEN sfa.id END) AS paid_count,
    COUNT(DISTINCT CASE WHEN sfa.status='partial' THEN sfa.id END) AS partial_count,
    COALESCE(SUM(sfa.net_amount),0) AS total_invoiced,
    COALESCE(SUM(CASE WHEN sfa.status IN ('paid','waived') THEN sfa.net_amount ELSE 0 END),0) AS total_paid
    FROM fee_structures fs
    LEFT JOIN courses c ON c.id=fs.course_id
    LEFT JOIN student_fee_assignments sfa ON sfa.fee_structure_id=fs.id
    GROUP BY fs.id ORDER BY fs.created_at DESC");
$structures = [];
while ($r = mysqli_fetch_assoc($structures_res)) $structures[] = $r;

// ── Sync fee statuses for all students based on global payments ──
$all_students_sync = mysqli_query($conn, "SELECT DISTINCT student_id FROM student_fee_assignments");
while ($sync_row = mysqli_fetch_assoc($all_students_sync)) {
    $sync_sid = intval($sync_row['student_id']);
    $sync_total = floatval(mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(amount_paid),0) AS t FROM fee_payments WHERE student_id=$sync_sid"))['t']);
    $sync_assigns = mysqli_query($conn,
        "SELECT id, net_amount, due_date FROM student_fee_assignments WHERE student_id=$sync_sid AND status != 'waived' ORDER BY due_date ASC, assigned_at ASC");
    $sync_rem = $sync_total;
    while ($sa = mysqli_fetch_assoc($sync_assigns)) {
        $sa_net = floatval($sa['net_amount']);
        $sa_id  = intval($sa['id']);
        if ($sync_rem >= $sa_net) {
            mysqli_query($conn, "UPDATE student_fee_assignments SET status='paid' WHERE id=$sa_id AND status != 'paid'");
            $sync_rem -= $sa_net;
        } elseif ($sync_rem > 0) {
            mysqli_query($conn, "UPDATE student_fee_assignments SET status='partial' WHERE id=$sa_id AND status != 'partial'");
            $sync_rem = 0;
        } else {
            $ov = (!empty($sa['due_date']) && strtotime($sa['due_date']) < time()) ? 'overdue' : 'pending';
            mysqli_query($conn, "UPDATE student_fee_assignments SET status='$ov' WHERE id=$sa_id AND status NOT IN ('overdue','pending')");
        }
    }
}

// Students with fee summary
$students_res = mysqli_query($conn, "SELECT u.id, u.full_name, u.email, u.created_at,
    COUNT(DISTINCT sfa.id) AS fee_count,
    COALESCE(SUM(sfa.net_amount),0) AS total_assigned,
    COALESCE((SELECT SUM(fp2.amount_paid) FROM fee_payments fp2 WHERE fp2.student_id=u.id),0) AS total_paid,
    GREATEST(0, COALESCE(SUM(sfa.net_amount),0) - COALESCE((SELECT SUM(fp2.amount_paid) FROM fee_payments fp2 WHERE fp2.student_id=u.id),0)) AS balance,
    MAX(CASE WHEN sfa.status='overdue' THEN 1 ELSE 0 END) AS has_overdue,
    SUM(CASE WHEN sfa.status='paid' THEN 1 ELSE 0 END) AS paid_count,
    COUNT(DISTINCT fr.id) AS reminder_count
    FROM users u
    LEFT JOIN student_fee_assignments sfa ON sfa.student_id=u.id
    LEFT JOIN fee_reminders fr ON fr.student_id=u.id
    WHERE u.role='student'
    GROUP BY u.id ORDER BY balance DESC");
$students = [];
while ($r = mysqli_fetch_assoc($students_res)) $students[] = $r;

// Recent payments
$payments_res = mysqli_query($conn, "SELECT fp.*, u.full_name AS student_name, u.email,
    fs.name AS fee_name, fa.full_name AS recorded_by_name
    FROM fee_payments fp
    JOIN users u ON u.id=fp.student_id
    LEFT JOIN student_fee_assignments sfa ON sfa.id=fp.fee_assignment_id
    LEFT JOIN fee_structures fs ON fs.id=sfa.fee_structure_id
    JOIN users fa ON fa.id=fp.recorded_by
    ORDER BY fp.created_at DESC LIMIT 50");
$payments = [];
while ($r = mysqli_fetch_assoc($payments_res)) $payments[] = $r;

// Courses for filters
$courses_list = mysqli_query($conn, "SELECT id, title FROM courses ORDER BY title");
$courses = [];
while ($r = mysqli_fetch_assoc($courses_list)) $courses[] = $r;

// Payment method breakdown
$method_res = mysqli_query($conn, "SELECT payment_method, COUNT(*) AS cnt, SUM(amount_paid) AS total FROM fee_payments GROUP BY payment_method");
$method_data = [];
while ($r = mysqli_fetch_assoc($method_res)) $method_data[] = $r;

$active_tab = $_GET['tab'] ?? 'overview';
$msg = $_GET['msg'] ?? '';
$receipt_flash = $_GET['receipt'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Financial Accountant | Smart LMS</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root {
    --emerald: #10b981;
    --emerald-dark: #059669;
    --emerald-light: #d1fae5;
    --gold: #f59e0b;
    --gold-dark: #d97706;
    --navy: #0f172a;
    --navy-mid: #1e293b;
    --navy-light: #334155;
    --surface: #f8fafc;
    --card: #ffffff;
    --text-main: #0f172a;
    --text-muted: #64748b;
    --danger: #ef4444;
    --warning: #f59e0b;
}
* { font-family: 'Sora', sans-serif; box-sizing: border-box; }
body { background: var(--surface); color: var(--text-main); }

/* Sidebar */
.sidebar { background: var(--navy); width: 260px; min-height: 100vh; position: fixed; left:0; top:0; z-index:40; display:flex; flex-direction:column; }
.sidebar-logo { padding: 28px 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.07); }
.sidebar-logo .brand { font-size: 20px; font-weight: 800; color: #fff; letter-spacing: -0.5px; }
.sidebar-logo .brand span { color: var(--emerald); }
.sidebar-badge { background: var(--emerald); color: #fff; font-size: 9px; font-weight: 700; letter-spacing: 0.1em; padding: 2px 8px; border-radius: 20px; text-transform: uppercase; }
.nav-section { padding: 8px 12px 4px; font-size: 9px; font-weight: 700; color: rgba(255,255,255,0.3); letter-spacing: 0.15em; text-transform: uppercase; margin-top: 8px; }
.nav-item { display: flex; align-items: center; gap: 12px; padding: 11px 16px; margin: 2px 8px; border-radius: 10px; cursor: pointer; transition: all 0.2s; color: rgba(255,255,255,0.55); font-size: 13px; font-weight: 500; text-decoration: none; }
.nav-item:hover { background: rgba(255,255,255,0.06); color: #fff; }
.nav-item.active { background: var(--emerald); color: #fff; font-weight: 600; }
.nav-item .icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.08); font-size: 13px; flex-shrink: 0; }
.nav-item.active .icon { background: rgba(255,255,255,0.2); }

/* Main content */
.main { margin-left: 260px; padding: 32px; min-height: 100vh; }
.topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px; }
.page-title { font-size: 24px; font-weight: 800; color: var(--navy); }
.page-subtitle { font-size: 13px; color: var(--text-muted); margin-top: 2px; }

/* Stat cards */
.stat-card { background: var(--card); border-radius: 16px; padding: 24px; border: 1px solid #e2e8f0; position: relative; overflow: hidden; transition: transform 0.2s, box-shadow 0.2s; }
.stat-card:hover { transform: translateY(-2px); box-shadow: 0 12px 24px rgba(0,0,0,0.08); }
.stat-card .accent-line { position: absolute; top: 0; left: 0; right: 0; height: 3px; }
.stat-value { font-size: 30px; font-weight: 800; font-family: 'JetBrains Mono', monospace; margin: 8px 0 4px; }
.stat-label { font-size: 11px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-muted); }
.stat-change { font-size: 11px; font-weight: 600; margin-top: 6px; }
.stat-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; }

/* Section card */
.section-card { background: var(--card); border-radius: 16px; border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 24px; }
.section-header { padding: 20px 24px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; }
.section-title { font-size: 15px; font-weight: 700; color: var(--navy); }
.section-body { padding: 24px; }

/* Table */
.data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.data-table th { background: #f8fafc; padding: 10px 14px; text-align: left; font-size: 10px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-muted); border-bottom: 1px solid #e2e8f0; }
.data-table td { padding: 13px 14px; border-bottom: 1px solid #f1f5f9; color: var(--text-main); vertical-align: middle; }
.data-table tr:last-child td { border-bottom: none; }
.data-table tr:hover td { background: #f8fafc; }
.mono { font-family: 'JetBrains Mono', monospace; }

/* Badges */
.badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; }
.badge-paid { background: #d1fae5; color: #065f46; }
.badge-partial { background: #fef3c7; color: #92400e; }
.badge-pending { background: #f1f5f9; color: #475569; }
.badge-overdue { background: #fee2e2; color: #991b1b; }
.badge-waived { background: #ede9fe; color: #4c1d95; }
.badge-tuition { background: #dbeafe; color: #1e40af; }
.badge-exam { background: #fce7f3; color: #831843; }
.badge-other { background: #f3f4f6; color: #374151; }

/* Buttons */
.btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 18px; border-radius: 10px; font-size: 12px; font-weight: 700; cursor: pointer; transition: all 0.2s; border: none; text-decoration: none; letter-spacing: 0.03em; }
.btn-primary { background: var(--emerald); color: #fff; }
.btn-primary:hover { background: var(--emerald-dark); }
.btn-gold { background: var(--gold); color: #fff; }
.btn-gold:hover { background: var(--gold-dark); }
.btn-danger { background: #fee2e2; color: #dc2626; }
.btn-danger:hover { background: #fecaca; }
.btn-ghost { background: #f1f5f9; color: var(--navy); }
.btn-ghost:hover { background: #e2e8f0; }
.btn-sm { padding: 6px 12px; font-size: 11px; border-radius: 7px; }

/* Forms */
.form-group { margin-bottom: 16px; }
.form-label { display: block; font-size: 10px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 6px; }
.form-input { width: 100%; padding: 10px 14px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-size: 13px; color: var(--text-main); background: #f8fafc; transition: border-color 0.2s; outline: none; font-family: 'Sora', sans-serif; }
.form-input:focus { border-color: var(--emerald); background: #fff; }
.form-select { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2364748b'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; background-size: 16px; }

/* Modal */
.modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,0.6); backdrop-filter: blur(4px); z-index: 100; display: flex; align-items: center; justify-content: center; padding: 16px; opacity: 0; visibility: hidden; transition: all 0.25s; }
.modal-overlay.open { opacity: 1; visibility: visible; }
.modal-box { background: #fff; border-radius: 20px; padding: 32px; width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto; transform: translateY(20px); transition: transform 0.25s; }
.modal-overlay.open .modal-box { transform: translateY(0); }
.modal-title { font-size: 18px; font-weight: 800; color: var(--navy); margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }

/* Toast */
.toast { position: fixed; top: 24px; right: 24px; z-index: 999; background: var(--navy); color: #fff; padding: 14px 20px; border-radius: 12px; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 10px; box-shadow: 0 8px 24px rgba(0,0,0,0.2); transform: translateX(120%); transition: transform 0.4s cubic-bezier(0.22,1,0.36,1); }
.toast.show { transform: translateX(0); }
.toast.success .dot { color: var(--emerald); }
.toast.info .dot { color: var(--gold); }

/* Tab content */
.tab-panel { display: none; }
.tab-panel.active { display: block; }

/* Chart container */
.chart-wrap { position: relative; height: 260px; }

/* Progress bar */
.progress-bar { height: 8px; border-radius: 9999px; background: #e2e8f0; overflow: hidden; position: relative; }
.progress-fill { height: 100%; border-radius: 9999px; transition: width 1.2s cubic-bezier(0.22,1,0.36,1); min-width: 0; }
.progress-bar.lg { height: 12px; }
.progress-bar.sm { height: 5px; }
/* Responsive progress labels */
.prog-row { display:flex; justify-content:space-between; align-items:center; font-size:12px; margin-bottom:5px; }
.prog-row .prog-name { font-weight:600; color:var(--text-main); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:70%; }
.prog-row .prog-pct  { font-family:'JetBrains Mono',monospace; font-weight:700; font-size:11px; flex-shrink:0; }
@media(max-width:640px) {
    .progress-bar { height: 7px; }
    .progress-bar.lg { height: 10px; }
    .prog-row .prog-name { font-size:11px; max-width:60%; }
}

/* KPI ring */
.kpi-ring { width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; position: relative; flex-shrink:0; }
.kpi-ring::before { content: ''; position: absolute; inset: 6px; border-radius: 50%; background: white; }
.kpi-ring span { position: relative; z-index: 1; font-size: 14px; font-weight: 800; font-family: 'JetBrains Mono', monospace; }

/* ── RESPONSIVE ─────────────────────────────────────────── */
.chart-wrap { position: relative; height: 260px; }
@media (max-width: 900px) {
    .main { margin-left: 0 !important; padding: 16px !important; }
    .sidebar { transform: translateX(-100%); transition: transform .3s; z-index: 200; }
    .sidebar.open { transform: translateX(0); }
    .mob-menu-btn { display: flex !important; }
    .stat-value { font-size: 22px !important; }
}
@media (max-width: 640px) {
    .chart-wrap { height: 200px; }
    .stat-card { padding: 16px; }
    .section-body { padding: 14px !important; }
    .kpi-ring { width: 64px; height: 64px; }
    .kpi-ring span { font-size: 11px; }
}
/* Responsive grid overrides — applied via JS class toggle */
.dash-charts-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 24px; }
.dash-bottom-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
@media (max-width: 900px) {
    .dash-charts-grid { grid-template-columns: 1fr !important; }
    .dash-bottom-grid { grid-template-columns: 1fr !important; }
    .dash-kpi-grid  { grid-template-columns: 1fr 1fr !important; }
}
@media (max-width: 480px) {
    .dash-kpi-grid  { grid-template-columns: 1fr !important; }
}

/* Report card */
.report-metric { border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 16px 20px; display: flex; align-items: center; justify-content: space-between; }

/* Sidebar footer */
.sidebar-footer { margin-top: auto; padding: 16px 12px; border-top: 1px solid rgba(255,255,255,0.07); }

@keyframes fadeSlide { from { opacity:0; transform: translateY(10px); } to { opacity:1; transform: none; } }
.anim { animation: fadeSlide 0.4s ease forwards; }
</style>
</head>
<body>

<!-- ─── FIXED LOGOUT BAR ─────────────────────────────────────── -->
<div style="position:fixed;top:0;right:0;z-index:999;padding:12px 20px;">
    <a href="logout.php"
       style="display:inline-flex;align-items:center;gap:8px;background:#fff;border:1.5px solid #e2e8f0;
              color:#ef4444;padding:9px 18px;border-radius:12px;font-size:12px;font-weight:800;
              text-decoration:none;box-shadow:0 4px 12px rgba(0,0,0,0.08);transition:all .2s;letter-spacing:.02em;"
       onmouseover="this.style.background='#fef2f2';this.style.borderColor='#fca5a5'"
       onmouseout="this.style.background='#fff';this.style.borderColor='#e2e8f0'">
        <i class="fa-solid fa-arrow-right-from-bracket"></i> Logout
    </a>
</div>

<!-- ─── TOAST ─────────────────────────────────────────────────── -->
<?php if ($msg): ?>
<div id="toast" class="toast success show">
    <i class="fa-solid fa-circle-check dot"></i>
    <span><?= htmlspecialchars(urldecode($msg)) ?></span>
    <?php if ($receipt_flash): ?><span class="text-emerald-300 font-mono ml-2"><?= htmlspecialchars($receipt_flash) ?></span><?php endif; ?>
</div>
<script>setTimeout(() => { const t=document.getElementById('toast'); if(t){ t.style.transform='translateX(120%)'; setTimeout(()=>t.remove(),400); } }, 4000);</script>
<?php endif; ?>

<!-- ─── SIDEBAR ───────────────────────────────────────────────── -->
<aside class="sidebar">
    <div class="sidebar-logo">
        <div class="brand">Smart<span>LMS</span></div>
        <div style="margin-top:6px; display:flex; align-items:center; gap:8px;">
            <span class="sidebar-badge"><i class="fa-solid fa-coins" style="font-size:8px;"></i> Finance</span>
        </div>
    </div>

    <nav style="padding:12px 0; flex:1;">
        <div class="nav-section">Main</div>
        <a href="?tab=overview" class="nav-item <?= $active_tab==='overview'?'active':'' ?>">
            <span class="icon"><i class="fa-solid fa-chart-pie"></i></span> Overview
        </a>
        <a href="?tab=students" class="nav-item <?= $active_tab==='students'?'active':'' ?>">
            <span class="icon"><i class="fa-solid fa-users"></i></span> Student Fees
            <?php if (count(array_filter($students, fn($s)=>$s['has_overdue'])) > 0): ?>
            <span style="margin-left:auto;background:#ef4444;color:#fff;font-size:9px;font-weight:700;padding:1px 7px;border-radius:10px;">
                <?= count(array_filter($students, fn($s)=>$s['has_overdue'])) ?>
            </span>
            <?php endif; ?>
        </a>
        <a href="?tab=structures" class="nav-item <?= $active_tab==='structures'?'active':'' ?>">
            <span class="icon"><i class="fa-solid fa-layer-group"></i></span> Fee Structures
        </a>
        <a href="?tab=payments" class="nav-item <?= $active_tab==='payments'?'active':'' ?>">
            <span class="icon"><i class="fa-solid fa-receipt"></i></span> Payments
        </a>

        <div class="nav-section">Tools</div>
        <a href="?tab=reports" class="nav-item <?= $active_tab==='reports'?'active':'' ?>">
            <span class="icon"><i class="fa-solid fa-file-chart-column"></i></span> Reports
        </a>
        <a href="?tab=reminders" class="nav-item <?= $active_tab==='reminders'?'active':'' ?>">
            <span class="icon"><i class="fa-solid fa-bell"></i></span> Reminders
        </a>
        <a href="?tab=assign" class="nav-item <?= $active_tab==='assign'?'active':'' ?>">
            <span class="icon"><i class="fa-solid fa-bolt"></i></span> Bulk Assign
        </a>
    </nav>

    <div class="sidebar-footer">
        <div style="display:flex;align-items:center;gap:10px;padding:10px 8px;">
            <div style="width:36px;height:36px;border-radius:10px;background:var(--emerald);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;color:#fff;">
                <?= strtoupper(substr($fa_name, 0, 1)) ?>
            </div>
            <div>
                <div style="font-size:12px;font-weight:700;color:#fff;"><?= htmlspecialchars($fa_name) ?></div>
                <div style="font-size:10px;color:rgba(255,255,255,0.4);">Financial Accountant</div>
            </div>
        </div>
        <a href="logout.php" class="nav-item" style="margin-top:4px;">
            <span class="icon"><i class="fa-solid fa-arrow-right-from-bracket"></i></span> Sign Out
        </a>
    </div>
</aside>

<!-- ─── MAIN ──────────────────────────────────────────────────── -->
<main class="main">

<!-- Mobile menu toggle (hidden on desktop) -->
<button class="mob-menu-btn" id="mobMenuBtn"
    style="display:none;position:fixed;top:14px;left:14px;z-index:300;width:40px;height:40px;
           background:var(--navy);border:none;border-radius:10px;align-items:center;justify-content:center;cursor:pointer;"
    onclick="document.querySelector('.sidebar').classList.toggle('open')">
    <i class="fa-solid fa-bars" style="color:#fff;font-size:16px;"></i>
</button>
<!-- Mobile overlay -->
<div id="mobOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:199;"
     onclick="document.querySelector('.sidebar').classList.remove('open');document.getElementById('mobOverlay').style.display='none';"></div>

    <!-- ═══ OVERVIEW TAB ══════════════════════════════════════════ -->
    <div class="tab-panel <?= $active_tab==='overview'?'active':'' ?>" id="tab-overview">
        <div class="topbar">
            <div>
                <div class="page-title">Financial Overview</div>
                <div class="page-subtitle">
                    <i class="fa-solid fa-calendar-days" style="color:var(--emerald);margin-right:5px;"></i>
                    <?= date('l, F j, Y') ?> &nbsp;·&nbsp; <?= $total_students ?> registered students
                </div>
            </div>
            <div style="display:flex;gap:10px;">
                <button onclick="openModal('modal-record-pay')" class="btn btn-primary">
                    <i class="fa-solid fa-plus"></i> Record Payment
                </button>
                <button onclick="openModal('modal-create-structure')" class="btn btn-ghost">
                    <i class="fa-solid fa-layer-group"></i> New Fee
                </button>
                <a href="logout.php" class="btn" style="background:#fee2e2;color:#dc2626;">
                    <i class="fa-solid fa-right-from-bracket"></i> Logout
                </a>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="anim dash-kpi-grid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-bottom:28px;">
            <div class="stat-card">
                <div class="accent-line" style="background:linear-gradient(90deg,#10b981,#34d399);"></div>
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <div>
                        <div class="stat-label">Total Collected</div>
                        <div class="stat-value" style="color:var(--emerald);">KES <?= number_format($total_collected, 0) ?></div>
                        <div class="stat-change" style="color:var(--emerald);"><i class="fa-solid fa-arrow-trend-up"></i> All time revenue</div>
                    </div>
                    <div class="stat-icon" style="background:#d1fae5;color:var(--emerald);"><i class="fa-solid fa-coins"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="accent-line" style="background:linear-gradient(90deg,#3b82f6,#60a5fa);"></div>
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <div>
                        <div class="stat-label">Total Invoiced</div>
                        <div class="stat-value" style="color:#3b82f6;">KES <?= number_format($total_invoiced, 0) ?></div>
                        <div class="stat-change" style="color:#3b82f6;"><i class="fa-solid fa-file-invoice"></i> Across all students</div>
                    </div>
                    <div class="stat-icon" style="background:#dbeafe;color:#3b82f6;"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="accent-line" style="background:linear-gradient(90deg,#f59e0b,#fbbf24);"></div>
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <div>
                        <div class="stat-label">Outstanding Balance</div>
                        <div class="stat-value" style="color:var(--gold);">KES <?= number_format(max(0,$total_balance), 0) ?></div>
                        <div class="stat-change" style="color:var(--gold);"><i class="fa-solid fa-hourglass-half"></i> Pending collection</div>
                    </div>
                    <div class="stat-icon" style="background:#fef3c7;color:var(--gold);"><i class="fa-solid fa-clock"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="accent-line" style="background:linear-gradient(90deg,#ef4444,#f87171);"></div>
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <div>
                        <div class="stat-label">Overdue Accounts</div>
                        <div class="stat-value" style="color:#ef4444;"><?= $overdue_count ?></div>
                        <div class="stat-change" style="color:#ef4444;"><i class="fa-solid fa-triangle-exclamation"></i> Past due date</div>
                    </div>
                    <div class="stat-icon" style="background:#fee2e2;color:#ef4444;"><i class="fa-solid fa-circle-exclamation"></i></div>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="dash-charts-grid">
            <div class="section-card anim">
                <div class="section-header">
                    <span class="section-title"><i class="fa-solid fa-chart-bar" style="color:var(--emerald);margin-right:8px;"></i> Monthly Collections</span>
                    <span style="font-size:11px;color:var(--text-muted);">Last 6 months</span>
                </div>
                <div class="section-body">
                    <div class="chart-wrap"><canvas id="monthlyChart"></canvas></div>
                </div>
            </div>
            <div class="section-card anim">
                <div class="section-header">
                    <span class="section-title"><i class="fa-solid fa-chart-donut" style="color:var(--gold);margin-right:8px;"></i> Payment Methods</span>
                </div>
                <div class="section-body">
                    <div class="chart-wrap"><canvas id="methodChart"></canvas></div>
                </div>
            </div>
        </div>

        <!-- Collection rate + top defaulters -->
        <div class="dash-bottom-grid">
            <div class="section-card anim">
                <div class="section-header">
                    <span class="section-title"><i class="fa-solid fa-gauge-high" style="color:#8b5cf6;margin-right:8px;"></i> Collection Rate</span>
                </div>
                <div class="section-body">
                    <?php
                    $rate       = $total_invoiced > 0 ? round(($total_collected/$total_invoiced)*100,1) : 0;
                    $rate_vis   = min(100, $rate); // cap ring fill at 100%
                    $rate_color = $rate >= 100 ? '#10b981' : ($rate >= 80 ? '#10b981' : ($rate >= 50 ? '#f59e0b' : '#ef4444'));
                    $rate_label = $rate >= 100 ? 'Fully collected' : ($rate >= 80 ? 'On track' : ($rate >= 50 ? 'Moderate' : 'Needs attention'));
                    ?>
                    <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;margin-bottom:20px;">
                        <div class="kpi-ring" style="background:conic-gradient(<?= $rate_color ?> <?= $rate_vis ?>%, #e2e8f0 0);flex-shrink:0;">
                            <span style="color:<?= $rate_color ?>;font-size:<?= strlen((string)$rate)>4?'11px':'14px' ?>;"><?= $rate >= 100 ? '✓' : $rate.'%' ?></span>
                        </div>
                        <div style="min-width:0;">
                            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);"><?= $rate_label ?></div>
                            <div style="font-size:26px;font-weight:900;color:<?= $rate_color ?>;font-family:'JetBrains Mono',monospace;line-height:1.1;"><?= $rate ?>%</div>
                            <div style="font-size:11px;color:var(--text-muted);">of total invoiced &nbsp;·&nbsp; KES <?= number_format($total_collected,0) ?> collected</div>
                        </div>
                    </div>
                    <div>
                        <?php if(empty($structures)): ?>
                        <p style="font-size:12px;color:var(--text-muted);text-align:center;padding:12px 0;">No fee structures yet.</p>
                        <?php else: foreach ($structures as $st):
                            $st_inv  = floatval($st['total_invoiced']);
                            $st_paid = floatval($st['total_paid']);
                            $st_rate = $st_inv > 0 ? round(($st_paid/$st_inv)*100,1) : 0;
                            $st_color = $st_rate >= 80 ? '#10b981' : ($st_rate >= 40 ? '#f59e0b' : '#ef4444');
                            $paid_c   = intval($st['paid_count']);
                            $total_c  = intval($st['assigned_count']);
                        ?>
                        <div style="margin-bottom:14px;">
                            <div class="prog-row">
                                <span class="prog-name" title="<?= htmlspecialchars($st['name']) ?>"><?= htmlspecialchars($st['name']) ?></span>
                                <span class="prog-pct" style="color:<?= $st_color ?>;"><?= $st_rate ?>%</span>
                            </div>
                            <div class="progress-bar lg" title="<?= $paid_c ?>/<?= $total_c ?> students paid">
                                <div class="progress-fill" style="width:<?= $st_rate ?>%;background:<?= $st_color ?>;"></div>
                            </div>
                            <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--text-muted);margin-top:3px;">
                                <span><?= $paid_c ?>/<?= $total_c ?> students paid</span>
                                <span style="font-family:'JetBrains Mono',monospace;">KES <?= number_format($st_paid,0) ?> / <?= number_format($st_inv,0) ?></span>
                            </div>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>

            <div class="section-card anim">
                <div class="section-header">
                    <span class="section-title"><i class="fa-solid fa-user-xmark" style="color:#ef4444;margin-right:8px;"></i> Top Outstanding</span>
                    <a href="?tab=students" class="btn btn-ghost btn-sm">View All</a>
                </div>
                <div class="section-body" style="padding:0;">
                    <table class="data-table">
                        <thead><tr><th>Student</th><th>Balance</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice(array_filter($students, fn($s)=>$s['balance']>0), 0, 7) as $s): ?>
                        <tr>
                            <td>
                                <div style="font-weight:600;font-size:13px;"><?= htmlspecialchars($s['full_name']) ?></div>
                                <div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($s['email']) ?></div>
                            </td>
                            <td><span class="mono" style="color:#ef4444;font-weight:700;">KES <?= number_format($s['balance'],0) ?></span></td>
                            <td><?= $s['has_overdue'] ? '<span class="badge badge-overdue">Overdue</span>' : '<span class="badge badge-partial">Partial</span>' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty(array_filter($students, fn($s)=>$s['balance']>0))): ?>
                        <tr><td colspan="3" style="text-align:center;padding:24px;color:var(--text-muted);font-size:13px;"><i class="fa-solid fa-circle-check" style="color:var(--emerald);margin-right:6px;"></i>All fees cleared!</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ STUDENTS TAB ══════════════════════════════════════════ -->
    <div class="tab-panel <?= $active_tab==='students'?'active':'' ?>" id="tab-students">
        <div class="topbar">
            <div>
                <div class="page-title">Student Fee Ledger</div>
                <div class="page-subtitle"><?= count($students) ?> students · manage balances, assign fees, send reminders</div>
            </div>
            <div style="display:flex;gap:10px;">
                <input type="text" id="studentSearch" placeholder="Search students..." onkeyup="filterStudents()" class="form-input" style="width:220px;">
                <button onclick="openModal('modal-assign-fee')" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Assign Fee</button>
            </div>
        </div>

        <!-- Filter chips -->
        <div style="display:flex;gap:8px;margin-bottom:20px;">
            <button onclick="filterByStatus('all')" class="btn btn-ghost btn-sm status-filter active" data-filter="all">All (<?= count($students) ?>)</button>
            <button onclick="filterByStatus('overdue')" class="btn btn-ghost btn-sm status-filter" data-filter="overdue" style="color:#ef4444;">
                <i class="fa-solid fa-circle-exclamation"></i> Overdue (<?= count(array_filter($students,fn($s)=>$s['has_overdue'])) ?>)
            </button>
            <button onclick="filterByStatus('balance')" class="btn btn-ghost btn-sm status-filter" data-filter="balance" style="color:var(--gold);">
                <i class="fa-solid fa-hourglass-half"></i> Outstanding (<?= count(array_filter($students,fn($s)=>$s['balance']>0)) ?>)
            </button>
            <button onclick="filterByStatus('cleared')" class="btn btn-ghost btn-sm status-filter" data-filter="cleared" style="color:var(--emerald);">
                <i class="fa-solid fa-circle-check"></i> Cleared (<?= count(array_filter($students,fn($s)=>$s['balance']<=0 && $s['total_assigned']>0)) ?>)
            </button>
        </div>

        <div class="section-card">
            <div class="section-body" style="padding:0;">
                <table class="data-table" id="studentTable">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Total Assigned</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($students as $s):
                        $balance    = floatval($s['balance']);
                        $paid       = floatval($s['total_paid']);
                        $assigned   = floatval($s['total_assigned']);
                        $pct        = $assigned > 0 ? min(100, round(($paid/$assigned)*100)) : 0;
                        $fully_paid = ($assigned > 0 && $balance <= 0);
                        $row_status = $s['has_overdue'] ? 'overdue' : ($fully_paid ? 'cleared' : ($balance > 0 && $assigned > 0 ? 'balance' : ($assigned > 0 ? 'cleared' : 'none')));
                    ?>
                    <tr data-status="<?= $row_status ?>" data-name="<?= strtolower(htmlspecialchars($s['full_name'])) ?>" data-email="<?= strtolower(htmlspecialchars($s['email'])) ?>">
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <div style="width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--emerald),#34d399);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;color:#fff;flex-shrink:0;">
                                    <?= strtoupper(substr($s['full_name'],0,1)) ?>
                                </div>
                                <div>
                                    <div style="font-weight:600;"><?= htmlspecialchars($s['full_name']) ?></div>
                                    <div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($s['email']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td><span class="mono">KES <?= number_format($assigned,0) ?></span></td>
                        <td>
                            <div><span class="mono" style="color:var(--emerald);">KES <?= number_format($paid,0) ?></span></div>
                            <div class="progress-bar sm" style="margin-top:4px;width:80px;"><div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $pct>=100?'#10b981':($pct>=50?'#3b82f6':'#f59e0b') ?>;"></div></div>
                        </td>
                        <td><span class="mono" style="color:<?= $balance>0?'#ef4444':'var(--emerald)' ?>;font-weight:700;">KES <?= number_format(abs($balance),0) ?><?= $balance<=0&&$assigned>0?' ✓':'' ?></span></td>
                        <td>
                            <?php if ($s['has_overdue']): ?>
                                <span class="badge badge-overdue"><i class="fa-solid fa-triangle-exclamation"></i> Overdue</span>
                            <?php elseif ($fully_paid): ?>
                                <span class="badge badge-paid"><i class="fa-solid fa-shield-check"></i> Cleared</span>
                            <?php elseif ($paid > 0): ?>
                                <span class="badge badge-partial">Partial</span>
                            <?php elseif ($assigned > 0): ?>
                                <span class="badge badge-pending">Pending</span>
                            <?php else: ?>
                                <span class="badge badge-other">No Fees</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                <button onclick="openPayModal(<?= $s['id'] ?>, '<?= addslashes($s['full_name']) ?>')" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> Pay</button>
                                <button onclick="openAssignModal(<?= $s['id'] ?>, '<?= addslashes($s['full_name']) ?>')" class="btn btn-ghost btn-sm"><i class="fa-solid fa-file-invoice"></i> Assign</button>
                                <button onclick="openReminderModal(<?= $s['id'] ?>, '<?= addslashes($s['full_name']) ?>')" class="btn btn-ghost btn-sm" style="color:var(--gold);"><i class="fa-solid fa-bell"></i></button>
                                <button onclick="viewStudentLedger(<?= $s['id'] ?>)" class="btn btn-ghost btn-sm"><i class="fa-solid fa-eye"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($students)): ?>
                    <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">No students found</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══ FEE STRUCTURES TAB ════════════════════════════════════ -->
    <div class="tab-panel <?= $active_tab==='structures'?'active':'' ?>" id="tab-structures">
        <div class="topbar">
            <div>
                <div class="page-title">Fee Structures</div>
                <div class="page-subtitle">Define tuition, exam, library, and other institutional fees</div>
            </div>
            <button onclick="openModal('modal-create-structure')" class="btn btn-primary"><i class="fa-solid fa-plus"></i> New Fee Structure</button>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:20px;">
        <?php foreach ($structures as $st):
            $st_paid  = floatval($st['total_paid']);
            $st_inv   = floatval($st['total_invoiced']);
            $st_rate  = $st_inv > 0 ? round(($st_paid/$st_inv)*100,1) : 0;
            $st_color = $st_rate >= 80 ? '#10b981' : ($st_rate >= 40 ? '#f59e0b' : ($st_rate > 0 ? '#3b82f6' : '#94a3b8'));
            $cat_colors = ['tuition'=>'#dbeafe:#1e40af','examination'=>'#fce7f3:#831843','library'=>'#d1fae5:#065f46','accommodation'=>'#ede9fe:#4c1d95','transport'=>'#fef3c7:#92400e','medical'=>'#fee2e2:#991b1b','activity'=>'#e0f2fe:#075985','other'=>'#f3f4f6:#374151'];
            [$bg,$fg] = explode(':', $cat_colors[$st['fee_category']] ?? '#f3f4f6:#374151');
        ?>
        <div class="section-card" style="margin-bottom:0;">
            <div style="padding:20px 22px;border-bottom:1px solid #f1f5f9;">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;">
                    <div>
                        <div style="font-size:15px;font-weight:700;color:var(--navy);"><?= htmlspecialchars($st['name']) ?></div>
                        <div style="margin-top:5px;display:flex;gap:6px;flex-wrap:wrap;">
                            <span class="badge" style="background:<?= $bg ?>;color:<?= $fg ?>;"><?= ucfirst($st['fee_category']) ?></span>
                            <span class="badge badge-other"><?= htmlspecialchars($st['semester']) ?></span>
                            <?= $st['is_mandatory'] ? '<span class="badge" style="background:#fee2e2;color:#991b1b;">Mandatory</span>' : '<span class="badge badge-pending">Optional</span>' ?>
                        </div>
                    </div>
                    <div style="text-align:right;flex-shrink:0;">
                        <div style="font-size:20px;font-weight:800;font-family:\'JetBrains Mono\',monospace;color:var(--navy);">KES <?= number_format($st['amount'],0) ?></div>
                        <div style="font-size:10px;color:var(--text-muted);"><?= htmlspecialchars($st['academic_year']) ?></div>
                    </div>
                </div>
            </div>
            <div style="padding:16px 22px;">
                <?php if ($st['description']): ?>
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;"><?= htmlspecialchars($st['description']) ?></div>
                <?php endif; ?>
                <div style="display:flex;gap:16px;font-size:12px;margin-bottom:12px;">
                    <div><span style="color:var(--text-muted);">Assigned to</span><br><strong><?= $st['assigned_count'] ?> students</strong></div>
                    <div><span style="color:var(--text-muted);">Invoiced</span><br><strong class="mono">KES <?= number_format($st_inv,0) ?></strong></div>
                    <div><span style="color:var(--text-muted);">Collected</span><br><strong class="mono" style="color:var(--emerald);">KES <?= number_format($st_paid,0) ?></strong></div>
                </div>
                <div style="margin-bottom:14px;">
                    <div class="prog-row" style="margin-bottom:5px;">
                        <span style="color:var(--text-muted);font-size:11px;">Collection rate</span>
                        <span class="prog-pct" style="color:<?= $st_color ?>;"><?= $st_rate ?>%</span>
                    </div>
                    <div class="progress-bar lg">
                        <div class="progress-fill" style="width:<?= $st_rate ?>%;background:<?= $st_color ?>;"></div>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--text-muted);margin-top:3px;">
                        <span><?= intval($st['paid_count']) ?>/<?= intval($st['assigned_count']) ?> students paid</span>
                        <span style="font-family:'JetBrains Mono',monospace;">KES <?= number_format($st_paid,0) ?></span>
                    </div>
                </div>
                <div style="display:flex;gap:8px;">
                    <button onclick="openAssignStructureModal(<?= $st['id'] ?>, '<?= addslashes($st['name']) ?>')" class="btn btn-primary btn-sm" style="flex:1;justify-content:center;"><i class="fa-solid fa-user-plus"></i> Assign</button>
                    <form method="POST" onsubmit="return confirm('Delete this fee structure and all assignments?');" style="display:inline;">
                        <input type="hidden" name="action" value="delete_structure">
                        <input type="hidden" name="structure_id" value="<?= $st['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i></button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($structures)): ?>
        <div style="grid-column:1/-1;text-align:center;padding:60px;color:var(--text-muted);">
            <i class="fa-solid fa-layer-group" style="font-size:40px;opacity:0.3;display:block;margin-bottom:12px;"></i>
            No fee structures yet. <button onclick="openModal('modal-create-structure')" class="btn btn-primary btn-sm" style="margin-left:8px;">Create First</button>
        </div>
        <?php endif; ?>
        </div>
    </div>

    <!-- ═══ PAYMENTS TAB ══════════════════════════════════════════ -->
    <div class="tab-panel <?= $active_tab==='payments'?'active':'' ?>" id="tab-payments">
        <div class="topbar">
            <div>
                <div class="page-title">Payment Ledger</div>
                <div class="page-subtitle"><?= count($payments) ?> transactions recorded</div>
            </div>
            <button onclick="openModal('modal-record-pay')" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Record Payment</button>
        </div>

        <div class="section-card">
            <div class="section-body" style="padding:0;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Receipt #</th>
                            <th>Student</th>
                            <th>Fee</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Ref / TXN</th>
                            <th>Date</th>
                            <th>Recorded By</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($payments as $p):
                        $method_icons = ['cash'=>'fa-money-bill-wave:#10b981','bank_transfer'=>'fa-building-columns:#3b82f6','mpesa'=>'fa-mobile-screen:#22c55e','cheque'=>'fa-file-lines:#8b5cf6','online'=>'fa-globe:#06b6d4','scholarship'=>'fa-graduation-cap:#f59e0b'];
                        [$icon,$color] = explode(':', $method_icons[$p['payment_method']] ?? 'fa-circle:#94a3b8');
                    ?>
                    <tr>
                        <td><span class="mono" style="background:#f1f5f9;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700;"><?= htmlspecialchars($p['receipt_number']) ?></span></td>
                        <td>
                            <div style="font-weight:600;"><?= htmlspecialchars($p['student_name']) ?></div>
                            <div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($p['email']) ?></div>
                        </td>
                        <td style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($p['fee_name'] ?? '—') ?></td>
                        <td><span class="mono" style="color:var(--emerald);font-weight:700;">KES <?= number_format($p['amount_paid'],0) ?></span></td>
                        <td>
                            <span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;">
                                <i class="fa-solid <?= $icon ?>" style="color:<?= $color ?>;"></i>
                                <?= ucfirst(str_replace('_',' ',$p['payment_method'])) ?>
                            </span>
                        </td>
                        <td><span class="mono" style="font-size:11px;"><?= htmlspecialchars($p['transaction_ref'] ?: '—') ?></span></td>
                        <td style="font-size:12px;"><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
                        <td style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($p['recorded_by_name']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($payments)): ?>
                    <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted);">No payments recorded yet</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══ REPORTS TAB ═══════════════════════════════════════════ -->
    <div class="tab-panel <?= $active_tab==='reports'?'active':'' ?>" id="tab-reports">
        <div class="topbar">
            <div>
                <div class="page-title">Financial Reports</div>
                <div class="page-subtitle">Summaries, defaulters, and collection analytics</div>
            </div>
            <button onclick="window.print()" class="btn btn-ghost"><i class="fa-solid fa-print"></i> Print Report</button>
        </div>

        <!-- Summary Metrics -->
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px;">
            <?php
            $paid_count    = count(array_filter($students,fn($s)=>$s['balance']<=0&&$s['total_assigned']>0));
            $partial_count = count(array_filter($students,fn($s)=>$s['total_paid']>0&&$s['balance']>0));
            $pending_count = count(array_filter($students,fn($s)=>$s['total_paid']==0&&$s['total_assigned']>0));
            ?>
            <div class="report-metric">
                <div>
                    <div style="font-size:11px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.07em;">Fully Paid</div>
                    <div style="font-size:28px;font-weight:800;color:var(--emerald);"><?= $paid_count ?></div>
                    <div style="font-size:11px;color:var(--text-muted);">students cleared</div>
                </div>
                <i class="fa-solid fa-circle-check" style="font-size:32px;color:var(--emerald);opacity:.4;"></i>
            </div>
            <div class="report-metric">
                <div>
                    <div style="font-size:11px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.07em;">Partial Payment</div>
                    <div style="font-size:28px;font-weight:800;color:var(--gold);"><?= $partial_count ?></div>
                    <div style="font-size:11px;color:var(--text-muted);">students with balance</div>
                </div>
                <i class="fa-solid fa-hourglass-half" style="font-size:32px;color:var(--gold);opacity:.4;"></i>
            </div>
            <div class="report-metric">
                <div>
                    <div style="font-size:11px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.07em;">Not Paid</div>
                    <div style="font-size:28px;font-weight:800;color:#ef4444;"><?= $pending_count ?></div>
                    <div style="font-size:11px;color:var(--text-muted);">students defaulting</div>
                </div>
                <i class="fa-solid fa-xmark-circle" style="font-size:32px;color:#ef4444;opacity:.4;"></i>
            </div>
        </div>

        <!-- Defaulters List -->
        <div class="section-card">
            <div class="section-header">
                <span class="section-title"><i class="fa-solid fa-user-xmark" style="color:#ef4444;margin-right:8px;"></i> Defaulters Report</span>
                <span style="font-size:11px;color:var(--text-muted);">Students with outstanding balances</span>
            </div>
            <div class="section-body" style="padding:0;">
                <table class="data-table">
                    <thead><tr><th>#</th><th>Student</th><th>Invoiced</th><th>Paid</th><th>Balance</th><th>Overdue</th></tr></thead>
                    <tbody>
                    <?php $i=1; foreach (array_filter($students,fn($s)=>$s['balance']>0) as $s): ?>
                    <tr>
                        <td style="color:var(--text-muted);font-size:12px;"><?= $i++ ?></td>
                        <td>
                            <div style="font-weight:600;"><?= htmlspecialchars($s['full_name']) ?></div>
                            <div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($s['email']) ?></div>
                        </td>
                        <td><span class="mono">KES <?= number_format($s['total_assigned'],0) ?></span></td>
                        <td><span class="mono" style="color:var(--emerald);">KES <?= number_format($s['total_paid'],0) ?></span></td>
                        <td><span class="mono" style="color:#ef4444;font-weight:700;">KES <?= number_format($s['balance'],0) ?></span></td>
                        <td><?= $s['has_overdue'] ? '<span class="badge badge-overdue">Yes</span>' : '<span class="badge badge-pending">No</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty(array_filter($students,fn($s)=>$s['balance']>0))): ?>
                    <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted);"><i class="fa-solid fa-party-horn" style="color:var(--emerald);margin-right:6px;"></i> All students have cleared their fees!</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══ REMINDERS TAB ═════════════════════════════════════════ -->
    <div class="tab-panel <?= $active_tab==='reminders'?'active':'' ?>" id="tab-reminders">
        <div class="topbar">
            <div>
                <div class="page-title">Fee Reminders</div>
                <div class="page-subtitle">Notify students about outstanding payments</div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
            <!-- Send reminder -->
            <div class="section-card">
                <div class="section-header"><span class="section-title"><i class="fa-solid fa-paper-plane" style="color:var(--emerald);margin-right:8px;"></i> Send Reminder</span></div>
                <div class="section-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="send_reminder">
                        <div class="form-group">
                            <label class="form-label">Select Student</label>
                            <select name="student_id" class="form-input form-select" required>
                                <option value="">Choose student...</option>
                                <?php foreach ($students as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['full_name']) ?> — Balance: KES <?= number_format($s['balance'],0) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Message</label>
                            <textarea name="message" class="form-input" rows="5" required placeholder="Dear student, this is a reminder that your fee balance is outstanding. Please visit the finance office to settle your account at your earliest convenience."></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;"><i class="fa-solid fa-paper-plane"></i> Send Reminder</button>
                    </form>
                </div>
            </div>

            <!-- Bulk reminder -->
            <div class="section-card">
                <div class="section-header"><span class="section-title"><i class="fa-solid fa-bullhorn" style="color:var(--gold);margin-right:8px;"></i> Quick Templates</span></div>
                <div class="section-body">
                    <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">Students with outstanding balances who need reminders:</p>
                    <?php foreach (array_filter($students,fn($s)=>$s['balance']>0) as $s): ?>
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:12px;background:#f8fafc;border-radius:10px;margin-bottom:8px;">
                        <div>
                            <div style="font-weight:600;font-size:13px;"><?= htmlspecialchars($s['full_name']) ?></div>
                            <div style="font-size:11px;color:#ef4444;font-family:'JetBrains Mono',monospace;">KES <?= number_format($s['balance'],0) ?> outstanding</div>
                        </div>
                        <button onclick="quickReminder(<?= $s['id'] ?>, '<?= addslashes($s['full_name']) ?>', <?= $s['balance'] ?>)" class="btn btn-gold btn-sm">
                            <i class="fa-solid fa-bell"></i> Remind
                        </button>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty(array_filter($students,fn($s)=>$s['balance']>0))): ?>
                    <div style="text-align:center;padding:30px;color:var(--text-muted);">
                        <i class="fa-solid fa-circle-check" style="color:var(--emerald);font-size:32px;margin-bottom:10px;display:block;"></i>
                        No outstanding balances!
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ BULK ASSIGN TAB ════════════════════════════════════════ -->
    <div class="tab-panel <?= $active_tab==='assign'?'active':'' ?>" id="tab-assign">
        <div class="topbar">
            <div>
                <div class="page-title">Bulk Fee Assignment</div>
                <div class="page-subtitle">Assign a fee structure to all students at once</div>
            </div>
        </div>
        <div class="section-card" style="max-width:560px;">
            <div class="section-header"><span class="section-title"><i class="fa-solid fa-bolt" style="color:var(--gold);margin-right:8px;"></i> Assign to All Students</span></div>
            <div class="section-body">
                <form method="POST">
                    <input type="hidden" name="action" value="bulk_assign">
                    <div class="form-group">
                        <label class="form-label">Fee Structure</label>
                        <select name="fee_structure_id" class="form-input form-select" required>
                            <option value="">Choose fee structure...</option>
                            <?php foreach ($structures as $st): ?>
                            <option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['name']) ?> — KES <?= number_format($st['amount'],0) ?> (<?= $st['academic_year'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Filter by Course (Optional)</label>
                        <select name="filter_course_id" class="form-input form-select">
                            <option value="">All Students</option>
                            <?php foreach ($courses as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Due Date</label>
                        <input type="date" name="due_date" class="form-input" value="<?= date('Y-m-d', strtotime('+30 days')) ?>" required>
                    </div>
                    <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:10px;padding:14px;margin-bottom:16px;font-size:12px;color:#92400e;">
                        <i class="fa-solid fa-triangle-exclamation" style="margin-right:6px;"></i>
                        This will assign the selected fee to <strong><?= count($students) ?> students</strong>. Existing assignments are ignored (no duplicates).
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;font-size:14px;padding:12px;">
                        <i class="fa-solid fa-bolt"></i> Bulk Assign Fee
                    </button>
                </form>
            </div>
        </div>
    </div>

</main>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- MODALS -->
<!-- ═══════════════════════════════════════════════════════════════ -->

<!-- Create Fee Structure -->
<div id="modal-create-structure" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-title"><span style="width:36px;height:36px;border-radius:10px;background:var(--emerald);display:flex;align-items:center;justify-content:center;"><i class="fa-solid fa-layer-group" style="color:#fff;font-size:14px;"></i></span> New Fee Structure</div>
        <form method="POST">
            <input type="hidden" name="action" value="create_fee_structure">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Fee Name</label>
                    <input name="name" class="form-input" placeholder="e.g. Semester 1 Tuition Fee 2025" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Amount (KES)</label>
                    <input name="amount" type="number" step="0.01" class="form-input" placeholder="0.00" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="fee_category" class="form-input form-select">
                        <option value="tuition">Tuition</option>
                        <option value="examination">Examination</option>
                        <option value="library">Library</option>
                        <option value="accommodation">Accommodation</option>
                        <option value="transport">Transport</option>
                        <option value="medical">Medical</option>
                        <option value="activity">Activity</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Academic Year</label>
                    <input name="academic_year" class="form-input" placeholder="2025/2026" value="<?= date('Y') . '/' . (date('Y')+1) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Semester</label>
                    <select name="semester" class="form-input form-select">
                        <option>Semester 1</option>
                        <option>Semester 2</option>
                        <option>Full Year</option>
                        <option>One Time</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Linked Course (optional)</label>
                    <select name="course_id" class="form-input form-select">
                        <option value="">All Courses</option>
                        <?php foreach ($courses as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Mandatory?</label>
                    <select name="is_mandatory" class="form-input form-select">
                        <option value="1">Yes</option>
                        <option value="0">No</option>
                    </select>
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Description (optional)</label>
                    <textarea name="description" class="form-input" rows="2" placeholder="Fee description..."></textarea>
                </div>
            </div>
            <div style="display:flex;gap:10px;margin-top:8px;">
                <button type="button" onclick="closeModal('modal-create-structure')" class="btn btn-ghost" style="flex:1;justify-content:center;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="flex:2;justify-content:center;"><i class="fa-solid fa-plus"></i> Create Structure</button>
            </div>
        </form>
    </div>
</div>

<!-- Record Payment -->
<div id="modal-record-pay" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-title"><span style="width:36px;height:36px;border-radius:10px;background:var(--emerald);display:flex;align-items:center;justify-content:center;"><i class="fa-solid fa-receipt" style="color:#fff;font-size:14px;"></i></span> Record Payment</div>
        <form method="POST" id="payForm">
            <input type="hidden" name="action" value="record_payment">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Student</label>
                    <select name="student_id" id="pay-student-select" class="form-input form-select" required onchange="loadStudentFees(this.value)">
                        <option value="">Select student...</option>
                        <?php foreach ($students as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['full_name']) ?> (Bal: KES <?= number_format($s['balance'],0) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Fee Assignment (optional)</label>
                    <select name="fee_assignment_id" id="pay-fee-select" class="form-input form-select">
                        <option value="">General Payment</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Amount Paid (KES)</label>
                    <input name="amount_paid" type="number" step="0.01" class="form-input" placeholder="0.00" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" class="form-input form-select">
                        <option value="cash">Cash</option>
                        <option value="mpesa">M-Pesa</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="cheque">Cheque</option>
                        <option value="online">Online</option>
                        <option value="scholarship">Scholarship/Bursary</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Transaction Ref (optional)</label>
                    <input name="transaction_ref" class="form-input" placeholder="e.g. QK7A2X...">
                </div>
                <div class="form-group">
                    <label class="form-label">Payment Date</label>
                    <input name="payment_date" type="date" class="form-input" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Notes (optional)</label>
                    <input name="notes" class="form-input" placeholder="Any additional notes...">
                </div>
            </div>
            <div style="display:flex;gap:10px;margin-top:8px;">
                <button type="button" onclick="closeModal('modal-record-pay')" class="btn btn-ghost" style="flex:1;justify-content:center;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="flex:2;justify-content:center;"><i class="fa-solid fa-check"></i> Record Payment</button>
            </div>
        </form>
    </div>
</div>

<!-- Assign Fee to Student -->
<div id="modal-assign-fee" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-title"><span style="width:36px;height:36px;border-radius:10px;background:#3b82f6;display:flex;align-items:center;justify-content:center;"><i class="fa-solid fa-file-invoice-dollar" style="color:#fff;font-size:14px;"></i></span> Assign Fee to Student</div>
        <form method="POST">
            <input type="hidden" name="action" value="assign_fee">
            <div class="form-group">
                <label class="form-label">Student</label>
                <select name="student_id" id="assign-student-select" class="form-input form-select" required>
                    <option value="">Select student...</option>
                    <?php foreach ($students as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Fee Structure</label>
                <select name="fee_structure_id" class="form-input form-select" required>
                    <option value="">Select fee...</option>
                    <?php foreach ($structures as $st): ?>
                    <option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['name']) ?> — KES <?= number_format($st['amount'],0) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group">
                    <label class="form-label">Discount (KES)</label>
                    <input name="discount_amount" type="number" step="0.01" class="form-input" value="0">
                </div>
                <div class="form-group">
                    <label class="form-label">Due Date</label>
                    <input name="due_date" type="date" class="form-input" value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Discount Reason (optional)</label>
                <input name="discount_reason" class="form-input" placeholder="e.g. Scholarship, Bursary...">
            </div>
            <div style="display:flex;gap:10px;margin-top:8px;">
                <button type="button" onclick="closeModal('modal-assign-fee')" class="btn btn-ghost" style="flex:1;justify-content:center;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="flex:2;justify-content:center;"><i class="fa-solid fa-check"></i> Assign Fee</button>
            </div>
        </form>
    </div>
</div>

<!-- Reminder Modal -->
<div id="modal-reminder" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-title"><span style="width:36px;height:36px;border-radius:10px;background:var(--gold);display:flex;align-items:center;justify-content:center;"><i class="fa-solid fa-bell" style="color:#fff;font-size:14px;"></i></span> Send Fee Reminder</div>
        <form method="POST">
            <input type="hidden" name="action" value="send_reminder">
            <input type="hidden" name="student_id" id="reminder-student-id">
            <div class="form-group">
                <label class="form-label">To</label>
                <input id="reminder-student-name" class="form-input" readonly style="background:#f8fafc;">
            </div>
            <div class="form-group">
                <label class="form-label">Message</label>
                <textarea name="message" id="reminder-message" class="form-input" rows="5" required></textarea>
            </div>
            <div style="display:flex;gap:10px;margin-top:8px;">
                <button type="button" onclick="closeModal('modal-reminder')" class="btn btn-ghost" style="flex:1;justify-content:center;">Cancel</button>
                <button type="submit" class="btn btn-gold" style="flex:2;justify-content:center;"><i class="fa-solid fa-paper-plane"></i> Send Reminder</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── CHARTS & JS ───────────────────────────────────────────────── -->
<script>
// Charts
const monthlyCtx = document.getElementById('monthlyChart');
if (monthlyCtx) {
    new Chart(monthlyCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($monthly_data,'label')) ?>,
            datasets: [{
                label: 'Collections (KES)',
                data: <?= json_encode(array_column($monthly_data,'amount')) ?>,
                backgroundColor: 'rgba(16,185,129,0.15)',
                borderColor: '#10b981',
                borderWidth: 2,
                borderRadius: 8,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { font: { family: 'Sora', size: window.innerWidth < 640 ? 9 : 11 }, maxRotation: 45 } },
                y: { grid: { color: '#f1f5f9' }, ticks: {
                    font: { family: 'JetBrains Mono', size: window.innerWidth < 640 ? 9 : 10 },
                    callback: v => v >= 1000 ? 'KES ' + (v/1000).toFixed(0) + 'K' : 'KES ' + v
                }}
            },
            onResize: function(chart, size) {
                chart.options.scales.x.ticks.font.size = size.width < 400 ? 8 : 10;
                chart.options.scales.y.ticks.font.size = size.width < 400 ? 8 : 10;
            }
        }
    });
}

const methodCtx = document.getElementById('methodChart');
if (methodCtx) {
    const md = <?= json_encode($method_data) ?>;
    const labels = md.map(m => m.payment_method.replace('_',' ').replace(/\b\w/g,l=>l.toUpperCase()));
    const data   = md.map(m => parseFloat(m.total));
    const colors = ['#10b981','#22c55e','#3b82f6','#8b5cf6','#f59e0b','#ef4444'];
    new Chart(methodCtx, {
        type: 'doughnut',
        data: { labels, datasets: [{ data, backgroundColor: colors, borderWidth: 2, borderColor: '#fff', hoverOffset: 6 }] },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: {
                position: window.innerWidth < 500 ? 'bottom' : 'bottom',
                labels: { font: { family: 'Sora', size: window.innerWidth < 500 ? 9 : 11 }, padding: window.innerWidth < 500 ? 8 : 12, boxWidth: 12 }
            }},
            onResize: function(chart, size) {
                chart.options.plugins.legend.labels.font.size = size.width < 300 ? 8 : 10;
                chart.options.plugins.legend.labels.padding = size.width < 300 ? 6 : 10;
            }
        }
    });
}

// Mobile sidebar
const mobBtn = document.getElementById('mobMenuBtn');
const overlay = document.getElementById('mobOverlay');
if (mobBtn) {
    document.querySelector('.sidebar').addEventListener('transitionend', function() {
        if (this.classList.contains('open')) {
            overlay.style.display = 'block';
        } else {
            overlay.style.display = 'none';
        }
    });
}
// Show mob button when screen is narrow
function checkMob() {
    const btn = document.getElementById('mobMenuBtn');
    if (btn) btn.style.display = window.innerWidth <= 900 ? 'flex' : 'none';
}
checkMob();
window.addEventListener('resize', checkMob);

// Modal helpers
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});

// Open pay modal pre-filled
function openPayModal(studentId, name) {
    document.getElementById('pay-student-select').value = studentId;
    loadStudentFees(studentId);
    openModal('modal-record-pay');
}

// Open assign modal pre-filled
function openAssignModal(studentId, name) {
    document.getElementById('assign-student-select').value = studentId;
    openModal('modal-assign-fee');
}

// Open assign structure modal
function openAssignStructureModal(feeId, name) {
    document.querySelector('#modal-assign-fee [name="fee_structure_id"]').value = feeId;
    openModal('modal-assign-fee');
}

// Load student fee assignments for payment modal
function loadStudentFees(studentId) {
    if (!studentId) return;
    fetch('?ajax=fees&student_id=' + studentId)
        .then(r => r.json())
        .then(fees => {
            const sel = document.getElementById('pay-fee-select');
            sel.innerHTML = '<option value="">General Payment</option>';
            fees.forEach(f => {
                sel.innerHTML += `<option value="${f.id}">${f.fee_name} — Balance: KES ${parseFloat(f.balance).toLocaleString()}</option>`;
            });
        }).catch(() => {});
}

// Reminder modal
function openReminderModal(studentId, name) {
    document.getElementById('reminder-student-id').value = studentId;
    document.getElementById('reminder-student-name').value = name;
    document.getElementById('reminder-message').value = `Dear ${name},\n\nThis is a reminder from the Finance Office that your fee account has an outstanding balance. Please visit the finance office or make your payment at the earliest convenience to avoid any disruption to your academic activities.\n\nThank you.\nFinance Department`;
    openModal('modal-reminder');
}

// Quick reminder
function quickReminder(id, name, balance) {
    document.getElementById('reminder-student-id').value = id;
    document.getElementById('reminder-student-name').value = name;
    document.getElementById('reminder-message').value = `Dear ${name},\n\nYour current outstanding fee balance is KES ${parseFloat(balance).toLocaleString()}. Please settle this amount by visiting the Finance Office or using the student portal.\n\nThank you.\nFinance Department`;
    openModal('modal-reminder');
}

// Student ledger view
function viewStudentLedger(studentId) {
    window.location.href = '?tab=payments&filter_student=' + studentId;
}

// Filter students
function filterStudents() {
    const q = document.getElementById('studentSearch').value.toLowerCase();
    document.querySelectorAll('#studentTable tbody tr').forEach(row => {
        const name  = row.dataset.name || '';
        const email = row.dataset.email || '';
        row.style.display = (name.includes(q) || email.includes(q)) ? '' : 'none';
    });
}

// Filter by status
function filterByStatus(status) {
    document.querySelectorAll('.status-filter').forEach(b => b.classList.remove('active'));
    event.target.classList.add('active');
    document.querySelectorAll('#studentTable tbody tr').forEach(row => {
        if (status === 'all') { row.style.display = ''; return; }
        row.style.display = (row.dataset.status === status) ? '' : 'none';
    });
}
</script>

<?php
// ── AJAX for loading student fee assignments ─────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'fees') {
    $sid = intval($_GET['student_id'] ?? 0);
    $res = mysqli_query($conn, "SELECT sfa.id, fs.name AS fee_name,
        sfa.net_amount,
        COALESCE((SELECT SUM(fp.amount_paid) FROM fee_payments fp WHERE fp.fee_assignment_id=sfa.id),0) AS paid,
        sfa.net_amount - COALESCE((SELECT SUM(fp.amount_paid) FROM fee_payments fp WHERE fp.fee_assignment_id=sfa.id),0) AS balance
        FROM student_fee_assignments sfa
        JOIN fee_structures fs ON fs.id=sfa.fee_structure_id
        WHERE sfa.student_id=$sid AND sfa.status NOT IN ('paid','waived')");
    $out = [];
    while ($r = mysqli_fetch_assoc($res)) $out[] = $r;
    header('Content-Type: application/json');
    echo json_encode($out);
    exit();
}
?>
</body>
</html>