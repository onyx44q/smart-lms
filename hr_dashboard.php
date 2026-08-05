<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'hr_manager') {
    header("Location: index.php"); exit();
}
$hr_id   = $_SESSION['user_id'];
$hr_name = $_SESSION['user_name'];

// ── Auto-create HR tables ─────────────────────────────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `hr_staff` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT NULL,
  `staff_no` VARCHAR(30) DEFAULT NULL,
  `full_name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `gender` ENUM('male','female','other') DEFAULT NULL,
  `date_of_birth` DATE DEFAULT NULL,
  `national_id` VARCHAR(30) DEFAULT NULL,
  `department` VARCHAR(100) DEFAULT NULL,
  `job_title` VARCHAR(150) DEFAULT NULL,
  `job_type` ENUM('full_time','part_time','contract','intern') DEFAULT 'full_time',
  `employment_date` DATE DEFAULT NULL,
  `termination_date` DATE DEFAULT NULL,
  `status` ENUM('active','on_leave','terminated','suspended') DEFAULT 'active',
  `basic_salary` DECIMAL(12,2) DEFAULT 0.00,
  `allowances` DECIMAL(12,2) DEFAULT 0.00,
  `deductions` DECIMAL(12,2) DEFAULT 0.00,
  `bank_name` VARCHAR(100) DEFAULT NULL,
  `bank_account` VARCHAR(50) DEFAULT NULL,
  `emergency_contact` VARCHAR(150) DEFAULT NULL,
  `emergency_phone` VARCHAR(30) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `profile_notes` TEXT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY(`department`), KEY(`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `hr_leave_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `staff_id` INT NOT NULL,
  `leave_type` ENUM('annual','sick','maternity','paternity','emergency','unpaid','other') DEFAULT 'annual',
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `days_requested` INT NOT NULL DEFAULT 1,
  `reason` TEXT DEFAULT NULL,
  `status` ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending',
  `reviewed_by` INT DEFAULT NULL,
  `review_notes` TEXT DEFAULT NULL,
  `reviewed_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY(`staff_id`), KEY(`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `hr_payroll` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `staff_id` INT NOT NULL,
  `pay_period` VARCHAR(30) NOT NULL,
  `basic_salary` DECIMAL(12,2) DEFAULT 0.00,
  `allowances` DECIMAL(12,2) DEFAULT 0.00,
  `overtime` DECIMAL(12,2) DEFAULT 0.00,
  `gross_pay` DECIMAL(12,2) DEFAULT 0.00,
  `paye` DECIMAL(12,2) DEFAULT 0.00,
  `nhif` DECIMAL(12,2) DEFAULT 0.00,
  `nssf` DECIMAL(12,2) DEFAULT 0.00,
  `other_deductions` DECIMAL(12,2) DEFAULT 0.00,
  `net_pay` DECIMAL(12,2) DEFAULT 0.00,
  `payment_method` ENUM('bank_transfer','cash','mpesa','cheque') DEFAULT 'bank_transfer',
  `status` ENUM('draft','processed','paid') DEFAULT 'draft',
  `processed_by` INT DEFAULT NULL,
  `processed_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY(`staff_id`), KEY(`pay_period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `hr_announcements` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(200) NOT NULL,
  `message` TEXT NOT NULL,
  `target_department` VARCHAR(100) DEFAULT NULL,
  `priority` ENUM('normal','urgent','info') DEFAULT 'normal',
  `posted_by` INT NOT NULL,
  `is_active` TINYINT DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `hr_performance` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `staff_id` INT NOT NULL,
  `review_period` VARCHAR(30) NOT NULL,
  `attendance_score` DECIMAL(4,1) DEFAULT 0.0,
  `performance_score` DECIMAL(4,1) DEFAULT 0.0,
  `teamwork_score` DECIMAL(4,1) DEFAULT 0.0,
  `initiative_score` DECIMAL(4,1) DEFAULT 0.0,
  `overall_score` DECIMAL(4,1) DEFAULT 0.0,
  `comments` TEXT DEFAULT NULL,
  `reviewed_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY(`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── HANDLE ACTIONS ────────────────────────────────────────────────────
$msg = ''; $msg_type = 'info';

// Add Staff
if (isset($_POST['action']) && $_POST['action'] === 'add_staff') {
    $fn   = mysqli_real_escape_string($conn, trim($_POST['full_name']));
    $em   = mysqli_real_escape_string($conn, trim($_POST['email']));
    $ph   = mysqli_real_escape_string($conn, trim($_POST['phone'] ?? ''));
    $gen  = mysqli_real_escape_string($conn, $_POST['gender'] ?? 'male');
    $dob  = mysqli_real_escape_string($conn, $_POST['date_of_birth'] ?? '');
    $nid  = mysqli_real_escape_string($conn, trim($_POST['national_id'] ?? ''));
    $dept = mysqli_real_escape_string($conn, trim($_POST['department']));
    $jt   = mysqli_real_escape_string($conn, trim($_POST['job_title']));
    $jtyp = mysqli_real_escape_string($conn, $_POST['job_type'] ?? 'full_time');
    $edt  = mysqli_real_escape_string($conn, $_POST['employment_date'] ?? date('Y-m-d'));
    $sal  = floatval($_POST['basic_salary'] ?? 0);
    $all  = floatval($_POST['allowances'] ?? 0);
    $ded  = floatval($_POST['deductions'] ?? 0);
    $bank = mysqli_real_escape_string($conn, trim($_POST['bank_name'] ?? ''));
    $bacc = mysqli_real_escape_string($conn, trim($_POST['bank_account'] ?? ''));
    $ec   = mysqli_real_escape_string($conn, trim($_POST['emergency_contact'] ?? ''));
    $ep   = mysqli_real_escape_string($conn, trim($_POST['emergency_phone'] ?? ''));
    $notes= mysqli_real_escape_string($conn, trim($_POST['profile_notes'] ?? ''));

    // Auto generate staff no
    $cnt = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*)+1 AS c FROM hr_staff"))['c'];
    $sno = 'STF-' . date('Y') . '-' . str_pad($cnt, 4, '0', STR_PAD_LEFT);

    mysqli_query($conn, "INSERT INTO hr_staff (staff_no,full_name,email,phone,gender,date_of_birth,national_id,department,job_title,job_type,employment_date,basic_salary,allowances,deductions,bank_name,bank_account,emergency_contact,emergency_phone,profile_notes,created_by)
        VALUES ('$sno','$fn','$em','$ph','$gen',".($dob?"'$dob'":'NULL').",'$nid','$dept','$jt','$jtyp','$edt',$sal,$all,$ded,'$bank','$bacc','$ec','$ep','$notes',$hr_id)");
    header("Location: hr_dashboard.php?tab=staff&msg=Staff+member+added&mtype=success"); exit();
}

// Edit Staff
if (isset($_POST['action']) && $_POST['action'] === 'edit_staff') {
    $sid  = intval($_POST['staff_id']);
    $dept = mysqli_real_escape_string($conn, trim($_POST['department']));
    $jt   = mysqli_real_escape_string($conn, trim($_POST['job_title']));
    $jtyp = mysqli_real_escape_string($conn, $_POST['job_type']);
    $sal  = floatval($_POST['basic_salary']);
    $all  = floatval($_POST['allowances']);
    $ded  = floatval($_POST['deductions']);
    $sts  = mysqli_real_escape_string($conn, $_POST['status']);
    $ph   = mysqli_real_escape_string($conn, trim($_POST['phone'] ?? ''));
    $bank = mysqli_real_escape_string($conn, trim($_POST['bank_name'] ?? ''));
    $bacc = mysqli_real_escape_string($conn, trim($_POST['bank_account'] ?? ''));
    $notes= mysqli_real_escape_string($conn, trim($_POST['profile_notes'] ?? ''));
    mysqli_query($conn, "UPDATE hr_staff SET department='$dept',job_title='$jt',job_type='$jtyp',basic_salary=$sal,allowances=$all,deductions=$ded,status='$sts',phone='$ph',bank_name='$bank',bank_account='$bacc',profile_notes='$notes' WHERE id=$sid");
    header("Location: hr_dashboard.php?tab=staff&msg=Staff+updated&mtype=success"); exit();
}

// Terminate Staff
if (isset($_POST['action']) && $_POST['action'] === 'terminate') {
    $sid = intval($_POST['staff_id']);
    $tdt = mysqli_real_escape_string($conn, $_POST['termination_date'] ?? date('Y-m-d'));
    mysqli_query($conn, "UPDATE hr_staff SET status='terminated', termination_date='$tdt' WHERE id=$sid");
    header("Location: hr_dashboard.php?tab=staff&msg=Staff+terminated&mtype=success"); exit();
}

// Review Leave
if (isset($_POST['action']) && $_POST['action'] === 'review_leave') {
    $lid     = intval($_POST['leave_id']);
    $status  = mysqli_real_escape_string($conn, $_POST['leave_status']);
    $rnotes  = mysqli_real_escape_string($conn, trim($_POST['review_notes'] ?? ''));
    mysqli_query($conn, "UPDATE hr_leave_requests SET status='$status', reviewed_by=$hr_id, review_notes='$rnotes', reviewed_at=NOW() WHERE id=$lid");
    // If approved, update staff status
    if ($status === 'approved') {
        $lr = mysqli_fetch_assoc(mysqli_query($conn, "SELECT staff_id FROM hr_leave_requests WHERE id=$lid"));
        if ($lr) mysqli_query($conn, "UPDATE hr_staff SET status='on_leave' WHERE id={$lr['staff_id']}");
    }
    header("Location: hr_dashboard.php?tab=leaves&msg=Leave+reviewed&mtype=success"); exit();
}

// Process Payroll
if (isset($_POST['action']) && $_POST['action'] === 'process_payroll') {
    $period = mysqli_real_escape_string($conn, $_POST['pay_period']);
    // Get all active staff
    $staff_res = mysqli_query($conn, "SELECT hs.* FROM hr_staff hs WHERE hs.status='active'");
    $count = 0;
    while ($s = mysqli_fetch_assoc($staff_res)) {
        $gross = $s['basic_salary'] + $s['allowances'];
        // Simple Kenyan PAYE approximation
        $paye  = $gross > 57667 ? ($gross - 57667) * 0.3 + 10500 : ($gross > 32667 ? ($gross - 32667) * 0.25 + 5000 : ($gross > 12298 ? ($gross - 12298) * 0.2 + 0 : 0));
        $nhif  = min(1700, max(150, round($gross * 0.015)));
        $nssf  = min(200, round($gross * 0.06));
        $net   = $gross - $paye - $nhif - $nssf - $s['deductions'];
        // Skip if already processed for this period
        $ex = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM hr_payroll WHERE staff_id={$s['id']} AND pay_period='$period'"));
        if (!$ex) {
            mysqli_query($conn, "INSERT INTO hr_payroll (staff_id,pay_period,basic_salary,allowances,gross_pay,paye,nhif,nssf,net_pay,status,processed_by,processed_at)
                VALUES ({$s['id']},'$period',{$s['basic_salary']},{$s['allowances']},$gross,$paye,$nhif,$nssf,$net,'processed',$hr_id,NOW())");
            $count++;
        }
    }
    header("Location: hr_dashboard.php?tab=payroll&msg=Payroll+processed+for+$count+staff&mtype=success"); exit();
}

// Mark payroll paid
if (isset($_POST['action']) && $_POST['action'] === 'mark_paid') {
    $pid = intval($_POST['payroll_id']);
    mysqli_query($conn, "UPDATE hr_payroll SET status='paid' WHERE id=$pid");
    header("Location: hr_dashboard.php?tab=payroll&msg=Marked+as+paid&mtype=success"); exit();
}

// Post Announcement
if (isset($_POST['action']) && $_POST['action'] === 'post_announcement') {
    $title   = mysqli_real_escape_string($conn, trim($_POST['title']));
    $message = mysqli_real_escape_string($conn, trim($_POST['message']));
    $dept    = mysqli_real_escape_string($conn, trim($_POST['target_department'] ?? ''));
    $prio    = mysqli_real_escape_string($conn, $_POST['priority'] ?? 'normal');
    mysqli_query($conn, "INSERT INTO hr_announcements (title,message,target_department,priority,posted_by) VALUES ('$title','$message',".($dept?"'$dept'":'NULL').",'$prio',$hr_id)");
    header("Location: hr_dashboard.php?tab=announcements&msg=Announcement+posted&mtype=success"); exit();
}

// Add Performance Review
if (isset($_POST['action']) && $_POST['action'] === 'add_review') {
    $sid  = intval($_POST['staff_id']);
    $per  = mysqli_real_escape_string($conn, $_POST['review_period']);
    $a    = floatval($_POST['attendance_score']);
    $p    = floatval($_POST['performance_score']);
    $t    = floatval($_POST['teamwork_score']);
    $i    = floatval($_POST['initiative_score']);
    $ov   = round(($a + $p + $t + $i) / 4, 1);
    $cmt  = mysqli_real_escape_string($conn, trim($_POST['comments'] ?? ''));
    mysqli_query($conn, "INSERT INTO hr_performance (staff_id,review_period,attendance_score,performance_score,teamwork_score,initiative_score,overall_score,comments,reviewed_by)
        VALUES ($sid,'$per',$a,$p,$t,$i,$ov,'$cmt',$hr_id)");
    header("Location: hr_dashboard.php?tab=performance&msg=Review+added&mtype=success"); exit();
}

$msg      = htmlspecialchars($_GET['msg'] ?? '');
$msg_type = htmlspecialchars($_GET['mtype'] ?? 'info');
$tab      = htmlspecialchars($_GET['tab'] ?? 'overview');

// ── DATA ─────────────────────────────────────────────────────────────
// ── Pull ALL users from system and MERGE with hr_staff records ──────
// First auto-import any user who isn't already in hr_staff
$import_res = mysqli_query($conn,
    "SELECT u.id, u.full_name, u.email, u.role, u.created_at
     FROM users u
     WHERE u.role != 'student'
       AND NOT EXISTS (SELECT 1 FROM hr_staff hs WHERE hs.user_id = u.id OR hs.email = u.email)");
if ($import_res) {
    while ($imp = mysqli_fetch_assoc($import_res)) {
        $fn  = mysqli_real_escape_string($conn, $imp['full_name']);
        $em  = mysqli_real_escape_string($conn, $imp['email']);
        $cnt2 = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*)+1 AS c FROM hr_staff"))['c'];
        $sno = 'STF-'.date('Y').'-'.str_pad($cnt2,4,'0',STR_PAD_LEFT);
        $dept = match($imp['role']) {
            'lecturer' => 'Academics',
            'admin' => 'Administration',
            'financial_accountant' => 'Finance',
            'boarding_master' => 'Student Affairs',
            'hr_manager' => 'Human Resources',
            default => 'General'
        };
        $jt = match($imp['role']) {
            'lecturer' => 'Lecturer',
            'admin' => 'System Administrator',
            'financial_accountant' => 'Financial Accountant',
            'boarding_master' => 'Boarding Master',
            'hr_manager' => 'HR Manager',
            default => ucwords(str_replace('_',' ',$imp['role']))
        };
        $uid = intval($imp['id']);
        mysqli_query($conn, "INSERT IGNORE INTO hr_staff (user_id,staff_no,full_name,email,department,job_title,job_type,employment_date,status,created_by)
            VALUES ($uid,'$sno','$fn','$em','$dept','$jt','full_time','".date('Y-m-d',strtotime($imp['created_at']))."','active',$hr_id)");
    }
}

$staff_res = mysqli_query($conn,
    "SELECT hs.*, COALESCE(u.role,'') AS system_role
     FROM hr_staff hs
     LEFT JOIN users u ON u.id = hs.user_id OR u.email = hs.email
     GROUP BY hs.id
     ORDER BY hs.status ASC, hs.full_name ASC");
$staff_all = [];
while ($s = mysqli_fetch_assoc($staff_res)) $staff_all[] = $s;

$active_staff  = array_filter($staff_all, fn($s) => $s['status'] === 'active');
$onleave_staff = array_filter($staff_all, fn($s) => $s['status'] === 'on_leave');
$total_salary  = array_sum(array_column(array_values($active_staff), 'basic_salary'));

$departments = array_unique(array_filter(array_column($staff_all, 'department')));

$leaves_res = mysqli_query($conn,
    "SELECT lr.*, s.full_name, s.department,
            u.full_name AS user_name, u.role AS user_role
     FROM hr_leave_requests lr
     LEFT JOIN hr_staff s ON s.id = lr.staff_id
     LEFT JOIN users u ON u.id = lr.user_id
     ORDER BY lr.created_at DESC LIMIT 100");
$leaves = [];
while ($l = mysqli_fetch_assoc($leaves_res)) {
    // Use user name if staff name missing
    if (empty($l['full_name'])) $l['full_name'] = $l['user_name'] ?? 'Unknown';
    if (empty($l['department'])) $l['department'] = ucwords(str_replace('_',' ',$l['user_role'] ?? ''));
    $leaves[] = $l;
}
$pending_leaves = array_filter($leaves, fn($l) => $l['status'] === 'pending');

$payroll_res = mysqli_query($conn, "SELECT pr.*, s.full_name, s.job_title, s.department FROM hr_payroll pr JOIN hr_staff s ON s.id=pr.staff_id ORDER BY pr.created_at DESC LIMIT 200");
$payroll = [];
while ($p = mysqli_fetch_assoc($payroll_res)) $payroll[] = $p;

$announcements_res = mysqli_query($conn, "SELECT a.*, u.full_name AS poster FROM hr_announcements a JOIN users u ON u.id=a.posted_by WHERE a.is_active=1 ORDER BY a.created_at DESC LIMIT 30");
$announcements = [];
while ($a = mysqli_fetch_assoc($announcements_res)) $announcements[] = $a;

$perf_res = mysqli_query($conn, "SELECT pr.*, s.full_name, s.department FROM hr_performance pr JOIN hr_staff s ON s.id=pr.staff_id ORDER BY pr.created_at DESC LIMIT 100");
$reviews = [];
while ($r = mysqli_fetch_assoc($perf_res)) $reviews[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HR Dashboard — SmartLMS</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@700;800&family=Outfit:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root {
    --bg:     #f0f4f8;
    --panel:  #ffffff;
    --border: #e2e8f0;
    --text:   #1e293b;
    --muted:  #64748b;
    --accent: #6366f1;
    --accent2:#818cf8;
    --green:  #10b981;
    --red:    #ef4444;
    --amber:  #f59e0b;
    --blue:   #3b82f6;
    --purple: #8b5cf6;
    --sidebar-bg: #1e1b4b;
    --sidebar-text: rgba(255,255,255,.65);
}
* { margin:0; padding:0; box-sizing:border-box; }
body { background:var(--bg); color:var(--text); font-family:'Outfit',sans-serif; min-height:100vh; display:flex; flex-direction:column; }

.topbar {
    background:#fff; border-bottom:1px solid var(--border);
    padding:0 24px; height:56px; display:flex; align-items:center; justify-content:space-between;
    position:sticky; top:0; z-index:100; box-shadow:0 1px 3px rgba(0,0,0,.06);
}
.topbar-logo { display:flex; align-items:center; gap:10px; }
.topbar-logo .mark {
    width:34px; height:34px; background:var(--accent);
    border-radius:9px; display:flex; align-items:center; justify-content:center;
    color:#fff; font-size:16px; font-weight:900;
}
.topbar-logo span { font-family:'Space Grotesk',sans-serif; font-size:15px; font-weight:800; color:var(--accent); }
.topbar-right { display:flex; align-items:center; gap:12px; }
.user-chip { display:flex; align-items:center; gap:8px; padding:6px 12px; border:1px solid var(--border); border-radius:20px; }
.user-chip span { font-size:12px; font-weight:600; }
.role-pill { background:#ede9fe; color:var(--purple); font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; }
.logout-btn { color:var(--muted); font-size:12px; text-decoration:none; padding:7px 14px; border-radius:8px; border:1px solid var(--border); transition:all .2s; }
.logout-btn:hover { color:var(--red); border-color:rgba(239,68,68,.4); }

.layout { display:flex; flex:1; }
.sidebar {
    width:230px; background:var(--sidebar-bg); padding:16px 0;
    flex-shrink:0; position:sticky; top:56px; height:calc(100vh - 56px); overflow-y:auto;
}
.sidebar-section { padding:10px 18px 4px; font-size:9px; font-weight:700; color:rgba(255,255,255,.3); text-transform:uppercase; letter-spacing:.1em; }
.nav-item {
    display:flex; align-items:center; gap:10px; padding:10px 18px;
    font-size:12px; font-weight:600; color:var(--sidebar-text);
    cursor:pointer; border-left:3px solid transparent; text-decoration:none; transition:all .2s;
}
.nav-item:hover { color:#fff; background:rgba(255,255,255,.05); }
.nav-item.active { color:#fff; background:rgba(99,102,241,.2); border-left-color:var(--accent2); }
.nav-badge { margin-left:auto; background:var(--red); color:#fff; font-size:9px; font-weight:800; padding:2px 6px; border-radius:10px; }

.main { flex:1; padding:28px; overflow-y:auto; }
.page-head { margin-bottom:22px; }
.page-head h1 { font-family:'Space Grotesk',sans-serif; font-size:22px; font-weight:800; color:var(--text); }
.page-head p  { font-size:12px; color:var(--muted); margin-top:3px; }

.kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:14px; margin-bottom:22px; }
.kpi { background:#fff; border:1px solid var(--border); border-radius:12px; padding:16px; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.kpi-icon { width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:16px; margin-bottom:10px; }
.kpi-lbl { font-size:10px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; }
.kpi-val { font-family:'JetBrains Mono',monospace; font-size:24px; font-weight:700; color:var(--text); margin:4px 0 3px; }
.kpi-sub { font-size:11px; color:var(--muted); }

.card { background:#fff; border:1px solid var(--border); border-radius:14px; overflow:hidden; margin-bottom:20px; box-shadow:0 1px 4px rgba(0,0,0,.04); }
.card-head { padding:14px 20px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
.card-title { font-size:14px; font-weight:800; color:var(--text); }
.card-sub   { font-size:11px; color:var(--muted); margin-top:1px; }
.card-body  { padding:20px; }

.tbl-wrap { overflow-x:auto; }
table.tbl { width:100%; border-collapse:collapse; min-width:600px; }
table.tbl thead th { padding:9px 12px; text-align:left; font-size:10px; font-weight:700; color:var(--muted); letter-spacing:.06em; text-transform:uppercase; border-bottom:2px solid var(--border); white-space:nowrap; background:#fafafa; }
table.tbl td { padding:12px 12px; font-size:12px; color:var(--text); border-bottom:1px solid #f5f5f5; vertical-align:middle; }
table.tbl tbody tr:last-child td { border-bottom:none; }
table.tbl tbody tr:hover td { background:#fafafa; }

.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.form-grid.cols-3 { grid-template-columns:1fr 1fr 1fr; }
@media(max-width:640px){ .form-grid, .form-grid.cols-3 { grid-template-columns:1fr; } }
.form-group { display:flex; flex-direction:column; gap:5px; }
.form-group label { font-size:10px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; }
.form-group input, .form-group select, .form-group textarea {
    border:1px solid var(--border); border-radius:8px; padding:9px 12px;
    font-size:13px; font-family:'Outfit',sans-serif; color:var(--text);
    outline:none; transition:border-color .2s; background:#fff;
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(99,102,241,.08); }
.form-group textarea { resize:vertical; min-height:80px; }
.form-full { grid-column:1/-1; }

.btn { display:inline-flex; align-items:center; gap:7px; padding:9px 18px; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; border:none; transition:all .2s; font-family:'Outfit',sans-serif; }
.btn-primary { background:var(--accent); color:#fff; }
.btn-primary:hover { background:#4f46e5; }
.btn-sm { padding:6px 12px; font-size:11px; }
.btn-green  { background:rgba(16,185,129,.12); color:var(--green); border:1px solid rgba(16,185,129,.3); }
.btn-green:hover  { background:rgba(16,185,129,.22); }
.btn-red    { background:rgba(239,68,68,.1); color:var(--red); border:1px solid rgba(239,68,68,.25); }
.btn-red:hover    { background:rgba(239,68,68,.2); }
.btn-blue   { background:rgba(59,130,246,.1); color:var(--blue); border:1px solid rgba(59,130,246,.25); }
.btn-blue:hover   { background:rgba(59,130,246,.2); }
.btn-amber  { background:rgba(245,158,11,.1); color:var(--amber); border:1px solid rgba(245,158,11,.25); }
.btn-amber:hover  { background:rgba(245,158,11,.2); }

.badge { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:20px; font-size:10px; font-weight:700; white-space:nowrap; }
.badge-active     { background:#d1fae5; color:#065f46; }
.badge-on_leave   { background:#fef3c7; color:#92400e; }
.badge-terminated { background:#fee2e2; color:#991b1b; }
.badge-suspended  { background:#ede9fe; color:#5b21b6; }
.badge-pending    { background:#fef3c7; color:#92400e; }
.badge-approved   { background:#d1fae5; color:#065f46; }
.badge-rejected   { background:#fee2e2; color:#991b1b; }
.badge-draft      { background:#f1f5f9; color:#64748b; }
.badge-processed  { background:#dbeafe; color:#1e40af; }
.badge-paid       { background:#d1fae5; color:#065f46; }
.badge-cancelled  { background:#f8fafc; color:#94a3b8; }

.alert { padding:12px 16px; border-radius:10px; font-size:13px; font-weight:600; margin-bottom:16px; display:flex; align-items:center; gap:8px; }
.alert-success { background:#ecfdf5; border:1px solid #a7f3d0; color:#059669; }
.alert-error   { background:#fef2f2; border:1px solid #fecaca; color:#dc2626; }
.alert-info    { background:#eff6ff; border:1px solid #bfdbfe; color:#2563eb; }

.view { display:none; }
.view.active { display:block; }

.filter-row { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
.filter-row input, .filter-row select { border:1px solid var(--border); border-radius:8px; padding:8px 12px; font-size:12px; color:var(--text); outline:none; background:#fff; }
.filter-row input:focus, .filter-row select:focus { border-color:var(--accent); }

.score-bar-wrap { display:flex; align-items:center; gap:8px; }
.score-bar { flex:1; height:6px; background:#e2e8f0; border-radius:4px; overflow:hidden; min-width:60px; }
.score-bar-fill { height:100%; border-radius:4px; }

@media(max-width:768px) { .sidebar { display:none; } .main { padding:14px; } }
</style>
</head>
<body>

<div class="topbar">
    <div class="topbar-logo">
        <div class="mark">HR</div>
        <div>
            <span>SmartLMS</span>
        </div>
    </div>
    <div class="topbar-right">
        <div class="user-chip">
            <i class="fa-solid fa-user-tie" style="color:var(--accent);font-size:12px;"></i>
            <span><?php echo htmlspecialchars($hr_name); ?></span>
            <span class="role-pill">HR Manager</span>
        </div>
        <a href="apply_leave.php" class="logout-btn" style="color:#f59e0b;border-color:rgba(245,158,11,.3);"><i class="fa-solid fa-calendar-minus"></i> Apply Leave</a>
        <a href="logout.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket mr-1"></i> Logout</a>
    </div>
</div>

<div class="layout">
    <nav class="sidebar">
        <div class="sidebar-section">HR Management</div>
        <a class="nav-item <?php echo $tab==='overview'?'active':''; ?>" onclick="switchTab('overview')">
            <i class="fa-solid fa-chart-pie w-4"></i> Overview
        </a>
        <a class="nav-item <?php echo $tab==='staff'?'active':''; ?>" onclick="switchTab('staff')">
            <i class="fa-solid fa-id-badge w-4"></i> Staff Records
        </a>
        <a class="nav-item <?php echo $tab==='add-staff'?'active':''; ?>" onclick="switchTab('add-staff')">
            <i class="fa-solid fa-user-plus w-4"></i> Add Staff
        </a>
        <div class="sidebar-section">Operations</div>
        <a class="nav-item <?php echo $tab==='leaves'?'active':''; ?>" onclick="switchTab('leaves')">
            <i class="fa-solid fa-calendar-minus w-4"></i> Leave Requests
            <?php if (count($pending_leaves) > 0): ?><span class="nav-badge"><?php echo count($pending_leaves); ?></span><?php endif; ?>
        </a>
        <a class="nav-item <?php echo $tab==='payroll'?'active':''; ?>" onclick="switchTab('payroll')">
            <i class="fa-solid fa-money-bill-wave w-4"></i> Payroll
        </a>
        <a class="nav-item <?php echo $tab==='performance'?'active':''; ?>" onclick="switchTab('performance')">
            <i class="fa-solid fa-star-half-stroke w-4"></i> Performance
        </a>
        <div class="sidebar-section">Communication</div>
        <a class="nav-item <?php echo $tab==='announcements'?'active':''; ?>" onclick="switchTab('announcements')">
            <i class="fa-solid fa-bullhorn w-4"></i> Announcements
        </a>
    </nav>

    <main class="main">
        <?php if ($msg): ?>
        <div class="alert alert-<?php echo $msg_type === 'success' ? 'success' : ($msg_type === 'error' ? 'error' : 'info'); ?>">
            <i class="fa-solid <?php echo $msg_type==='success'?'fa-circle-check':'fa-circle-exclamation'; ?>"></i>
            <?php echo $msg; ?>
        </div>
        <?php endif; ?>

        <!-- ══ OVERVIEW ══ -->
        <div id="view-overview" class="view <?php echo $tab==='overview'?'active':''; ?>">
            <div class="page-head">
                <h1>👥 HR Overview</h1>
                <p>Staff summary and HR metrics</p>
            </div>
            <div class="kpi-grid">
                <div class="kpi">
                    <div class="kpi-icon" style="background:#ede9fe;"><i class="fa-solid fa-users" style="color:var(--purple);"></i></div>
                    <div class="kpi-lbl">Total Staff</div>
                    <div class="kpi-val"><?php echo count($staff_all); ?></div>
                    <div class="kpi-sub"><?php echo count($active_staff); ?> active</div>
                </div>
                <div class="kpi">
                    <div class="kpi-icon" style="background:#d1fae5;"><i class="fa-solid fa-user-check" style="color:var(--green);"></i></div>
                    <div class="kpi-lbl">Active Staff</div>
                    <div class="kpi-val"><?php echo count($active_staff); ?></div>
                    <div class="kpi-sub">On duty</div>
                </div>
                <div class="kpi">
                    <div class="kpi-icon" style="background:#fef3c7;"><i class="fa-solid fa-calendar-minus" style="color:var(--amber);"></i></div>
                    <div class="kpi-lbl">On Leave</div>
                    <div class="kpi-val"><?php echo count($onleave_staff); ?></div>
                    <div class="kpi-sub"><?php echo count($pending_leaves); ?> pending requests</div>
                </div>
                <div class="kpi">
                    <div class="kpi-icon" style="background:#eff6ff;"><i class="fa-solid fa-money-bill" style="color:var(--blue);"></i></div>
                    <div class="kpi-lbl">Monthly Salary</div>
                    <div class="kpi-val" style="font-size:18px;">KES <?php echo number_format($total_salary,0); ?></div>
                    <div class="kpi-sub">Active staff total</div>
                </div>
                <div class="kpi">
                    <div class="kpi-icon" style="background:#f0fdf4;"><i class="fa-solid fa-building" style="color:var(--green);"></i></div>
                    <div class="kpi-lbl">Departments</div>
                    <div class="kpi-val"><?php echo count($departments); ?></div>
                    <div class="kpi-sub">Across institution</div>
                </div>
            </div>

            <!-- Department breakdown -->
            <?php
            $dept_counts = [];
            foreach ($staff_all as $s) {
                $d = $s['department'] ?: 'General';
                if (!isset($dept_counts[$d])) $dept_counts[$d] = 0;
                $dept_counts[$d]++;
            }
            arsort($dept_counts);
            ?>
            <div class="card">
                <div class="card-head"><div class="card-title">Staff by Department</div></div>
                <div class="card-body">
                    <?php $max_d = max(array_values($dept_counts) ?: [1]); ?>
                    <?php foreach ($dept_counts as $dept => $cnt): ?>
                    <div style="margin-bottom:10px;">
                        <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                            <span style="font-size:12px;font-weight:600;"><?php echo htmlspecialchars($dept); ?></span>
                            <span style="font-size:11px;color:var(--muted);"><?php echo $cnt; ?> staff</span>
                        </div>
                        <div style="height:6px;background:#e2e8f0;border-radius:4px;overflow:hidden;">
                            <div style="width:<?php echo round($cnt/$max_d*100); ?>%;height:100%;background:var(--accent);border-radius:4px;"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($dept_counts)): ?><p style="color:var(--muted);font-size:13px;text-align:center;">No staff data yet.</p><?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ══ STAFF RECORDS ══ -->
        <div id="view-staff" class="view <?php echo $tab==='staff'?'active':''; ?>">
            <div class="page-head"><h1>📋 Staff Records</h1><p>All staff members and their details</p></div>
            <div class="filter-row">
                <input type="text" id="staff-search" placeholder="Search name, email, dept..." oninput="filterStaff()" style="width:260px;">
                <select id="staff-dept-filter" onchange="filterStaff()">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?><option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?></option><?php endforeach; ?>
                </select>
                <select id="staff-status-filter" onchange="filterStaff()">
                    <option value="">All Status</option>
                    <option value="active">Active</option>
                    <option value="on_leave">On Leave</option>
                    <option value="terminated">Terminated</option>
                    <option value="suspended">Suspended</option>
                </select>
            </div>
            <div class="card">
                <div class="tbl-wrap">
                    <table class="tbl" id="staff-table">
                        <thead><tr><th>Staff No</th><th>Name</th><th>Department</th><th>Job Title</th><th>System Role</th><th>Type</th><th>Salary (KES)</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($staff_all as $s): ?>
                        <tr class="staff-row" data-name="<?php echo strtolower($s['full_name']); ?>" data-email="<?php echo strtolower($s['email']??''); ?>" data-dept="<?php echo strtolower($s['department']??''); ?>" data-status="<?php echo $s['status']; ?>">
                            <td style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);"><?php echo $s['staff_no']; ?></td>
                            <td>
                                <div style="font-weight:700;"><?php echo htmlspecialchars($s['full_name']); ?></div>
                                <div style="font-size:10px;color:var(--muted);"><?php echo htmlspecialchars($s['email']??''); ?></div>
                            </td>
                            <td style="font-size:12px;"><?php echo htmlspecialchars($s['department']??'—'); ?></td>
                            <td style="font-size:12px;"><?php echo htmlspecialchars($s['job_title']??'—'); ?></td>
                            <td>
                                <?php if (!empty($s['system_role'])): 
                                    $role_colors = ['admin'=>['#fef3c7','#92400e'],'lecturer'=>['#eff6ff','#1d4ed8'],'financial_accountant'=>['#ecfdf5','#065f46'],'boarding_master'=>['#fef9c3','#713f12'],'hr_manager'=>['#ede9fe','#4c1d95'],'student'=>['#f1f5f9','#475569']];
                                    [$rbg,$rc] = $role_colors[$s['system_role']] ?? ['#f1f5f9','#475569'];
                                ?>
                                <span style="font-size:10px;font-weight:700;background:<?php echo $rbg; ?>;color:<?php echo $rc; ?>;padding:2px 8px;border-radius:10px;white-space:nowrap;"><?php echo ucwords(str_replace('_',' ',$s['system_role'])); ?></span>
                                <?php else: ?>
                                <span style="font-size:10px;color:var(--muted);">—</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge" style="background:#f1f5f9;color:#475569;"><?php echo str_replace('_',' ',$s['job_type']); ?></span></td>
                            <td style="font-family:'JetBrains Mono',monospace;font-size:12px;"><?php echo number_format($s['basic_salary'],0); ?></td>
                            <td><span class="badge badge-<?php echo $s['status']; ?>"><?php echo str_replace('_',' ',ucfirst($s['status'])); ?></span></td>
                            <td>
                                <button class="btn btn-sm btn-blue" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($s)); ?>)"><i class="fa-solid fa-pen"></i></button>
                                <?php if ($s['status'] !== 'terminated'): ?>
                                <button class="btn btn-sm btn-red" onclick="openTerminate(<?php echo $s['id']; ?>, '<?php echo htmlspecialchars(addslashes($s['full_name'])); ?>')"><i class="fa-solid fa-user-slash"></i></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($staff_all)): ?>
                        <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:40px;font-size:13px;font-style:italic;">No staff records yet. Add staff to get started.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ══ ADD STAFF ══ -->
        <div id="view-add-staff" class="view <?php echo $tab==='add-staff'?'active':''; ?>">
            <div class="page-head"><h1>➕ Add New Staff</h1><p>Register a new staff member</p></div>
            <div class="card">
                <div class="card-head"><div class="card-title">Staff Registration Form</div></div>
                <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add_staff">
                    <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px;">Personal Information</div>
                    <div class="form-grid" style="margin-bottom:18px;">
                        <div class="form-group"><label>Full Name *</label><input type="text" name="full_name" required placeholder="John Kamau"></div>
                        <div class="form-group"><label>Email</label><input type="email" name="email" placeholder="john@school.ac.ke"></div>
                        <div class="form-group"><label>Phone</label><input type="text" name="phone" placeholder="+254 7xx xxx xxx"></div>
                        <div class="form-group"><label>Gender</label><select name="gender"><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></select></div>
                        <div class="form-group"><label>Date of Birth</label><input type="date" name="date_of_birth"></div>
                        <div class="form-group"><label>National ID</label><input type="text" name="national_id" placeholder="12345678"></div>
                        <div class="form-group form-full"><label>Address</label><input type="text" name="address" placeholder="P.O. Box, Town"></div>
                    </div>
                    <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px;">Employment Details</div>
                    <div class="form-grid" style="margin-bottom:18px;">
                        <div class="form-group"><label>Department *</label><input type="text" name="department" required placeholder="e.g. Academics, Finance, Admin"></div>
                        <div class="form-group"><label>Job Title *</label><input type="text" name="job_title" required placeholder="e.g. Lecturer, Accountant"></div>
                        <div class="form-group"><label>Job Type</label><select name="job_type"><option value="full_time">Full Time</option><option value="part_time">Part Time</option><option value="contract">Contract</option><option value="intern">Intern</option></select></div>
                        <div class="form-group"><label>Employment Date</label><input type="date" name="employment_date" value="<?php echo date('Y-m-d'); ?>"></div>
                    </div>
                    <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px;">Compensation & Banking</div>
                    <div class="form-grid" style="margin-bottom:18px;">
                        <div class="form-group"><label>Basic Salary (KES)</label><input type="number" name="basic_salary" step="0.01" placeholder="50000"></div>
                        <div class="form-group"><label>Allowances (KES)</label><input type="number" name="allowances" step="0.01" placeholder="5000"></div>
                        <div class="form-group"><label>Other Deductions (KES)</label><input type="number" name="deductions" step="0.01" placeholder="0"></div>
                        <div class="form-group"><label>Payment Method</label><select name="payment_method"><option>bank_transfer</option><option>mpesa</option><option>cash</option><option>cheque</option></select></div>
                        <div class="form-group"><label>Bank Name</label><input type="text" name="bank_name" placeholder="Equity, KCB, Co-op..."></div>
                        <div class="form-group"><label>Bank Account Number</label><input type="text" name="bank_account" placeholder="1234567890"></div>
                    </div>
                    <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px;">Emergency Contact</div>
                    <div class="form-grid" style="margin-bottom:18px;">
                        <div class="form-group"><label>Emergency Contact Name</label><input type="text" name="emergency_contact" placeholder="Jane Kamau"></div>
                        <div class="form-group"><label>Emergency Contact Phone</label><input type="text" name="emergency_phone" placeholder="+254 7xx xxx xxx"></div>
                        <div class="form-group form-full"><label>Profile Notes</label><textarea name="profile_notes" placeholder="Any additional notes..."></textarea></div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-user-plus"></i> Register Staff Member</button>
                </form>
                </div>
            </div>
        </div>

        <!-- ══ LEAVES ══ -->
        <div id="view-leaves" class="view <?php echo $tab==='leaves'?'active':''; ?>">
            <div class="page-head"><h1>📅 Leave Requests</h1><p>Review and manage staff leave applications</p></div>
            <div class="card">
                <div class="card-head">
                    <div class="card-title">All Leave Requests</div>
                    <select id="leave-filter" onchange="filterLeaves()" style="border:1px solid var(--border);border-radius:8px;padding:6px 12px;font-size:12px;background:#fff;">
                        <option value="">All</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
                <div class="tbl-wrap">
                    <table class="tbl" id="leaves-table">
                        <thead><tr><th>Staff</th><th>Department</th><th>Leave Type</th><th>From</th><th>To</th><th>Days</th><th>Reason</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($leaves as $l): ?>
                        <tr class="leave-row" data-status="<?php echo $l['status']; ?>">
                            <td style="font-weight:700;"><?php echo htmlspecialchars($l['full_name']); ?></td>
                            <td style="font-size:11px;"><?php echo htmlspecialchars($l['department']??'—'); ?></td>
                            <td><span class="badge" style="background:#ede9fe;color:var(--purple);"><?php echo ucfirst(str_replace('_',' ',$l['leave_type'])); ?></span></td>
                            <td style="font-size:11px;"><?php echo date('d M Y',strtotime($l['start_date'])); ?></td>
                            <td style="font-size:11px;"><?php echo date('d M Y',strtotime($l['end_date'])); ?></td>
                            <td style="font-family:'JetBrains Mono',monospace;text-align:center;"><?php echo $l['days_requested']; ?></td>
                            <td style="font-size:11px;color:var(--muted);max-width:160px;"><?php echo htmlspecialchars(substr($l['reason']??'—',0,60)); ?><?php echo strlen($l['reason']??'') > 60 ? '…' : ''; ?></td>
                            <td><span class="badge badge-<?php echo $l['status']; ?>"><?php echo ucfirst($l['status']); ?></span></td>
                            <td>
                                <?php if ($l['status'] === 'pending'): ?>
                                <button class="btn btn-sm btn-green" onclick="reviewLeave(<?php echo $l['id']; ?>, 'approved')"><i class="fa-solid fa-check"></i> Approve</button>
                                <button class="btn btn-sm btn-red" onclick="reviewLeave(<?php echo $l['id']; ?>, 'rejected')"><i class="fa-solid fa-xmark"></i> Reject</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($leaves)): ?><tr><td colspan="9" style="text-align:center;color:var(--muted);padding:40px;font-style:italic;">No leave requests found.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ══ PAYROLL ══ -->
        <div id="view-payroll" class="view <?php echo $tab==='payroll'?'active':''; ?>">
            <div class="page-head"><h1>💰 Payroll Processing</h1><p>Generate and manage staff payroll</p></div>
            <div class="card" style="margin-bottom:20px;">
                <div class="card-head"><div class="card-title">Process Monthly Payroll</div></div>
                <div class="card-body">
                    <form method="POST" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                        <input type="hidden" name="action" value="process_payroll">
                        <div class="form-group">
                            <label>Pay Period (e.g. Jan 2025)</label>
                            <input type="text" name="pay_period" value="<?php echo date('M Y'); ?>" style="width:200px;">
                        </div>
                        <button type="submit" class="btn btn-primary" onclick="return confirm('Process payroll for all active staff for this period?')">
                            <i class="fa-solid fa-money-bill-wave"></i> Process Payroll
                        </button>
                    </form>
                    <p style="font-size:11px;color:var(--muted);margin-top:8px;">Payroll calculates PAYE, NHIF, and NSSF deductions automatically using Kenyan statutory rates.</p>
                </div>
            </div>
            <div class="card">
                <div class="card-head">
                    <div class="card-title">Payroll Records</div>
                    <input type="text" id="payroll-search" placeholder="Search..." oninput="filterPayroll()" style="border:1px solid var(--border);border-radius:8px;padding:6px 12px;font-size:12px;width:200px;">
                </div>
                <div class="tbl-wrap">
                    <table class="tbl" id="payroll-table">
                        <thead><tr><th>Staff</th><th>Department</th><th>Period</th><th>Basic</th><th>Gross</th><th>PAYE</th><th>NHIF</th><th>NSSF</th><th>Net Pay</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($payroll as $p): ?>
                        <tr class="payroll-row" data-name="<?php echo strtolower($p['full_name']); ?>">
                            <td style="font-weight:700;"><?php echo htmlspecialchars($p['full_name']); ?></td>
                            <td style="font-size:11px;"><?php echo htmlspecialchars($p['department']??'—'); ?></td>
                            <td style="font-size:11px;font-weight:600;"><?php echo htmlspecialchars($p['pay_period']); ?></td>
                            <td style="font-family:'JetBrains Mono',monospace;font-size:11px;"><?php echo number_format($p['basic_salary'],0); ?></td>
                            <td style="font-family:'JetBrains Mono',monospace;font-size:11px;"><?php echo number_format($p['gross_pay'],0); ?></td>
                            <td style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--red);"><?php echo number_format($p['paye'],0); ?></td>
                            <td style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--amber);"><?php echo number_format($p['nhif'],0); ?></td>
                            <td style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--amber);"><?php echo number_format($p['nssf'],0); ?></td>
                            <td style="font-family:'JetBrains Mono',monospace;font-size:12px;font-weight:800;color:var(--green);"><?php echo number_format($p['net_pay'],0); ?></td>
                            <td><span class="badge badge-<?php echo $p['status']; ?>"><?php echo ucfirst($p['status']); ?></span></td>
                            <td>
                                <?php if ($p['status'] === 'processed'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="mark_paid">
                                    <input type="hidden" name="payroll_id" value="<?php echo $p['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-green"><i class="fa-solid fa-check"></i> Mark Paid</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($payroll)): ?><tr><td colspan="11" style="text-align:center;color:var(--muted);padding:40px;font-style:italic;">No payroll records yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ══ PERFORMANCE ══ -->
        <div id="view-performance" class="view <?php echo $tab==='performance'?'active':''; ?>">
            <div class="page-head"><h1>⭐ Performance Reviews</h1><p>Rate and track staff performance</p></div>
            <div class="card" style="margin-bottom:20px;">
                <div class="card-head"><div class="card-title">Add Performance Review</div></div>
                <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add_review">
                    <div class="form-grid cols-3" style="margin-bottom:14px;">
                        <div class="form-group">
                            <label>Staff Member *</label>
                            <select name="staff_id" required>
                                <option value="">— Select Staff —</option>
                                <?php foreach ($staff_all as $s): if ($s['status'] === 'terminated') continue; ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['full_name']); ?> (<?php echo htmlspecialchars($s['department']??'—'); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label>Review Period</label><input type="text" name="review_period" value="<?php echo date('M Y'); ?>" required></div>
                        <div class="form-group"><label>Attendance Score (0-10)</label><input type="number" name="attendance_score" min="0" max="10" step="0.1" placeholder="8.5" required></div>
                        <div class="form-group"><label>Performance Score (0-10)</label><input type="number" name="performance_score" min="0" max="10" step="0.1" placeholder="7.5" required></div>
                        <div class="form-group"><label>Teamwork Score (0-10)</label><input type="number" name="teamwork_score" min="0" max="10" step="0.1" placeholder="9.0" required></div>
                        <div class="form-group"><label>Initiative Score (0-10)</label><input type="number" name="initiative_score" min="0" max="10" step="0.1" placeholder="8.0" required></div>
                        <div class="form-group form-full"><label>Comments</label><textarea name="comments" placeholder="Performance review comments..."></textarea></div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-star"></i> Submit Review</button>
                </form>
                </div>
            </div>
            <div class="card">
                <div class="card-head"><div class="card-title">Review History</div></div>
                <div class="tbl-wrap">
                    <table class="tbl">
                        <thead><tr><th>Staff</th><th>Department</th><th>Period</th><th>Attendance</th><th>Performance</th><th>Teamwork</th><th>Initiative</th><th>Overall</th></tr></thead>
                        <tbody>
                        <?php foreach ($reviews as $r):
                            $ov = floatval($r['overall_score']);
                            $ov_c = $ov >= 8 ? 'var(--green)' : ($ov >= 6 ? 'var(--blue)' : ($ov >= 4 ? 'var(--amber)' : 'var(--red)'));
                        ?>
                        <tr>
                            <td style="font-weight:700;"><?php echo htmlspecialchars($r['full_name']); ?></td>
                            <td style="font-size:11px;"><?php echo htmlspecialchars($r['department']??'—'); ?></td>
                            <td style="font-size:11px;font-weight:600;"><?php echo htmlspecialchars($r['review_period']); ?></td>
                            <?php foreach (['attendance_score','performance_score','teamwork_score','initiative_score'] as $sc): ?>
                            <td>
                                <div class="score-bar-wrap">
                                    <div class="score-bar"><div class="score-bar-fill" style="width:<?php echo floatval($r[$sc])*10; ?>%;background:var(--accent);"></div></div>
                                    <span style="font-size:11px;font-weight:700;min-width:24px;"><?php echo $r[$sc]; ?></span>
                                </div>
                            </td>
                            <?php endforeach; ?>
                            <td style="font-family:'JetBrains Mono',monospace;font-size:16px;font-weight:800;color:<?php echo $ov_c; ?>;"><?php echo $ov; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($reviews)): ?><tr><td colspan="8" style="text-align:center;color:var(--muted);padding:40px;font-style:italic;">No reviews yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ══ ANNOUNCEMENTS ══ -->
        <div id="view-announcements" class="view <?php echo $tab==='announcements'?'active':''; ?>">
            <div class="page-head"><h1>📣 HR Announcements</h1><p>Post notices for staff members</p></div>
            <div class="card" style="margin-bottom:20px;">
                <div class="card-head"><div class="card-title">Post Announcement</div></div>
                <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="post_announcement">
                    <div class="form-grid" style="margin-bottom:14px;">
                        <div class="form-group"><label>Title *</label><input type="text" name="title" required placeholder="Announcement title"></div>
                        <div class="form-group"><label>Priority</label><select name="priority"><option value="normal">Normal</option><option value="urgent">🔴 Urgent</option><option value="info">ℹ Info</option></select></div>
                        <div class="form-group"><label>Target Department (leave blank for all)</label><input type="text" name="target_department" placeholder="e.g. Academics"></div>
                        <div class="form-group form-full"><label>Message *</label><textarea name="message" required style="min-height:100px;" placeholder="Write your announcement..."></textarea></div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-bullhorn"></i> Post Announcement</button>
                </form>
                </div>
            </div>
            <?php foreach ($announcements as $a):
                $pc = $a['priority']==='urgent' ? 'var(--red)' : ($a['priority']==='info' ? 'var(--blue)' : 'var(--amber)');
            ?>
            <div class="card" style="margin-bottom:12px;">
                <div class="card-body" style="padding:16px 20px;">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:8px;">
                        <div>
                            <div style="font-size:15px;font-weight:800;"><?php echo htmlspecialchars($a['title']); ?></div>
                            <div style="font-size:10px;color:var(--muted);margin-top:3px;">
                                <span style="color:<?php echo $pc; ?>;font-weight:700;"><?php echo strtoupper($a['priority']); ?></span>
                                · <?php echo $a['target_department'] ? 'Dept: '.htmlspecialchars($a['target_department']) : 'All Staff'; ?>
                                · <?php echo date('d M Y H:i', strtotime($a['created_at'])); ?>
                                · By <?php echo htmlspecialchars($a['poster']); ?>
                            </div>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="delete_notice">
                            <input type="hidden" name="notice_id" value="<?php echo $a['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-red" onclick="return confirm('Delete this announcement?')"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </div>
                    <div style="font-size:13px;color:var(--muted);line-height:1.6;"><?php echo nl2br(htmlspecialchars($a['message'])); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($announcements)): ?><div style="text-align:center;color:var(--muted);padding:40px;font-size:13px;">No announcements yet.</div><?php endif; ?>
        </div>

    </main>
</div>

<!-- Edit Staff Modal -->
<div id="edit-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;align-items:center;justify-content:center;overflow-y:auto;" onclick="closeModal('edit-modal')">
    <div style="background:#fff;border-radius:16px;padding:28px;width:560px;max-width:95vw;margin:20px auto;max-height:90vh;overflow-y:auto;" onclick="event.stopPropagation()">
        <h3 style="font-family:'Space Grotesk',sans-serif;font-size:18px;margin-bottom:16px;">Edit Staff</h3>
        <form method="POST">
            <input type="hidden" name="action" value="edit_staff">
            <input type="hidden" name="staff_id" id="edit-staff-id">
            <div class="form-grid" style="margin-bottom:14px;">
                <div class="form-group"><label>Phone</label><input type="text" name="phone" id="edit-phone"></div>
                <div class="form-group"><label>Department</label><input type="text" name="department" id="edit-dept" required></div>
                <div class="form-group"><label>Job Title</label><input type="text" name="job_title" id="edit-title" required></div>
                <div class="form-group"><label>Job Type</label><select name="job_type" id="edit-type"><option value="full_time">Full Time</option><option value="part_time">Part Time</option><option value="contract">Contract</option><option value="intern">Intern</option></select></div>
                <div class="form-group"><label>Basic Salary (KES)</label><input type="number" name="basic_salary" id="edit-salary" step="0.01"></div>
                <div class="form-group"><label>Allowances (KES)</label><input type="number" name="allowances" id="edit-allowances" step="0.01"></div>
                <div class="form-group"><label>Other Deductions</label><input type="number" name="deductions" id="edit-deductions" step="0.01"></div>
                <div class="form-group"><label>Status</label><select name="status" id="edit-status"><option value="active">Active</option><option value="on_leave">On Leave</option><option value="suspended">Suspended</option></select></div>
                <div class="form-group"><label>Bank Name</label><input type="text" name="bank_name" id="edit-bank"></div>
                <div class="form-group"><label>Bank Account</label><input type="text" name="bank_account" id="edit-bacc"></div>
                <div class="form-group" style="grid-column:span 2;"><label>Notes</label><textarea name="profile_notes" id="edit-notes"></textarea></div>
            </div>
            <div style="display:flex;gap:10px;">
                <button type="submit" class="btn btn-primary">💾 Save Changes</button>
                <button type="button" class="btn" style="background:#f1f5f9;color:var(--muted);" onclick="closeModal('edit-modal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Terminate Modal -->
<div id="terminate-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;align-items:center;justify-content:center;" onclick="closeModal('terminate-modal')">
    <div style="background:#fff;border-radius:16px;padding:28px;width:400px;max-width:95vw;" onclick="event.stopPropagation()">
        <h3 style="font-family:'Space Grotesk',sans-serif;font-size:18px;color:var(--red);margin-bottom:12px;">Terminate Staff</h3>
        <p style="font-size:13px;color:var(--muted);margin-bottom:16px;" id="terminate-name-label"></p>
        <form method="POST">
            <input type="hidden" name="action" value="terminate">
            <input type="hidden" name="staff_id" id="terminate-staff-id">
            <div class="form-group" style="margin-bottom:14px;"><label>Termination Date</label><input type="date" name="termination_date" value="<?php echo date('Y-m-d'); ?>"></div>
            <div style="display:flex;gap:10px;">
                <button type="submit" class="btn btn-red" onclick="return confirm('This action marks the staff as terminated. Continue?')"><i class="fa-solid fa-user-slash"></i> Confirm Termination</button>
                <button type="button" class="btn" style="background:#f1f5f9;color:var(--muted);" onclick="closeModal('terminate-modal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Leave Review Modal -->
<div id="leave-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;align-items:center;justify-content:center;" onclick="closeModal('leave-modal')">
    <div style="background:#fff;border-radius:16px;padding:28px;width:420px;max-width:95vw;" onclick="event.stopPropagation()">
        <h3 style="font-family:'Space Grotesk',sans-serif;font-size:18px;margin-bottom:16px;" id="leave-modal-title">Review Leave</h3>
        <form method="POST">
            <input type="hidden" name="action" value="review_leave">
            <input type="hidden" name="leave_id" id="review-leave-id">
            <input type="hidden" name="leave_status" id="review-leave-status">
            <div class="form-group" style="margin-bottom:14px;"><label>Review Notes (optional)</label><textarea name="review_notes" placeholder="Add comments..."></textarea></div>
            <div style="display:flex;gap:10px;">
                <button type="submit" class="btn btn-primary" id="leave-submit-btn">Submit</button>
                <button type="button" class="btn" style="background:#f1f5f9;color:var(--muted);" onclick="closeModal('leave-modal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
const TABS = ['overview','staff','add-staff','leaves','payroll','performance','announcements'];
function switchTab(t) {
    TABS.forEach(id => {
        const el = document.getElementById('view-'+id);
        if (el) el.classList.toggle('active', id===t);
    });
    document.querySelectorAll('.nav-item').forEach(el => {
        el.classList.toggle('active', el.getAttribute('onclick') === "switchTab('"+t+"')");
    });
    history.replaceState(null,'','hr_dashboard.php?tab='+t);
}

function openEditModal(s) {
    document.getElementById('edit-staff-id').value = s.id;
    document.getElementById('edit-phone').value = s.phone || '';
    document.getElementById('edit-dept').value = s.department || '';
    document.getElementById('edit-title').value = s.job_title || '';
    document.getElementById('edit-type').value = s.job_type || 'full_time';
    document.getElementById('edit-salary').value = s.basic_salary || 0;
    document.getElementById('edit-allowances').value = s.allowances || 0;
    document.getElementById('edit-deductions').value = s.deductions || 0;
    document.getElementById('edit-status').value = s.status || 'active';
    document.getElementById('edit-bank').value = s.bank_name || '';
    document.getElementById('edit-bacc').value = s.bank_account || '';
    document.getElementById('edit-notes').value = s.profile_notes || '';
    const m = document.getElementById('edit-modal');
    m.style.display = 'flex';
}
function openTerminate(id, name) {
    document.getElementById('terminate-staff-id').value = id;
    document.getElementById('terminate-name-label').textContent = 'Terminating: ' + name;
    document.getElementById('terminate-modal').style.display = 'flex';
}
function reviewLeave(id, status) {
    document.getElementById('review-leave-id').value = id;
    document.getElementById('review-leave-status').value = status;
    document.getElementById('leave-modal-title').textContent = status === 'approved' ? '✅ Approve Leave' : '❌ Reject Leave';
    document.getElementById('leave-submit-btn').className = 'btn ' + (status === 'approved' ? 'btn-green' : 'btn-red');
    document.getElementById('leave-modal').style.display = 'flex';
}
function closeModal(id) { document.getElementById(id).style.display = 'none'; }

function filterStaff() {
    const q = document.getElementById('staff-search').value.toLowerCase();
    const d = document.getElementById('staff-dept-filter').value.toLowerCase();
    const s = document.getElementById('staff-status-filter').value;
    document.querySelectorAll('.staff-row').forEach(row => {
        const nm  = row.dataset.name.includes(q) || row.dataset.email.includes(q);
        const dp  = !d || row.dataset.dept.includes(d);
        const st  = !s || row.dataset.status === s;
        row.style.display = nm && dp && st ? '' : 'none';
    });
}
function filterLeaves() {
    const s = document.getElementById('leave-filter').value;
    document.querySelectorAll('.leave-row').forEach(row => {
        row.style.display = !s || row.dataset.status === s ? '' : 'none';
    });
}
function filterPayroll() {
    const q = document.getElementById('payroll-search').value.toLowerCase();
    document.querySelectorAll('.payroll-row').forEach(row => {
        row.style.display = row.dataset.name.includes(q) ? '' : 'none';
    });
}

const urlParams = new URLSearchParams(window.location.search);
switchTab(urlParams.get('tab') || 'overview');
</script>
</body>
</html>

