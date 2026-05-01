<?php
/**
 * download_submission.php — SmartLMS Secure Assignment File Download
 *
 * Forces a browser download (regardless of file type) for any submission
 * file attached to an assignment. Access is restricted to:
 *   - The student who owns the submission
 *   - A lecturer who teaches the course the assignment belongs to
 *
 * Usage: download_submission.php?sub_id=SUBMISSION_ID
 */

include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Auth ──────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Unauthorised.');
}

$user_id    = intval($_SESSION['user_id']);
$role       = strtolower($_SESSION['role'] ?? '');
$sub_id     = intval($_GET['sub_id'] ?? 0);

if ($sub_id === 0) {
    http_response_code(400);
    exit('Invalid request.');
}

// ── Fetch submission ──────────────────────────────────────────────────
$sub = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT s.id, s.student_id, s.file_path, a.course_id
     FROM assignment_submissions s
     JOIN assignments a ON a.id = s.assignment_id
     WHERE s.id = $sub_id"
));

if (!$sub || empty($sub['file_path'])) {
    http_response_code(404);
    exit('File not found.');
}

// ── Authorisation check ───────────────────────────────────────────────
$allowed = false;

if ($role === 'student' && intval($sub['student_id']) === $user_id) {
    // Student downloading their own submission
    $allowed = true;
}

if ($role === 'lecturer') {
    // Lecturer must teach the course this assignment belongs to
    $owns = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM courses
         WHERE id = " . intval($sub['course_id']) . "
           AND (lecturer_id = $user_id
                OR id = (SELECT course_id FROM users WHERE id = $user_id AND course_id IS NOT NULL LIMIT 1))"
    ));
    if ($owns) $allowed = true;
}

if ($role === 'admin') {
    $allowed = true;
}

if (!$allowed) {
    http_response_code(403);
    exit('Access denied.');
}

// ── Resolve file path ─────────────────────────────────────────────────
// file_path is stored as a relative path from the project root
// e.g. "uploads/assignments/1234_s1_a2.pdf"
$file_path = $sub['file_path'];

// Try relative to document root first, then as-is
$abs_path = '';
if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($file_path, '/'))) {
    $abs_path = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($file_path, '/');
} elseif (file_exists($file_path)) {
    $abs_path = $file_path;
} elseif (file_exists(__DIR__ . '/' . ltrim($file_path, '/'))) {
    $abs_path = __DIR__ . '/' . ltrim($file_path, '/');
}

if (!$abs_path || !file_exists($abs_path)) {
    http_response_code(404);
    exit('File not found on server. Path: ' . htmlspecialchars($file_path));
}

// ── MIME type map ─────────────────────────────────────────────────────
$ext = strtolower(pathinfo($abs_path, PATHINFO_EXTENSION));
$mime_map = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'txt'  => 'text/plain',
    'csv'  => 'text/csv',
    'zip'  => 'application/zip',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
];
$mime = $mime_map[$ext] ?? 'application/octet-stream';

// ── Force download ────────────────────────────────────────────────────
$filename = basename($abs_path);

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($abs_path));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

ob_clean();
flush();
readfile($abs_path);
exit();
?>