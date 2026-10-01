<?php
/**
 * SmartLMS — Fix Role Accounts (Boarding Master + HR Manager)
 * Run this ONCE in your browser after any database re-import:
 *   http://localhost/smart%20lms/fix_roles.php
 * Then DELETE this file immediately after.
 */
include 'config.php';

$results = [];

// ── Step 1: Extend role ENUM (safe if already done) ──────────────────
$alter = mysqli_query($conn,
    "ALTER TABLE `users`
     MODIFY COLUMN `role`
       ENUM('student','lecturer','admin','financial_accountant','boarding_master','hr_manager')
       NOT NULL DEFAULT 'student'"
);
$results[] = ['Extend role ENUM', $alter ? '✅ Done' : '⚠ ' . mysqli_error($conn)];

// ── Step 2: Ensure admission_number column exists (used elsewhere) ───
@mysqli_query($conn, "ALTER TABLE users ADD COLUMN `admission_number` VARCHAR(30) DEFAULT NULL AFTER `email`");
@mysqli_query($conn, "ALTER TABLE users ADD UNIQUE KEY `uq_admission_number` (`admission_number`)");

// ── Step 3: Fresh PHP-generated bcrypt hash for "password123" ────────
$hash1 = password_hash('password123', PASSWORD_BCRYPT);
$hash2 = password_hash('password123', PASSWORD_BCRYPT);

// ── Step 4: Boarding Master account — delete any broken copy, recreate ──
mysqli_query($conn, "DELETE FROM `users` WHERE `email`='boarding@smartlms.com'");
$r1 = mysqli_query($conn,
    "INSERT INTO `users` (`full_name`, `email`, `password`, `role`)
     VALUES ('Boarding Master', 'boarding@smartlms.com', '" . mysqli_real_escape_string($conn,$hash1) . "', 'boarding_master')"
);
$results[] = ['Create boarding@smartlms.com', $r1 ? '✅ Created (pw: password123)' : '❌ ' . mysqli_error($conn)];

// ── Step 5: HR Manager account — delete any broken copy, recreate ────
mysqli_query($conn, "DELETE FROM `users` WHERE `email`='hr@smartlms.com'");
$r2 = mysqli_query($conn,
    "INSERT INTO `users` (`full_name`, `email`, `password`, `role`)
     VALUES ('HR Manager', 'hr@smartlms.com', '" . mysqli_real_escape_string($conn,$hash2) . "', 'hr_manager')"
);
$results[] = ['Create hr@smartlms.com', $r2 ? '✅ Created (pw: password123)' : '❌ ' . mysqli_error($conn)];

// ── Step 6: Verify both accounts actually pass password_verify() ─────
$verify_res = mysqli_query($conn,
    "SELECT id, full_name, email, role, LEFT(password,7) AS hash_prefix, password
     FROM users WHERE email IN ('boarding@smartlms.com','hr@smartlms.com')"
);
$verified = [];
while ($v = mysqli_fetch_assoc($verify_res)) {
    $pw_ok = password_verify('password123', $v['password']);
    $verified[] = [
        'id' => $v['id'], 'full_name' => $v['full_name'], 'email' => $v['email'],
        'role' => $v['role'], 'hash_prefix' => $v['hash_prefix'],
        'password_check' => $pw_ok ? '✅ password123 works' : '❌ mismatch'
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>SmartLMS Fix — Role Accounts</title>
<style>
  body{font-family:'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;padding:40px;max-width:780px;margin:0 auto;}
  h1{color:#f59e0b;font-size:24px;margin-bottom:6px;}
  p.sub{color:#64748b;font-size:13px;margin-bottom:28px;}
  .card{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:20px 24px;margin-bottom:16px;}
  .card h2{font-size:13px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.08em;margin-bottom:14px;}
  table{width:100%;border-collapse:collapse;}
  th,td{padding:10px 14px;text-align:left;font-size:13px;border-bottom:1px solid #334155;}
  th{font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.07em;background:#0f172a;}
  tr:last-child td{border-bottom:none;}
  .ok{color:#10b981;}.err{color:#ef4444;}.warn{color:#f59e0b;}
  .cred{background:#0f172a;border:1px solid #334155;border-radius:8px;padding:14px 18px;font-family:'Courier New',monospace;font-size:13px;line-height:1.8;}
  .cred span{color:#f59e0b;font-weight:700;}
  .warn-box{background:#451a03;border:1px solid #92400e;border-radius:8px;padding:12px 16px;font-size:13px;color:#fbbf24;margin-top:16px;}
</style>
</head>
<body>

<h1>🔧 SmartLMS Role Account Fix</h1>
<p class="sub">Re-creates Boarding Master and HR Manager accounts with a correct password hash — run this any time the database gets re-imported.</p>

<div class="card">
    <h2>Fix Steps</h2>
    <table>
        <thead><tr><th>Step</th><th>Result</th></tr></thead>
        <tbody>
        <?php foreach ($results as [$step, $result]): ?>
        <tr>
            <td><?php echo htmlspecialchars($step); ?></td>
            <td class="<?php echo strpos($result,'✅')!==false?'ok':(strpos($result,'❌')!==false?'err':'warn'); ?>">
                <?php echo htmlspecialchars($result); ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>Account Verification</h2>
    <?php if (empty($verified)): ?>
    <p class="err">❌ No accounts found — something went wrong. Check the MySQL error above.</p>
    <?php else: ?>
    <table>
        <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Hash Prefix</th><th>Password Test</th></tr></thead>
        <tbody>
        <?php foreach ($verified as $v): ?>
        <tr>
            <td><?php echo $v['id']; ?></td>
            <td><?php echo htmlspecialchars($v['full_name']); ?></td>
            <td><?php echo htmlspecialchars($v['email']); ?></td>
            <td style="color:#a78bfa;font-weight:700;"><?php echo htmlspecialchars($v['role']); ?></td>
            <td style="font-family:monospace;font-size:11px;color:#64748b;"><?php echo htmlspecialchars($v['hash_prefix']); ?>…</td>
            <td class="<?php echo strpos($v['password_check'],'✅')!==false?'ok':'err'; ?>"><?php echo $v['password_check']; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Login Credentials</h2>
    <div class="cred">
        <span>Boarding Master</span><br>
        Email: boarding@smartlms.com<br>
        Password: password123<br>
        Role: Boarding Master<br><br>
        <span>HR Manager</span><br>
        Email: hr@smartlms.com<br>
        Password: password123<br>
        Role: HR Manager
    </div>
    <div class="warn-box">
        ⚠ <strong>Security:</strong> Delete this file (<code>fix_roles.php</code>) from your server right after confirming login works.
        <br>Then change these passwords from inside each dashboard.
    </div>
</div>

</body>
</html>
