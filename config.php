<?php
// 1. Safe Session Start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Database Connection
// IMPORTANT: since PHP 8.1, mysqli throws exceptions on query errors by
// default instead of returning false. This codebase was written for the
// old behaviour (checking "if (!$result)" and using @ to suppress errors),
// so without this line, any failing query anywhere in the app throws an
// UNCAUGHT exception mid-request — killing the script with no output.
// That's what was causing pages (like the AI Advisor panel) to hang or
// silently fail instead of showing a normal error.
mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli("localhost", "root", "", "smart_lms");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ── One-time schema patch: semester_number on course_units ──────────────
// Added after the table already existed in older installs, so
// CREATE TABLE IF NOT EXISTS elsewhere never adds it. Checked here, once,
// on every request, so every page (admin, lecturer, student, exam card)
// can safely rely on the column being present. Existing units default to
// Semester 1.
static $semester_col_checked = false;
if (!$semester_col_checked) {
    $semester_col_checked = true;
    $has_units_table = mysqli_query($conn, "SHOW TABLES LIKE 'course_units'");
    if ($has_units_table && mysqli_num_rows($has_units_table) > 0) {
        $sem_col_check = mysqli_query($conn, "SHOW COLUMNS FROM course_units LIKE 'semester_number'");
        if ($sem_col_check && mysqli_num_rows($sem_col_check) === 0) {
            mysqli_query($conn, "ALTER TABLE course_units ADD COLUMN semester_number INT(11) NOT NULL DEFAULT 1");
        }
    }
}

// 3. Role Security Function — strict, single-role match only
function checkRole($role) {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== $role) {
        session_unset();
        session_destroy();
        header("Location: index.php?error=unauthorized");
        exit();
    }
}

// 4. AI CONFIG
define('AI_PROVIDER', 'groq');
define('GROQ_API_KEY', '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');

// ── Minimum overall percentage (across ALL assessment components of a
//    unit) a student must reach to be considered to have PASSED that unit
//    when no lecturer has manually overridden the status. ──────────────
if (!defined('UNIT_PASS_PERCENT')) define('UNIT_PASS_PERCENT', 40);

/**
 * Resolve whether a student has passed a unit's latest attempt, using the
 * unit's OVERALL PERFORMANCE (total marks scored across every assessment
 * component vs. total marks available) rather than requiring a lecturer
 * to manually flip a switch.
 *
 * Priority:
 *   1. If a lecturer has explicitly set the attempt's status to 'failed',
 *      that override always wins (e.g. disciplinary / exam-misconduct fail).
 *   2. If a lecturer has explicitly set it to 'passed', that is honoured too.
 *   3. Otherwise ('active'), it is derived automatically: every assessment
 *      component for the unit must have a recorded mark, and the aggregate
 *      percentage must meet UNIT_PASS_PERCENT.
 *
 * Returns:
 *   [
 *     'resolved' => 'passed' | 'failed' | 'pending',  // pending = not fully assessed yet
 *     'passed'   => bool,
 *     'pct'      => float|null,   // overall % once fully marked, else null
 *     'attempt_no' => int|null,
 *     'source'   => 'manual' | 'computed' | 'none',
 *   ]
 */
function unit_completion_status($conn, $student_id, $unit_id) {
    $student_id = intval($student_id);
    $unit_id    = intval($unit_id);

    $latest = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT status, attempt_no FROM unit_registrations
         WHERE student_id=$student_id AND unit_id=$unit_id
         ORDER BY attempt_no DESC LIMIT 1"));

    if (!$latest) {
        return ['resolved' => 'pending', 'passed' => false, 'pct' => null, 'attempt_no' => null, 'source' => 'none'];
    }
    $attempt_no = intval($latest['attempt_no']);

    // Manual overrides always win.
    if ($latest['status'] === 'failed') {
        return ['resolved' => 'failed', 'passed' => false, 'pct' => null, 'attempt_no' => $attempt_no, 'source' => 'manual'];
    }
    if ($latest['status'] === 'passed') {
        return ['resolved' => 'passed', 'passed' => true, 'pct' => null, 'attempt_no' => $attempt_no, 'source' => 'manual'];
    }

    // status === 'active' — derive from overall marks performance.
    $sum_max = 0.0; $sum_marks = 0.0; $total_assess = 0; $marked_count = 0;
    $ares = mysqli_query($conn, "SELECT id, max_mark FROM unit_assessments WHERE unit_id=$unit_id");
    while ($a = mysqli_fetch_assoc($ares)) {
        $total_assess++;
        $sum_max += floatval($a['max_mark']);
        $aid = intval($a['id']);
        $m = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT mark FROM unit_marks WHERE assessment_id=$aid AND student_id=$student_id"));
        if ($m && $m['mark'] !== null) {
            $marked_count++;
            $sum_marks += floatval($m['mark']);
        }
    }

    $fully_marked = $total_assess > 0 && $marked_count === $total_assess;
    if (!$fully_marked) {
        return ['resolved' => 'pending', 'passed' => false, 'pct' => null, 'attempt_no' => $attempt_no, 'source' => 'computed'];
    }

    $pct    = $sum_max > 0 ? round(($sum_marks / $sum_max) * 100, 1) : 0.0;
    $passed = $pct >= UNIT_PASS_PERCENT;
    return [
        'resolved'   => $passed ? 'passed' : 'failed',
        'passed'     => $passed,
        'pct'        => $pct,
        'attempt_no' => $attempt_no,
        'source'     => 'computed',
    ];
}

/**
 * Convenience wrapper: true only when the unit's latest attempt is passed
 * (whether by explicit lecturer status or by computed overall performance).
 */
function unit_is_passed($conn, $student_id, $unit_id) {
    return unit_completion_status($conn, $student_id, $unit_id)['passed'];
}

/**
 * Live "which semester/year is this student currently in, for this course"
 * — computed fresh every call from their unit registrations, so it moves
 * forward automatically the moment they register for a later semester's
 * units (no stored field to go stale).
 * Returns ['semester'=>int, 'year'=>int, 'semester_in_year'=>int] or null
 * if the student isn't registered for any unit in the course yet.
 */
function student_current_semester($conn, $student_id, $course_id) {
    $student_id = intval($student_id); $course_id = intval($course_id);
    $res = mysqli_query($conn, "
        SELECT MAX(cu.semester_number) AS max_sem
        FROM unit_registrations ur
        JOIN course_units cu ON cu.id = ur.unit_id
        WHERE ur.student_id = $student_id AND cu.course_id = $course_id
    ");
    $row = mysqli_fetch_assoc($res);
    if (!$row || $row['max_sem'] === null) return null;
    $sem = intval($row['max_sem']);
    return [
        'semester'         => $sem,
        'year'             => intval(ceil($sem / 2)),
        'semester_in_year' => (($sem - 1) % 2) + 1,
    ];
}

// ── STEP A: Update ENUM *first* before any user inserts ──────────────
$enumRow = mysqli_fetch_assoc(mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'role'"));
if ($enumRow && strpos($enumRow['Type'], 'hr_manager') === false) {
    mysqli_query($conn, "ALTER TABLE users MODIFY COLUMN role 
        ENUM('student','lecturer','admin','financial_accountant','boarding_master','hr_manager') NOT NULL DEFAULT 'student'");
}

// ── STEP A1b: Extended student profile fields on users (My Details) ──
foreach ([
    'phone'            => "ALTER TABLE users ADD COLUMN phone VARCHAR(20) DEFAULT NULL AFTER admission_number",
    'date_of_birth'    => "ALTER TABLE users ADD COLUMN date_of_birth DATE DEFAULT NULL AFTER phone",
    'previous_school'  => "ALTER TABLE users ADD COLUMN previous_school VARCHAR(150) DEFAULT NULL AFTER date_of_birth",
    'religion'         => "ALTER TABLE users ADD COLUMN religion VARCHAR(50) DEFAULT NULL AFTER previous_school",
    'address'          => "ALTER TABLE users ADD COLUMN address VARCHAR(255) DEFAULT NULL AFTER religion",
    'profile_photo'    => "ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) DEFAULT NULL AFTER address",
] as $col => $sql) {
    $chk = mysqli_fetch_assoc(mysqli_query($conn, "SHOW COLUMNS FROM users LIKE '$col'"));
    if (!$chk) { mysqli_query($conn, $sql); }
}

// ── STEP A1c: Refund requests (Apply for Refund) ──────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS refund_requests (
  id INT(11) NOT NULL AUTO_INCREMENT,
  student_id INT(11) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  reason TEXT NOT NULL,
  bank_details VARCHAR(255) DEFAULT NULL,
  status ENUM('pending','approved','rejected','paid') NOT NULL DEFAULT 'pending',
  reviewed_by INT(11) DEFAULT NULL,
  review_notes VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── STEP A1g: Semester numbering on course units — powers next-semester unlock ──
$sem_chk = mysqli_fetch_assoc(mysqli_query($conn, "SHOW COLUMNS FROM course_units LIKE 'semester_number'"));
if (!$sem_chk) {
    mysqli_query($conn, "ALTER TABLE course_units ADD COLUMN semester_number INT(11) NOT NULL DEFAULT 1 AFTER course_id");
}
foreach ([
    'status'     => "ALTER TABLE unit_registrations ADD COLUMN status ENUM('active','passed','failed') NOT NULL DEFAULT 'active' AFTER unit_id",
    'attempt_no' => "ALTER TABLE unit_registrations ADD COLUMN attempt_no INT(11) NOT NULL DEFAULT 1 AFTER status",
] as $col => $sql) {
    $chk = mysqli_fetch_assoc(mysqli_query($conn, "SHOW COLUMNS FROM unit_registrations LIKE '$col'"));
    if (!$chk) { mysqli_query($conn, $sql); }
}
// Widen the unique key so a student can have multiple attempt rows for the same unit
$idx_chk = mysqli_query($conn, "SHOW INDEX FROM unit_registrations WHERE Key_name != 'PRIMARY'");
if ($idx_chk) {
    $existing_keys = [];
    while ($ik = mysqli_fetch_assoc($idx_chk)) $existing_keys[$ik['Key_name']] = true;
    foreach (array_keys($existing_keys) as $kn) {
        mysqli_query($conn, "ALTER TABLE unit_registrations DROP INDEX `$kn`");
    }
    mysqli_query($conn, "ALTER TABLE unit_registrations ADD UNIQUE KEY unique_reg_attempt (student_id, unit_id, attempt_no)");
}

// ── STEP A1f: Course completion status (student + admin visibility) ──
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS course_completions (
  id INT(11) NOT NULL AUTO_INCREMENT,
  student_id INT(11) NOT NULL,
  course_id INT(11) NOT NULL,
  status ENUM('complete','incomplete') NOT NULL DEFAULT 'incomplete',
  checked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY unique_sc (student_id, course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS graduation_registrations (
  id INT(11) NOT NULL AUTO_INCREMENT,
  student_id INT(11) NOT NULL,
  intended_ceremony VARCHAR(50) NOT NULL,
  gown_size VARCHAR(20) DEFAULT NULL,
  status ENUM('pending','approved','rejected','cleared') NOT NULL DEFAULT 'pending',
  reviewed_by INT(11) DEFAULT NULL,
  review_notes VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── STEP A1h: Graduation registrations now scoped to a specific course ──
$grad_course_chk = mysqli_fetch_assoc(mysqli_query($conn, "SHOW COLUMNS FROM graduation_registrations LIKE 'course_id'"));
if (!$grad_course_chk) {
    mysqli_query($conn, "ALTER TABLE graduation_registrations ADD COLUMN course_id INT(11) DEFAULT NULL AFTER student_id");
}

// ── STEP A1i: Admin-assigned graduation ceremony date, visible to the
//    student once set — separate from the student's own "intended_ceremony"
//    text field, which is just their preference at registration time. ──
$grad_date_chk = mysqli_fetch_assoc(mysqli_query($conn, "SHOW COLUMNS FROM graduation_registrations LIKE 'graduation_date'"));
if (!$grad_date_chk) {
    mysqli_query($conn, "ALTER TABLE graduation_registrations ADD COLUMN graduation_date DATE DEFAULT NULL AFTER intended_ceremony");
}

// ── STEP A2: Classes module — auto-create + auto-seed ────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `classes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `class_code` VARCHAR(20) NOT NULL,
  `category` ENUM('Education','Technical','Business','Nursing') NOT NULL,
  `capacity` INT NOT NULL DEFAULT 40,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_class_code` (`class_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `class_allocations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `class_id` INT NOT NULL,
  `allocated_by` INT DEFAULT NULL,
  `allocated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `status` ENUM('active','transferred','removed') NOT NULL DEFAULT 'active',
  KEY(`student_id`), KEY(`class_id`), KEY(`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Ensure the 4 course categories exist as courses (Education, Technical, Business, Nursing)
$course_categories = [
    'Education' => 'Education',
    'Technical' => 'Technical Courses',
    'Business'  => 'Business Courses',
    'Nursing'   => 'Nursing',
];
foreach ($course_categories as $cat => $title) {
    $exists = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM courses WHERE skill_category='$cat' LIMIT 1"));
    if (!$exists) {
        $t = mysqli_real_escape_string($conn, $title);
        mysqli_query($conn, "INSERT INTO courses (title, description, skill_category) VALUES ('$t','Auto-created course category','$cat')");
    }
}

// Seed classes: SE_1..SE_10 (Education), ST_1..ST_10 (Technical), SB_1..SB_10 (Business), SN_1..SN_5 (Nursing)
$class_prefixes = ['Education' => ['SE', 10], 'Technical' => ['ST', 10], 'Business' => ['SB', 10], 'Nursing' => ['SN', 5]];
foreach ($class_prefixes as $cat => [$prefix, $count]) {
    for ($i = 1; $i <= $count; $i++) {
        $code = "{$prefix}_{$i}";
        mysqli_query($conn, "INSERT IGNORE INTO classes (class_code, category, capacity) VALUES ('$code','$cat',40)");
    }
}

// ── STEP B: Auto-create finance tables ───────────────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS fee_structures (
  id INT(11) NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  fee_category ENUM('tuition','examination','library','accommodation','transport','medical','activity','other') NOT NULL DEFAULT 'tuition',
  academic_year VARCHAR(20) NOT NULL,
  semester ENUM('Semester 1','Semester 2','Full Year','One Time') NOT NULL DEFAULT 'Semester 1',
  course_id INT(11) DEFAULT NULL,
  is_mandatory TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT(11) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS student_fee_assignments (
  id INT(11) NOT NULL AUTO_INCREMENT,
  student_id INT(11) NOT NULL,
  fee_structure_id INT(11) NOT NULL,
  total_amount DECIMAL(12,2) NOT NULL,
  discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  discount_reason VARCHAR(255) DEFAULT NULL,
  net_amount DECIMAL(12,2) NOT NULL,
  academic_year VARCHAR(20) NOT NULL,
  semester VARCHAR(50) NOT NULL,
  due_date DATE DEFAULT NULL,
  status ENUM('pending','partial','paid','overdue','waived') NOT NULL DEFAULT 'pending',
  assigned_by INT(11) DEFAULT NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_stu_fee (student_id, fee_structure_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS fee_payments (
  id INT(11) NOT NULL AUTO_INCREMENT,
  student_id INT(11) NOT NULL,
  fee_assignment_id INT(11) DEFAULT NULL,
  amount_paid DECIMAL(12,2) NOT NULL,
  payment_method ENUM('cash','bank_transfer','mpesa','cheque','online','scholarship') NOT NULL DEFAULT 'cash',
  transaction_ref VARCHAR(100) DEFAULT NULL,
  receipt_number VARCHAR(50) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  payment_date DATE NOT NULL,
  recorded_by INT(11) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS fee_reminders (
  id INT(11) NOT NULL AUTO_INCREMENT,
  student_id INT(11) NOT NULL,
  message TEXT NOT NULL,
  sent_by INT(11) NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── STEP B2: M-Pesa STK Push transaction log (tracks every prompt sent) ──
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mpesa_transactions (
  id INT(11) NOT NULL AUTO_INCREMENT,
  student_id INT(11) NOT NULL,
  fee_assignment_id INT(11) DEFAULT NULL,
  phone VARCHAR(15) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  account_reference VARCHAR(20) DEFAULT NULL,
  merchant_request_id VARCHAR(60) DEFAULT NULL,
  checkout_request_id VARCHAR(60) DEFAULT NULL,
  mpesa_receipt_number VARCHAR(30) DEFAULT NULL,
  result_code INT(5) DEFAULT NULL,
  result_desc VARCHAR(255) DEFAULT NULL,
  status ENUM('pending','success','failed','cancelled') NOT NULL DEFAULT 'pending',
  fee_payment_id INT(11) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY (checkout_request_id),
  KEY (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── STEP C: Insert users AFTER ENUM is confirmed updated ─────────────

// Admin account
$adminEmail = 'admin@smartlms.com';
$chkAdmin = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM users WHERE email='$adminEmail'"));
if (!$chkAdmin) {
    $hp = password_hash("losblancos1", PASSWORD_BCRYPT);
    mysqli_query($conn, "INSERT INTO users (full_name, email, password, role)
                         VALUES ('System Admin', '$adminEmail', '$hp', 'admin')");
}

// Financial Accountant account
$faEmail = 'finance@smartlms.com';
$chkFA = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, role FROM users WHERE email='$faEmail'"));
if (!$chkFA) {
    $fp = password_hash("finance2025", PASSWORD_BCRYPT);
    mysqli_query($conn, "INSERT INTO users (full_name, email, password, role)
                         VALUES ('Finance Office', '$faEmail', '$fp', 'financial_accountant')");
} elseif ($chkFA['role'] !== 'financial_accountant') {
    $fp = password_hash("finance2025", PASSWORD_BCRYPT);
    mysqli_query($conn, "UPDATE users SET role='financial_accountant', password='$fp', full_name='Finance Office'
                         WHERE email='$faEmail'");
}

// ── Password Reset table (idempotent, safe to run every load) ────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `token` VARCHAR(80) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_token` (`token`),
  KEY(`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
?>