<?php
/**
 * lecturer_profile.php — "My Profile" for lecturers.
 *
 * Lets a lecturer record/update:
 *   • whether they are a Professor, and their academic rank
 *   • teaching + industry experience (years)
 *   • highest qualification, field of study, awarding institution
 *   • department, employment type, areas of expertise, short bio
 *   • contact + office details (phone, office, office hours, professional link)
 *   • display name and password
 *
 * Extra details live in their own table (lecturer_profiles, created on first
 * visit); only the display name touches the existing users table.
 */

include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

checkRole('lecturer');

$user_id = intval($_SESSION['user_id']);

// ── Table (created on first use) ─────────────────────────────────────────
@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `lecturer_profiles` (
    `user_id`               INT NOT NULL PRIMARY KEY,
    `is_professor`          TINYINT(1) NOT NULL DEFAULT 0,
    `academic_rank`         VARCHAR(40)  DEFAULT NULL,
    `employment_type`       VARCHAR(30)  DEFAULT NULL,
    `department`            VARCHAR(100) DEFAULT NULL,
    `highest_qualification` VARCHAR(40)  DEFAULT NULL,
    `field_of_study`        VARCHAR(120) DEFAULT NULL,
    `awarding_institution`  VARCHAR(120) DEFAULT NULL,
    `years_teaching`        SMALLINT UNSIGNED DEFAULT NULL,
    `years_industry`        SMALLINT UNSIGNED DEFAULT NULL,
    `expertise`             VARCHAR(255) DEFAULT NULL,
    `bio`                   TEXT DEFAULT NULL,
    `phone`                 VARCHAR(30)  DEFAULT NULL,
    `office_location`       VARCHAR(100) DEFAULT NULL,
    `office_hours`          VARCHAR(150) DEFAULT NULL,
    `professional_link`     VARCHAR(255) DEFAULT NULL,
    `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Allowed values (server-side whitelists) ──────────────────────────────
$PROFESSORIAL_RANKS     = ['Professor', 'Associate Professor', 'Assistant Professor'];
$NON_PROFESSORIAL_RANKS = ['Senior Lecturer', 'Lecturer', 'Assistant Lecturer', 'Tutorial Fellow', 'Visiting Lecturer', 'Other'];
$EMPLOYMENT_TYPES       = ['Full-time', 'Part-time', 'Adjunct / Visiting'];
$QUALIFICATIONS         = ["Bachelor's Degree", 'Postgraduate Diploma', "Master's Degree", 'PhD / Doctorate', 'Other'];

// ── CSRF token ───────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_lect_profile'])) {
    $_SESSION['csrf_lect_profile'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_lect_profile'];

$errors  = [];
$flash   = '';

// ── Load current data ────────────────────────────────────────────────────
function lp_load($conn, $user_id) {
    $stmt = mysqli_prepare($conn, "SELECT full_name, email FROM users WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $u = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: ['full_name' => '', 'email' => ''];
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "SELECT * FROM lecturer_profiles WHERE user_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $p = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [];
    mysqli_stmt_close($stmt);
    return [$u, $p];
}

// Small helper: trim + cap length
function lp_text($key, $max) {
    $v = trim((string)($_POST[$key] ?? ''));
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v);
    return mb_substr($v, 0, $max);
}

// ── Handle POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Your session expired. Please reload the page and try again.';
    } else {
        $action = $_POST['action'] ?? 'profile';

        // ── Save profile ────────────────────────────────────────────────
        if ($action === 'profile') {
            $full_name  = lp_text('full_name', 100);
            $is_prof    = (($_POST['is_professor'] ?? 'no') === 'yes') ? 1 : 0;
            $rank       = lp_text('academic_rank', 40);
            $emp        = lp_text('employment_type', 30);
            $dept       = lp_text('department', 100);
            $qual       = lp_text('highest_qualification', 40);
            $field      = lp_text('field_of_study', 120);
            $inst       = lp_text('awarding_institution', 120);
            $expertise  = lp_text('expertise', 255);
            $bio        = lp_text('bio', 1000);
            $phone      = lp_text('phone', 30);
            $office     = lp_text('office_location', 100);
            $hours      = lp_text('office_hours', 150);
            $link       = lp_text('professional_link', 255);

            $yt_raw = trim((string)($_POST['years_teaching'] ?? ''));
            $yi_raw = trim((string)($_POST['years_industry'] ?? ''));
            $years_teaching = null; $years_industry = null;

            if ($full_name === '') $errors[] = 'Full name is required.';

            if ($yt_raw !== '') {
                if (!ctype_digit($yt_raw) || intval($yt_raw) > 60) $errors[] = 'Teaching experience must be a whole number between 0 and 60 years.';
                else $years_teaching = intval($yt_raw);
            }
            if ($yi_raw !== '') {
                if (!ctype_digit($yi_raw) || intval($yi_raw) > 60) $errors[] = 'Industry experience must be a whole number between 0 and 60 years.';
                else $years_industry = intval($yi_raw);
            }

            // Rank must agree with the Professor yes/no answer.
            if ($rank !== '') {
                $validRanks = $is_prof ? $PROFESSORIAL_RANKS : $NON_PROFESSORIAL_RANKS;
                if (!in_array($rank, $validRanks, true)) {
                    $errors[] = $is_prof
                        ? 'Please choose a professorial rank (Professor, Associate Professor or Assistant Professor).'
                        : 'Please choose a lecturer rank, or switch "Are you a Professor?" to Yes.';
                }
            } elseif ($is_prof) {
                $errors[] = 'Please choose your professorial rank.';
            }

            if ($emp !== '' && !in_array($emp, $EMPLOYMENT_TYPES, true))   $errors[] = 'Invalid employment type.';
            if ($qual !== '' && !in_array($qual, $QUALIFICATIONS, true))   $errors[] = 'Invalid qualification.';
            if ($phone !== '' && !preg_match('/^[0-9+()\-\s]{6,30}$/', $phone)) $errors[] = 'Phone number can only contain digits, spaces, + ( ) and -.';
            if ($link !== '') {
                if (!filter_var($link, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $link)) {
                    $errors[] = 'Professional link must be a full web address starting with http:// or https://.';
                }
            }

            if (empty($errors)) {
                $nz = fn($v) => ($v === '' ? null : $v);
                $rank = $nz($rank); $emp = $nz($emp); $dept = $nz($dept); $qual = $nz($qual);
                $field = $nz($field); $inst = $nz($inst); $expertise = $nz($expertise);
                $bio = $nz($bio); $phone = $nz($phone); $office = $nz($office);
                $hours = $nz($hours); $link = $nz($link);

                $stmt = mysqli_prepare($conn,
                    "INSERT INTO lecturer_profiles
                        (user_id, is_professor, academic_rank, employment_type, department,
                         highest_qualification, field_of_study, awarding_institution,
                         years_teaching, years_industry, expertise, bio, phone,
                         office_location, office_hours, professional_link)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                         is_professor=VALUES(is_professor), academic_rank=VALUES(academic_rank),
                         employment_type=VALUES(employment_type), department=VALUES(department),
                         highest_qualification=VALUES(highest_qualification), field_of_study=VALUES(field_of_study),
                         awarding_institution=VALUES(awarding_institution), years_teaching=VALUES(years_teaching),
                         years_industry=VALUES(years_industry), expertise=VALUES(expertise), bio=VALUES(bio),
                         phone=VALUES(phone), office_location=VALUES(office_location),
                         office_hours=VALUES(office_hours), professional_link=VALUES(professional_link)"
                );
                mysqli_stmt_bind_param($stmt, 'iissssssiissssss',
                    $user_id, $is_prof, $rank, $emp, $dept, $qual, $field, $inst,
                    $years_teaching, $years_industry, $expertise, $bio, $phone, $office, $hours, $link
                );
                $ok = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                if ($ok) {
                    $n = mysqli_prepare($conn, "UPDATE users SET full_name = ? WHERE id = ?");
                    mysqli_stmt_bind_param($n, 'si', $full_name, $user_id);
                    mysqli_stmt_execute($n);
                    mysqli_stmt_close($n);
                    $_SESSION['user_name'] = $full_name;
                    header("Location: lecturer_profile.php?saved=1");
                    exit();
                }
                $errors[] = 'Could not save your profile. Please try again.';
            }

        // ── Change password ─────────────────────────────────────────────
        } elseif ($action === 'password') {
            $current = (string)($_POST['current_password'] ?? '');
            $new     = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');

            $stmt = mysqli_prepare($conn, "SELECT password FROM users WHERE id = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt, 'i', $user_id);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);

            if (!$row || !password_verify($current, $row['password'])) {
                $errors[] = 'Your current password is incorrect.';
            } elseif (strlen($new) < 8) {
                $errors[] = 'New password must be at least 8 characters long.';
            } elseif ($new !== $confirm) {
                $errors[] = 'New password and confirmation do not match.';
            } elseif (password_verify($new, $row['password'])) {
                $errors[] = 'New password must be different from your current password.';
            } else {
                $hash = password_hash($new, PASSWORD_BCRYPT);
                $u = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE id = ?");
                mysqli_stmt_bind_param($u, 'si', $hash, $user_id);
                mysqli_stmt_execute($u);
                mysqli_stmt_close($u);
                // Temporary password replaced — lift the forced-change requirement.
                mysqli_query($conn, "UPDATE users SET must_change_password = 0 WHERE id = " . intval($user_id));
                session_regenerate_id(true);
                header("Location: lecturer_profile.php?pwd=1");
                exit();
            }
        }
    }
}

if (isset($_GET['saved'])) $flash = 'Profile updated successfully.';
if (isset($_GET['pwd']))   $flash = 'Password changed successfully.';

[$u, $p] = lp_load($conn, $user_id);

// Is this account still on an admin-issued temporary password?
$mcpRes = mysqli_query($conn, "SELECT must_change_password FROM users WHERE id = " . intval($user_id) . " LIMIT 1");
$mustChange = ($mcpRes && ($mcpRow = mysqli_fetch_assoc($mcpRes))) ? !empty($mcpRow['must_change_password']) : false;

// On a failed POST, show what the user typed instead of the saved values.
$val = function ($key, $default = '') use ($p) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'profile' && isset($_POST[$key])) {
        return (string)$_POST[$key];
    }
    return (string)($p[$key] ?? $default);
};
$full_name_val = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'profile' && isset($_POST['full_name']))
                 ? (string)$_POST['full_name'] : (string)$u['full_name'];
$is_prof_val   = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'profile')
                 ? (($_POST['is_professor'] ?? 'no') === 'yes') : !empty($p['is_professor']);

// ── Profile completeness ─────────────────────────────────────────────────
$completeFields = ['academic_rank','employment_type','department','highest_qualification','field_of_study',
                   'awarding_institution','years_teaching','expertise','bio','phone','office_location','office_hours'];
$filled = 0;
foreach ($completeFields as $f) {
    if (isset($p[$f]) && $p[$f] !== null && $p[$f] !== '') $filled++;
}
$completePct = (int)round($filled / count($completeFields) * 100);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function sel($current, $option) { return $current === $option ? 'selected' : ''; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile — SmartLMS</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  * { box-sizing: border-box; font-family: 'DM Sans', sans-serif; }
  body { background: #f1f5f9; color: #1e293b; margin: 0; padding: 32px 16px 60px; }
  .wrap { max-width: 860px; margin: 0 auto; }
  .back { font-size: 11px; font-weight: 800; text-transform: uppercase; color: #94a3b8; text-decoration: none; letter-spacing: .05em; }
  .back:hover { color: #4f46e5; }
  .head { display: flex; align-items: center; gap: 16px; margin: 14px 0 22px; flex-wrap: wrap; }
  .avatar { width: 56px; height: 56px; border-radius: 18px; background: linear-gradient(135deg,#6366f1,#8b5cf6); color: white; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 22px; flex-shrink: 0; }
  h1 { font-size: 24px; font-weight: 900; margin: 0; }
  .sub { color: #64748b; font-size: 13px; margin-top: 2px; }
  .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: .04em; margin-left: 6px; vertical-align: middle; }
  .badge-prof { background: #fef3c7; color: #b45309; }
  .badge-rank { background: #eef2ff; color: #4338ca; }
  .progress { background: white; border: 1px solid #e2e8f0; border-radius: 16px; padding: 14px 18px; margin-bottom: 18px; }
  .progress .top { display: flex; justify-content: space-between; font-size: 11px; font-weight: 800; text-transform: uppercase; color: #64748b; margin-bottom: 8px; }
  .bar { height: 8px; background: #e2e8f0; border-radius: 999px; overflow: hidden; }
  .bar > div { height: 100%; background: linear-gradient(90deg,#6366f1,#8b5cf6); }
  .card { background: white; border: 1px solid #e2e8f0; border-radius: 20px; padding: 24px; margin-bottom: 18px; }
  .card h2 { font-size: 13px; font-weight: 900; text-transform: uppercase; letter-spacing: .06em; color: #4f46e5; margin: 0 0 16px; display: flex; align-items: center; gap: 8px; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  .full { grid-column: 1 / -1; }
  label { display: block; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: #94a3b8; margin-bottom: 6px; }
  input[type=text], input[type=number], input[type=password], input[type=url], select, textarea {
    width: 100%; padding: 11px 14px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; font-weight: 600;
    color: #1e293b; background: #f8fafc; outline: none; font-family: inherit; }
  textarea { min-height: 96px; resize: vertical; font-weight: 500; }
  input:focus, select:focus, textarea:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.12); background: white; }
  input[readonly] { color: #94a3b8; cursor: not-allowed; }
  .hint { font-size: 11px; color: #94a3b8; margin-top: 5px; }
  .toggle { display: flex; gap: 10px; }
  .toggle label.opt { flex: 1; margin: 0; padding: 11px 14px; border: 1px solid #e2e8f0; border-radius: 12px; background: #f8fafc; cursor: pointer;
    font-size: 13px; font-weight: 800; color: #64748b; text-transform: none; letter-spacing: 0; display: flex; align-items: center; justify-content: center; gap: 8px; }
  .toggle input { display: none; }
  .toggle input:checked + span { color: #4338ca; }
  .toggle label.opt:has(input:checked) { border-color: #6366f1; background: #eef2ff; color: #4338ca; }
  .btn { background: linear-gradient(135deg,#4f46e5,#7c3aed); color: white; border: none; padding: 13px 26px; border-radius: 12px; font-size: 12px;
    font-weight: 900; text-transform: uppercase; letter-spacing: .06em; cursor: pointer; }
  .btn:hover { filter: brightness(1.08); }
  .btn-alt { background: #1e293b; }
  .alert { padding: 12px 16px; border-radius: 12px; font-size: 13px; font-weight: 700; margin-bottom: 16px; }
  .alert-ok { background: #d1fae5; color: #047857; }
  .alert-err { background: #fee2e2; color: #b91c1c; }
  .alert-err ul { margin: 0; padding-left: 18px; }
  @media (max-width: 640px) { .grid { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="wrap">
  <?php if (!$mustChange): ?>
  <a href="lecturer_dashboard.php" class="back"><i class="fa-solid fa-arrow-left"></i> Back to dashboard</a>
  <?php endif; ?>

  <div class="head">
    <div class="avatar"><?php echo h(strtoupper(mb_substr($u['full_name'] ?: 'L', 0, 1))); ?></div>
    <div>
      <h1>
        <?php echo h($u['full_name']); ?>
        <?php if (!empty($p['is_professor'])): ?><span class="badge badge-prof"><i class="fa-solid fa-award"></i> Professor</span><?php endif; ?>
        <?php if (!empty($p['academic_rank'])): ?><span class="badge badge-rank"><?php echo h($p['academic_rank']); ?></span><?php endif; ?>
      </h1>
      <div class="sub"><?php echo h($u['email']); ?></div>
    </div>
  </div>

  <?php if ($mustChange): ?>
    <div class="alert" style="background:#fef3c7;color:#92400e;"><i class="fa-solid fa-triangle-exclamation"></i>
      Welcome! Your account was created by an administrator with a temporary password. Please set your own password below before continuing.</div>
  <?php endif; ?>
  <?php if ($flash): ?><div class="alert alert-ok"><i class="fa-solid fa-circle-check"></i> <?php echo h($flash); ?></div><?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="alert alert-err"><ul><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul></div>
  <?php endif; ?>

  <div class="progress">
    <div class="top"><span>Profile completeness</span><span><?php echo $completePct; ?>%</span></div>
    <div class="bar"><div style="width:<?php echo $completePct; ?>%"></div></div>
  </div>

  <form method="POST" action="lecturer_profile.php" autocomplete="off">
    <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
    <input type="hidden" name="action" value="profile">

    <!-- Academic standing -->
    <div class="card">
      <h2><i class="fa-solid fa-user-graduate"></i> Academic standing</h2>
      <div class="grid">
        <div class="full">
          <label>Full name</label>
          <input type="text" name="full_name" required maxlength="100" value="<?php echo h($full_name_val); ?>">
        </div>

        <div>
          <label>Are you a Professor?</label>
          <div class="toggle">
            <label class="opt"><input type="radio" name="is_professor" value="yes" <?php echo $is_prof_val ? 'checked' : ''; ?> onchange="syncRank()"><span><i class="fa-solid fa-award"></i> Yes</span></label>
            <label class="opt"><input type="radio" name="is_professor" value="no"  <?php echo !$is_prof_val ? 'checked' : ''; ?> onchange="syncRank()"><span>No</span></label>
          </div>
        </div>

        <div>
          <label>Academic rank</label>
          <?php $rankVal = $val('academic_rank'); ?>
          <select name="academic_rank" id="academic_rank">
            <option value="">— Select rank —</option>
            <optgroup label="Professorial" data-group="prof">
              <?php foreach ($PROFESSORIAL_RANKS as $r): ?><option value="<?php echo h($r); ?>" <?php echo sel($rankVal, $r); ?>><?php echo h($r); ?></option><?php endforeach; ?>
            </optgroup>
            <optgroup label="Lecturing staff" data-group="lect">
              <?php foreach ($NON_PROFESSORIAL_RANKS as $r): ?><option value="<?php echo h($r); ?>" <?php echo sel($rankVal, $r); ?>><?php echo h($r); ?></option><?php endforeach; ?>
            </optgroup>
          </select>
        </div>

        <div>
          <label>Employment type</label>
          <?php $empVal = $val('employment_type'); ?>
          <select name="employment_type">
            <option value="">— Select —</option>
            <?php foreach ($EMPLOYMENT_TYPES as $t): ?><option value="<?php echo h($t); ?>" <?php echo sel($empVal, $t); ?>><?php echo h($t); ?></option><?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>Department / Faculty</label>
          <input type="text" name="department" maxlength="100" placeholder="e.g. School of Computing" value="<?php echo h($val('department')); ?>">
        </div>
      </div>
    </div>

    <!-- Experience & qualifications -->
    <div class="card">
      <h2><i class="fa-solid fa-briefcase"></i> Experience &amp; qualifications</h2>
      <div class="grid">
        <div>
          <label>Teaching experience (years)</label>
          <input type="number" name="years_teaching" min="0" max="60" step="1" value="<?php echo h($val('years_teaching')); ?>">
        </div>
        <div>
          <label>Industry / professional experience (years)</label>
          <input type="number" name="years_industry" min="0" max="60" step="1" value="<?php echo h($val('years_industry')); ?>">
        </div>
        <div>
          <label>Highest qualification</label>
          <?php $qVal = $val('highest_qualification'); ?>
          <select name="highest_qualification">
            <option value="">— Select —</option>
            <?php foreach ($QUALIFICATIONS as $q): ?><option value="<?php echo h($q); ?>" <?php echo sel($qVal, $q); ?>><?php echo h($q); ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Field of study</label>
          <input type="text" name="field_of_study" maxlength="120" placeholder="e.g. Computer Science" value="<?php echo h($val('field_of_study')); ?>">
        </div>
        <div class="full">
          <label>Awarding institution</label>
          <input type="text" name="awarding_institution" maxlength="120" placeholder="e.g. University of Nairobi" value="<?php echo h($val('awarding_institution')); ?>">
        </div>
        <div class="full">
          <label>Areas of expertise / research interests</label>
          <input type="text" name="expertise" maxlength="255" placeholder="e.g. Machine Learning, Databases, Software Engineering" value="<?php echo h($val('expertise')); ?>">
          <div class="hint">Separate topics with commas.</div>
        </div>
        <div class="full">
          <label>Short bio</label>
          <textarea name="bio" maxlength="1000" placeholder="A few lines about your background and teaching approach."><?php echo h($val('bio')); ?></textarea>
        </div>
      </div>
    </div>

    <!-- Contact & office -->
    <div class="card">
      <h2><i class="fa-solid fa-address-card"></i> Contact &amp; office</h2>
      <div class="grid">
        <div>
          <label>Email (login)</label>
          <input type="text" value="<?php echo h($u['email']); ?>" readonly>
          <div class="hint">Contact an administrator to change your login email.</div>
        </div>
        <div>
          <label>Phone</label>
          <input type="text" name="phone" maxlength="30" placeholder="+254 700 000000" value="<?php echo h($val('phone')); ?>">
        </div>
        <div>
          <label>Office location</label>
          <input type="text" name="office_location" maxlength="100" placeholder="e.g. Block B, Room 204" value="<?php echo h($val('office_location')); ?>">
        </div>
        <div>
          <label>Office / consultation hours</label>
          <input type="text" name="office_hours" maxlength="150" placeholder="e.g. Tue &amp; Thu, 2:00–4:00 PM" value="<?php echo h($val('office_hours')); ?>">
        </div>
        <div class="full">
          <label>Professional profile link</label>
          <input type="url" name="professional_link" maxlength="255" placeholder="https://www.linkedin.com/in/… or ORCID / Google Scholar" value="<?php echo h($val('professional_link')); ?>">
        </div>
      </div>
    </div>

    <button type="submit" class="btn"><i class="fa-solid fa-floppy-disk"></i> Save profile</button>
  </form>

  <!-- Password -->
  <form method="POST" action="lecturer_profile.php" autocomplete="off" style="margin-top:28px;">
    <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
    <input type="hidden" name="action" value="password">
    <div class="card">
      <h2><i class="fa-solid fa-key"></i> Change password</h2>
      <div class="grid">
        <div class="full">
          <label>Current password</label>
          <input type="password" name="current_password" required autocomplete="current-password">
        </div>
        <div>
          <label>New password</label>
          <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
          <div class="hint">At least 8 characters.</div>
        </div>
        <div>
          <label>Confirm new password</label>
          <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
        </div>
      </div>
      <div style="margin-top:18px;"><button type="submit" class="btn btn-alt"><i class="fa-solid fa-lock"></i> Update password</button></div>
    </div>
  </form>
</div>

<script>
// Show only the rank options that match the Professor yes/no answer.
function syncRank() {
    const isProf = document.querySelector('input[name="is_professor"]:checked').value === 'yes';
    const sel = document.getElementById('academic_rank');
    sel.querySelectorAll('optgroup').forEach(g => {
        const show = (g.dataset.group === 'prof') === isProf;
        g.hidden = !show;
        g.disabled = !show;
    });
    const cur = sel.options[sel.selectedIndex];
    if (cur && cur.value && cur.parentElement.tagName === 'OPTGROUP' && cur.parentElement.disabled) sel.value = '';
}
syncRank();
</script>
</body>
</html>
