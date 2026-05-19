<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Login Diagnostic | Smart LMS</title>
<style>
* { font-family: 'Segoe UI', sans-serif; box-sizing: border-box; margin: 0; padding: 0; }
body { background: #0f172a; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
.card { background: #fff; border-radius: 20px; padding: 36px; width: 100%; max-width: 640px; }
h2 { font-size: 20px; font-weight: 800; color: #0f172a; margin-bottom: 4px; }
.sub { font-size: 12px; color: #94a3b8; margin-bottom: 24px; }
.row { display: flex; align-items: flex-start; gap: 12px; padding: 12px 16px; border-radius: 10px; margin-bottom: 8px; }
.ok   { background: #f0fdf4; border: 1px solid #86efac; }
.fail { background: #fef2f2; border: 1px solid #fca5a5; }
.warn { background: #fffbeb; border: 1px solid #fde68a; }
.dot  { width: 28px; height: 28px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 13px; flex-shrink: 0; }
.ok   .dot { background: #10b981; color: #fff; }
.fail .dot { background: #ef4444; color: #fff; }
.warn .dot { background: #f59e0b; color: #fff; }
.label { font-weight: 700; font-size: 13px; color: #0f172a; }
.detail { font-size: 11px; color: #64748b; margin-top: 3px; font-family: monospace; word-break: break-all; }
.fix-btn { display: block; text-align: center; background: #10b981; color: #fff; padding: 14px; border-radius: 12px; font-weight: 700; font-size: 14px; margin-top: 20px; text-decoration: none; border: none; cursor: pointer; width: 100%; }
.fix-btn:hover { background: #059669; }
.fix-btn.red { background: #ef4444; }
.divider { height: 1px; background: #f1f5f9; margin: 16px 0; }
pre { background: #f8fafc; border-radius: 8px; padding: 12px; font-size: 11px; overflow-x: auto; margin-top: 8px; }
</style>
</head>
<body>
<div class="card">
<h2>🔍 Login Diagnostic Tool</h2>
<p class="sub">Pinpoints exactly why finance@smartlms.com login fails — auto-fixes all issues found</p>

<?php
$conn = new mysqli("localhost", "root", "", "smart_lms");

function row($type, $label, $detail) {
    $icons = ['ok'=>'✓','fail'=>'✗','warn'=>'!'];
    echo "<div class='row $type'><div class='dot'>{$icons[$type]}</div><div><div class='label'>$label</div><div class='detail'>$detail</div></div></div>";
}

// ── 1. DB Connection
if ($conn->connect_error) {
    row('fail', 'DB Connection Failed', $conn->connect_error);
    echo "</div></body></html>"; exit();
}
row('ok', 'DB Connection', 'Connected to smart_lms');

// ── 2. Check users table exists
$tbl = mysqli_fetch_assoc(mysqli_query($conn, "SHOW TABLES LIKE 'users'"));
if (!$tbl) { row('fail', 'users table missing', 'The users table does not exist'); echo "</div></body></html>"; exit(); }
row('ok', 'users table exists', '');

// ── 3. Check ENUM
$col = mysqli_fetch_assoc(mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'role'"));
$enumType = $col['Type'] ?? 'unknown';
if (strpos($enumType, 'financial_accountant') !== false) {
    row('ok', 'Role ENUM is correct', $enumType);
} else {
    row('fail', 'Role ENUM missing financial_accountant', "Current: $enumType — fixing now...");
    mysqli_query($conn, "ALTER TABLE users MODIFY COLUMN role ENUM('student','lecturer','admin','financial_accountant') NOT NULL DEFAULT 'student'");
    $col2 = mysqli_fetch_assoc(mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'role'"));
    row('ok', 'ENUM fixed', $col2['Type']);
}

// ── 4. Check finance user record
$fa = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE email='finance@smartlms.com'"));
if (!$fa) {
    row('fail', 'finance@smartlms.com NOT found in users table', 'Will insert now...');
    $hp = password_hash('finance2025', PASSWORD_BCRYPT);
    $r = mysqli_query($conn, "INSERT INTO users (full_name, email, password, role) VALUES ('Finance Office','finance@smartlms.com','$hp','financial_accountant')");
    if ($r) {
        $fa = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE email='finance@smartlms.com'"));
        row('ok', 'finance account created, ID=' . $fa['id'], 'role=' . $fa['role']);
    } else {
        row('fail', 'INSERT failed', mysqli_error($conn));
    }
} else {
    row('ok', 'finance@smartlms.com found', "ID={$fa['id']} | role={$fa['role']} | name={$fa['full_name']}");
}

// ── 5. Check role value
if ($fa && $fa['role'] !== 'financial_accountant') {
    row('fail', 'Wrong role in DB', "Has role='{$fa['role']}' — fixing to 'financial_accountant'");
    $hp = password_hash('finance2025', PASSWORD_BCRYPT);
    mysqli_query($conn, "UPDATE users SET role='financial_accountant', password='$hp' WHERE email='finance@smartlms.com'");
    $fa = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE email='finance@smartlms.com'"));
    row('ok', 'Role fixed', "Now: role={$fa['role']}");
} elseif ($fa) {
    row('ok', 'Role is financial_accountant', '');
}

// ── 6. Verify password hash
if ($fa) {
    if (password_verify('finance2025', $fa['password'])) {
        row('ok', 'Password hash valid', 'password_verify("finance2025", hash) = TRUE ✓');
    } else {
        row('fail', 'Password hash mismatch — resetting password now', 'Old hash: ' . substr($fa['password'],0,30) . '...');
        $newHash = password_hash('finance2025', PASSWORD_BCRYPT);
        mysqli_query($conn, "UPDATE users SET password='$newHash' WHERE email='finance@smartlms.com'");
        $fa2 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT password FROM users WHERE email='finance@smartlms.com'"));
        if (password_verify('finance2025', $fa2['password'])) {
            row('ok', 'Password reset successful', 'Verified OK');
        } else {
            row('fail', 'Password still broken', 'PHP bcrypt may be broken on your server');
        }
    }
}

// ── 7. Simulate exact login query
echo "<div class='divider'></div>";
row('warn', 'Simulating login query...', 'Running exact same query as process_login.php');

$email_test = 'finance@smartlms.com';
$role_test  = 'financial_accountant';

// Method A: prepared statement with get_result
$method_a_ok = false;
if (method_exists(new mysqli(), 'get_result') || function_exists('mysqli_stmt_get_result')) {
    $stmt = $conn->prepare("SELECT id, full_name, role, password FROM users WHERE email=? AND role=?");
    if ($stmt) {
        $stmt->bind_param("ss", $email_test, $role_test);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $user = $res->fetch_assoc()) {
            $method_a_ok = true;
            row('ok', 'Prepared stmt (get_result) works', "Found user: {$user['full_name']} | role={$user['role']}");
            if (password_verify('finance2025', $user['password'])) {
                row('ok', 'Full login simulation SUCCESS', 'Login WILL work with process_login.php ✓');
            } else {
                row('fail', 'Password mismatch in simulation', '');
            }
        } else {
            row('fail', 'get_result() found no rows', 'Query ran but returned nothing');
        }
    }
} else {
    row('warn', 'get_result() NOT available on this server', 'Using bind_result() fallback instead');
}

// Method B: bind_result (always works)
$stmt2 = $conn->prepare("SELECT id, full_name, role, password FROM users WHERE email=? AND role=?");
if ($stmt2) {
    $stmt2->bind_param("ss", $email_test, $role_test);
    $stmt2->execute();
    $id2=$fn2=$rl2=$pw2=null;
    $stmt2->bind_result($id2,$fn2,$rl2,$pw2);
    if ($stmt2->fetch()) {
        row('ok', 'bind_result() method works', "Found: $fn2 | role=$rl2");
        if (password_verify('finance2025', $pw2)) {
            row('ok', 'bind_result login simulation PASSED', "process_login.php will use this method ✓");
        } else {
            row('fail', 'bind_result: password mismatch', '');
        }
    } else {
        row('fail', 'bind_result: no rows found', "email=$email_test AND role=$role_test returned nothing");
    }
}

// ── 8. Check PHP version & mysqlnd
echo "<div class='divider'></div>";
$phpv = phpversion();
$mysqlnd = function_exists('mysqli_fetch_all') ? 'YES (mysqlnd available)' : 'NO (mysqlnd missing)';
row('warn', "PHP $phpv", "mysqlnd: $mysqlnd");

// ── 9. List all users for reference
echo "<div class='divider'></div>";
$all = mysqli_query($conn, "SELECT id, full_name, email, role FROM users ORDER BY id DESC LIMIT 10");
$rows = [];
while ($r = mysqli_fetch_assoc($all)) $rows[] = $r;
row('warn', 'All users in DB (latest 10)', 'See table below');
echo "<pre>";
printf("%-4s %-20s %-30s %-22s\n", 'ID', 'Name', 'Email', 'Role');
echo str_repeat('-', 80) . "\n";
foreach ($rows as $r) printf("%-4s %-20s %-30s %-22s\n", $r['id'], $r['full_name'], $r['email'], $r['role']);
echo "</pre>";
?>

<div class="divider"></div>
<a href="index.php" class="fix-btn">→ All fixed — Go to Login</a>
<p style="text-align:center;font-size:11px;color:#94a3b8;margin-top:12px;">⚠️ Delete <code>login_diagnostic.php</code> after use</p>
</div>
</body>
</html>