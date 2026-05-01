<?php
include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id'])) {
    header('Location: upload_material.php'); exit();
}

$lecturer_id = (int)$_SESSION['user_id'];
$unit_id     = (int)($_POST['unit_id'] ?? 0);
$title       = trim($_POST['title'] ?? '');
$type        = in_array($_POST['type'] ?? '', ['pdf','video','word']) ? $_POST['type'] : null;

// ── Ensure columns exist ───────────────────────────────────────────────
@mysqli_query($conn, "ALTER TABLE materials MODIFY COLUMN `type` ENUM('pdf','video','word') DEFAULT NULL");
@mysqli_query($conn, "ALTER TABLE materials ADD COLUMN IF NOT EXISTS unit_id INT(11) DEFAULT NULL AFTER course_id");

// ── Validate unit belongs to this lecturer ─────────────────────────────
if ($unit_id <= 0 || !$title) {
    die("Unit and title are required.");
}
$unit_row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT cu.id, cu.course_id FROM course_units cu
     WHERE cu.id = $unit_id AND cu.lecturer_id = $lecturer_id"
));
if (!$unit_row) {
    die("Access denied — you are not assigned to this unit.");
}
$course_id = (int)$unit_row['course_id'];

// ── Validate file ──────────────────────────────────────────────────────
if (empty($_FILES['resource_file']['name'])) {
    die("No file selected.");
}
$file    = $_FILES['resource_file'];
$ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = match($type) {
    'video'  => ['mp4','mov','avi','webm'],
    'word'   => ['doc','docx'],
    'pdf'    => ['pdf'],
    default  => ['pdf','doc','docx','mp4','mov','avi','webm'],
};

// Auto-detect type from extension if somehow not sent
if (!$type) {
    $type = match($ext) {
        'pdf'              => 'pdf',
        'doc','docx'       => 'word',
        'mp4','mov','avi','webm' => 'video',
        default            => 'pdf'
    };
}

if (!in_array($ext, $allowed)) {
    die("Invalid file type .$ext for category '$type'. Allowed: " . implode(', ', $allowed));
}

// ── Create target folder ───────────────────────────────────────────────
$folder = match($type) {
    'video'  => 'uploads/videos/',
    'word'   => 'uploads/docs/',
    default  => 'uploads/notes/',
};
if (!is_dir($folder)) mkdir($folder, 0777, true);

// ── Move file ──────────────────────────────────────────────────────────
$safe_name  = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']);
$dest       = $folder . $safe_name;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    die("Upload failed — check folder permissions on '$folder'.");
}

// ── Save to DB ─────────────────────────────────────────────────────────
$title_sql = mysqli_real_escape_string($conn, $title);
$type_sql  = mysqli_real_escape_string($conn, $type);
$dest_sql  = mysqli_real_escape_string($conn, $dest);

mysqli_query($conn,
    "INSERT INTO materials (course_id, unit_id, lecturer_id, title, type, file_path)
     VALUES ($course_id, $unit_id, $lecturer_id, '$title_sql', '$type_sql', '$dest_sql')"
);

// Redirect back — highlight the unit that was just uploaded to
header("Location: upload_material.php?msg=uploaded&uploaded_unit=$unit_id");
exit();