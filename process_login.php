<?php
session_start();
include 'config.php';
include_once 'login_settings.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php"); exit();
}

$identifier = mysqli_real_escape_string($conn, trim($_POST['email'] ?? ''));
$password   = trim($_POST['password'] ?? '');
$role       = mysqli_real_escape_string($conn, trim($_POST['role'] ?? ''));
// SECURITY: the role is NOT taken from the browser unless the legacy selector
// is switched on. Normally it is read from the matched account in the database.
if (!LOGIN_SHOW_ROLE_SELECTOR) $role = '';
$roleSql    = ($role !== '') ? "AND role='$role'" : '';

// Extend role ENUM silently in case it hasn't been done yet
@mysqli_query($conn, "ALTER TABLE users MODIFY COLUMN `role`
    ENUM('student','lecturer','admin','financial_accountant','boarding_master','hr_manager')
    NOT NULL DEFAULT 'student'");

// Also ensure admission_number column exists on users table
@mysqli_query($conn, "ALTER TABLE users ADD COLUMN `admission_number` VARCHAR(30) DEFAULT NULL AFTER `email`");
@mysqli_query($conn, "ALTER TABLE users ADD UNIQUE KEY `uq_admission_number` (`admission_number`)");

// ── Try to find user by EMAIL first, then by ADMISSION NUMBER ─────────
$user = null;

// Try email match
$res = mysqli_query($conn,
    "SELECT id, full_name, role, password, must_change_password FROM users
     WHERE email='$identifier' $roleSql LIMIT 1");
if ($res && mysqli_num_rows($res) === 1) {
    $user = mysqli_fetch_assoc($res);
}

// If not found by email, try admission_number (students only)
if (!$user) {
    $res2 = mysqli_query($conn,
        "SELECT id, full_name, role, password, must_change_password FROM users
         WHERE admission_number='$identifier' $roleSql LIMIT 1");
    if ($res2 && mysqli_num_rows($res2) === 1) {
        $user = mysqli_fetch_assoc($res2);
    }
}

// ── Login rate limiting / lockout ─────────────────────────────────────
// Blocks repeated password guessing: after LOGIN_MAX_ATTEMPTS failed
// logins for the same account (or LOGIN_MAX_ATTEMPTS_IP from the same
// IP) inside LOGIN_WINDOW_SECONDS, further attempts are refused until the
// window since the last failure has passed.
define('LOGIN_MAX_ATTEMPTS',    5);    // failures per account
define('LOGIN_MAX_ATTEMPTS_IP', 15);   // failures per IP address
define('LOGIN_WINDOW_SECONDS',  900);  // 15 minutes

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `identifier` VARCHAR(190) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_identifier` (`identifier`, `attempted_at`),
    KEY `idx_ip` (`ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Behind the ngrok tunnel REMOTE_ADDR is the tunnel, so prefer the
// forwarded client address when present.
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $client_ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
}
$client_ip  = substr($client_ip, 0, 45);
$attempt_id = substr(strtolower(trim($_POST['email'] ?? '')), 0, 190);

// Returns minutes left on a lockout, or 0 if not locked.
function login_lock_minutes($conn, $col, $value, $max) {
    $win = LOGIN_WINDOW_SECONDS;
    $stmt = mysqli_prepare($conn,
        "SELECT COUNT(*) AS c, UNIX_TIMESTAMP(MAX(attempted_at)) AS last_at, UNIX_TIMESTAMP() AS now_ts
         FROM login_attempts
         WHERE $col = ? AND attempted_at > (NOW() - INTERVAL $win SECOND)");
    if (!$stmt) return 0;
    mysqli_stmt_bind_param($stmt, 's', $value);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row || intval($row['c']) < $max) return 0;
    $remaining = intval($row['last_at']) + $win - intval($row['now_ts']);
    return $remaining > 0 ? (int)ceil($remaining / 60) : 0;
}

$lockMins = max(
    login_lock_minutes($conn, 'identifier', $attempt_id, LOGIN_MAX_ATTEMPTS),
    login_lock_minutes($conn, 'ip_address', $client_ip,  LOGIN_MAX_ATTEMPTS_IP)
);
if ($lockMins > 0) {
    header("Location: index.php?error=locked&wait=" . $lockMins);
    exit();
}

// ── Verify password and create session ───────────────────────────────
if ($user && password_verify($password, $user['password'])) {
    // Successful login clears this account's failed-attempt history.
    $clr = mysqli_prepare($conn, "DELETE FROM login_attempts WHERE identifier = ?");
    if ($clr) { mysqli_stmt_bind_param($clr, 's', $attempt_id); mysqli_stmt_execute($clr); mysqli_stmt_close($clr); }

    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['role']      = $user['role'];

    switch ($user['role']) {
        case 'admin':                header("Location: admin_dashboard.php");     break;
        case 'lecturer':             header("Location: " . (!empty($user['must_change_password']) ? "lecturer_profile.php?force=1" : "lecturer_dashboard.php")); break;
        case 'financial_accountant': header("Location: financial_dashboard.php"); break;
        case 'boarding_master':      header("Location: boarding_dashboard.php");  break;
        case 'hr_manager':           header("Location: hr_dashboard.php");        break;
        default:                     header("Location: student_dashboard.php");   break;
    }
    exit();
}

// Failed login: record it so repeated guesses trip the lockout above.
$log = mysqli_prepare($conn, "INSERT INTO login_attempts (identifier, ip_address) VALUES (?, ?)");
if ($log) { mysqli_stmt_bind_param($log, 'ss', $attempt_id, $client_ip); mysqli_stmt_execute($log); mysqli_stmt_close($log); }
// Housekeeping: drop attempts older than a day.
@mysqli_query($conn, "DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");

header("Location: index.php?error=invalid");
exit();
