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

function rb_pdf_extract_stream_text(string $block): string {
    $text = '';
    if (preg_match_all('/BT\s*(.*?)\s*ET/s', $block, $btBlocks) && !empty($btBlocks[1])) {
        $chunks = $btBlocks[1];
    } else {
        $chunks = [$block];
    }
    foreach ($chunks as $chunk) {
        // Literal strings: (Hello world) Tj
        preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj/', $chunk, $tj);
        foreach ($tj[1] as $t) $text .= stripslashes($t) . ' ';

        // Positioned arrays: [ (Hel) -30 (lo) 250 (world) ] TJ
        // The numbers are kerning adjustments in 1/1000 text-space units.
        // Small numbers are normal intra-word kerning; a large negative
        // number (roughly beyond -100) is PDF's usual way of encoding an
        // inter-word GAP with no literal space character — so we insert a
        // space there and nowhere else, instead of always spacing every
        // fragment (which previously fragmented words into "T opic", "p e
        // r c e i v i n g", etc.).
        preg_match_all('/\[(.*?)\]\s*TJ/s', $chunk, $tjArr);
        foreach ($tjArr[1] as $arr) {
            preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)|(-?\d+(?:\.\d+)?)/', $arr, $toks, PREG_SET_ORDER);
            $word = '';
            foreach ($toks as $tok) {
                if (isset($tok[1]) && $tok[1] !== '') {
                    $word .= stripslashes($tok[1]);
                } elseif (isset($tok[2]) && $tok[2] !== '' && floatval($tok[2]) < -100) {
                    $word .= ' ';
                }
            }
            $text .= $word . ' ';
        }

        // Hex-encoded strings: <48656C6C6F> Tj
        preg_match_all('/<([0-9A-Fa-f\s]+)>\s*Tj/', $chunk, $hexTj);
        foreach ($hexTj[1] as $h) {
            $h = preg_replace('/\s+/', '', $h);
            if ($h !== '' && strlen($h) % 2 === 0) {
                $bin = @hex2bin($h);
                if ($bin !== false) $text .= $bin . ' ';
            }
        }
        $text .= "\n";
    }
    return $text;
}

// Rejects binary/garbage "text" (e.g. from an undecodable or image-only PDF)
// so it never reaches the AI prompt or the rule-based sentence extractor.
function rb_looks_like_real_text(string $text): bool {
    if (strlen(trim($text)) < 80) return false;
    $words = preg_split('/\s+/', trim($text));
    if (count($words) < 15) return false;
    $alphaWords = 0;
    foreach ($words as $w) {
        $w = trim($w, ".,;:!?()[]{}\"'-");
        if ($w !== '' && preg_match('/^[A-Za-z]{2,}$/', $w)) $alphaWords++;
    }
    return ($alphaWords / count($words)) >= 0.55;
}

// Tells the lecturer, plainly, whether the attached material has enough
// distinct content for all 9 quiz categories (Core Theory / Practical
// Application / General Aptitude × Beginner / Intermediate / Advanced) to
// draw on completely separate sentences from one another. The rule-based
// engine (used whenever the AI call fails) always guarantees a single
// quiz never repeats a question against itself — but it cannot invent
// content that isn't in the material. If fewer usable sentences exist
// than the 9 categories combined need, some sentence sharing BETWEEN
// categories is mathematically unavoidable, no matter how the selection
// logic is written — the only real fix is longer source material.
function rb_material_length_note(string $extracted_text, int $num_questions, string $source): ?string {
    // Only relevant when the rule-based engine actually generated questions
    // from real sentence content — that's the specific path the 9-way
    // category slicing applies to. ($source is 'fallback' whenever the
    // rule-based engine ran, whether or not material was attached.)
    if ($source !== 'fallback' || !rb_looks_like_real_text($extracted_text)) return null;

    $sentenceCount = count(rb_split_sentences($extracted_text));
    $neededForZeroSharing = 9 * $num_questions;

    if ($sentenceCount >= $neededForZeroSharing) return null; // plenty of material, no note needed

    return "This material has about $sentenceCount usable sentences. Generating "
         . "$num_questions questions across all 9 quiz categories (3 types × 3 difficulties) "
         . "would ideally need around $neededForZeroSharing to keep every category's questions "
         . "completely separate from one another. Questions within THIS quiz will never repeat "
         . "themselves — but with this little material, some sentences may end up shared between "
         . "different categories (e.g. a sentence used in Beginner Theory might also appear in "
         . "Advanced Aptitude). If you want zero sharing across categories too, the only real fix "
         . "is longer source material — more usable sentences per document — since no amount of "
         . "clever indexing can create content that isn't there.";
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
                // ── PDF text extraction ───────────────────────────────────
                // Most PDFs (Word/PowerPoint/Google Docs exports) store page
                // content COMPRESSED (FlateDecode/zlib) inside "stream ...
                // endstream" blocks. Scanning the raw file bytes for "(...)
                // Tj" — as before — finds nothing in that case, because the
                // readable text simply isn't in the raw bytes. We now find
                // each stream, inflate it if it's FlateDecode, and THEN
                // scan the decompressed content for Tj/TJ text commands.
                $raw = @file_get_contents($resolved);
                $pdf_text = '';
                if ($raw) {
                    if (preg_match_all('/(<<[^>]*?>>)\s*stream\r?\n(.*?)\r?\n?endstream/s', $raw, $streams, PREG_SET_ORDER)) {
                        foreach ($streams as $sm) {
                            $dict = $sm[1];
                            $data = $sm[2];

                            if (stripos($dict, '/FlateDecode') !== false) {
                                $decoded = @gzuncompress($data);
                                if ($decoded === false) $decoded = @gzinflate(substr($data, 2));
                                if ($decoded === false) continue; // undecodable, skip this stream
                            } elseif (stripos($dict, '/Filter') !== false) {
                                // Image/other binary filter (DCTDecode, CCITT, etc.) — not text.
                                continue;
                            } else {
                                $decoded = $data; // uncompressed content stream
                            }

                            $pdf_text .= rb_pdf_extract_stream_text($decoded);
                        }
                    }
                    // Safety net: also scan the raw bytes directly, in case
                    // some content wasn't inside a matched stream block.
                    if (strlen(trim($pdf_text)) < 200) {
                        $pdf_text .= rb_pdf_extract_stream_text($raw);
                    }
                }
                $clean = preg_replace('/[^\x20-\x7E\n\r\t]/', ' ', $pdf_text);
                $clean = preg_replace('/\s{2,}/', ' ', $clean);
                $clean = trim($clean);

                if (rb_looks_like_real_text($clean)) {
                    $extracted_text = substr($clean, 0, 6000);
                    $source = 'lecture_notes';
                }
                // If it doesn't look like real text (e.g. a scanned/image-only
                // PDF with no embedded text layer), $extracted_text stays ''
                // rather than being filled with garbage — the generator then
                // falls back to the general-knowledge path instead of
                // building questions out of noise.

            } elseif ($mat_row['type'] === 'word') {
                // ── DOCX: extract plain text from word/document.xml ──────
                $zip = new ZipArchive();
                if ($zip->open($resolved) === true) {
                    $xml = $zip->getFromName('word/document.xml');
                    $zip->close();
                    if ($xml) {
                        $xml_clean    = strip_tags(str_replace(['</w:p>', '</w:r>'], ["\n", ' '], $xml));
                        $clean = trim($xml_clean);
                        if (rb_looks_like_real_text($clean)) {
                            $extracted_text = substr($clean, 0, 6000);
                            $source = 'lecture_notes';
                        }
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
$ai_error  = null;
if (defined('GROQ_API_KEY') && !empty(GROQ_API_KEY)) {
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            // llama-3.1-8b-instant was retired by Groq on 16 Aug 2026 —
            // openai/gpt-oss-20b is Groq's recommended replacement.
            'model'       => 'openai/gpt-oss-20b',
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
    $ai_raw    = curl_exec($ch);
    $curl_errno = curl_errno($ch);
    $curl_err   = curl_error($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curl_errno) {
        $ai_error = "cURL error: $curl_err";
    } elseif ($http_code !== 200) {
        $ai_error = "Groq API returned HTTP $http_code: " . substr($ai_raw ?: '', 0, 300);
    } else {
        $ai_data = json_decode($ai_raw, true);
        $ai_text = $ai_data['choices'][0]['message']['content'] ?? '';
        if ($ai_text === '') {
            $ai_error = 'Groq API returned an empty response.';
        } elseif (preg_match('/\[.*\]/s', $ai_text, $matches)) {
            $questions = json_decode($matches[0], true);
            if (!is_array($questions) || empty($questions)) {
                $ai_error = 'Could not parse AI response as a question list.';
            }
        } else {
            $ai_error = 'AI response did not contain a JSON question array.';
        }
    }
} else {
    $ai_error = 'GROQ_API_KEY is not configured.';
}

// ── Rule-based fallback helpers ─────────────────────────────────────────
// These build fallback questions FROM the extracted lecture content when
// possible (cloze + "which statement is correct" questions drawn from real
// sentences), so a Groq outage no longer means every fallback quiz is the
// same five generic templates. Only when there is no usable material text
// at all do we fall back to varied generic stems.

function rb_split_sentences(string $text): array {
    $text  = preg_replace('/\s+/', ' ', $text);
    $parts = preg_split('/(?<=[.!?])\s+/', $text);
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p, " \t\n\r\0\x0B-•·");
        if (strlen($p) < 40 || strlen($p) > 220) continue;
        if (str_word_count($p) < 6) continue;
        $out[] = $p;
    }
    return array_values(array_unique($out));
}

function rb_stopwords(): array {
    return array_flip(['the','and','for','are','with','that','this','from','have','has','had',
        'was','were','will','shall','can','could','would','should','not','but','you','your',
        'their','there','which','when','where','what','while','into','onto','about','these',
        'those','then','than','also','such','some','any','all','each','other','more','most',
        'over','under','between','within','without','because','however','thus','therefore',
        'been','being','they','them','its','a','an','of','in','on','to','is','as','by','be','or','at']);
}

function rb_key_terms(array $sentences): array {
    $stop = rb_stopwords();
    $capPhrases = [];
    $termFreq   = [];
    foreach ($sentences as $s) {
        if (preg_match_all('/\b[A-Z][a-zA-Z]{2,}(?:\s+[A-Z][a-zA-Z]{2,}){0,2}\b/', $s, $m)) {
            foreach ($m[0] as $cp) {
                if (strlen($cp) >= 5) $capPhrases[$cp] = ($capPhrases[$cp] ?? 0) + 1;
            }
        }
        foreach (preg_split('/\W+/', $s) as $w) {
            $lw = strtolower($w);
            if (strlen($w) >= 5 && $w !== '' && ctype_alpha($w) && !isset($stop[$lw])) {
                $termFreq[$w] = ($termFreq[$w] ?? 0) + 1;
            }
        }
    }
    arsort($capPhrases);
    arsort($termFreq);
    $pool = array_keys($capPhrases);
    foreach (array_keys($termFreq) as $w) {
        if (!in_array($w, $pool, true)) $pool[] = $w;
    }
    return array_slice(array_values(array_unique($pool)), 0, 40);
}

function rb_find_term_in_sentence(string $sentence, array $pool): ?string {
    usort($pool, fn($a, $b) => strlen($b) - strlen($a));
    foreach ($pool as $term) {
        if (stripos($sentence, $term) !== false) return $term;
    }
    return null;
}

function rb_negate(string $sentence): string {
    foreach (['is', 'are', 'can', 'was', 'were'] as $verb) {
        if (preg_match('/\b' . $verb . '\b/i', $sentence)) {
            $neg = $verb === 'can' ? 'cannot' : $verb . ' not';
            return preg_replace('/\b' . $verb . '\b/i', $neg, $sentence, 1);
        }
    }
    return 'It is incorrect to say that ' . lcfirst($sentence);
}

function rb_swap_number(string $sentence): ?string {
    if (preg_match('/\d+/', $sentence, $m)) {
        $num = intval($m[0]);
        $new = $num + max(1, intval($num * 0.3) + 1);
        return preg_replace('/\d+/', (string)$new, $sentence, 1);
    }
    return null;
}

function rb_swap_term(string $sentence, ?string $term, array $pool): ?string {
    if (!$term) return null;
    $others = array_values(array_filter($pool, fn($t) => strcasecmp($t, $term) !== 0));
    if (empty($others)) return null;
    shuffle($others);
    return preg_replace('/\b' . preg_quote($term, '/') . '\b/i', $others[0], $sentence, 1);
}

// Shuffles 4 options while returning which letter now holds the correct one.
function rb_shuffle_options(array $optionsArr): array {
    $correctValue = $optionsArr[0];
    shuffle($optionsArr);
    $letters = ['A', 'B', 'C', 'D'];
    $correctLetter = 'A';
    foreach ($optionsArr as $idx => $val) {
        if ($val === $correctValue) { $correctLetter = $letters[$idx]; break; }
    }
    return [$optionsArr, $correctLetter];
}

function rb_find_terms_in_sentence(string $sentence, array $pool, int $limit = 2): array {
    usort($pool, fn($a, $b) => strlen($b) - strlen($a));
    $found = [];
    foreach ($pool as $term) {
        if (stripos($sentence, $term) !== false) {
            $found[] = $term;
            if (count($found) >= $limit) break;
        }
    }
    return $found;
}

function rb_other_sentences(array $sentences, int $excludeIdx, int $count, int $offset = 0): array {
    $n = count($sentences);
    $out = [];
    for ($k = 1; $k <= $n && count($out) < $count; $k++) {
        $idx = ($excludeIdx + $offset + $k) % $n;
        if ($idx === $excludeIdx) continue;
        $s = $sentences[$idx];
        if (!in_array($s, $out, true)) $out[] = $s;
    }
    return $out;
}

// Picks which question TYPES to rotate through, and in what order, based on
// quiz type AND difficulty — this is what makes Core Theory / Practical
// Application / General Aptitude, and Beginner / Intermediate / Advanced,
// actually look different from each other instead of all producing the
// same cloze-then-statement pattern regardless of what was selected.
// Rotation of question TYPES per quiz_type + difficulty. Built as a Latin
// square PER DIFFICULTY ROW: at every question position, theory/practical/
// aptitude each use a DIFFERENT type from one another (not just a different
// order of the same set) — otherwise, even with different source sentences,
// three categories opening with the same type ("incorrect_statement" for
// example) read as stylistically the same quiz. Advanced also drops the
// simple "cloze" type entirely (recall-only) so it reads distinctly harder
// than Beginner/Intermediate, which still use it.
function rb_type_rotation(string $quiz_type, string $difficulty): array {
    $difficulty = strtolower($difficulty);
    $sets = [
        'theory' => [
            'beginner'     => ['cloze', 'correct_statement', 'term_id'],
            'intermediate' => ['correct_statement', 'term_id', 'incorrect_statement'],
            'advanced'     => ['incorrect_statement', 'term_id', 'correct_statement'],
        ],
        'practical' => [
            'beginner'     => ['term_id', 'cloze', 'correct_statement'],
            'intermediate' => ['term_id', 'incorrect_statement', 'correct_statement'],
            'advanced'     => ['term_id', 'correct_statement', 'incorrect_statement'],
        ],
        'aptitude' => [
            'beginner'     => ['correct_statement', 'term_id', 'cloze'],
            'intermediate' => ['incorrect_statement', 'correct_statement', 'term_id'],
            'advanced'     => ['correct_statement', 'incorrect_statement', 'term_id'],
        ],
    ];
    $forType = $sets[$quiz_type] ?? $sets['theory'];
    return $forType[$difficulty] ?? $forType['intermediate'];
}

// Wraps the question stem so the same underlying fact reads as a recall
// question (theory), an applied scenario (practical), or a reasoning
// question (aptitude) — same source sentence, different framing.
function rb_frame_question(string $quiz_type, string $base): string {
    return match ($quiz_type) {
        'practical' => "In a practical, real-world use of this topic — $base",
        'aptitude'  => "Reasoning from the material — $base",
        default     => $base,
    };
}

function generateRuleBasedQuestions(string $extracted_text, string $topic, string $context_label, string $quiz_type, string $difficulty, int $num_questions): array {
    $sentences = rb_split_sentences($extracted_text);
    $difficulty = strtolower($difficulty) ?: 'intermediate';
    if (!in_array($difficulty, ['beginner', 'intermediate', 'advanced'])) $difficulty = 'intermediate';

    // Order the sentence pool by difficulty so each level actually draws on
    // different material: beginner favours the shorter/simpler sentences,
    // advanced favours the longer/denser ones, intermediate stays natural.
    if ($difficulty === 'beginner') {
        usort($sentences, fn($a, $b) => strlen($a) <=> strlen($b));
    } elseif ($difficulty === 'advanced') {
        usort($sentences, fn($a, $b) => strlen($b) <=> strlen($a));
    }

    $questions = [];
    $usedTexts = [];

    if (!empty($sentences)) {
        $pool  = rb_key_terms($sentences);
        $n     = count($sentences);
        $types = rb_type_rotation($quiz_type, $difficulty);

        // Give each of the 9 (quiz_type × difficulty) combinations its own
        // non-overlapping ~1/9th slice of the sentence pool to start from.
        // Slicing only by difficulty (as before) meant e.g. Beginner
        // Practical and Beginner Aptitude both started at sentence #0 —
        // sharing types at some question positions then produced literally
        // the same question text in two different categories.
        $comboIndex = [
            'theory'    => ['beginner' => 0, 'intermediate' => 1, 'advanced' => 2],
            'practical' => ['beginner' => 3, 'intermediate' => 4, 'advanced' => 5],
            'aptitude'  => ['beginner' => 6, 'intermediate' => 7, 'advanced' => 8],
        ];
        $idx = $comboIndex[$quiz_type][$difficulty] ?? 0;

        // Give each combo a different STARTING point in the sentence pool
        // (spread roughly a ninth of the material apart) so categories
        // generally draw on different content — but, unlike an earlier
        // version, do NOT lock each category into a tiny fixed-width block.
        // Locking to a block meant that once a quiz needed more questions
        // than its block had sentences, it was forced to reuse the SAME
        // sentence (just reworded) — which still reads as a repeated
        // question. Walking forward from the offset across the FULL pool
        // instead guarantees every question in one quiz uses a genuinely
        // different sentence, as long as num_questions <= the number of
        // sentences available — true for realistic material. (If material
        // is extremely short — fewer usable sentences than 9 × num_questions
        // combined — some sentence sharing between DIFFERENT categories
        // becomes mathematically unavoidable; there simply isn't enough
        // distinct content to go around. When that happens we still favour
        // never repeating a sentence WITHIN one category's own quiz.)
        $spacing     = max(1, intdiv($n, 9));
        $startOffset = ($idx * $spacing) % $n;

        for ($i = 0; $i < $num_questions; $i++) {
            // Only kicks in on the rare case num_questions > n (the
            // material genuinely has fewer distinct sentences than
            // questions requested) — forces sentence AND type variation
            // together once we've cycled through every sentence once.
            $globalLap = intdiv($i, $n);
            $sentIdx   = ($startOffset + $i) % $n;
            $type      = $types[($i + $globalLap) % count($types)];
            $sentence  = $sentences[$sentIdx];
            $terms     = rb_find_terms_in_sentence($sentence, $pool, 2);
            $term     = $terms[0] ?? null;

            if (($type === 'cloze' || $type === 'term_id') && !$term) $type = 'correct_statement';

            if ($type === 'cloze') {
                // On a repeat lap through this block, blank out the OTHER
                // key term in the sentence (if there is one) instead of the
                // same one — so a forced repeat still reads as a different
                // question, not a verbatim rerun.
                $clozeTerm = ($globalLap % 2 === 1 && !empty($terms[1])) ? $terms[1] : $term;
                $blanked = preg_replace('/\b' . preg_quote($clozeTerm, '/') . '\b/i', '_____', $sentence, 1);
                $others  = array_values(array_filter($pool, fn($t) => strcasecmp($t, $clozeTerm) !== 0));
                shuffle($others);
                $distractors = array_slice($others, 0, 3);
                while (count($distractors) < 3) $distractors[] = 'None of the other options';

                $qtext = rb_frame_question($quiz_type, "complete the statement about $topic: \"$blanked\"");
                $qtext = ucfirst($qtext);
                [$optionsArr, $correctLetter] = rb_shuffle_options(array_merge([$clozeTerm], $distractors));
                $explanation = "From the lecture material: \"$sentence\"";

            } elseif ($type === 'term_id') {
                // Same idea in reverse: alternate which term is described
                // vs. which is the answer, based on the repeat lap.
                $term2 = ($globalLap % 2 === 1 && !empty($terms[1])) ? $term : ($terms[1] ?? $term);
                $described = preg_replace('/\b' . preg_quote($term2, '/') . '\b/i', 'this concept', $sentence, 1);
                $others  = array_values(array_filter($pool, fn($t) => strcasecmp($t, $term2) !== 0));
                shuffle($others);
                $distractors = array_slice($others, 0, 3);
                while (count($distractors) < 3) $distractors[] = 'None of the other options';

                $qtext = rb_frame_question($quiz_type, "which term is \"this concept\" referring to here: \"$described\"?");
                $qtext = ucfirst($qtext);
                [$optionsArr, $correctLetter] = rb_shuffle_options(array_merge([$term2], $distractors));
                $explanation = "From the lecture material: \"$sentence\"";

            } elseif ($type === 'incorrect_statement') {
                $swapTerm = rb_swap_term($sentence, $term, $pool);
                $numSwap  = rb_swap_number($sentence);
                // Rotate which "make it false" strategy we use across repeat
                // laps (term swap / number swap / negation), so a forced
                // repeat on short material still alters the sentence a
                // different way each time instead of the identical edit.
                $strategies = array_values(array_filter([$swapTerm, $numSwap, rb_negate($sentence)]));
                if (empty($strategies)) $strategies = [rb_negate($sentence)];
                $falseVariant = $difficulty === 'beginner'
                    ? rb_negate($sentence)
                    : $strategies[$globalLap % count($strategies)];
                $trueDistractors = rb_other_sentences($sentences, $sentIdx, 3, $i);
                while (count($trueDistractors) < 3) $trueDistractors[] = $sentence;

                $qtext = rb_frame_question($quiz_type, "which of the following statements about $topic is NOT correct, based on the lecture material?");
                $qtext = ucfirst($qtext);
                [$optionsArr, $correctLetter] = rb_shuffle_options(array_merge([$falseVariant], $trueDistractors));
                $explanation = "The other three statements are taken directly from the lecture material for $context_label; this one has been altered.";

            } else { // correct_statement
                $variants = [];
                $swapTerm = rb_swap_term($sentence, $term, $pool);
                if ($swapTerm) $variants[] = $swapTerm;
                $numSwap = rb_swap_number($sentence);
                if ($numSwap) $variants[] = $numSwap;
                $variants[] = rb_negate($sentence);
                foreach (rb_other_sentences($sentences, $sentIdx, 2, $i) as $os) $variants[] = $os;

                $variants = array_values(array_unique(array_filter($variants,
                    fn($v) => strcasecmp(trim($v), trim($sentence)) !== 0)));
                shuffle($variants);
                $variants = array_slice($variants, 0, 3);
                while (count($variants) < 3) $variants[] = 'This statement does not appear in the lecture material.';

                $qtext = rb_frame_question($quiz_type, "which statement about $topic is correct, based on the lecture material?");
                $qtext = ucfirst($qtext);
                [$optionsArr, $correctLetter] = rb_shuffle_options(array_merge([$sentence], $variants));
                $explanation = "The correct option is taken directly from the lecture material for $context_label.";
            }

            // Guard against an exact repeat slipping through (e.g. very short material).
            $key = md5($qtext);
            if (isset($usedTexts[$key])) {
                $qtext .= " [Q" . ($i + 1) . "]";
            }
            $usedTexts[$key] = true;

            $questions[] = [
                'question_text'  => $qtext,
                'option_a'       => $optionsArr[0],
                'option_b'       => $optionsArr[1],
                'option_c'       => $optionsArr[2],
                'option_d'       => $optionsArr[3],
                'correct_option' => $correctLetter,
                'explanation'    => $explanation,
            ];
        }
        return $questions;
    }

    // ── No usable material text at all: varied generic stems, still
    //    randomised so it isn't always option A and isn't five repeats. ──
    $stemsByType = [
        'theory' => [
            "What is the core definition of %s?",
            "Which statement best explains %s?",
            "What is a defining characteristic of %s?",
            "Which of the following best describes the purpose of %s?",
            "What foundational concept underlies %s?",
        ],
        'practical' => [
            "In a real-world scenario, how would %s most likely be applied?",
            "Which situation best demonstrates the practical use of %s?",
            "If a team needed to implement %s, what would be the first step?",
            "Which outcome would you expect when applying %s correctly?",
            "What is a common challenge when applying %s in practice?",
        ],
        'aptitude' => [
            "Given the topic %s, which conclusion logically follows?",
            "Which pattern is most consistent with the principles of %s?",
            "If two approaches to %s conflict, which factor should decide between them?",
            "Which of these is the most logical next step after %s?",
            "Based on %s, which inference is best supported?",
        ],
    ];
    $stems = $stemsByType[$quiz_type] ?? $stemsByType['theory'];

    $correctPool = [
        "It directly relates to the core principles of $topic within $context_label.",
        "It reflects the standard approach taught for $topic.",
        "It aligns with the accepted definition used in $context_label.",
    ];
    $wrongPool = [
        "It describes a concept unrelated to $topic.",
        "It contradicts the standard approach to $topic.",
        "It applies to a different unit, not $context_label.",
        "It is a common misconception about $topic.",
        "It confuses $topic with a similar but distinct idea.",
        "It reverses the correct order of the process.",
    ];

    $difficultyTag = match ($difficulty) {
        'beginner' => 'basic recall',
        'advanced' => 'applied, multi-step reasoning',
        default    => 'standard',
    };

    for ($i = 0; $i < $num_questions; $i++) {
        $stem  = $stems[$i % count($stems)];
        $qtext = sprintf($stem, $topic) . " (" . $context_label . " — $difficultyTag)";

        $correct = $correctPool[$i % count($correctPool)];
        $wrongShuffled = $wrongPool;
        shuffle($wrongShuffled);
        $wrongs = array_slice($wrongShuffled, 0, 3);

        [$optionsArr, $correctLetter] = rb_shuffle_options(array_merge([$correct], $wrongs));

        $questions[] = [
            'question_text'  => $qtext,
            'option_a'       => $optionsArr[0],
            'option_b'       => $optionsArr[1],
            'option_c'       => $optionsArr[2],
            'option_d'       => $optionsArr[3],
            'correct_option' => $correctLetter,
            'explanation'    => "Generated by the rule-based fallback engine — no lecture material or AI response was available. Please review before publishing.",
        ];
    }
    return $questions;
}

// ── Rule-based fallback ───────────────────────────────────────────────────
if (!is_array($questions) || empty($questions)) {
    $source    = 'fallback';
    $questions = generateRuleBasedQuestions($extracted_text, $topic, $context_label, $quiz_type, $difficulty, $num_questions);
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
    'ai_error'     => $source === 'fallback' ? $ai_error : null,
    'material_note' => rb_material_length_note($extracted_text, $num_questions, $source),
]);