<?php
/**
 * SmartLMS — Fix New Role Accounts
 * Run this ONCE in your browser: http://yoursite/fix_roles.php
 * Then DELETE this file immediately after.
 */
include 'config.php';

$results = [];

// ── Step 1: Extend role ENUM ─────────────────────────────────────────
$alter = mysqli_query($conn,
    "ALTER TABLE `users`
     MODIFY COLUMN `role`
       ENUM('student','lecturer','admin','financial_accountant','boarding_master','hr_manager')
       NOT NULL DEFAULT 'student'"
);
$results[] = ['Extend role ENUM', $alter ? '✅ Done' : '⚠ ' . mysqli_error($conn)];

// ── Step 2: Hash for "password123" (PHP bcrypt) ──────────────────────
$hash = password_hash('password123', PASSWORD_BCRYPT);

// ── Step 3: Boarding Master account ─────────────────────────────────
// Delete old broken record first (wrong hash), then re-insert cleanly
mysqli_query($conn, "DELETE FROM `users` WHERE `email`='boarding@smartlms.com'");
$r1 = mysqli_query($conn,
    "INSERT INTO `users` (`full_name`, `email`, `password`, `role`)
     VALUES ('Boarding Master', 'boarding@smartlms.com', '" . mysqli_real_escape_string($conn,$hash) . "', 'boarding_master')"
);
$results[] = ['Create boarding@smartlms.com', $r1 ? '✅ Created (pw: password123)' : '❌ ' . mysqli_error($conn)];

// ── Step 4: HR Manager account ───────────────────────────────────────
$hash2 = password_hash('password123', PASSWORD_BCRYPT);
mysqli_query($conn, "DELETE FROM `users` WHERE `email`='hr@smartlms.com'");
$r2 = mysqli_query($conn,
    "INSERT INTO `users` (`full_name`, `email`, `password`, `role`)
     VALUES ('HR Manager', 'hr@smartlms.com', '" . mysqli_real_escape_string($conn,$hash2) . "', 'hr_manager')"
);
$results[] = ['Create hr@smartlms.com', $r2 ? '✅ Created (pw: password123)' : '❌ ' . mysqli_error($conn)];

// ── Step 5: Verify the accounts were created correctly ───────────────
$verify_res = mysqli_query($conn,
    "SELECT id, full_name, email, role, LEFT(password,7) AS hash_prefix
     FROM users WHERE email IN ('boarding@smartlms.com','hr@smartlms.com')"
);
$verified = [];
while ($v = mysqli_fetch_assoc($verify_res)) {
    $pw_ok = password_verify('password123', 
        mysqli_fetch_assoc(mysqli_query($conn, "SELECT password FROM users WHERE id={$v['id']}"))['password']
    );
    $verified[] = $v + ['password_check' => $pw_ok ? '✅ password123 works' : '❌ mismatch'];
}

// ── Step 6: Also fix process_login.php to allow new roles ────────────
$login_file = __DIR__ . '/process_login.php';
$login_src  = file_get_contents($login_file);
$role_check_ok = strpos($login_src, 'boarding_master') !== false;
$results[] = ['process_login.php has new roles', $role_check_ok ? '✅ Already patched' : '⚠ Needs update — upload the new process_login.php'];

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
<p class="sub">Setting up Boarding Master and HR Manager accounts with correct password hashing</p>

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
    <p class="err">❌ No accounts found — something went wrong. Check MySQL error above.</p>
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
        ⚠ <strong>Security:</strong> Delete this file immediately after use — <code>fix_roles.php</code>
        <br>Then change these passwords from inside each dashboard.
    </div>
</div>

<?php
// ── Extra: also allow creating custom accounts ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['new_email'])) {
    $ne   = mysqli_real_escape_string($conn, trim($_POST['new_email']));
    $nn   = mysqli_real_escape_string($conn, trim($_POST['new_name']));
    $nr   = mysqli_real_escape_string($conn, $_POST['new_role']);
    $npw  = trim($_POST['new_password']);
    $nph  = mysqli_real_escape_string($conn, password_hash($npw, PASSWORD_BCRYPT));
    mysqli_query($conn, "DELETE FROM users WHERE email='$ne'");
    $nr2 = mysqli_query($conn, "INSERT INTO users (full_name,email,password,role) VALUES ('$nn','$ne','$nph','$nr')");
    echo '<div class="card" style="border-color:'.($nr2?'#10b981':'#ef4444').'"><h2>'.($nr2?'✅ Account Created':'❌ Failed').'</h2>';
    echo $nr2 ? "<p>$nn &lt;$ne&gt; created with role <strong>$nr</strong> and your chosen password.</p>"
              : '<p>Error: '.htmlspecialchars(mysqli_error($conn)).'</p>';
    echo '</div>';
}
?>

<div class="card" style="border-color:#334155;">
    <h2>➕ Create Additional Account (Optional)</h2>
    <form method="POST" style="display:grid;gap:10px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div>
                <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:5px;">Full Name</label>
                <input type="text" name="new_name" placeholder="Jane Wambua" style="width:100%;background:#0f172a;border:1px solid #334155;border-radius:7px;padding:9px 12px;color:#e2e8f0;font-size:13px;">
            </div>
            <div>
                <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:5px;">Email</label>
                <input type="email" name="new_email" placeholder="jane@school.ac.ke" style="width:100%;background:#0f172a;border:1px solid #334155;border-radius:7px;padding:9px 12px;color:#e2e8f0;font-size:13px;">
            </div>
            <div>
                <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:5px;">Role</label>
                <select name="new_role" style="width:100%;background:#0f172a;border:1px solid #334155;border-radius:7px;padding:9px 12px;color:#e2e8f0;font-size:13px;">
                    <option value="boarding_master">Boarding Master</option>
                    <option value="hr_manager">HR Manager</option>
                    <option value="financial_accountant">Financial Accountant</option>
                    <option value="admin">Admin</option>
                    <option value="lecturer">Lecturer</option>
                    <option value="student">Student</option>
                </select>
            </div>
            <div>
                <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:5px;">Password</label>
                <input type="password" name="new_password" placeholder="Min 8 characters" style="width:100%;background:#0f172a;border:1px solid #334155;border-radius:7px;padding:9px 12px;color:#e2e8f0;font-size:13px;">
            </div>
        </div>
        <button type="submit" style="background:#f59e0b;color:#0f172a;border:none;border-radius:8px;padding:10px 24px;font-size:13px;font-weight:800;cursor:pointer;justify-self:start;">
            Create Account
        </button>
    </form>
</div>

</body>
</html>
