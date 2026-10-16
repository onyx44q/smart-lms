<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include 'config.php';
include_once 'login_settings.php';

$msg = ''; $msg_type = ''; $reset_link = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = mysqli_real_escape_string($conn, trim($_POST['identifier'] ?? ''));
    $role       = mysqli_real_escape_string($conn, trim($_POST['role'] ?? ''));
    if (!LOGIN_SHOW_ROLE_SELECTOR) $role = '';   // role comes from the account, not the browser
    $roleSql    = ($role !== '') ? "AND role='$role'" : '';

    if ($identifier === '' || (LOGIN_SHOW_ROLE_SELECTOR && $role === '')) {
        $msg = "Please fill in all fields."; $msg_type = 'error';
    } else {
        // Match by email OR admission number, same pattern as login
        $user = null;
        $res = mysqli_query($conn, "SELECT id, full_name, email FROM users WHERE email='$identifier' $roleSql LIMIT 1");
        if ($res && mysqli_num_rows($res) === 1) $user = mysqli_fetch_assoc($res);
        if (!$user) {
            $res2 = mysqli_query($conn, "SELECT id, full_name, email FROM users WHERE admission_number='$identifier' $roleSql LIMIT 1");
            if ($res2 && mysqli_num_rows($res2) === 1) $user = mysqli_fetch_assoc($res2);
        }

        // Always show the same generic success message whether or not the account
        // exists, so this form can't be used to probe which emails are registered.
        $msg = "If an account matches those details, a password reset link has been generated below.";
        $msg_type = 'success';

        if ($user) {
            $token   = bin2hex(random_bytes(32));
            $uid     = intval($user['id']);
            mysqli_query($conn, "INSERT INTO password_resets (user_id, token, expires_at) VALUES ($uid, '$token', DATE_ADD(NOW(), INTERVAL 1 HOUR))");

            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $path   = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
            $reset_link = "$scheme://$host$path/reset_password.php?token=$token";

            // Best-effort email attempt — silently ignored if no mail server is configured
            $subject = "SmartLMS Password Reset";
            $body    = "Hello {$user['full_name']},\r\n\r\nClick the link below to reset your SmartLMS password. This link expires in 1 hour.\r\n\r\n$reset_link\r\n\r\nIf you did not request this, you can safely ignore this email.";
            @mail($user['email'], $subject, $body, "From: no-reply@smartlms.com");
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password — SmartLMS</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
body { background: linear-gradient(135deg,#0f172a,#1e1b4b); min-height: 100vh; }
.card { background: rgba(255,255,255,.97); backdrop-filter: blur(20px); box-shadow: 0 32px 80px rgba(0,0,0,.4); }
</style>
</head>
<body class="flex items-center justify-center p-6 font-sans">

<div class="card w-full max-w-sm p-8 rounded-3xl">
    <div class="text-center mb-6">
        <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center mx-auto mb-4 shadow-lg shadow-orange-500/30">
            <i class="fa-solid fa-key text-white text-xl"></i>
        </div>
        <h1 class="text-2xl font-black text-slate-900 uppercase tracking-tight">Forgot Password</h1>
        <p class="text-slate-400 text-xs mt-1">Enter your details to reset your password</p>
    </div>

    <?php if ($msg): ?>
    <div class="mb-5 px-4 py-3 rounded-xl text-xs font-bold border flex items-start gap-2
        <?php echo $msg_type === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-red-50 border-red-200 text-red-600'; ?>">
        <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?> mt-0.5"></i>
        <span><?php echo htmlspecialchars($msg); ?></span>
    </div>
    <?php endif; ?>

    <?php if ($reset_link): ?>
    <div class="mb-5 p-4 bg-blue-50 border border-blue-200 rounded-xl">
        <p class="text-[10px] font-black uppercase text-blue-500 tracking-widest mb-2">
            <i class="fa-solid fa-circle-info mr-1"></i>Your Reset Link
        </p>
        <p class="text-[11px] text-slate-500 mb-2">If your mail server isn't configured, use this link directly (valid for 1 hour):</p>
        <a href="<?php echo htmlspecialchars($reset_link); ?>" class="text-xs font-bold text-blue-700 break-all underline hover:text-blue-900">
            <?php echo htmlspecialchars($reset_link); ?>
        </a>
    </div>
    <?php endif; ?>

    <?php if (!$reset_link): ?>
    <form method="POST" class="space-y-4">
        <?php if (LOGIN_SHOW_ROLE_SELECTOR): ?>
        <div>
            <label class="text-[10px] font-black uppercase text-slate-500 tracking-widest mb-1 block">Role</label>
            <select name="role" required class="w-full p-3 bg-slate-100 rounded-xl outline-none text-sm font-bold text-slate-700 appearance-none focus:ring-2 focus:ring-amber-500">
                <option value="student">Student</option>
                <option value="lecturer">Lecturer</option>
                <option value="admin">Admin</option>
                <option value="financial_accountant">Financial Accountant</option>
                <option value="boarding_master">Boarding Master</option>
                <option value="hr_manager">HR Manager</option>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label class="text-[10px] font-black uppercase text-slate-500 tracking-widest mb-1 block">Email or Admission No.</label>
            <input type="text" name="identifier" required placeholder="Email or Admission Number"
                   class="w-full p-3 bg-slate-100 rounded-xl text-sm font-semibold text-slate-800 outline-none focus:ring-2 focus:ring-amber-500">
        </div>
        <button type="submit" class="w-full bg-gradient-to-r from-amber-500 to-orange-600 hover:from-amber-600 hover:to-orange-700 text-white py-3.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all transform active:scale-[.98] shadow-lg flex items-center justify-center gap-2">
            <i class="fa-solid fa-paper-plane"></i> Send Reset Link
        </button>
    </form>
    <?php else: ?>
    <a href="index.php" class="w-full inline-flex items-center justify-center gap-2 bg-slate-900 hover:bg-slate-800 text-white py-3.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all">
        <i class="fa-solid fa-arrow-left"></i> Back to Login
    </a>
    <?php endif; ?>

    <div class="mt-5 pt-4 border-t border-slate-100 text-center">
        <a href="index.php" class="text-xs text-slate-400 font-medium hover:text-blue-600">
            <i class="fa-solid fa-arrow-left mr-1"></i> Back to Login
        </a>
    </div>
</div>

</body>
</html>
