<?php
/**
 * Ai_quiz_generator.php
 * Generates MCQ quizzes from:
 *   (a) a specific uploaded material selected by the lecturer, OR
 *   (b) a manually typed topic
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
            if ($mat_row['type'] === 'pdf') {
                // ── PDF text extraction (raw binary approach) ─────────────
                $raw = @file_get_contents($resolved);
                if ($raw) {
                    // Method 1: Extract text from PDF stream objects
                    preg_match_all('/BT\s*(.*?)\s*ET/s', $raw, $bt_blocks);
                    $pdf_text = '';
                    foreach ($bt_blocks[1] as $block) {
                        preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj/', $block, $tj);
                        foreach ($tj[1] as $t) {
                            $pdf_text .= stripslashes($t) . ' ';
                        }
                        // Also catch TJ arrays
                        preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $tjArr);
                        foreach ($tjArr[1] as $arr) {
                            preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/', $arr, $parts);
                            foreach ($parts[1] as $p) {
                                $pdf_text .= stripslashes($p) . ' ';
                            }
                        }
                    }
                    // Method 2: fallback — grep all string tokens
                    if (strlen(trim($pdf_text)) < 100) {
                        preg_match_all('/\(((?:[^()\\\\]|\\\\.){2,})\)\s*Tj/', $raw, $m2);
                        $pdf_text = implode(' ', array_map('stripslashes', $m2[1]));
                    }
                    // Clean and limit
                    $extracted_text = preg_replace('/[^\x20-\x7E\n\r\t]/', ' ', $pdf_text);
                    $extracted_text = preg_replace('/\s{3,}/', ' ', $extracted_text);
                    $extracted_text = substr(trim($extracted_text), 0, 6000);
                    if (strlen($extracted_text) >= 80) $source = 'lecture_notes';
                }

            } elseif ($mat_row['type'] === 'word') {
                // ── DOCX: extract plain text from word/document.xml ──────
                $zip = new ZipArchive();
                if ($zip->open($resolved) === true) {
                    $xml = $zip->getFromName('word/document.xml');
                    $zip->close();
                    if ($xml) {
                        $xml_clean    = strip_tags(str_replace(['</w:p>', '</w:r>'], ["\n", ' '], $xml));
                        $extracted_text = substr(trim($xml_clean), 0, 6000);
                        if (strlen($extracted_text) >= 80) $source = 'lecture_notes';
                    }
                }
            }
        }

        if (empty($topic)) $topic = $source_title;
    }
}

if (empty($topic)) $topic = $context_label;

// ── FIX 1: Generate clear, dynamic instructions for Type and Difficulty ──
$type_instruction = match($quiz_type) {
    'practical' => "Design scenario-based questions. The student must apply the extracted concepts to solve a real-world problem or hypothetical situation. Avoid simple definitions.",
    'aptitude'  => "Design logical reasoning questions. Test the student's analytical skills by making them deduce outcomes, identify patterns, or find logical conclusions based on the provided material.",
    default     => "Design theoretical questions. Focus on core knowledge, key definitions, and assessing the student's direct factual understanding of the material."
};

$diff_instruction = match(strtolower($difficulty)) {
    'beginner' => "Use simple language. Focus on surface-level understanding, fundamental concepts, and obvious facts.",
    'advanced' => "Use complex phrasing. Include plausible distractors, require multi-step reasoning, and focus on nuanced details or exceptions in the text.",
    default    => "Aim for an intermediate level with balanced clarity and a moderate challenge."
};

// ── Build AI prompt ───────────────────────────────────────────────────────
$has_content = strlen($extracted_text) >= 80;

if ($has_content) {
    // FIX 2: Integrate the specific instructions into the extraction prompt
    $content_snippet = substr($extracted_text, 0, 4000);
    $prompt = "You are an expert academic quiz creator. Using ONLY the following lecture note content, generate $num_questions multiple-choice questions (MCQs) for the topic '$topic'.

LECTURE CONTENT:
\"\"\"
$content_snippet
\"\"\"

CRITICAL REQUIREMENTS:
1. Type: $quiz_type. $type_instruction
2. Difficulty: $difficulty. $diff_instruction
3. Questions must be directly based on the content above, but rephrased to fit the required type and difficulty. Ensure questions are distinct and not repetitive.
4. Each question must have exactly 4 options (A, B, C, D).
5. Only ONE correct answer per question.
6. Include a brief explanation for why the correct answer is right.
7. Do NOT add any conversational text outside the JSON array.

Return ONLY a valid JSON array in this exact format:
[{\"question_text\":\"...\",\"option_a\":\"...\",\"option_b\":\"...\",\"option_c\":\"...\",\"option_d\":\"...\",\"correct_option\":\"A\",\"explanation\":\"...\"}]";

} else {
    // Also apply the specific instructions to the general knowledge prompts
    $prompt = "You are an expert academic quiz creator. Generate $num_questions multiple-choice questions (MCQs) for the course '$context_label' on the specific topic '$topic'.

CRITICAL REQUIREMENTS:
1. Type: $quiz_type. $type_instruction
2. Difficulty: $difficulty. $diff_instruction
3. Ensure questions are distinct from one another and cover different facets of the topic.
4. Each question must have exactly 4 options (A, B, C, D).
5. Only ONE correct answer per question.
6. Include a brief explanation for why the correct answer is right.

Return ONLY a valid JSON array in this exact format:
[{\"question_text\":\"...\",\"option_a\":\"...\",\"option_b\":\"...\",\"option_c\":\"...\",\"option_d\":\"...\",\"correct_option\":\"A\",\"explanation\":\"...\"}]";
}

// ── Call GROQ AI ──────────────────────────────────────────────────────────
$questions = null;
if (defined('GROQ_API_KEY') && !empty(GROQ_API_KEY)) {
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'       => 'llama-3.1-8b-instant',
            'messages'    => [['role' => 'user', 'content' => $prompt]],
            // FIX 3: Increased temperature from 0.3 to 0.7 for better variety
            'temperature' => 0.7, 
            'max_tokens'  => 3000,
        ]),
        CURLOPT_HTTPHEADER  => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . GROQ_API_KEY,
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $ai_raw  = curl_exec($ch);
    curl_close($ch);
    $ai_data = json_decode($ai_raw, true);
    $ai_text = $ai_data['choices'][0]['message']['content'] ?? '';
    if (preg_match('/\[.*\]/s', $ai_text, $matches)) {
        $questions = json_decode($matches[0], true);
    }
}

// ── Rule-based fallback ───────────────────────────────────────────────────
if (!is_array($questions) || empty($questions)) {
    $source    = 'fallback';
    $questions = [];
    
    // FIX 4: Create a variety of fallback templates instead of one static string
    $fallback_templates = [
        "What is the primary definition of %s in the context of %s?",
        "Which of the following is a key application of %s?",
        "Identify the main principle behind %s.",
        "How does %s impact the overall process in %s?",
        "Which scenario best demonstrates the concept of %s?"
    ];

    for ($i = 1; $i <= $num_questions; $i++) {
        // Rotate through templates to prevent identical questions
        $template = $fallback_templates[($i - 1) % count($fallback_templates)];
        // Use sprintf to safely insert the topic and context
        $question_text = sprintf($template, $topic, $context_label);
        
        $questions[] = [
            'question_text'  => "[$topic] Q$i: " . $question_text,
            'option_a'       => "Primary principle of $topic",
            'option_b'       => "Secondary theory",
            'option_c'       => "Unrelated concept",
            'option_d'       => "None of the above",
            'correct_option' => 'A',
            'explanation'    => "Fallback question — AI generation failed or timed out. Please try again.",
        ];
    }
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
]);