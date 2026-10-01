<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include 'config.php';

$token = mysqli_real_escape_string($conn, trim($_GET['token'] ?? $_POST['token'] ?? ''));
$msg = ''; $msg_type = ''; $valid = false; $done = false;

if ($token === '') {
    $msg = "No reset token provided."; $msg_type = 'error';
} else {
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT pr.*, u.full_name, u.email FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.token='$token' AND pr.used=0 AND pr.expires_at > NOW()
         LIMIT 1"
    ));
    if (!$row) {
        // Look up the token regardless of state so we can explain exactly why it failed
        $raw = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT used, expires_at, (expires_at <= NOW()) AS is_expired FROM password_resets WHERE token='$token' LIMIT 1"
        ));
        if (!$raw) {
            $msg = "This reset link doesn't match any request. Please request a new one.";
        } elseif (intval($raw['used']) === 1) {
            $msg = "This reset link has already been used. Please request a new one.";
        } elseif (intval($raw['is_expired']) === 1) {
            $msg = "This reset link has expired (links are valid for 1 hour). Please request a new one.";
        } else {
            $msg = "This reset link is invalid. Please request a new one.";
        }
        $msg_type = 'error';
    } else {
        $valid = true;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
            $p1 = $_POST['new_password'] ?? '';
            $p2 = $_POST['confirm_password'] ?? '';

            if (strlen($p1) < 6) {
                $msg = "Password must be at least 6 characters."; $msg_type = 'error';
            } elseif ($p1 !== $p2) {
                $msg = "Passwords do not match."; $msg_type = 'error';
            } else {
                $hash = password_hash($p1, PASSWORD_BCRYPT);
                $uid  = intval($row['user_id']);
                mysqli_query($conn, "UPDATE users SET password='$hash' WHERE id=$uid");
                mysqli_query($conn, "UPDATE password_resets SET used=1 WHERE token='$token'");
                // Invalidate all other outstanding reset tokens for this user
                mysqli_query($conn, "UPDATE password_resets SET used=1 WHERE user_id=$uid AND used=0");
                $done = true;
                $msg = "Your password has been reset successfully. You can now log in.";
                $msg_type = 'success';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password — SmartLMS</title>
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
        <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-700 flex items-center justify-center mx-auto mb-4 shadow-lg shadow-blue-600/30">
            <i class="fa-solid fa-lock text-white text-xl"></i>
        </div>
        <h1 class="text-2xl font-black text-slate-900 uppercase tracking-tight">Reset Password</h1>
        <?php if ($valid && !$done): ?>
        <p class="text-slate-400 text-xs mt-1">Choose a new password for <?php echo htmlspecialchars($row['full_name']); ?></p>
        <?php endif; ?>
    </div>

    <?php if ($msg): ?>
    <div class="mb-5 px-4 py-3 rounded-xl text-xs font-bold border flex items-start gap-2
        <?php echo $msg_type === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-red-50 border-red-200 text-red-600'; ?>">
        <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?> mt-0.5"></i>
        <span><?php echo htmlspecialchars($msg); ?></span>
    </div>
    <?php endif; ?>

    <?php if ($valid && !$done): ?>
    <form method="POST" class="space-y-4">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
        <div>
            <label class="text-[10px] font-black uppercase text-slate-500 tracking-widest mb-1 block">New Password</label>
            <div class="relative">
                <input type="password" name="new_password" id="pwd1" required minlength="6" placeholder="At least 6 characters"
                       class="w-full p-3 pr-10 bg-slate-100 rounded-xl text-sm font-semibold text-slate-800 outline-none focus:ring-2 focus:ring-blue-500">
                <button type="button" onclick="togglePwd('pwd1','eye1')" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">
                    <i class="fa-solid fa-eye text-xs" id="eye1"></i>
                </button>
            </div>
        </div>
        <div>
            <label class="text-[10px] font-black uppercase text-slate-500 tracking-widest mb-1 block">Confirm New Password</label>
            <div class="relative">
                <input type="password" name="confirm_password" id="pwd2" required minlength="6" placeholder="Re-enter password"
                       class="w-full p-3 pr-10 bg-slate-100 rounded-xl text-sm font-semibold text-slate-800 outline-none focus:ring-2 focus:ring-blue-500">
                <button type="button" onclick="togglePwd('pwd2','eye2')" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">
                    <i class="fa-solid fa-eye text-xs" id="eye2"></i>
                </button>
            </div>
        </div>
        <button type="submit" class="w-full bg-gradient-to-r from-blue-600 to-indigo-700 hover:from-blue-700 hover:to-indigo-800 text-white py-3.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all transform active:scale-[.98] shadow-lg flex items-center justify-center gap-2">
            <i class="fa-solid fa-check"></i> Set New Password
        </button>
    </form>
    <?php elseif ($done): ?>
    <a href="index.php" class="w-full inline-flex items-center justify-center gap-2 bg-slate-900 hover:bg-slate-800 text-white py-3.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all">
        <i class="fa-solid fa-right-to-bracket"></i> Go to Login
    </a>
    <?php else: ?>
    <a href="forgot_password.php" class="w-full inline-flex items-center justify-center gap-2 bg-amber-500 hover:bg-amber-600 text-white py-3.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all">
        <i class="fa-solid fa-rotate-right"></i> Request New Link
    </a>
    <?php endif; ?>
</div>

<script>
function togglePwd(inputId, iconId) {
    const inp = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    if (inp.type === 'password') { inp.type = 'text'; icon.classList.replace('fa-eye','fa-eye-slash'); }
    else { inp.type = 'password'; icon.classList.replace('fa-eye-slash','fa-eye'); }
}
</script>
</body>
</html>
