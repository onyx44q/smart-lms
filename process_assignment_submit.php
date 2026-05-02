<?php
/**
 * process_assignment_submit.php — SmartLMS Assignment Submission Handler
 *
 * Flow:
 *   1. Validate session + POST data
 *   2. Verify student is enrolled in the assignment's course
 *   3. Insert or update the submission row
 *   4. Run runPlagiarismEngine() — rule-based, no external API
 *   5. Redirect to assignment_submit.php with result status
 */

include 'config.php';
include 'plagiarism_engine.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// ── Auth guard ─────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: assignment_submit.php");
    exit();
}

$student_id    = intval($_SESSION['user_id']);
$assignment_id = intval($_POST['assignment_id'] ?? 0);
$raw_text      = trim($_POST['submission_text'] ?? '');

// ── Basic validation ───────────────────────────────────────────────────────
if ($assignment_id === 0 || empty($raw_text)) {
    header("Location: assignment_submit.php?status=error&msg=Assignment+ID+and+submission+text+are+required.");
    exit();
}

$word_count = str_word_count($raw_text);
if ($word_count < 10) {
    header("Location: assignment_submit.php?status=error&msg=Submission+must+be+at+least+10+words.");
    exit();
}

// ── Fetch the assignment and verify enrollment ─────────────────────────────
$assignment = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT a.id, a.course_id, a.title, a.max_words, a.due_date
     FROM assignments a
     WHERE a.id = $assignment_id"
));

if (!$assignment) {
    header("Location: assignment_submit.php?status=error&msg=Assignment+not+found.");
    exit();
}

// Check enrollment
$enrolled = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id FROM enrollments
     WHERE student_id = $student_id AND course_id = " . intval($assignment['course_id'])
));

if (!$enrolled) {
    header("Location: assignment_submit.php?status=error&msg=You+are+not+enrolled+in+this+course.");
    exit();
}

// ── Word-count cap check ───────────────────────────────────────────────────
if ($word_count > intval($assignment['max_words'])) {
    $limit = intval($assignment['max_words']);
    header("Location: assignment_submit.php?status=error&msg=Submission+exceeds+the+{$limit}-word+limit.+You+submitted+$word_count+words.");
    exit();
}

// ── Optional file upload ───────────────────────────────────────────────────
$file_path = '';
if (!empty($_FILES['submission_file']['name'])) {
    $allowedExt = ['pdf', 'doc', 'docx', 'txt'];
    $fileExt    = strtolower(pathinfo($_FILES['submission_file']['name'], PATHINFO_EXTENSION));
    if (!in_array($fileExt, $allowedExt)) {
        header("Location: assignment_submit.php?status=error&msg=Only+PDF,+DOC,+DOCX+and+TXT+files+are+allowed.");
        exit();
    }
    if (!is_dir('uploads/assignments')) mkdir('uploads/assignments', 0777, true);
    $newName   = time() . '_s' . $student_id . '_a' . $assignment_id . '.' . $fileExt;
    $targetPath = 'uploads/assignments/' . $newName;
    if (move_uploaded_file($_FILES['submission_file']['tmp_name'], $targetPath)) {
        $file_path = $targetPath;
    }
}

// ── Auto-create tables if they do not exist yet ───────────────────────────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `assignment_submissions` (
      `id`              INT(11)   NOT NULL AUTO_INCREMENT,
      `assignment_id`   INT(11)   NOT NULL,
      `student_id`      INT(11)   NOT NULL,
      `submission_text` LONGTEXT  NOT NULL,
      `file_path`       VARCHAR(500) DEFAULT NULL,
      `word_count`      INT(11)   NOT NULL DEFAULT 0,
      `submitted_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_submission` (`assignment_id`, `student_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `plagiarism_reports` (
      `id`                        INT(11)      NOT NULL AUTO_INCREMENT,
      `submission_id`             INT(11)      NOT NULL,
      `student_similarity_score`  DECIMAL(5,2) NOT NULL DEFAULT 0.00,
      `internet_similarity_score` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
      `overall_score`             DECIMAL(5,2) NOT NULL DEFAULT 0.00,
      `verdict`                   VARCHAR(20)  NOT NULL DEFAULT 'LOW RISK',
      `matched_students`          LONGTEXT     DEFAULT NULL,
      `flags`                     LONGTEXT     DEFAULT NULL,
      `analysed_at`               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_report` (`submission_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Save or update submission ──────────────────────────────────────────────
$textSql     = mysqli_real_escape_string($conn, $raw_text);
$filePathSql = mysqli_real_escape_string($conn, $file_path);

// Check for existing submission
$existing = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id FROM assignment_submissions
     WHERE assignment_id = $assignment_id AND student_id = $student_id"
));

if ($existing) {
    // UPDATE — student is resubmitting
    $submission_id = intval($existing['id']);
    mysqli_query($conn,
        "UPDATE assignment_submissions
         SET submission_text = '$textSql',
             file_path       = '$filePathSql',
             word_count      = $word_count,
             submitted_at    = NOW()
         WHERE id = $submission_id"
    );
    // Delete old plagiarism report so it gets regenerated fresh
    mysqli_query($conn, "DELETE FROM plagiarism_reports WHERE submission_id = $submission_id");
} else {
    // INSERT — first submission
    mysqli_query($conn,
        "INSERT INTO assignment_submissions
            (assignment_id, student_id, submission_text, file_path, word_count, submitted_at)
         VALUES
            ($assignment_id, $student_id, '$textSql', '$filePathSql', $word_count, NOW())"
    );
    $submission_id = intval(mysqli_insert_id($conn));
}

if (!$submission_id) {
    header("Location: assignment_submit.php?status=error&msg=Could+not+save+submission.+Please+try+again.");
    exit();
}

// ── Run the plagiarism engine ──────────────────────────────────────────────
$report = runPlagiarismEngine(
    $submission_id,
    $raw_text,
    $assignment_id,
    $student_id,
    $conn
);

// ── Notify the student via the in-app notifications table ─────────────────
$verdictMsg = mysqli_real_escape_string($conn,
    "Your submission for \"{$assignment['title']}\" has been analysed. " .
    "Plagiarism verdict: {$report['verdict']} (overall score: {$report['overall_score']}%)."
);
mysqli_query($conn,
    "INSERT INTO notifications (user_id, message, is_read, created_at)
     VALUES ($student_id, '$verdictMsg', 0, NOW())"
);

// ── Redirect with result ───────────────────────────────────────────────────
$verdict     = urlencode($report['verdict']);
$overallScore = urlencode($report['overall_score']);
header("Location: assignment_submit.php?status=submitted&verdict=$verdict&score=$overallScore");
exit();
?>