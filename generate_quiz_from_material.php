<?php
include 'config.php';
checkRole('lecturer');

// Use the key defined in your ai_analysis.php
define('ANTHROPIC_API_KEY', 'YOUR_ANTHROPIC_API_KEY_HERE');

if (isset($_GET['course_id'])) {
    $course_id = intval($_GET['course_id']);
    
    // Get latest PDF material
    $material_q = mysqli_query($conn, "SELECT file_path, title FROM materials 
                                       WHERE course_id = $course_id AND type = 'pdf' 
                                       ORDER BY id DESC LIMIT 1");
    $material = mysqli_fetch_assoc($material_q);

    if (!$material) die("Error: No PDF material found. Upload course notes first.");

    // Simple text extraction (Assuming server has pdftotext)
    $text = shell_exec("pdftotext " . escapeshellarg($material['file_path']) . " -");
    if (empty($text)) $text = "Context: " . $material['title'];

    $prompt = "As an AI Academic Examiner, generate 5 MCQs based ONLY on this text: \"$text\". 
               Return ONLY a JSON object: {\"title\":\"Quiz\", \"questions\":[{\"text\":\"?\",\"a\":\"\",\"b\":\"\",\"c\":\"\",\"d\":\"\",\"correct\":\"a\"}]}";

    $payload = json_encode([
        'model' => 'claude-3-sonnet-20240229',
        'max_tokens' => 2000,
        'messages' => [['role' => 'user', 'content' => $prompt]]
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01']
    ]);

    $response = json_decode(curl_exec($ch), true);
    $ai_json = json_decode($response['content'][0]['text'], true);

    if ($ai_json) {
        $title = mysqli_real_escape_string($conn, $ai_json['title']);
        mysqli_query($conn, "INSERT INTO quizzes (course_id, title) VALUES ($course_id, '$title')");
        $quiz_id = mysqli_insert_id($conn);

        foreach ($ai_json['questions'] as $q) {
            mysqli_query($conn, "INSERT INTO questions (quiz_id, question_text, option_a, option_b, option_c, option_d, correct_answer, skill_tag) 
                VALUES ($quiz_id, '{$q['text']}', '{$q['a']}', '{$q['b']}', '{$q['c']}', '{$q['d']}', '{$q['correct']}', 'Core Theory')");
        }
        header("Location: lecturer_dashboard.php?status=success&msg=QuizGenerated");
    }
}
?>