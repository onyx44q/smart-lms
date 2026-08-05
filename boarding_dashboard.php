<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'boarding_master') {
    header("Location: index.php"); exit();
}

$bm_id   = $_SESSION['user_id'];
$bm_name = $_SESSION['user_name'];

// ── Auto-create boarding tables ──────────────────────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `boarding_dorms` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `gender` ENUM('male','female') NOT NULL,
  `capacity` INT NOT NULL DEFAULT 20,
  `floor_count` INT DEFAULT 1,
  `description` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `boarding_rooms` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `dorm_id` INT NOT NULL,
  `room_number` VARCHAR(20) NOT NULL,
  `capacity` INT NOT NULL DEFAULT 4,
  `floor` INT DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY(`dorm_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `boarding_allocations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `dorm_id` INT NOT NULL,
  `room_id` INT DEFAULT NULL,
  `bed_number` VARCHAR(10) DEFAULT NULL,
  `academic_year` VARCHAR(20) NOT NULL,
  `semester` VARCHAR(30) NOT NULL DEFAULT 'Semester 1',
  `check_in_date` DATE DEFAULT NULL,
  `check_out_date` DATE DEFAULT NULL,
  `status` ENUM('active','vacated','transferred','pending') NOT NULL DEFAULT 'pending',
  `allocated_by` INT NOT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_stu_year_sem` (`student_id`, `academic_year`, `semester`),
  KEY(`dorm_id`), KEY(`room_id`), KEY(`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `boarding_notices` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(200) NOT NULL,
  `message` TEXT NOT NULL,
  `target` ENUM('all','male','female','specific_dorm') DEFAULT 'all',
  `dorm_id` INT DEFAULT NULL,
  `priority` ENUM('normal','urgent','info') DEFAULT 'normal',
  `posted_by` INT NOT NULL,
  `is_active` TINYINT DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Add UNIQUE constraint on dorm name+gender to prevent duplicates ──
@mysqli_query($conn, "ALTER TABLE boarding_dorms ADD UNIQUE KEY uq_dorm_name_gender (name, gender)");

// ── Remove existing duplicates (keep lowest id per name+gender) ───────
mysqli_query($conn, "DELETE d1 FROM boarding_dorms d1
    INNER JOIN boarding_dorms d2
    WHERE d1.id > d2.id AND d1.name = d2.name AND d1.gender = d2.gender");

// ── Seed dorms using INSERT IGNORE (safe to run every load) ──────────
$female_dorms = [
    'Elgon','Classic','VIP','Bakhita','Stardorm','Rhunda','Lavington',
    'Ikolomani','Highrise','Chairlady','Tanzania','Ayomi',
    'Kaveve Kazoze','Caren Muslims','Amazon',
    'Lancaster 1','Lancaster 2','Lancaster 3','Lancaster 4','Lancaster 5'
];
$male_dorms = [
    'Pentagon','Babylon','Kingstore','Muslims (Easleigh)',
    'Westgate A','Westgate B','Statehouse (Boys)','White House',
    'Muslim 2','Admin Dorm','Chiefs Dorm'
];
foreach ($female_dorms as $d) {
    $dn = mysqli_real_escape_string($conn, $d);
    mysqli_query($conn, "INSERT IGNORE INTO boarding_dorms (name, gender, capacity) VALUES ('$dn','female',40)");
}
foreach ($male_dorms as $d) {
    $dn = mysqli_real_escape_string($conn, $d);
    mysqli_query($conn, "INSERT IGNORE INTO boarding_dorms (name, gender, capacity) VALUES ('$dn','male',40)");
}

// ── HANDLE ACTIONS ────────────────────────────────────────────────────
$msg = ''; $msg_type = '';

// Allocate student to dorm
if (isset($_POST['action']) && $_POST['action'] === 'allocate') {
    $stu_id  = intval($_POST['student_id']);
    $dorm_id = intval($_POST['dorm_id']);
    $room    = mysqli_real_escape_string($conn, trim($_POST['room_number'] ?? ''));
    $bed     = mysqli_real_escape_string($conn, trim($_POST['bed_number'] ?? ''));
    $yr      = mysqli_real_escape_string($conn, $_POST['academic_year'] ?? date('Y').'/'.( date('Y')+1));
    $sem     = mysqli_real_escape_string($conn, $_POST['semester'] ?? 'Semester 1');
    $cin     = mysqli_real_escape_string($conn, $_POST['check_in_date'] ?? date('Y-m-d'));
    $notes   = mysqli_real_escape_string($conn, trim($_POST['notes'] ?? ''));

    // Check capacity
    $dorm_info = mysqli_fetch_assoc(mysqli_query($conn, "SELECT capacity FROM boarding_dorms WHERE id=$dorm_id"));
    $current   = intval(mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) AS c FROM boarding_allocations WHERE dorm_id=$dorm_id AND status='active' AND academic_year='$yr' AND semester='$sem'"))['c']);

    if ($dorm_info && $current >= $dorm_info['capacity']) {
        $msg = "Dorm is at full capacity ($current/{$dorm_info['capacity']}) for this period.";
        $msg_type = 'error';
    } else {
        // Use INSERT ... ON DUPLICATE KEY UPDATE to handle unique constraint safely
        $q = mysqli_query($conn,
            "INSERT INTO boarding_allocations
                (student_id, dorm_id, room_id, bed_number, academic_year, semester,
                 check_in_date, status, allocated_by, notes)
             VALUES
                ($stu_id, $dorm_id, NULL, '$bed', '$yr', '$sem',
                 '$cin', 'active', $bm_id, '$notes')
             ON DUPLICATE KEY UPDATE
                dorm_id       = VALUES(dorm_id),
                bed_number    = VALUES(bed_number),
                check_in_date = VALUES(check_in_date),
                status        = 'active',
                allocated_by  = VALUES(allocated_by),
                notes         = VALUES(notes)");
        if ($q) {
            $msg = "Student allocated to dorm successfully."; $msg_type = 'success';
        } else {
            $msg = "Error: " . mysqli_error($conn); $msg_type = 'error';
        }
    }
    header("Location: boarding_dashboard.php?tab=allocate&msg=".urlencode($msg)."&mtype=$msg_type"); exit();
}

// Vacate allocation
if (isset($_POST['action']) && $_POST['action'] === 'vacate') {
    $aid = intval($_POST['allocation_id']);
    $cout = mysqli_real_escape_string($conn, $_POST['check_out_date'] ?? date('Y-m-d'));
    mysqli_query($conn, "UPDATE boarding_allocations SET status='vacated', check_out_date='$cout' WHERE id=$aid");
    header("Location: boarding_dashboard.php?tab=allocations&msg=Student+vacated&mtype=success"); exit();
}

// Transfer allocation
if (isset($_POST['action']) && $_POST['action'] === 'transfer') {
    $aid         = intval($_POST['allocation_id']);
    $new_dorm_id = intval($_POST['new_dorm_id']);
    $new_bed     = mysqli_real_escape_string($conn, trim($_POST['new_bed_number'] ?? ''));
    mysqli_query($conn, "UPDATE boarding_allocations SET dorm_id=$new_dorm_id, bed_number='$new_bed', status='active' WHERE id=$aid");
    header("Location: boarding_dashboard.php?tab=allocations&msg=Student+transferred&mtype=success"); exit();
}

// Update dorm capacity
if (isset($_POST['action']) && $_POST['action'] === 'update_capacity') {
    $dorm_id  = intval($_POST['dorm_id']);
    $capacity = intval($_POST['capacity']);
    mysqli_query($conn, "UPDATE boarding_dorms SET capacity=$capacity WHERE id=$dorm_id");
    header("Location: boarding_dashboard.php?tab=dorms&msg=Capacity+updated&mtype=success"); exit();
}

// Post notice
if (isset($_POST['action']) && $_POST['action'] === 'post_notice') {
    $title    = mysqli_real_escape_string($conn, trim($_POST['title']));
    $message  = mysqli_real_escape_string($conn, trim($_POST['message']));
    $target   = mysqli_real_escape_string($conn, $_POST['target'] ?? 'all');
    $dorm_id  = intval($_POST['notice_dorm_id'] ?? 0) ?: 'NULL';
    $priority = mysqli_real_escape_string($conn, $_POST['priority'] ?? 'normal');
    mysqli_query($conn, "INSERT INTO boarding_notices (title,message,target,dorm_id,priority,posted_by) VALUES ('$title','$message','$target',".($dorm_id==='NULL'?'NULL':$dorm_id).",'$priority',$bm_id)");
    header("Location: boarding_dashboard.php?tab=notices&msg=Notice+posted&mtype=success"); exit();
}

// Delete notice
if (isset($_POST['action']) && $_POST['action'] === 'delete_notice') {
    $nid = intval($_POST['notice_id']);
    mysqli_query($conn, "UPDATE boarding_notices SET is_active=0 WHERE id=$nid AND posted_by=$bm_id");
    header("Location: boarding_dashboard.php?tab=notices&msg=Notice+removed&mtype=success"); exit();
}

$msg      = htmlspecialchars($_GET['msg'] ?? '');
$msg_type = htmlspecialchars($_GET['mtype'] ?? 'info');
$tab      = htmlspecialchars($_GET['tab'] ?? 'overview');

// ── DATA FETCH ────────────────────────────────────────────────────────
$dorms_res  = mysqli_query($conn, "SELECT MIN(id) AS id, name, gender, MAX(capacity) AS capacity, MAX(floor_count) AS floor_count, description FROM boarding_dorms GROUP BY name, gender ORDER BY gender, name");
$dorms = $dorms_female = $dorms_male = [];
while ($d = mysqli_fetch_assoc($dorms_res)) {
    $dorms[$d['id']] = $d;
    if ($d['gender'] === 'female') $dorms_female[] = $d;
    else $dorms_male[] = $d;
}

// Stats per dorm (current year)
$cur_year = date('Y').'/'. (date('Y')+1);
$alloc_stats = [];
$st_res = mysqli_query($conn, "SELECT dorm_id, COUNT(*) AS occupied FROM boarding_allocations WHERE status='active' GROUP BY dorm_id");
while ($r = mysqli_fetch_assoc($st_res)) $alloc_stats[$r['dorm_id']] = intval($r['occupied']);

// Total stats
$total_allocated = array_sum($alloc_stats);
$total_capacity  = array_sum(array_column($dorms, 'capacity'));

// Students for allocation dropdown (all students)
$students_res = mysqli_query($conn, "SELECT u.id, u.full_name, u.email, c.title AS course,
    (SELECT d.name FROM boarding_allocations ba JOIN boarding_dorms d ON d.id=ba.dorm_id WHERE ba.student_id=u.id AND ba.status='active' LIMIT 1) AS current_dorm
    FROM users u LEFT JOIN courses c ON c.id=u.course_id
    WHERE u.role='student' ORDER BY u.full_name ASC");
$students = [];
while ($s = mysqli_fetch_assoc($students_res)) $students[$s['id']] = $s;

// Allocations list
$allocations_res = mysqli_query($conn, "SELECT ba.*, u.full_name, u.email, d.name AS dorm_name, d.gender,
    bm.full_name AS allocated_by_name
    FROM boarding_allocations ba
    JOIN users u ON u.id=ba.student_id
    JOIN boarding_dorms d ON d.id=ba.dorm_id
    LEFT JOIN users bm ON bm.id=ba.allocated_by
    ORDER BY ba.status ASC, ba.created_at DESC");
$allocations = [];
while ($a = mysqli_fetch_assoc($allocations_res)) $allocations[] = $a;

// Notices
$notices_res = mysqli_query($conn, "SELECT bn.*, u.full_name AS posted_by_name,
    d.name AS dorm_name FROM boarding_notices bn
    LEFT JOIN users u ON u.id=bn.posted_by
    LEFT JOIN boarding_dorms d ON d.id=bn.dorm_id
    WHERE bn.is_active=1 ORDER BY bn.created_at DESC LIMIT 50");
$notices = [];
while ($n = mysqli_fetch_assoc($notices_res)) $notices[] = $n;

$unhoused_count = count(array_filter($students, fn($s) => !$s['current_dorm']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Boarding Master Dashboard — SmartLMS</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root {
    --bg:     #0f172a;
    --panel:  #1e293b;
    --border: #334155;
    --accent: #f59e0b;
    --accent2:#fbbf24;
    --text:   #f1f5f9;
    --muted:  #94a3b8;
    --green:  #10b981;
    --red:    #ef4444;
    --blue:   #3b82f6;
    --purple: #a855f7;
    --female: #ec4899;
    --male:   #3b82f6;
}
* { margin:0; padding:0; box-sizing:border-box; }
body { background:var(--bg); color:var(--text); font-family:'DM Sans',sans-serif; min-height:100vh; display:flex; flex-direction:column; }

/* ── Top bar ── */
.topbar {
    background:var(--panel); border-bottom:1px solid var(--border);
    padding:0 24px; height:60px; display:flex; align-items:center; justify-content:space-between;
    position:sticky; top:0; z-index:100;
}
.topbar-logo { display:flex; align-items:center; gap:10px; }
.topbar-logo .mark {
    width:36px; height:36px; background:linear-gradient(135deg,var(--accent),var(--accent2));
    border-radius:10px; display:flex; align-items:center; justify-content:center;
    font-size:18px;
}
.topbar-logo span { font-family:'Syne',sans-serif; font-size:16px; font-weight:800; color:var(--accent); }
.topbar-logo small { font-size:10px; color:var(--muted); display:block; margin-top:-2px; }
.topbar-right { display:flex; align-items:center; gap:12px; }
.user-chip { display:flex; align-items:center; gap:8px; background:rgba(255,255,255,.05); border:1px solid var(--border); padding:6px 12px; border-radius:20px; }
.user-chip span { font-size:12px; font-weight:600; color:var(--text); }
.logout-btn { color:var(--muted); font-size:12px; text-decoration:none; padding:6px 12px; border-radius:8px; transition:all .2s; }
.logout-btn:hover { color:var(--red); background:rgba(239,68,68,.1); }

/* ── Layout ── */
.layout { display:flex; flex:1; }
.sidebar {
    width:220px; background:var(--panel); border-right:1px solid var(--border);
    padding:16px 0; flex-shrink:0; position:sticky; top:60px; height:calc(100vh - 60px);
    overflow-y:auto;
}
.sidebar-section { padding:8px 16px 4px; font-size:9px; font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:.1em; }
.nav-item {
    display:flex; align-items:center; gap:10px;
    padding:10px 18px; font-size:12px; font-weight:600; color:var(--muted);
    cursor:pointer; transition:all .2s; border-left:3px solid transparent; text-decoration:none;
}
.nav-item:hover { color:var(--text); background:rgba(255,255,255,.04); border-left-color:var(--border); }
.nav-item.active { color:var(--accent); background:rgba(245,158,11,.07); border-left-color:var(--accent); }
.nav-item .badge {
    margin-left:auto; background:var(--red); color:#fff;
    font-size:9px; font-weight:800; padding:1px 6px; border-radius:10px;
}

/* ── Main ── */
.main { flex:1; padding:28px; overflow-y:auto; }
.page-head { margin-bottom:24px; }
.page-head h1 { font-family:'Syne',sans-serif; font-size:24px; font-weight:800; color:var(--text); }
.page-head p  { font-size:12px; color:var(--muted); margin-top:3px; }

/* ── KPI ── */
.kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:14px; margin-bottom:24px; }
.kpi { background:var(--panel); border:1px solid var(--border); border-radius:12px; padding:16px 18px; position:relative; overflow:hidden; }
.kpi::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; }
.kpi.blue::before   { background:var(--blue); }
.kpi.green::before  { background:var(--green); }
.kpi.female::before { background:var(--female); }
.kpi.male::before   { background:var(--male); }
.kpi.amber::before  { background:var(--accent); }
.kpi-lbl { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.09em; color:var(--muted); margin-bottom:6px; }
.kpi-val { font-family:'JetBrains Mono',monospace; font-size:26px; font-weight:700; color:var(--text); }
.kpi-sub { font-size:10px; color:var(--muted); margin-top:4px; }

/* ── Section card ── */
.card { background:var(--panel); border:1px solid var(--border); border-radius:14px; overflow:hidden; margin-bottom:20px; }
.card-head { padding:14px 20px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
.card-title { font-size:13px; font-weight:800; color:var(--text); }
.card-sub   { font-size:11px; color:var(--muted); margin-top:1px; }
.card-body  { padding:20px; }

/* ── Table ── */
.tbl-wrap { overflow-x:auto; }
table.tbl { width:100%; border-collapse:collapse; min-width:600px; }
table.tbl thead th { padding:9px 12px; text-align:left; font-size:9px; font-weight:700; color:var(--muted); letter-spacing:.08em; text-transform:uppercase; border-bottom:1px solid var(--border); white-space:nowrap; }
table.tbl td { padding:12px 12px; font-size:12px; color:var(--text); border-bottom:1px solid rgba(51,65,85,.5); vertical-align:middle; }
table.tbl tbody tr:last-child td { border-bottom:none; }
table.tbl tbody tr:hover td { background:rgba(255,255,255,.02); }

/* ── Dorm grid ── */
.dorm-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px; }
.dorm-card {
    background:var(--bg); border:1px solid var(--border); border-radius:12px;
    padding:14px 16px; position:relative; overflow:hidden;
    transition:border-color .2s;
}
.dorm-card:hover { border-color:var(--accent); }
.dorm-card .dorm-gender { position:absolute; top:0; left:0; right:0; height:3px; }
.dorm-card .dorm-gender.female { background:var(--female); }
.dorm-card .dorm-gender.male   { background:var(--male); }
.dorm-card-name { font-weight:800; font-size:13px; color:var(--text); margin:6px 0 4px; }
.dorm-cap-bar { height:5px; background:rgba(255,255,255,.08); border-radius:4px; overflow:hidden; margin:8px 0 4px; }
.dorm-cap-fill { height:100%; border-radius:4px; transition:width .8s ease; }
.dorm-occ { font-size:10px; color:var(--muted); display:flex; justify-content:space-between; }

/* ── Form styles ── */
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
@media(max-width:640px){ .form-grid { grid-template-columns:1fr; } }
.form-group label { display:block; font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); margin-bottom:5px; }
.form-group input, .form-group select, .form-group textarea {
    width:100%; background:var(--bg); border:1px solid var(--border); border-radius:8px;
    padding:10px 12px; font-size:13px; color:var(--text); font-family:'DM Sans',sans-serif;
    outline:none; transition:border-color .2s;
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color:var(--accent); }
.form-group textarea { resize:vertical; min-height:80px; }
.form-group select option { background:var(--panel); }
.btn {
    display:inline-flex; align-items:center; gap:7px;
    padding:10px 20px; border-radius:8px; font-size:12px; font-weight:700;
    cursor:pointer; border:none; transition:all .2s; text-decoration:none;
}
.btn-primary { background:var(--accent); color:var(--bg); }
.btn-primary:hover { background:var(--accent2); }
.btn-danger  { background:rgba(239,68,68,.15); color:var(--red); border:1px solid rgba(239,68,68,.3); }
.btn-danger:hover  { background:rgba(239,68,68,.25); }
.btn-sm { padding:6px 12px; font-size:11px; }
.btn-blue  { background:rgba(59,130,246,.15); color:var(--blue); border:1px solid rgba(59,130,246,.3); }
.btn-blue:hover { background:rgba(59,130,246,.25); }
.btn-green { background:rgba(16,185,129,.15); color:var(--green); border:1px solid rgba(16,185,129,.3); }
.btn-green:hover { background:rgba(16,185,129,.25); }

/* ── Badges ── */
.badge { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:20px; font-size:10px; font-weight:700; white-space:nowrap; }
.badge-active   { background:rgba(16,185,129,.15); color:var(--green); }
.badge-vacated  { background:rgba(100,116,139,.15); color:var(--muted); }
.badge-pending  { background:rgba(245,158,11,.15); color:var(--accent); }
.badge-transferred { background:rgba(168,85,247,.15); color:var(--purple); }
.badge-female   { background:rgba(236,72,153,.15); color:var(--female); }
.badge-male     { background:rgba(59,130,246,.15); color:var(--blue); }

/* ── Notice cards ── */
.notice-card { background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:16px; margin-bottom:12px; position:relative; }
.notice-urgent { border-color:rgba(239,68,68,.4); }
.notice-info   { border-color:rgba(59,130,246,.3); }
.notice-hd { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; margin-bottom:8px; }
.notice-title { font-size:14px; font-weight:800; color:var(--text); }
.notice-meta { font-size:10px; color:var(--muted); margin-top:4px; }
.notice-body { font-size:12px; color:var(--muted); line-height:1.6; }

/* ── Alert ── */
.alert { padding:12px 16px; border-radius:10px; font-size:13px; font-weight:600; margin-bottom:16px; display:flex; align-items:center; gap:8px; }
.alert-success { background:rgba(16,185,129,.15); border:1px solid rgba(16,185,129,.3); color:var(--green); }
.alert-error   { background:rgba(239,68,68,.15); border:1px solid rgba(239,68,68,.3); color:var(--red); }
.alert-info    { background:rgba(59,130,246,.15); border:1px solid rgba(59,130,246,.3); color:var(--blue); }

/* ── Filter ── */
.filter-row { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
.filter-row input, .filter-row select {
    background:var(--bg); border:1px solid var(--border); border-radius:8px;
    padding:8px 12px; font-size:12px; color:var(--text); outline:none;
}
.filter-row input:focus, .filter-row select:focus { border-color:var(--accent); }

/* ── Tab views ── */
.view { display:none; }
.view.active { display:block; }

/* ── Progress stat ── */
.stat-row { display:flex; align-items:center; justify-content:space-between; margin-bottom:4px; }
.stat-bar-wrap { flex:1; height:6px; background:rgba(255,255,255,.07); border-radius:4px; overflow:hidden; margin:0 10px; }
.stat-bar-fill { height:100%; border-radius:4px; }

@media(max-width:768px) {
    .sidebar { display:none; }
    .main    { padding:16px; }
}
</style>
</head>
<body>

<!-- Topbar -->
<div class="topbar">
    <div class="topbar-logo">
        <div class="mark">🏠</div>
        <div>
            <span>SmartLMS</span>
            <small>Boarding Management</small>
        </div>
    </div>
    <div class="topbar-right">
        <div class="user-chip">
            <i class="fa-solid fa-user-shield" style="color:var(--accent);font-size:12px;"></i>
            <span><?php echo htmlspecialchars($bm_name); ?></span>
            <span style="font-size:10px;color:var(--muted);background:rgba(245,158,11,.12);padding:1px 7px;border-radius:6px;">Boarding Master</span>
        </div>
        <a href="apply_leave.php" class="logout-btn" style="color:#f59e0b;border-color:rgba(245,158,11,.3);"><i class="fa-solid fa-calendar-minus"></i> Apply Leave</a>
        <a href="logout.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</div>

<div class="layout">
    <!-- Sidebar -->
    <nav class="sidebar">
        <div class="sidebar-section">Management</div>
        <a class="nav-item <?php echo $tab==='overview'?'active':''; ?>" onclick="switchTab('overview')">
            <i class="fa-solid fa-chart-pie w-4"></i> Overview
        </a>
        <a class="nav-item <?php echo $tab==='dorms'?'active':''; ?>" onclick="switchTab('dorms')">
            <i class="fa-solid fa-building w-4"></i> Dorm Blocks
        </a>
        <a class="nav-item <?php echo $tab==='allocate'?'active':''; ?>" onclick="switchTab('allocate')">
            <i class="fa-solid fa-bed w-4"></i> Allocate Student
        </a>
        <a class="nav-item <?php echo $tab==='allocations'?'active':''; ?>" onclick="switchTab('allocations')">
            <i class="fa-solid fa-list-check w-4"></i> All Allocations
            <?php $active_count = count(array_filter($allocations, fn($a) => $a['status']==='active'));
            if ($active_count): ?><span class="badge" style="background:var(--green);color:#fff;border-radius:10px;font-size:9px;padding:1px 6px;margin-left:auto;"><?php echo $active_count; ?></span><?php endif; ?>
        </a>
        <div class="sidebar-section">Communication</div>
        <a class="nav-item <?php echo $tab==='notices'?'active':''; ?>" onclick="switchTab('notices')">
            <i class="fa-solid fa-bullhorn w-4"></i> Notices
        </a>
        <div class="sidebar-section">Students</div>
        <a class="nav-item <?php echo $tab==='students'?'active':''; ?>" onclick="switchTab('students')">
            <i class="fa-solid fa-users w-4"></i> Students
            <?php if ($unhoused_count > 0): ?><span class="badge"><?php echo $unhoused_count; ?></span><?php endif; ?>
        </a>
    </nav>

    <!-- Main Content -->
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
                <h1>🏠 Boarding Overview</h1>
                <p>Current occupancy and housing status across all dorms</p>
            </div>

            <div class="kpi-grid">
                <div class="kpi blue">
                    <div class="kpi-lbl">Total Capacity</div>
                    <div class="kpi-val"><?php echo number_format($total_capacity); ?></div>
                    <div class="kpi-sub">Across <?php echo count($dorms); ?> dorms</div>
                </div>
                <div class="kpi green">
                    <div class="kpi-lbl">Occupied Beds</div>
                    <div class="kpi-val"><?php echo number_format($total_allocated); ?></div>
                    <div class="kpi-sub"><?php echo $total_capacity > 0 ? round($total_allocated/$total_capacity*100) : 0; ?>% occupancy</div>
                </div>
                <div class="kpi female">
                    <div class="kpi-lbl">Ladies' Dorms</div>
                    <div class="kpi-val"><?php echo count($dorms_female); ?></div>
                    <div class="kpi-sub"><?php echo array_sum(array_column($dorms_female,'capacity')); ?> beds total</div>
                </div>
                <div class="kpi male">
                    <div class="kpi-lbl">Gents' Dorms</div>
                    <div class="kpi-val"><?php echo count($dorms_male); ?></div>
                    <div class="kpi-sub"><?php echo array_sum(array_column($dorms_male,'capacity')); ?> beds total</div>
                </div>
                <div class="kpi amber">
                    <div class="kpi-lbl">Unhoused Students</div>
                    <div class="kpi-val"><?php echo $unhoused_count; ?></div>
                    <div class="kpi-sub">Not yet allocated</div>
                </div>
            </div>

            <!-- Occupancy overview per gender -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <?php foreach ([['Ladies\' Dorms', $dorms_female, 'var(--female)'], ['Gents\' Dorms', $dorms_male, 'var(--male)']] as [$label, $list, $color]): ?>
            <div class="card">
                <div class="card-head">
                    <div>
                        <div class="card-title"><?php echo $label; ?></div>
                        <div class="card-sub"><?php echo count($list); ?> blocks</div>
                    </div>
                </div>
                <div class="card-body" style="padding:12px 16px;">
                    <?php foreach ($list as $d):
                        $occ = $alloc_stats[$d['id']] ?? 0;
                        $cap = max(1, $d['capacity']);
                        $pct = round($occ/$cap*100);
                        $bar_c = $pct >= 90 ? 'var(--red)' : ($pct >= 60 ? 'var(--accent)' : $color);
                    ?>
                    <div class="stat-row" style="margin-bottom:8px;">
                        <span style="font-size:11px;font-weight:700;color:var(--text);min-width:130px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($d['name']); ?></span>
                        <div class="stat-bar-wrap">
                            <div class="stat-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $bar_c; ?>;"></div>
                        </div>
                        <span style="font-size:10px;font-family:'JetBrains Mono',monospace;color:var(--muted);min-width:52px;text-align:right;"><?php echo $occ; ?>/<?php echo $cap; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>

        <!-- ══ DORM BLOCKS ══ -->
        <div id="view-dorms" class="view <?php echo $tab==='dorms'?'active':''; ?>">
            <div class="page-head">
                <h1>🏢 Dorm Blocks</h1>
                <p>All dorms, capacities, and current occupancy</p>
            </div>

            <div style="margin-bottom:18px;">
                <div style="font-size:12px;font-weight:800;color:var(--female);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px;"><i class="fa-solid fa-venus mr-2"></i>Ladies' Dorms</div>
                <div class="dorm-grid">
                <?php foreach ($dorms_female as $d):
                    $occ = $alloc_stats[$d['id']] ?? 0;
                    $cap = max(1, $d['capacity']);
                    $pct = round($occ/$cap*100);
                    $bar_c = $pct >= 90 ? 'var(--red)' : ($pct >= 60 ? 'var(--accent)' : 'var(--female)');
                ?>
                <div class="dorm-card">
                    <div class="dorm-gender female"></div>
                    <div class="dorm-card-name"><?php echo htmlspecialchars($d['name']); ?></div>
                    <div class="dorm-cap-bar"><div class="dorm-cap-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $bar_c; ?>;"></div></div>
                    <div class="dorm-occ">
                        <span><?php echo $occ; ?> occupied</span>
                        <span><?php echo $cap - $occ; ?> free</span>
                    </div>
                    <form method="POST" style="margin-top:8px;display:flex;gap:6px;align-items:center;">
                        <input type="hidden" name="action" value="update_capacity">
                        <input type="hidden" name="dorm_id" value="<?php echo $d['id']; ?>">
                        <input type="number" name="capacity" value="<?php echo $d['capacity']; ?>" min="1" style="width:70px;padding:5px 8px;font-size:11px;background:var(--bg);border:1px solid var(--border);border-radius:6px;color:var(--text);">
                        <button type="submit" class="btn btn-sm" style="background:rgba(236,72,153,.15);color:var(--female);border:1px solid rgba(236,72,153,.3);">Set Cap</button>
                    </form>
                </div>
                <?php endforeach; ?>
                </div>
            </div>

            <div>
                <div style="font-size:12px;font-weight:800;color:var(--male);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px;"><i class="fa-solid fa-mars mr-2"></i>Gents' Dorms</div>
                <div class="dorm-grid">
                <?php foreach ($dorms_male as $d):
                    $occ = $alloc_stats[$d['id']] ?? 0;
                    $cap = max(1, $d['capacity']);
                    $pct = round($occ/$cap*100);
                    $bar_c = $pct >= 90 ? 'var(--red)' : ($pct >= 60 ? 'var(--accent)' : 'var(--male)');
                ?>
                <div class="dorm-card">
                    <div class="dorm-gender male"></div>
                    <div class="dorm-card-name"><?php echo htmlspecialchars($d['name']); ?></div>
                    <div class="dorm-cap-bar"><div class="dorm-cap-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $bar_c; ?>;"></div></div>
                    <div class="dorm-occ">
                        <span><?php echo $occ; ?> occupied</span>
                        <span><?php echo $cap - $occ; ?> free</span>
                    </div>
                    <form method="POST" style="margin-top:8px;display:flex;gap:6px;align-items:center;">
                        <input type="hidden" name="action" value="update_capacity">
                        <input type="hidden" name="dorm_id" value="<?php echo $d['id']; ?>">
                        <input type="number" name="capacity" value="<?php echo $d['capacity']; ?>" min="1" style="width:70px;padding:5px 8px;font-size:11px;background:var(--bg);border:1px solid var(--border);border-radius:6px;color:var(--text);">
                        <button type="submit" class="btn btn-sm" style="background:rgba(59,130,246,.15);color:var(--blue);border:1px solid rgba(59,130,246,.3);">Set Cap</button>
                    </form>
                </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ══ ALLOCATE ══ -->
        <div id="view-allocate" class="view <?php echo $tab==='allocate'?'active':''; ?>">
            <div class="page-head">
                <h1>🛏 Allocate Student to Dorm</h1>
                <p>Assign a student to a dormitory room/bed</p>
            </div>
            <div class="card">
                <div class="card-head"><div class="card-title">New / Update Allocation</div></div>
                <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="allocate">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Student *</label>
                            <select name="student_id" required id="stu-select" onchange="updateDormFilter()">
                                <option value="">— Select Student —</option>
                                <?php foreach ($students as $s): ?>
                                <option value="<?php echo $s['id']; ?>" data-dorm="<?php echo htmlspecialchars($s['current_dorm']??''); ?>">
                                    <?php echo htmlspecialchars($s['full_name']); ?>
                                    <?php if ($s['current_dorm']): ?> (Current: <?php echo htmlspecialchars($s['current_dorm']); ?>)<?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Dormitory *</label>
                            <select name="dorm_id" required id="dorm-select">
                                <option value="">— Select Dorm —</option>
                                <optgroup label="🌸 Ladies' Dorms">
                                <?php foreach ($dorms_female as $d):
                                    $occ = $alloc_stats[$d['id']] ?? 0;
                                    $free = $d['capacity'] - $occ;
                                ?>
                                <option value="<?php echo $d['id']; ?>" <?php echo $free <= 0 ? 'style="color:#ef4444;"' : ''; ?>>
                                    <?php echo htmlspecialchars($d['name']); ?> (<?php echo $free; ?> beds free)
                                </option>
                                <?php endforeach; ?>
                                </optgroup>
                                <optgroup label="🔵 Gents' Dorms">
                                <?php foreach ($dorms_male as $d):
                                    $occ = $alloc_stats[$d['id']] ?? 0;
                                    $free = $d['capacity'] - $occ;
                                ?>
                                <option value="<?php echo $d['id']; ?>" <?php echo $free <= 0 ? 'style="color:#ef4444;"' : ''; ?>>
                                    <?php echo htmlspecialchars($d['name']); ?> (<?php echo $free; ?> beds free)
                                </option>
                                <?php endforeach; ?>
                                </optgroup>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Room Number (optional)</label>
                            <input type="text" name="room_number" placeholder="e.g. Room 12A">
                        </div>
                        <div class="form-group">
                            <label>Bed / Bunk Number (optional)</label>
                            <input type="text" name="bed_number" placeholder="e.g. B2, Top Bunk">
                        </div>
                        <div class="form-group">
                            <label>Academic Year *</label>
                            <input type="text" name="academic_year" value="<?php echo date('Y').'/'. (date('Y')+1); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Semester *</label>
                            <select name="semester" required>
                                <option>Semester 1</option>
                                <option>Semester 2</option>
                                <option>Full Year</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Check-in Date</label>
                            <input type="date" name="check_in_date" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group" style="grid-column:span 2;">
                            <label>Notes (optional)</label>
                            <textarea name="notes" placeholder="Any special remarks or conditions..."></textarea>
                        </div>
                    </div>
                    <div style="margin-top:16px;">
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-bed"></i> Allocate Student</button>
                    </div>
                </form>
                </div>
            </div>
        </div>

        <!-- ══ ALL ALLOCATIONS ══ -->
        <div id="view-allocations" class="view <?php echo $tab==='allocations'?'active':''; ?>">
            <div class="page-head">
                <h1>📋 All Allocations</h1>
                <p>View, transfer, or vacate student dorm allocations</p>
            </div>
            <div class="card">
                <div class="card-head">
                    <div class="card-title">Allocation Records</div>
                    <div class="filter-row" style="margin-bottom:0;">
                        <input type="text" id="alloc-search" placeholder="Search student or dorm..." oninput="filterAllocs()" style="width:220px;">
                        <select id="alloc-status-filter" onchange="filterAllocs()">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="pending">Pending</option>
                            <option value="vacated">Vacated</option>
                            <option value="transferred">Transferred</option>
                        </select>
                    </div>
                </div>
                <div class="tbl-wrap">
                    <table class="tbl" id="alloc-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Dorm</th>
                                <th>Bed</th>
                                <th>Year / Sem</th>
                                <th>Check-In</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($allocations as $a):
                            $status_class = [
                                'active'=>'badge-active','vacated'=>'badge-vacated',
                                'pending'=>'badge-pending','transferred'=>'badge-transferred'
                            ][$a['status']] ?? 'badge-pending';
                        ?>
                        <tr class="alloc-row" data-name="<?php echo strtolower($a['full_name']); ?>" data-dorm="<?php echo strtolower($a['dorm_name']); ?>" data-status="<?php echo $a['status']; ?>">
                            <td>
                                <div style="font-weight:700;"><?php echo htmlspecialchars($a['full_name']); ?></div>
                                <div style="font-size:10px;color:var(--muted);"><?php echo htmlspecialchars($a['email']); ?></div>
                            </td>
                            <td>
                                <div style="font-weight:600;"><?php echo htmlspecialchars($a['dorm_name']); ?></div>
                                <span class="badge <?php echo $a['gender']==='female'?'badge-female':'badge-male'; ?>" style="margin-top:3px;"><?php echo ucfirst($a['gender']); ?></span>
                            </td>
                            <td style="font-family:'JetBrains Mono',monospace;font-size:11px;"><?php echo $a['bed_number'] ?: '—'; ?></td>
                            <td style="font-size:11px;"><?php echo htmlspecialchars($a['academic_year']); ?><br><?php echo htmlspecialchars($a['semester']); ?></td>
                            <td style="font-size:11px;"><?php echo $a['check_in_date'] ? date('d M Y',strtotime($a['check_in_date'])) : '—'; ?></td>
                            <td><span class="badge <?php echo $status_class; ?>"><?php echo ucfirst($a['status']); ?></span></td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                <?php if ($a['status'] === 'active'): ?>
                                <button class="btn btn-sm btn-danger" onclick="openVacate(<?php echo $a['id']; ?>, '<?php echo htmlspecialchars(addslashes($a['full_name'])); ?>')">
                                    <i class="fa-solid fa-sign-out-alt"></i> Vacate
                                </button>
                                <button class="btn btn-sm btn-blue" onclick="openTransfer(<?php echo $a['id']; ?>, '<?php echo htmlspecialchars(addslashes($a['full_name'])); ?>')">
                                    <i class="fa-solid fa-arrows-rotate"></i> Transfer
                                </button>
                                <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($allocations)): ?>
                        <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:32px;font-style:italic;">No allocations found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ══ NOTICES ══ -->
        <div id="view-notices" class="view <?php echo $tab==='notices'?'active':''; ?>">
            <div class="page-head">
                <h1>📣 Boarding Notices</h1>
                <p>Post notices visible to students in their boarding section</p>
            </div>
            <div class="card" style="margin-bottom:20px;">
                <div class="card-head"><div class="card-title">Post New Notice</div></div>
                <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="post_notice">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Title *</label>
                            <input type="text" name="title" required placeholder="Notice title...">
                        </div>
                        <div class="form-group">
                            <label>Priority</label>
                            <select name="priority">
                                <option value="normal">Normal</option>
                                <option value="urgent">🔴 Urgent</option>
                                <option value="info">ℹ Info</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Target Audience</label>
                            <select name="target" id="notice-target" onchange="toggleDormSelect()">
                                <option value="all">All Boarders</option>
                                <option value="female">Ladies Only</option>
                                <option value="male">Gents Only</option>
                                <option value="specific_dorm">Specific Dorm</option>
                            </select>
                        </div>
                        <div class="form-group" id="dorm-select-wrap" style="display:none;">
                            <label>Select Dorm</label>
                            <select name="notice_dorm_id">
                                <option value="">— All —</option>
                                <optgroup label="Ladies">
                                <?php foreach ($dorms_female as $d): ?><option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['name']); ?></option><?php endforeach; ?>
                                </optgroup>
                                <optgroup label="Gents">
                                <?php foreach ($dorms_male as $d): ?><option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['name']); ?></option><?php endforeach; ?>
                                </optgroup>
                            </select>
                        </div>
                        <div class="form-group" style="grid-column:span 2;">
                            <label>Message *</label>
                            <textarea name="message" required placeholder="Enter notice content..." style="min-height:100px;"></textarea>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="margin-top:12px;"><i class="fa-solid fa-bullhorn"></i> Post Notice</button>
                </form>
                </div>
            </div>

            <!-- Notice list -->
            <?php if (empty($notices)): ?>
            <div style="text-align:center;color:var(--muted);padding:40px;font-size:13px;">No notices posted yet.</div>
            <?php else: ?>
            <?php foreach ($notices as $n):
                $nc = $n['priority']==='urgent' ? 'notice-urgent' : ($n['priority']==='info' ? 'notice-info' : '');
                $pc = $n['priority']==='urgent' ? 'var(--red)' : ($n['priority']==='info' ? 'var(--blue)' : 'var(--accent)');
            ?>
            <div class="notice-card <?php echo $nc; ?>">
                <div class="notice-hd">
                    <div>
                        <div class="notice-title"><?php echo htmlspecialchars($n['title']); ?></div>
                        <div class="notice-meta">
                            <span style="color:<?php echo $pc; ?>;font-weight:700;"><?php echo strtoupper($n['priority']); ?></span>
                            &nbsp;·&nbsp; Target: <?php echo ucfirst(str_replace('_',' ',$n['target'])); ?>
                            <?php if ($n['dorm_name']): ?> › <?php echo htmlspecialchars($n['dorm_name']); ?><?php endif; ?>
                            &nbsp;·&nbsp; <?php echo date('d M Y H:i', strtotime($n['created_at'])); ?>
                            &nbsp;·&nbsp; By <?php echo htmlspecialchars($n['posted_by_name']); ?>
                        </div>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="delete_notice">
                        <input type="hidden" name="notice_id" value="<?php echo $n['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Remove this notice?')"><i class="fa-solid fa-trash"></i></button>
                    </form>
                </div>
                <div class="notice-body"><?php echo nl2br(htmlspecialchars($n['message'])); ?></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ══ STUDENTS ══ -->
        <div id="view-students" class="view <?php echo $tab==='students'?'active':''; ?>">
            <div class="page-head">
                <h1>👥 All Students</h1>
                <p>Housing status of all registered students</p>
            </div>
            <div class="filter-row" style="margin-bottom:14px;">
                <input type="text" id="stu-search" placeholder="Search name or email..." oninput="filterStudents()" style="width:260px;">
                <select id="stu-housed-filter" onchange="filterStudents()">
                    <option value="">All</option>
                    <option value="housed">Housed</option>
                    <option value="unhoused">Not Allocated</option>
                </select>
            </div>
            <div class="card">
                <div class="tbl-wrap">
                    <table class="tbl" id="stu-table">
                        <thead>
                            <tr><th>Name</th><th>Email</th><th>Programme</th><th>Current Dorm</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($students as $s): ?>
                        <tr class="stu-row" data-name="<?php echo strtolower($s['full_name']); ?>" data-email="<?php echo strtolower($s['email']); ?>" data-housed="<?php echo $s['current_dorm'] ? 'housed' : 'unhoused'; ?>">
                            <td style="font-weight:700;"><?php echo htmlspecialchars($s['full_name']); ?></td>
                            <td style="font-size:11px;color:var(--muted);"><?php echo htmlspecialchars($s['email']); ?></td>
                            <td style="font-size:11px;"><?php echo htmlspecialchars($s['course'] ?? '—'); ?></td>
                            <td>
                                <?php if ($s['current_dorm']): ?>
                                <span class="badge badge-active"><i class="fa-solid fa-bed"></i> <?php echo htmlspecialchars($s['current_dorm']); ?></span>
                                <?php else: ?>
                                <span class="badge badge-vacated">Not Allocated</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-primary" onclick="quickAllocate(<?php echo $s['id']; ?>, '<?php echo htmlspecialchars(addslashes($s['full_name'])); ?>')">
                                    <i class="fa-solid fa-bed"></i> Allocate
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </main>
</div>

<!-- Vacate Modal -->
<div id="vacate-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:200;display:flex;align-items:center;justify-content:center;" class="modal-bg" onclick="closeModal('vacate-modal')">
    <div style="background:var(--panel);border:1px solid var(--border);border-radius:16px;padding:28px;width:400px;max-width:95vw;" onclick="event.stopPropagation()">
        <h3 style="font-family:'Syne',sans-serif;font-size:18px;margin-bottom:16px;">Vacate Student</h3>
        <p style="font-size:13px;color:var(--muted);margin-bottom:16px;" id="vacate-name-label"></p>
        <form method="POST">
            <input type="hidden" name="action" value="vacate">
            <input type="hidden" name="allocation_id" id="vacate-alloc-id">
            <div class="form-group" style="margin-bottom:14px;">
                <label>Check-out Date</label>
                <input type="date" name="check_out_date" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div style="display:flex;gap:10px;">
                <button type="submit" class="btn btn-danger"><i class="fa-solid fa-sign-out-alt"></i> Confirm Vacate</button>
                <button type="button" class="btn" style="background:var(--bg);color:var(--muted);" onclick="closeModal('vacate-modal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Transfer Modal -->
<div id="transfer-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:200;align-items:center;justify-content:center;" onclick="closeModal('transfer-modal')">
    <div style="background:var(--panel);border:1px solid var(--border);border-radius:16px;padding:28px;width:440px;max-width:95vw;" onclick="event.stopPropagation()">
        <h3 style="font-family:'Syne',sans-serif;font-size:18px;margin-bottom:16px;">Transfer Student</h3>
        <p style="font-size:13px;color:var(--muted);margin-bottom:16px;" id="transfer-name-label"></p>
        <form method="POST">
            <input type="hidden" name="action" value="transfer">
            <input type="hidden" name="allocation_id" id="transfer-alloc-id">
            <div class="form-group" style="margin-bottom:12px;">
                <label>New Dormitory</label>
                <select name="new_dorm_id" required>
                    <option value="">— Select New Dorm —</option>
                    <optgroup label="Ladies' Dorms">
                    <?php foreach ($dorms_female as $d): ?><option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['name']); ?></option><?php endforeach; ?>
                    </optgroup>
                    <optgroup label="Gents' Dorms">
                    <?php foreach ($dorms_male as $d): ?><option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['name']); ?></option><?php endforeach; ?>
                    </optgroup>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:14px;">
                <label>New Bed Number (optional)</label>
                <input type="text" name="new_bed_number" placeholder="e.g. A3">
            </div>
            <div style="display:flex;gap:10px;">
                <button type="submit" class="btn btn-blue"><i class="fa-solid fa-arrows-rotate"></i> Transfer</button>
                <button type="button" class="btn" style="background:var(--bg);color:var(--muted);" onclick="closeModal('transfer-modal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
const TABS = ['overview','dorms','allocate','allocations','notices','students'];
function switchTab(t) {
    TABS.forEach(id => {
        document.getElementById('view-'+id)?.classList.toggle('active', id===t);
        document.querySelectorAll('.nav-item').forEach(el => {
            if (el.getAttribute('onclick')==="switchTab('"+t+"')") el.classList.add('active');
            else el.classList.remove('active');
        });
    });
    history.replaceState(null,'','boarding_dashboard.php?tab='+t);
}

function openVacate(id, name) {
    document.getElementById('vacate-alloc-id').value = id;
    document.getElementById('vacate-name-label').textContent = 'Vacating: ' + name;
    const m = document.getElementById('vacate-modal');
    m.style.display = 'flex';
}
function openTransfer(id, name) {
    document.getElementById('transfer-alloc-id').value = id;
    document.getElementById('transfer-name-label').textContent = 'Transferring: ' + name;
    const m = document.getElementById('transfer-modal');
    m.style.display = 'flex';
}
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

function toggleDormSelect() {
    const t = document.getElementById('notice-target').value;
    document.getElementById('dorm-select-wrap').style.display = t === 'specific_dorm' ? 'block' : 'none';
}

function filterAllocs() {
    const q = document.getElementById('alloc-search').value.toLowerCase();
    const s = document.getElementById('alloc-status-filter').value;
    document.querySelectorAll('.alloc-row').forEach(row => {
        const nm = row.dataset.name.includes(q) || row.dataset.dorm.includes(q);
        const st = !s || row.dataset.status === s;
        row.style.display = nm && st ? '' : 'none';
    });
}
function filterStudents() {
    const q = document.getElementById('stu-search').value.toLowerCase();
    const h = document.getElementById('stu-housed-filter').value;
    document.querySelectorAll('.stu-row').forEach(row => {
        const nm = row.dataset.name.includes(q) || row.dataset.email.includes(q);
        const ht = !h || row.dataset.housed === h;
        row.style.display = nm && ht ? '' : 'none';
    });
}

function quickAllocate(id, name) {
    switchTab('allocate');
    setTimeout(() => {
        const sel = document.getElementById('stu-select');
        if (sel) { sel.value = id; }
    }, 100);
}

// Init tab from URL
const urlParams = new URLSearchParams(window.location.search);
const initTab = urlParams.get('tab') || 'overview';
switchTab(initTab);
</script>
</body>
</html>
