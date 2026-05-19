<?php
// ═══════════════════════════════════════════════════
// SMART LMS — FINANCE LOGIN FIX
// Upload to project root → visit in browser → done
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

if ($conn->connect_error) {
    die("DB connection failed: " . $conn->connect_error);
}

// 1. Fix ENUM
step($conn, $log, $ok, 'ENUM updated',
    "ALTER TABLE users MODIFY COLUMN role ENUM('student','lecturer','admin','financial_accountant') NOT NULL DEFAULT 'student'");

// 2. Delete any broken finance account
step($conn, $log, $ok, 'Old finance account removed',
    "DELETE FROM users WHERE email='finance@smartlms.com'");

// 3. Generate fresh hash ON THIS SERVER and insert
$hash = password_hash('finance2025', PASSWORD_BCRYPT);
$hash = mysqli_real_escape_string($conn, $hash);
step($conn, $log, $ok, 'Finance account created fresh',
    "INSERT INTO users (full_name, email, password, role) VALUES ('Finance Office','finance@smartlms.com','$hash','financial_accountant')");

// 4. Verify immediately
$row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT * FROM users WHERE email='finance@smartlms.com' AND role='financial_accountant'"));

if ($row && password_verify('finance2025', $row['password'])) {
    $log[] = ['ok', 'Password verified — login WILL work ✓'];
} else {
    $log[] = ['fail', 'Verification failed — check DB manually'];
    $ok = false;
}

// 5. Create finance tables
foreach ([
    "CREATE TABLE IF NOT EXISTS fee_structures (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, description TEXT, amount DECIMAL(12,2) NOT NULL DEFAULT 0, fee_category ENUM('tuition','examination','library','accommodation','transport','medical','activity','other') DEFAULT 'tuition', academic_year VARCHAR(20) NOT NULL, semester ENUM('Semester 1','Semester 2','Full Year','One Time') DEFAULT 'Semester 1', course_id INT DEFAULT NULL, is_mandatory TINYINT(1) DEFAULT 1, created_by INT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS student_fee_assignments (id INT AUTO_INCREMENT PRIMARY KEY, student_id INT NOT NULL, fee_structure_id INT NOT NULL, total_amount DECIMAL(12,2) NOT NULL, discount_amount DECIMAL(12,2) DEFAULT 0, discount_reason VARCHAR(255), net_amount DECIMAL(12,2) NOT NULL, academic_year VARCHAR(20) NOT NULL, semester VARCHAR(50) NOT NULL, due_date DATE, status ENUM('pending','partial','paid','overdue','waived') DEFAULT 'pending', assigned_by INT, assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_stu_fee (student_id, fee_structure_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS fee_payments (id INT AUTO_INCREMENT PRIMARY KEY, student_id INT NOT NULL, fee_assignment_id INT DEFAULT NULL, amount_paid DECIMAL(12,2) NOT NULL, payment_method ENUM('cash','bank_transfer','mpesa','cheque','online','scholarship') DEFAULT 'cash', transaction_ref VARCHAR(100), receipt_number VARCHAR(50), notes TEXT, payment_date DATE NOT NULL, recorded_by INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS fee_reminders (id INT AUTO_INCREMENT PRIMARY KEY, student_id INT NOT NULL, message TEXT NOT NULL, sent_by INT NOT NULL, is_read TINYINT(1) DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
] as $sql) {
    mysqli_query($conn, $sql);
}
$log[] = ['ok', 'Finance tables ready'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Finance Fix</title>
<style>
*{font-family:'Segoe UI',sans-serif;box-sizing:border-box;margin:0;padding:0}
body{background:#0f172a;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.box{background:#fff;border-radius:20px;padding:36px;width:100%;max-width:500px}
h2{font-size:21px;font-weight:900;color:#0f172a;margin-bottom:4px}
.sub{font-size:12px;color:#94a3b8;margin-bottom:22px}
.row{display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:10px;margin-bottom:7px}
.ok{background:#f0fdf4;border:1px solid #86efac}
.fail{background:#fef2f2;border:1px solid #fca5a5}
.ico{width:26px;height:26px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:12px;flex-shrink:0}
.ok .ico{background:#10b981;color:#fff}
.fail .ico{background:#ef4444;color:#fff}
.txt{font-size:13px;font-weight:600;color:#0f172a}
.creds{background:#0f172a;border-radius:14px;padding:20px;margin:20px 0}
.creds p{color:#64748b;font-size:11px;margin-bottom:12px;text-transform:uppercase;letter-spacing:.05em;font-weight:700}
.cr{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.cl{color:#64748b;font-size:12px}
.cv{background:#1e293b;color:#10b981;font-family:monospace;font-size:13px;font-weight:700;padding:5px 12px;border-radius:7px}
.btn{display:block;text-align:center;background:#10b981;color:#fff;padding:14px;border-radius:12px;font-weight:800;font-size:14px;margin-top:4px;text-decoration:none}
.btn:hover{background:#059669}
.del{font-size:11px;color:#94a3b8;text-align:center;margin-top:14px}
</style>
</head>
<body>
<div class="box">
    <h2><?= $ok ? '✅ All Fixed!' : '⚠️ Some Issues Found' ?></h2>
    <p class="sub">Smart LMS Finance Module — one-time setup</p>

    <?php foreach ($log as [$type, $msg]): ?>
    <div class="row <?= $type ?>">
        <div class="ico"><?= $type==='ok'?'✓':'✗' ?></div>
        <div class="txt"><?= htmlspecialchars($msg) ?></div>
    </div>
    <?php endforeach; ?>

    <?php if ($ok): ?>
    <div class="creds">
        <p>Your login credentials</p>
        <div class="cr"><span class="cl">Email</span><span class="cv">finance@smartlms.com</span></div>
        <div class="cr"><span class="cl">Password</span><span class="cv">finance2025</span></div>
        <div class="cr"><span class="cl">Role</span><span class="cv">Financial Accountant</span></div>
    </div>
    <a href="index.php" class="btn">→ Go to Login Now</a>
    <?php else: ?>
    <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:12px;padding:16px;margin-top:16px;font-size:13px;color:#991b1b;">
        Something is still wrong. Screenshot this page and share it.
    </div>
    <?php endif; ?>

    <p class="del">⚠️ Delete <strong>FIX_NOW.php</strong> from your server after login works</p>
</div>
</body>
</html>