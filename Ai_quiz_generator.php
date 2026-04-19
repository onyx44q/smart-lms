<?php
/**
 * ai_quiz_generator.php — SmartLMS Quiz Generator (Updated for Practical & Aptitude)
 */

if (ob_get_level() == 0) ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0); 

// --- 1. FATAL ERROR CATCHER ---
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error'   => 'PHP Fatal Error: ' . $error['message'],
            'line'    => $error['line']
        ]);
        exit;
    }
});

// --- 2. CONFIG & AUTH ---
if (!file_exists('config.php')) {
    header('Content-Type: application/json');
    die(json_encode(['success' => false, 'error' => 'config.php missing']));
}
include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

function sendResponse($data) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

if (!isset($_SESSION['user_id'])) sendResponse(['success' => false, 'error' => 'Unauthorised']);

// --- 3. INPUT VALIDATION ---
$lecturer_id   = (int)$_SESSION['user_id'];
$course_id     = (int)($_POST['course_id'] ?? 0);
$topic         = trim($_POST['topic'] ?? '');
$num_questions = max(3, min(10, (int)($_POST['num_questions'] ?? 5)));
$quiz_type     = trim($_POST['quiz_type'] ?? 'theory'); // Options: theory, practical, aptitude

if ($course_id === 0 || empty($topic)) {
    sendResponse(['success' => false, 'error' => 'Course and Topic are required.']);
}

// Check database connection
if (!$conn) {
    sendResponse(['success' => false, 'error' => 'Database connection failed.']);
}

// --- 4. FETCH COURSE INFO ---
// Checks BOTH: courses.lecturer_id AND users.course_id
$course_query = mysqli_query($conn, 
    "SELECT title FROM courses 
     WHERE id = $course_id 
       AND (lecturer_id = $lecturer_id 
            OR id = (SELECT course_id FROM users WHERE id = $lecturer_id AND course_id IS NOT NULL LIMIT 1))"
);
$course = mysqli_fetch_assoc($course_query);
if (!$course) sendResponse(['success' => false, 'error' => 'Course not found.']);

// --- 5. PDF EXTRACTION ---
// Checks both course_id or lecturer_id/course_id combo
$materials_res = mysqli_query($conn, 
    "SELECT file_path FROM materials 
     WHERE (course_id = $course_id 
        OR (lecturer_id = $lecturer_id AND course_id = $course_id))
     AND type = 'pdf' LIMIT 3"
);
$context_text = "";
while ($mat = mysqli_fetch_assoc($materials_res)) {
    $full_path = $_SERVER['DOCUMENT_ROOT'] . '/' . $mat['file_path'];
    if (file_exists($full_path)) {
        $c = @file_get_contents($full_path);
        preg_match_all("/\((.*?)\) Tj/", $c, $m);
        $context_text .= preg_replace('/[^A-Za-z0-9\s]/', '', implode(" ", $m[1])) . " ";
    }
}

// --- 6. AI GENERATION (GROQ) ---
$questions = null;
if (defined('GROQ_API_KEY') && !empty(GROQ_API_KEY)) {
    
    // BRANCH PROMPT BASED ON QUIZ TYPE
    if ($quiz_type === 'practical') {
        // Practical: Focuses on troubleshooting, scenarios, and hands-on application
        $prompt = "Generate $num_questions PRACTICAL MCQs for '{$course['title']}' on the topic '$topic'. 
        Focus on real-world scenarios, problem-solving, and implementation tasks. 
        Return ONLY a JSON array. Format: [{\"question_text\":\"...\",\"option_a\":\"...\",\"option_b\":\"...\",\"option_c\":\"...\",\"option_d\":\"...\",\"correct_option\":\"A\",\"explanation\":\"...\"}]";
    } elseif ($quiz_type === 'aptitude') {
        // Aptitude: Focuses on logical reasoning and analytical skills within the course context
        $prompt = "Generate $num_questions GENERAL APTITUDE MCQs related to '{$course['title']}'. 
        Focus on logical reasoning, quantitative analysis, and pattern recognition related to the field of $topic. 
        Return ONLY a JSON array. Format: [{\"question_text\":\"...\",\"option_a\":\"...\",\"option_b\":\"...\",\"option_c\":\"...\",\"option_d\":\"...\",\"correct_option\":\"A\",\"explanation\":\"...\"}]";
    } else {
        // Theory: Keep exactly as originally provided
        $prompt = "Generate $num_questions MCQs for '{$course['title']}' on '$topic'. Return ONLY JSON array. Format: [{\"question_text\":\"...\",\"option_a\":\"...\",\"option_b\":\"...\",\"option_c\":\"...\",\"option_d\":\"...\",\"correct_option\":\"A\",\"explanation\":\"...\"}]";
    }
    
    $ch = curl_init("https://api.groq.com/openai/v1/chat/completions");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            "model" => "llama-3.1-8b-instant",
            "messages" => [["role" => "user", "content" => $prompt]],
            "temperature" => 0.2
        ]),
        CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Authorization: Bearer " . GROQ_API_KEY],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 25
    ]);
    $res = curl_exec($ch);
    $ai_data = json_decode($res, true);
    $content = $ai_data['choices'][0]['message']['content'] ?? '';
    if (preg_match('/\[.*\]/s', $content, $matches)) {
        $questions = json_decode($matches[0], true);
    }
}

// --- 7. RULE-BASED FALLBACK ---
if (!is_array($questions) || empty($questions)) {
    $questions = [];
    for ($i = 1; $i <= $num_questions; $i++) {
        $questions[] = [
            "question_text" => "[$topic] " . ucfirst($quiz_type) . " Review $i: Discuss the role of $topic within " . $course['title'],
            "option_a" => "Core principle of $topic",
            "option_b" => "Secondary theory",
            "option_c" => "Irrelevant concept",
            "option_d" => "None of the above",
            "correct_option" => "A",
            "explanation" => "Automated fallback question for $quiz_type mode."
        ];
    }
}

// --- 8. SAVE TO DATABASE ---
$quiz_title = mysqli_real_escape_string($conn, $course['title'] . " - " . $topic . " (" . ucfirst($quiz_type) . ")");
$header_query = "INSERT INTO quizzes (course_id, title, topic, created_by, created_at) VALUES ($course_id, '$quiz_title', '".mysqli_real_escape_string($conn, $topic)."', $lecturer_id, NOW())";

if (mysqli_query($conn, $header_query)) {
    $quiz_id = mysqli_insert_id($conn);
    $saved_count = 0;
    foreach ($questions as $q) {
        $sql = "INSERT INTO questions (quiz_id, question_text, option_a, option_b, option_c, option_d, correct_option, explanation) 
                VALUES ($quiz_id, 
                '".mysqli_real_escape_string($conn, $q['question_text'])."', 
                '".mysqli_real_escape_string($conn, $q['option_a'])."', 
                '".mysqli_real_escape_string($conn, $q['option_b'])."', 
                '".mysqli_real_escape_string($conn, $q['option_c'])."', 
                '".mysqli_real_escape_string($conn, $q['option_d'])."', 
                '".strtoupper($q['correct_option'] ?? 'A')."', 
                '".mysqli_real_escape_string($conn, $q['explanation'] ?? '')."')";
        if (mysqli_query($conn, $sql)) $saved_count++;
    }
    
    sendResponse([
        'success' => true,
        'quiz_id' => $quiz_id,
        'count' => $saved_count,
        'questions' => $questions 
    ]);
} else {
    sendResponse(['success' => false, 'error' => 'Database save failed: ' . mysqli_error($conn)]);
}
?>