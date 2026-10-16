<?php
/**
 * Ai_quiz_generator.php
 * Generates MCQ quizzes from:
 *   (a) a specific uploaded material selected by the lecturer, OR
 *   (b) a manually typed topic
 *
 * Pipeline: read notes (quiz_text_engine.php) → ask the AI for questions → VALIDATE every
 * question → top up from the notes with the rule-based builder if needed. Nothing that
 * fails the structure checks is ever saved.
 */

if (ob_get_level() == 0) ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'PHP Fatal: ' . $e['message']]);
        exit;
    }
});

include 'config.php';
include_once 'quiz_text_engine.php';   // reading notes, facts, rule-based questions, validation
@set_time_limit(180);                 // AI retries can take a while
if (session_status() === PHP_SESSION_NONE) session_start();

function sendResponse($data) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// ── Auth ─────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) sendResponse(['success' => false, 'error' => 'Unauthorised']);
$lecturer_id = (int)$_SESSION['user_id'];

// ── Input ─────────────────────────────────────────────────────────────────
$unit_id       = (int)($_POST['unit_id']       ?? 0);
$material_id   = (int)($_POST['material_id']   ?? 0);
$topic         = trim($_POST['topic']           ?? '');
$num_questions = max(3, min(15, (int)($_POST['num_questions'] ?? 5)));
$skill_name_raw = trim($_POST['skill_name']    ?? 'Core Theory');
$difficulty    = trim($_POST['difficulty']      ?? 'intermediate');

if ($unit_id <= 0) sendResponse(['success' => false, 'error' => 'Unit is required.']);

// ── Validate unit ownership ───────────────────────────────────────────────
$unit_row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT cu.id, cu.title AS unit_title, cu.course_id, c.title AS course_title
     FROM course_units cu
     JOIN courses c ON c.id = cu.course_id
     WHERE cu.id = $unit_id AND cu.lecturer_id = $lecturer_id"
));
if (!$unit_row) sendResponse(['success' => false, 'error' => 'Unit not found or not assigned to you.']);

$course_id   = (int)$unit_row['course_id'];
$unit_title  = $unit_row['unit_title'];
$course_title= $unit_row['course_title'];
$context_label = $unit_title ?: $course_title;

// ── Quiz type mapping ─────────────────────────────────────────────────────
$quiz_type = match($skill_name_raw) {
    'Practical Application' => 'practical',
    'General Aptitude'      => 'aptitude',
    default                 => 'theory',
};

// ── Extract text from selected material ───────────────────────────────────
$extracted_text = '';
$source         = 'general_knowledge';
$source_title   = '';
$source_type    = '';
$doc            = ['sections' => [], 'text' => ''];   // parsed notes (titled sections)
$material_unreadable = false;

if ($material_id > 0) {
    // Fetch the specific material — must belong to this unit and this lecturer
    $mat_row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id, title, type, file_path
         FROM materials
         WHERE id = $material_id AND unit_id = $unit_id AND lecturer_id = $lecturer_id"
    ));

    if ($mat_row) {
        $source_title = $mat_row['title'];
        $source_type  = $mat_row['type'];
        $file_path    = $mat_row['file_path'];

        // Try multiple path resolutions
        $resolved = null;
        $candidates = [
            $file_path,
            $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($file_path, '/'),
            dirname(__FILE__) . '/' . ltrim($file_path, '/'),
            __DIR__ . '/' . $file_path,
        ];
        foreach ($candidates as $c) {
            if (file_exists($c)) { $resolved = $c; break; }
        }

        if ($resolved) {
            if ($mat_row['type'] === 'pdf')       $doc = qe_read_pdf($resolved);
            elseif ($mat_row['type'] === 'word')  $doc = qe_read_docx($resolved);

            if (qe_looks_like_text($doc['text'])) {
                $extracted_text = $doc['text'];
                $source = 'lecture_notes';
            } else {
                // Scanned / image-only / unreadable file: don't invent "notes" out of noise.
                $doc = ['sections' => [], 'text' => ''];
                $material_unreadable = true;
            }
        }

        if (empty($topic)) $topic = $source_title;
    }
}

if (empty($topic)) $topic = $context_label;

// ── Groq call (OpenAI-compatible endpoint) ────────────────────────────────
function qe_call_groq(string $prompt): array {
    if (!function_exists('curl_init')) return [null, 'The PHP cURL extension is not enabled on this server'];
    $payload = [
        'model'            => 'openai/gpt-oss-20b',       // llama-3.1-8b-instant was retired by Groq on 16 Aug 2026
        'messages'         => [['role' => 'user', 'content' => $prompt]],
        'temperature'      => 0.4,
        'max_tokens'       => 6000,                        // reasoning tokens count towards this budget
        'reasoning_effort' => 'low',
    ];
    for ($try = 0; $try < 2; $try++) {
        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . GROQ_API_KEY],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 70,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch); $err = curl_error($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno) return [null, "Could not reach the AI service ($err)"];
        if ($http === 400 && isset($payload['reasoning_effort']) && stripos((string)$raw, 'reasoning') !== false) {
            unset($payload['reasoning_effort']); continue;      // model doesn't take that option — retry once without it
        }
        if ($http !== 200) return [null, "AI service returned HTTP $http: " . substr((string)$raw, 0, 200)];
        $data = json_decode($raw, true);
        $text = $data['choices'][0]['message']['content'] ?? '';
        if (trim($text) === '') return [null, 'AI service returned an empty answer'];
        return [$text, null];
    }
    return [null, 'AI service rejected the request'];
}

// ── 1) Ask the AI, then validate every question it returns ────────────────
$notes_for_ai = ($source === 'lecture_notes') ? qe_flatten($doc['sections'], 9000) : '';
$questions = [];
$ai_error  = null;
$ai_count  = 0;

if (defined('GROQ_API_KEY') && GROQ_API_KEY !== '') {
    for ($attempt = 1; $attempt <= 2 && count($questions) < $num_questions; $attempt++) {
        $ask = ($attempt === 1) ? $num_questions + 2 : ($num_questions - count($questions)) + 2;   // ask for a couple extra; weak ones get dropped
        [$text, $err] = qe_call_groq(qe_ai_prompt($notes_for_ai, $topic, $context_label, $quiz_type, $difficulty, $ask));
        if ($err) { $ai_error = $err; break; }
        $parsed = qe_parse_ai_json($text);
        if (!$parsed) { $ai_error = 'The AI answer could not be read as a question list'; continue; }
        $questions = qe_validate_questions(array_merge($questions, $parsed), $num_questions);
        if (empty($questions)) $ai_error = 'None of the AI questions passed the quality checks';
    }
    $ai_count = count($questions);
} else {
    $ai_error = 'GROQ_API_KEY is not configured';
}

// ── 2) Top up (or replace) from the notes with the rule-based builder ─────
$rule_count = 0;
if (count($questions) < $num_questions && !empty($doc['sections'])) {
    $rule = qe_rule_questions($doc, $quiz_type, $difficulty, $num_questions * 2);
    $before = count($questions);
    $questions = qe_validate_questions(array_merge($questions, $rule), $num_questions);
    $rule_count = count($questions) - $before;
}

if (empty($questions)) {
    $why = $material_unreadable
        ? 'The selected file has no readable text (it may be a scanned image or a slide deck exported as pictures).'
        : (empty($doc['sections']) ? 'No notes were selected for this quiz.' : 'The notes did not contain enough clear definitions or lists to build questions.');
    sendResponse(['success' => false,
        'error' => 'Could not create good questions. ' . $why . ($ai_error ? " The AI service also failed: $ai_error." : '')
                 . ' Try a text-based PDF/Word file, or check your Groq API key and try again.']);
}

// What the lecturer is told about where the questions came from
if ($ai_count > 0)                       { $source = ($source === 'lecture_notes') ? 'lecture_notes' : 'general_knowledge'; }
else                                     { $source = 'fallback'; }

$material_note = null;
if ($material_unreadable) {
    $material_note = 'The selected file has no readable text, so the questions were written from the topic instead of your notes. For questions drawn from your notes, upload a text-based PDF or Word file.';
} elseif (count($questions) < $num_questions) {
    $material_note = 'Only ' . count($questions) . ' of the ' . $num_questions . ' requested questions could be built to a good standard. The notes contain a limited number of clear definitions and lists; attach a longer file for more.';
}

// ── Save quiz to DB ───────────────────────────────────────────────────────
$quiz_title_str  = $context_label . ' — ' . $topic . ' (' . $skill_name_raw . ')';
$quiz_title_sql  = mysqli_real_escape_string($conn, $quiz_title_str);
$topic_sql       = mysqli_real_escape_string($conn, $topic);
$skill_sql       = mysqli_real_escape_string($conn, $skill_name_raw);
$diff_sql        = mysqli_real_escape_string($conn, $difficulty);
$unit_id_sql     = $unit_id > 0 ? $unit_id : 'NULL';
$material_id_sql = $material_id > 0 ? $material_id : 'NULL';

@mysqli_query($conn, "ALTER TABLE quizzes ADD COLUMN IF NOT EXISTS material_id INT(11) DEFAULT NULL AFTER unit_id");
@mysqli_query($conn, "ALTER TABLE quizzes ADD COLUMN IF NOT EXISTS difficulty VARCHAR(50) DEFAULT NULL AFTER material_id");

$ok = mysqli_query($conn,
    "INSERT INTO quizzes (course_id, unit_id, material_id, difficulty, title, topic, skill_name, created_by, created_at)
     VALUES ($course_id, $unit_id_sql, $material_id_sql, '$diff_sql', '$quiz_title_sql', '$topic_sql', '$skill_sql', $lecturer_id, NOW())"
);
if (!$ok) sendResponse(['success' => false, 'error' => 'DB save failed: ' . mysqli_error($conn)]);

$quiz_id     = mysqli_insert_id($conn);
$saved_count = 0;

foreach ($questions as $q) {
    $qtext = mysqli_real_escape_string($conn, $q['question_text'] ?? '');
    $oa    = mysqli_real_escape_string($conn, $q['option_a']      ?? '');
    $ob    = mysqli_real_escape_string($conn, $q['option_b']      ?? '');
    $oc    = mysqli_real_escape_string($conn, $q['option_c']      ?? '');
    $od    = mysqli_real_escape_string($conn, $q['option_d']      ?? '');
    $corr  = strtoupper($q['correct_option'] ?? 'A');
    $expl  = mysqli_real_escape_string($conn, $q['explanation']   ?? '');
    if (mysqli_query($conn,
        "INSERT INTO questions (quiz_id, question_text, option_a, option_b, option_c, option_d, correct_option, explanation)
         VALUES ($quiz_id, '$qtext', '$oa', '$ob', '$oc', '$od', '$corr', '$expl')"
    )) $saved_count++;
}

sendResponse([
    'success'      => true,
    'quiz_id'      => $quiz_id,
    'quiz_title'   => $quiz_title_str,
    'saved'        => $saved_count,
    'questions'    => $questions,
    'source'       => $source,
    'source_title' => $source_title,
    'pdfs_read'    => $source === 'lecture_notes' ? 1 : 0,
    'ai_error'     => ($ai_count === 0) ? $ai_error : null,
    'ai_count'     => $ai_count,
    'rule_count'   => $rule_count,
    'material_note' => $material_note,
]);