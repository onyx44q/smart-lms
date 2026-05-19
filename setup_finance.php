<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Finance Setup Fix | Smart LMS</title>
<style>
* { font-family: 'Segoe UI', sans-serif; box-sizing: border-box; }
body { background: #0f172a; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; }
.card { background: #fff; border-radius: 20px; padding: 40px; width: 100%; max-width: 600px; }
h1 { font-size: 22px; font-weight: 800; color: #0f172a; margin: 0 0 6px; }
.sub { color: #64748b; font-size: 13px; margin-bottom: 28px; }
.step { display: flex; gap: 14px; align-items: flex-start; padding: 14px; border-radius: 12px; margin-bottom: 10px; }
.step.ok   { background: #f0fdf4; border: 1px solid #bbf7d0; }
.step.fail { background: #fef2f2; border: 1px solid #fecaca; }
.step.warn { background: #fffbeb; border: 1px solid #fde68a; }
.icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; }
.ok   .icon { background: #10b981; color: #fff; }
.fail .icon { background: #ef4444; color: #fff; }
.warn .icon { background: #f59e0b; color: #fff; }
.step-title { font-weight: 700; font-size: 13px; color: #0f172a; }
.step-msg   { font-size: 12px; color: #64748b; margin-top: 2px; font-family: monospace; }
.creds { background: #0f172a; border-radius: 12px; padding: 20px; margin-top: 20px; }
.creds p { color: #94a3b8; font-size: 12px; margin: 0 0 10px; }
.cred-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
.cred-label { color: #64748b; font-size: 12px; }
.cred-val { background: #1e293b; color: #10b981; font-family: monospace; font-size: 13px; font-weight: 700; padding: 4px 12px; border-radius: 6px; }
.login-btn { display: block; text-align: center; background: #10b981; color: #fff; text-decoration: none; padding: 14px; border-radius: 12px; font-weight: 700; font-size: 14px; margin-top: 20px; }
.login-btn:hover { background: #059669; }
</style>
</head>
<body>
<div class="card">
    <h1>💰 Finance Module Setup</h1>
    <p class="sub">Run this once to fix the Financial Accountant login. Visit this page in your browser, then delete it.</p>

<?php
$conn = new mysqli("localhost", "root", "", "smart_lms");
$steps = [];

if ($conn->connect_error) {
    $steps[] = ['fail', 'Database Connection', $conn->connect_error];
} else {
    $steps[] = ['ok', 'Database Connection', 'Connected to smart_lms successfully'];

    // STEP 1 — Force full ENUM update (drop + redefine approach using MODIFY)
    // First check current ENUM
    $col = mysqli_fetch_assoc(mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'role'"));
    $current_enum = $col['Type'] ?? '';

    if (strpos($current_enum, 'financial_accountant') !== false) {
        $steps[] = ['ok', 'Role ENUM', "Already contains 'financial_accountant': $current_enum"];
    } else {
        // Detect all current enum values and add financial_accountant
        $alter = "ALTER TABLE users MODIFY COLUMN role ENUM('student','lecturer','admin','financial_accountant') NOT NULL DEFAULT 'student'";
        if (mysqli_query($conn, $alter)) {
            $new_col = mysqli_fetch_assoc(mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'role'"));
            $steps[] = ['ok', 'Role ENUM Updated', "New type: " . $new_col['Type']];
        } else {
            $steps[] = ['fail', 'Role ENUM Update Failed', mysqli_error($conn)];
        }
    }

    // STEP 2 — Create finance tables
    $tables = [
        'fee_structures' => "CREATE TABLE IF NOT EXISTS `fee_structures` (
            `id` INT(11) NOT NULL AUTO_INCREMENT, `name` VARCHAR(255) NOT NULL,
            `description` TEXT DEFAULT NULL, `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `fee_category` ENUM('tuition','examination','library','accommodation','transport','medical','activity','other') NOT NULL DEFAULT 'tuition',
            `academic_year` VARCHAR(20) NOT NULL, `semester` ENUM('Semester 1','Semester 2','Full Year','One Time') NOT NULL DEFAULT 'Semester 1',
            `course_id` INT(11) DEFAULT NULL, `is_mandatory` TINYINT(1) NOT NULL DEFAULT 1,
            `created_by` INT(11) DEFAULT NULL, `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'student_fee_assignments' => "CREATE TABLE IF NOT EXISTS `student_fee_assignments` (
            `id` INT(11) NOT NULL AUTO_INCREMENT, `student_id` INT(11) NOT NULL,
            `fee_structure_id` INT(11) NOT NULL, `total_amount` DECIMAL(12,2) NOT NULL,
            `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00, `discount_reason` VARCHAR(255) DEFAULT NULL,
            `net_amount` DECIMAL(12,2) NOT NULL, `academic_year` VARCHAR(20) NOT NULL, `semester` VARCHAR(50) NOT NULL,
            `due_date` DATE DEFAULT NULL, `status` ENUM('pending','partial','paid','overdue','waived') NOT NULL DEFAULT 'pending',
            `assigned_by` INT(11) DEFAULT NULL, `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_stu_fee` (`student_id`, `fee_structure_id`), PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'fee_payments' => "CREATE TABLE IF NOT EXISTS `fee_payments` (
            `id` INT(11) NOT NULL AUTO_INCREMENT, `student_id` INT(11) NOT NULL,
            `fee_assignment_id` INT(11) DEFAULT NULL, `amount_paid` DECIMAL(12,2) NOT NULL,
            `payment_method` ENUM('cash','bank_transfer','mpesa','cheque','online','scholarship') NOT NULL DEFAULT 'cash',
            `transaction_ref` VARCHAR(100) DEFAULT NULL, `receipt_number` VARCHAR(50) DEFAULT NULL,
            `notes` TEXT DEFAULT NULL, `payment_date` DATE NOT NULL, `recorded_by` INT(11) NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'fee_reminders' => "CREATE TABLE IF NOT EXISTS `fee_reminders` (
            `id` INT(11) NOT NULL AUTO_INCREMENT, `student_id` INT(11) NOT NULL,
            `message` TEXT NOT NULL, `sent_by` INT(11) NOT NULL,
            `is_read` TINYINT(1) NOT NULL DEFAULT 0, `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($tables as $tname => $tsql) {
        if (mysqli_query($conn, $tsql)) {
            $steps[] = ['ok', "Table `$tname`", 'Created or already exists'];
        } else {
            $steps[] = ['fail', "Table `$tname`", mysqli_error($conn)];
        }
    }

    // STEP 3 — Remove any broken finance account (wrong role or failed insert)
    mysqli_query($conn, "DELETE FROM users WHERE email='finance@smartlms.com'");
    $steps[] = ['warn', 'Cleared old finance account', 'Removed any previous broken record for finance@smartlms.com'];

    // STEP 4 — Re-insert with fresh bcrypt hash
    $newHash = password_hash("finance2025", PASSWORD_BCRYPT);
    $ins = mysqli_query($conn, "INSERT INTO users (full_name, email, password, role)
                                VALUES ('Finance Office', 'finance@smartlms.com', '$newHash', 'financial_accountant')");
    if ($ins) {
        $newId = mysqli_insert_id($conn);
        $steps[] = ['ok', 'Finance Account Created', "ID: $newId | Email: finance@smartlms.com | Password: finance2025"];
    } else {
        $steps[] = ['fail', 'Finance Account Insert Failed', mysqli_error($conn)];
    }

    // STEP 5 — Verify login will work
    $verify = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, full_name, role, password FROM users WHERE email='finance@smartlms.com' AND role='financial_accountant'"));
    if ($verify && password_verify("finance2025", $verify['password'])) {
        $steps[] = ['ok', 'Login Verification', "Password hash verified ✓ — login will work"];
    } else {
        $steps[] = ['fail', 'Login Verification', 'Hash verification failed — contact support'];
    }
}

$icons = ['ok' => '✓', 'fail' => '✗', 'warn' => '!'];
foreach ($steps as [$type, $title, $msg]):
?>
    <div class="step <?= $type ?>">
        <div class="icon"><?= $icons[$type] ?></div>
        <div>
            <div class="step-title"><?= htmlspecialchars($title) ?></div>
            <div class="step-msg"><?= htmlspecialchars($msg) ?></div>
        </div>
    </div>
<?php endforeach; ?>

    <div class="creds">
        <p>✅ Your Financial Accountant login credentials:</p>
        <div class="cred-row"><span class="cred-label">Email</span><span class="cred-val">finance@smartlms.com</span></div>
        <div class="cred-row"><span class="cred-label">Password</span><span class="cred-val">finance2025</span></div>
        <div class="cred-row"><span class="cred-label">Role</span><span class="cred-val">financial_accountant</span></div>
    </div>

    <a href="index.php" class="login-btn">→ Go to Login Page</a>

    <p style="font-size:11px;color:#94a3b8;text-align:center;margin-top:16px;">
        ⚠️ Delete this file after setup: <code>setup_finance.php</code>
    </p>
</div>
</body>
</html>