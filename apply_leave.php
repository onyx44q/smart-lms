<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include 'config.php';

// Allow ALL non-student roles to access this page
$role = $_SESSION['role'] ?? '';
$allowed_roles = ['admin','lecturer','financial_accountant','boarding_master','hr_manager'];
if (!in_array($role, $allowed_roles)) {
    header("Location: index.php"); exit();
}

$user_id   = intval($_SESSION['user_id']);
$user_name = $_SESSION['user_name'];

// Ensure hr_staff + hr_leave tables exist
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `hr_staff` (`id` INT AUTO_INCREMENT PRIMARY KEY,`user_id` INT DEFAULT NULL,`staff_no` VARCHAR(30) DEFAULT NULL,`full_name` VARCHAR(150) NOT NULL,`email` VARCHAR(150) DEFAULT NULL,`phone` VARCHAR(30) DEFAULT NULL,`gender` ENUM('male','female','other') DEFAULT NULL,`department` VARCHAR(100) DEFAULT NULL,`job_title` VARCHAR(150) DEFAULT NULL,`job_type` ENUM('full_time','part_time','contract','intern') DEFAULT 'full_time',`employment_date` DATE DEFAULT NULL,`status` ENUM('active','on_leave','terminated','suspended') DEFAULT 'active',`basic_salary` DECIMAL(12,2) DEFAULT 0.00,`allowances` DECIMAL(12,2) DEFAULT 0.00,`deductions` DECIMAL(12,2) DEFAULT 0.00,`bank_name` VARCHAR(100) DEFAULT NULL,`bank_account` VARCHAR(50) DEFAULT NULL,`created_by` INT DEFAULT NULL,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,KEY(`user_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `hr_leave_requests` (`id` INT AUTO_INCREMENT PRIMARY KEY,`staff_id` INT NOT NULL,`user_id` INT DEFAULT NULL,`leave_type` ENUM('annual','sick','maternity','paternity','emergency','unpaid','other') DEFAULT 'annual',`start_date` DATE NOT NULL,`end_date` DATE NOT NULL,`days_requested` INT NOT NULL DEFAULT 1,`reason` TEXT DEFAULT NULL,`status` ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending',`reviewed_by` INT DEFAULT NULL,`review_notes` TEXT DEFAULT NULL,`reviewed_at` TIMESTAMP NULL,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,KEY(`staff_id`),KEY(`user_id`),KEY(`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Add user_id column if missing (for direct user linkage)
@mysqli_query($conn, "ALTER TABLE hr_leave_requests ADD COLUMN `user_id` INT DEFAULT NULL AFTER `staff_id`");

// Auto-ensure this user has an hr_staff record
$my_staff = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM hr_staff WHERE user_id=$user_id LIMIT 1"));
if (!$my_staff) {
    $email = mysqli_real_escape_string($conn, '');
    // Get email from users table
    $u_row = mysqli_fetch_assoc(mysqli_query($conn,"SELECT email FROM users WHERE id=$user_id LIMIT 1"));
    $email = mysqli_real_escape_string($conn, $u_row['email'] ?? '');
    $fn    = mysqli_real_escape_string($conn, $user_name);
    $dept  = match($role) {
        'lecturer' => 'Academics', 'admin' => 'Administration',
        'financial_accountant' => 'Finance', 'boarding_master' => 'Student Affairs',
        'hr_manager' => 'Human Resources', default => 'General'
    };
    $jt = match($role) {
        'lecturer' => 'Lecturer', 'admin' => 'System Administrator',
        'financial_accountant' => 'Financial Accountant',
        'boarding_master' => 'Boarding Master', 'hr_manager' => 'HR Manager',
        default => ucwords(str_replace('_',' ',$role))
    };
    $cnt = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*)+1 AS c FROM hr_staff"))['c'];
    $sno = 'STF-'.date('Y').'-'.str_pad($cnt,4,'0',STR_PAD_LEFT);
    mysqli_query($conn, "INSERT INTO hr_staff (user_id,staff_no,full_name,email,department,job_title,job_type,employment_date,status,created_by)
        VALUES ($user_id,'$sno','$fn','$email','$dept','$jt','full_time','".date('Y-m-d')."','active',$user_id)");
    $my_staff = mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM hr_staff WHERE user_id=$user_id LIMIT 1"));
}
$staff_id = intval($my_staff['id']);

// ── HANDLE ACTIONS ────────────────────────────────────────────────────
$msg = ''; $msg_type = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'apply_leave') {
        $ltype  = mysqli_real_escape_string($conn, $_POST['leave_type'] ?? 'annual');
        $sdate  = mysqli_real_escape_string($conn, $_POST['start_date']);
        $edate  = mysqli_real_escape_string($conn, $_POST['end_date']);
        $reason = mysqli_real_escape_string($conn, trim($_POST['reason'] ?? ''));

        // Calculate days
        $d1 = new DateTime($sdate);
        $d2 = new DateTime($edate);
        $days = max(1, $d1->diff($d2)->days + 1);

        // Check for overlapping pending/approved leave
        $overlap = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM hr_leave_requests WHERE staff_id=$staff_id
             AND status IN ('pending','approved')
             AND NOT (end_date < '$sdate' OR start_date > '$edate') LIMIT 1"));
        if ($overlap) {
            $msg = "You already have a leave request for overlapping dates."; $msg_type = 'error';
        } elseif (empty($sdate) || empty($edate) || $sdate > $edate) {
            $msg = "Please enter valid start and end dates."; $msg_type = 'error';
        } else {
            mysqli_query($conn, "INSERT INTO hr_leave_requests (staff_id,user_id,leave_type,start_date,end_date,days_requested,reason,status)
                VALUES ($staff_id,$user_id,'$ltype','$sdate','$edate',$days,'$reason','pending')");
            $msg = "Leave request submitted successfully. Awaiting HR review."; $msg_type = 'success';
        }
    }

    if ($action === 'cancel_leave') {
        $lid = intval($_POST['leave_id']);
        mysqli_query($conn, "UPDATE hr_leave_requests SET status='cancelled' WHERE id=$lid AND staff_id=$staff_id AND status='pending'");
        $msg = "Leave request cancelled."; $msg_type = 'success';
    }
}

// ── DATA ─────────────────────────────────────────────────────────────
$my_leaves_res = mysqli_query($conn,
    "SELECT * FROM hr_leave_requests WHERE staff_id=$staff_id ORDER BY created_at DESC LIMIT 50");
$my_leaves = [];
while ($l = mysqli_fetch_assoc($my_leaves_res)) $my_leaves[] = $l;

// Leave balance summary
$approved_this_year = array_filter($my_leaves, fn($l) => $l['status']==='approved' && date('Y',strtotime($l['start_date']))===date('Y'));
$days_used = array_sum(array_column(array_values($approved_this_year), 'days_requested'));
$annual_entitlement = 21; // standard
$days_remaining = max(0, $annual_entitlement - $days_used);

// Dashboard link for back button
$back_links = [
    'admin' => 'admin_dashboard.php',
    'lecturer' => 'lecturer_dashboard.php',
    'financial_accountant' => 'financial_dashboard.php',
    'boarding_master' => 'boarding_dashboard.php',
    'hr_manager' => 'hr_dashboard.php',
];
$back_link = $back_links[$role] ?? 'index.php';

$role_colors = [
    'admin'=>'#6366f1','lecturer'=>'#3b82f6','financial_accountant'=>'#10b981',
    'boarding_master'=>'#f59e0b','hr_manager'=>'#8b5cf6'
];
$role_color = $role_colors[$role] ?? '#64748b';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Leave Application — SmartLMS</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700;800&family=Outfit:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--bg:#f1f5f9;--panel:#fff;--border:#e2e8f0;--text:#1e293b;--muted:#64748b;--soft:#94a3b8;--acc:<?php echo $role_color; ?>;}
*{margin:0;padding:0;box-sizing:border-box;}
body{background:var(--bg);font-family:'Outfit',sans-serif;color:var(--text);min-height:100vh;}
.topbar{background:var(--text);padding:0 28px;height:56px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
.tl{display:flex;align-items:center;gap:12px;}
.tl .mark{width:32px;height:32px;background:var(--acc);border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;font-weight:900;}
.tl span{font-family:'Space Grotesk',sans-serif;font-size:15px;font-weight:800;color:var(--acc);}
.tr{display:flex;align-items:center;gap:10px;}
.user-chip{display:flex;align-items:center;gap:8px;padding:5px 12px;border:1px solid rgba(255,255,255,.15);border-radius:20px;}
.user-chip span{font-size:12px;font-weight:600;color:#e2e8f0;}
.role-pill{background:var(--acc);color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;}
.back-btn{color:rgba(255,255,255,.5);font-size:12px;text-decoration:none;padding:7px 14px;border-radius:8px;border:1px solid rgba(255,255,255,.15);transition:all .2s;}
.back-btn:hover{color:#fff;border-color:rgba(255,255,255,.35);}
.page{max-width:900px;margin:0 auto;padding:32px 20px 60px;}
.page-hd{margin-bottom:24px;}
.page-hd h1{font-family:'Space Grotesk',sans-serif;font-size:24px;font-weight:800;color:var(--text);}
.page-hd p{font-size:12px;color:var(--muted);margin-top:3px;}
/* KPI */
.kpi-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:24px;}
.kpi{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,.04);position:relative;overflow:hidden;}
.kpi::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--acc);}
.kpi-lbl{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:5px;}
.kpi-val{font-family:'JetBrains Mono',monospace;font-size:24px;font-weight:700;color:var(--text);}
.kpi-sub{font-size:10px;color:var(--muted);margin-top:3px;}
/* Card */
.card{background:var(--panel);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.card-hd{padding:14px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px;}
.card-title{font-size:14px;font-weight:800;color:var(--text);}
.card-body{padding:22px;}
/* Form */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
@media(max-width:580px){.form-grid{grid-template-columns:1fr;}}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);}
.fg input,.fg select,.fg textarea{border:1px solid var(--border);border-radius:8px;padding:10px 13px;font-size:13px;font-family:'Outfit',sans-serif;color:var(--text);outline:none;transition:border-color .2s;background:#fff;}
.fg input:focus,.fg select:focus,.fg textarea:focus{border-color:var(--acc);box-shadow:0 0 0 3px color-mix(in srgb, var(--acc) 12%, transparent);}
.fg textarea{resize:vertical;min-height:90px;}
.fg.full{grid-column:1/-1;}
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 22px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;border:none;font-family:'Outfit',sans-serif;transition:all .2s;}
.btn-primary{background:var(--acc);color:#fff;}
.btn-primary:hover{opacity:.88;}
.btn-danger{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;}
.btn-danger:hover{background:#fee2e2;}
.btn-sm{padding:6px 12px;font-size:11px;}
/* Days counter */
.days-badge{display:inline-flex;align-items:center;gap:6px;background:color-mix(in srgb, var(--acc) 12%, transparent);border:1px solid color-mix(in srgb, var(--acc) 30%, transparent);color:var(--acc);font-size:12px;font-weight:700;padding:6px 14px;border-radius:20px;margin-top:10px;}
/* Table */
.tbl-wrap{overflow-x:auto;}
table.lt{width:100%;border-collapse:collapse;min-width:500px;}
table.lt thead th{padding:9px 14px;text-align:left;font-size:9px;font-weight:700;color:var(--muted);letter-spacing:.07em;text-transform:uppercase;border-bottom:2px solid var(--border);background:#fafafa;white-space:nowrap;}
table.lt td{padding:13px 14px;font-size:13px;color:var(--text);border-bottom:1px solid #f5f5f5;vertical-align:middle;}
table.lt tbody tr:last-child td{border-bottom:none;}
table.lt tbody tr:hover td{background:#fafafa;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;}
.b-pending{background:#fef3c7;color:#92400e;}
.b-approved{background:#d1fae5;color:#065f46;}
.b-rejected{background:#fee2e2;color:#991b1b;}
.b-cancelled{background:#f1f5f9;color:#64748b;}
/* Alert */
.alert{padding:13px 18px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:20px;display:flex;align-items:center;gap:9px;}
.alert-success{background:#ecfdf5;border:1px solid #a7f3d0;color:#059669;}
.alert-error{background:#fef2f2;border:1px solid #fecaca;color:#dc2626;}
/* Leave type icons */
.lt-icon{display:inline-flex;align-items:center;gap:5px;}
/* Info banner */
.info-banner{background:linear-gradient(135deg,color-mix(in srgb,var(--acc) 8%,white),color-mix(in srgb,var(--acc) 4%,white));border:1px solid color-mix(in srgb,var(--acc) 20%,white);border-radius:12px;padding:16px 20px;margin-bottom:20px;display:flex;align-items:center;gap:14px;}
.info-icon{width:44px;height:44px;background:var(--acc);border-radius:11px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;flex-shrink:0;}
@media(max-width:640px){.page{padding:16px 12px 40px;}.kpi-val{font-size:18px;}}
</style>
</head>
<body>

<div class="topbar">
    <div class="tl">
        <div class="mark"><i class="fa-solid fa-calendar-minus" style="font-size:13px;"></i></div>
        <span>SmartLMS</span>
    </div>
    <div class="tr">
        <div class="user-chip">
            <i class="fa-solid fa-user" style="color:var(--acc);font-size:11px;"></i>
            <span><?php echo htmlspecialchars($user_name); ?></span>
            <span class="role-pill"><?php echo ucwords(str_replace('_',' ',$role)); ?></span>
        </div>
        <a href="<?php echo $back_link; ?>" class="back-btn"><i class="fa-solid fa-arrow-left mr-1"></i> My Dashboard</a>
        <a href="logout.php" class="back-btn"><i class="fa-solid fa-right-from-bracket mr-1"></i> Logout</a>
    </div>
</div>

<div class="page">
    <div class="page-hd">
        <h1>📅 Leave Application</h1>
        <p>Apply for leave and track your leave history · <?php echo htmlspecialchars($my_staff['staff_no'] ?? '—'); ?> · <?php echo htmlspecialchars($my_staff['department'] ?? '—'); ?></p>
    </div>

    <?php if ($msg): ?>
    <div class="alert alert-<?php echo $msg_type; ?>">
        <i class="fa-solid <?php echo $msg_type==='success'?'fa-circle-check':'fa-circle-exclamation'; ?>"></i>
        <?php echo htmlspecialchars($msg); ?>
    </div>
    <?php endif; ?>

    <!-- Balance KPIs -->
    <div class="kpi-row">
        <div class="kpi">
            <div class="kpi-lbl">Annual Entitlement</div>
            <div class="kpi-val"><?php echo $annual_entitlement; ?></div>
            <div class="kpi-sub">Days per year</div>
        </div>
        <div class="kpi">
            <div class="kpi-lbl">Days Used</div>
            <div class="kpi-val" style="color:#dc2626;"><?php echo $days_used; ?></div>
            <div class="kpi-sub">Approved <?php echo date('Y'); ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-lbl">Days Remaining</div>
            <div class="kpi-val" style="color:var(--acc);"><?php echo $days_remaining; ?></div>
            <div class="kpi-sub">Annual leave balance</div>
        </div>
        <div class="kpi">
            <div class="kpi-lbl">Total Requests</div>
            <div class="kpi-val"><?php echo count($my_leaves); ?></div>
            <div class="kpi-sub"><?php echo count(array_filter($my_leaves,fn($l)=>$l['status']==='pending')); ?> pending</div>
        </div>
    </div>

    <!-- Info banner -->
    <div class="info-banner">
        <div class="info-icon">📋</div>
        <div>
            <div style="font-size:14px;font-weight:800;color:var(--text);margin-bottom:3px;">How Leave Works</div>
            <div style="font-size:12px;color:var(--muted);line-height:1.6;">Submit your request below. HR will review and approve or reject. You will see the status update here. Sick leave requires a medical certificate on return. Annual leave requires at least 7 days notice.</div>
        </div>
    </div>

    <!-- Application Form -->
    <div class="card">
        <div class="card-hd">
            <div class="card-title">➕ Apply for Leave</div>
        </div>
        <div class="card-body">
            <form method="POST" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="apply_leave">
                <div class="form-grid">
                    <div class="fg">
                        <label>Leave Type *</label>
                        <select name="leave_type" id="leave_type" required>
                            <option value="annual">🌴 Annual Leave</option>
                            <option value="sick">🏥 Sick Leave</option>
                            <option value="maternity">👶 Maternity Leave</option>
                            <option value="paternity">👨‍👶 Paternity Leave</option>
                            <option value="emergency">🚨 Emergency Leave</option>
                            <option value="unpaid">💸 Unpaid Leave</option>
                            <option value="other">📋 Other</option>
                        </select>
                    </div>
                    <div class="fg">
                        <!-- Days counter will appear here -->
                        <label>Duration</label>
                        <div id="days-display" class="days-badge" style="display:none;">
                            <i class="fa-solid fa-calendar-days"></i>
                            <span id="days-count">0</span> day(s)
                        </div>
                        <div style="font-size:11px;color:var(--muted);margin-top:6px;">Auto-calculated from dates</div>
                    </div>
                    <div class="fg">
                        <label>Start Date *</label>
                        <input type="date" name="start_date" id="start_date" required min="<?php echo date('Y-m-d'); ?>" onchange="calcDays()">
                    </div>
                    <div class="fg">
                        <label>End Date *</label>
                        <input type="date" name="end_date" id="end_date" required min="<?php echo date('Y-m-d'); ?>" onchange="calcDays()">
                    </div>
                    <div class="fg full">
                        <label>Reason / Additional Details *</label>
                        <textarea name="reason" required placeholder="Please describe the reason for your leave request..."></textarea>
                    </div>
                </div>
                <div style="margin-top:18px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-paper-plane"></i> Submit Leave Request
                    </button>
                    <span style="font-size:11px;color:var(--muted);">You will be notified once HR reviews your request.</span>
                </div>
            </form>
        </div>
    </div>

    <!-- My Leave History -->
    <div class="card">
        <div class="card-hd">
            <div class="card-title">📋 My Leave Requests</div>
            <div style="font-size:11px;color:var(--muted);"><?php echo count($my_leaves); ?> total</div>
        </div>
        <?php if (empty($my_leaves)): ?>
        <div style="padding:48px;text-align:center;color:var(--muted);">
            <i class="fa-solid fa-calendar-xmark" style="font-size:36px;margin-bottom:14px;opacity:.3;display:block;"></i>
            <div style="font-size:14px;font-weight:700;margin-bottom:5px;">No leave requests yet</div>
            <div style="font-size:12px;">Submit your first leave application above.</div>
        </div>
        <?php else: ?>
        <div class="tbl-wrap">
            <table class="lt">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Days</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>HR Notes</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $type_icons = ['annual'=>'🌴','sick'=>'🏥','maternity'=>'👶','paternity'=>'👨‍👶','emergency'=>'🚨','unpaid'=>'💸','other'=>'📋'];
                foreach ($my_leaves as $l):
                    $icon = $type_icons[$l['leave_type']] ?? '📋';
                    $badge_class = 'b-'.($l['status']);
                ?>
                <tr>
                    <td>
                        <span class="lt-icon">
                            <span><?php echo $icon; ?></span>
                            <span style="font-weight:700;"><?php echo ucfirst(str_replace('_',' ',$l['leave_type'])); ?></span>
                        </span>
                    </td>
                    <td style="font-size:12px;white-space:nowrap;"><?php echo date('d M Y',strtotime($l['start_date'])); ?></td>
                    <td style="font-size:12px;white-space:nowrap;"><?php echo date('d M Y',strtotime($l['end_date'])); ?></td>
                    <td style="font-family:'JetBrains Mono',monospace;font-weight:700;text-align:center;"><?php echo $l['days_requested']; ?></td>
                    <td style="font-size:11px;color:var(--muted);max-width:160px;"><?php echo htmlspecialchars(substr($l['reason']??'—',0,60)); ?><?php echo strlen($l['reason']??'')>60?'…':''; ?></td>
                    <td>
                        <span class="badge <?php echo $badge_class; ?>">
                            <?php
                            $status_icons = ['pending'=>'⏳','approved'=>'✅','rejected'=>'❌','cancelled'=>'🚫'];
                            echo ($status_icons[$l['status']]??'').' '.ucfirst($l['status']);
                            ?>
                        </span>
                    </td>
                    <td style="font-size:11px;color:var(--muted);">
                        <?php if ($l['review_notes']): ?>
                        <span title="<?php echo htmlspecialchars($l['review_notes']); ?>"><?php echo htmlspecialchars(substr($l['review_notes'],0,40)); ?>…</span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                        <?php if ($l['status'] === 'pending'): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this leave request?')">
                            <input type="hidden" name="action" value="cancel_leave">
                            <input type="hidden" name="leave_id" value="<?php echo $l['id']; ?>">
                            <button type="submit" class="btn btn-danger btn-sm">
                                <i class="fa-solid fa-xmark"></i> Cancel
                            </button>
                        </form>
                        <?php elseif ($l['status'] === 'approved'): ?>
                        <span style="font-size:11px;color:#059669;font-weight:700;">✅ Approved<?php echo $l['reviewed_at'] ? '<br><span style="color:var(--muted);font-size:10px;">'.date('d M Y',strtotime($l['reviewed_at'])).'</span>' : ''; ?></span>
                        <?php else: ?>
                        <span style="font-size:11px;color:var(--muted);">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Leave Policy Reference -->
    <div class="card">
        <div class="card-hd"><div class="card-title">📜 Leave Policy Reference</div></div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">
                <?php
                $policies = [
                    ['🌴','Annual Leave','21 days per year. Requires 7 days advance notice. Carry-over up to 5 days.'],
                    ['🏥','Sick Leave','Up to 30 days per year. Medical certificate required for 3+ consecutive days.'],
                    ['👶','Maternity Leave','90 days fully paid. Must apply 4 weeks before due date.'],
                    ['👨‍👶','Paternity Leave','14 days within 1 month of child\'s birth.'],
                    ['🚨','Emergency Leave','Up to 5 days for family bereavement or critical emergencies.'],
                    ['💸','Unpaid Leave','Available after exhausting paid leave. HR approval required.'],
                ];
                foreach ($policies as [$ic,$name,$desc]):
                ?>
                <div style="background:#f8fafc;border:1px solid var(--border);border-radius:10px;padding:13px 15px;">
                    <div style="font-size:18px;margin-bottom:6px;"><?php echo $ic; ?></div>
                    <div style="font-size:12px;font-weight:800;color:var(--text);margin-bottom:4px;"><?php echo $name; ?></div>
                    <div style="font-size:11px;color:var(--muted);line-height:1.5;"><?php echo $desc; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
function calcDays() {
    const s = document.getElementById('start_date').value;
    const e = document.getElementById('end_date').value;
    const display = document.getElementById('days-display');
    const count   = document.getElementById('days-count');
    if (s && e && s <= e) {
        const diff = Math.round((new Date(e) - new Date(s)) / 86400000) + 1;
        count.textContent = diff;
        display.style.display = 'inline-flex';
        // Auto-set end_date min
        document.getElementById('end_date').min = s;
    } else {
        display.style.display = 'none';
    }
}
function validateForm() {
    const s = document.getElementById('start_date').value;
    const e = document.getElementById('end_date').value;
    if (!s || !e) { alert('Please select both start and end dates.'); return false; }
    if (s > e) { alert('End date must be on or after start date.'); return false; }
    return true;
}
document.getElementById('start_date').addEventListener('change', function(){
    document.getElementById('end_date').min = this.value;
    if (document.getElementById('end_date').value < this.value) {
        document.getElementById('end_date').value = this.value;
    }
    calcDays();
});
</script>
</body>
</html>
