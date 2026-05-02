<?php
/**
 * plagiarism_engine.php — SmartLMS Rule-Based Plagiarism Detection Engine
 *
 * 100% PHP, zero external API calls.
 *
 * TWO analysis tracks:
 *   1. Student-to-student similarity  — trigram Jaccard index against every
 *      other submission on the same assignment.
 *   2. Internet/generic-content score — 8 heuristic rules that detect patterns
 *      typical of copied web, Wikipedia, or AI-generated academic text.
 *
 * Overall score = 60 % × peer score  +  40 % × internet heuristic score
 *
 * Verdict bands:
 *   overall ≥ 65  → HIGH RISK
 *   overall ≥ 35  → MEDIUM RISK
 *   otherwise     → LOW RISK
 *
 * Entry point: runPlagiarismEngine()
 */

// ── Thresholds (adjust without touching engine logic) ────────────────────────
define('PLGR_PEER_THRESHOLD',   55.0); // Jaccard % above which a peer match is reported
define('PLGR_HIGH_RISK',        65.0); // Overall score → HIGH RISK verdict
define('PLGR_MEDIUM_RISK',      35.0); // Overall score → MEDIUM RISK verdict
define('PLGR_MIN_WORDS',        30);   // Minimum word count to run analysis

// ── English stop-words excluded when building n-grams ────────────────────────
const PLGR_STOP_WORDS = [
    'a','an','the','and','or','but','in','on','at','to','for','of','with',
    'by','from','is','are','was','were','be','been','being','have','has',
    'had','do','does','did','will','would','could','should','may','might',
    'shall','this','that','these','those','it','its','he','she','they',
    'we','i','you','his','her','their','our','your','my','not','no',
    'as','if','so','then','than','when','where','who','which','how','what',
    'about','up','out','into','over','after','before','between','during',
    'through','more','also','just','very','can','one','two','three','each',
    'all','any','both','few','more','most','other','some','such','only'
];

// ── Text helpers ─────────────────────────────────────────────────────────────

function plgr_normalize(string $text): string {
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
    return preg_replace('/\s+/', ' ', trim($text));
}

function plgr_tokenize(string $normalized): array {
    $words = explode(' ', $normalized);
    return array_values(array_filter(
        $words,
        fn($w) => strlen($w) > 2 && !in_array($w, PLGR_STOP_WORDS)
    ));
}

function plgr_ngrams(array $tokens, int $n = 3): array {
    $ngrams = [];
    $count  = count($tokens);
    for ($i = 0; $i <= $count - $n; $i++) {
        $ngrams[] = implode(' ', array_slice($tokens, $i, $n));
    }
    return array_unique($ngrams);
}

/**
 * Jaccard similarity between two n-gram arrays.
 * Returns a percentage (0.0 – 100.0).
 */
function plgr_jaccard(array $a, array $b): float {
    if (empty($a) || empty($b)) return 0.0;
    $inter = count(array_intersect($a, $b));
    $union = count(array_unique(array_merge($a, $b)));
    return $union > 0 ? round(($inter / $union) * 100, 2) : 0.0;
}

// ── Internet / generic-content heuristics ───────────────────────────────────
/**
 * Analyses a text for patterns associated with copied or AI-generated content.
 * Does NOT query the internet — this is an estimate based on writing-style rules.
 *
 * @return array ['score' => float 0-100, 'flags' => string[]]
 */
function plgr_internet_heuristics(string $text): array {
    $flags = [];
    $score = 0;
    $lower = strtolower($text);
    $words = str_word_count($text, 1);
    $word_count = count($words);

    if ($word_count < PLGR_MIN_WORDS) {
        return [
            'score' => 0,
            'flags' => ['Submission too short for heuristic internet analysis (< ' . PLGR_MIN_WORDS . ' words).']
        ];
    }

    // ── RULE 1: Academic / encyclopaedia boilerplate phrases (+7 per hit, cap 42) ──
    $boilerplate = [
        'is defined as', 'refers to the', 'is a type of', 'was first introduced',
        'it should be noted', 'according to', 'furthermore', 'in conclusion',
        'in summary', 'it is worth noting', 'has been widely used',
        'studies have shown', 'research indicates', 'is commonly used',
        'is known as', 'is often referred to as', 'was developed by',
        'was introduced in', 'is generally considered', 'plays a crucial role',
        'it can be argued', 'as mentioned above', 'as stated by', 'as noted by',
        'this paper examines', 'the purpose of this', 'the aim of this study',
        'it is important to note', 'in other words', 'on the other hand'
    ];
    $bHits = 0;
    foreach ($boilerplate as $phrase) {
        if (str_contains($lower, $phrase)) {
            $bHits++;
            $flags[] = "Academic boilerplate phrase detected: \"$phrase\"";
        }
    }
    if ($bHits > 0) $score += min(42, $bHits * 7);

    // ── RULE 2: Citation / source markers (+12 per hit, cap 36) ──────────────
    $source_markers = [
        'wikipedia', 'source:', 'reference:', 'bibliography', 'doi:', 'isbn:',
        'http://', 'https://', 'www.', '[1]', '[2]', '[3]', 'et al.',
        '(2019)', '(2020)', '(2021)', '(2022)', '(2023)', '(2024)', '(2025)', 'ibid'
    ];
    $sHits = 0;
    foreach ($source_markers as $marker) {
        if (str_contains($lower, $marker)) {
            $sHits++;
            $flags[] = "Citation / source marker found: \"$marker\"";
        }
    }
    if ($sHits > 0) $score += min(36, $sHits * 12);

    // ── RULE 3: Typography copy-paste artifacts (smart quotes, em-dashes, NBSP) ─
    $art = preg_match_all('/[\x{2018}\x{2019}\x{201C}\x{201D}\x{2013}\x{2014}\x{00A0}]/u', $text);
    if ($art > 3) {
        $flags[] = "Typography artifacts detected ($art instances — smart quotes / em-dashes / non-breaking spaces) suggesting text pasted from a browser or external document.";
        $score += 15;
    }

    // ── RULE 4: High formal-vocabulary ratio (words > 8 chars) ───────────────
    $longWords   = array_filter($words, fn($w) => strlen($w) > 8);
    $formalRatio = $word_count > 0 ? count($longWords) / $word_count : 0;
    if ($formalRatio > 0.32) {
        $pct = round($formalRatio * 100);
        $flags[] = "High formal vocabulary ($pct% of words have 9+ characters) — consistent with copied academic or encyclopaedic text.";
        $score += min(12, (int)(($formalRatio - 0.32) * 100));
    }

    // ── RULE 5: Sentence-length uniformity (low std-dev = template / generated) ─
    $sentences = array_filter(
        preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY),
        fn($s) => str_word_count(trim($s)) > 3
    );
    if (count($sentences) >= 5) {
        $lens   = array_map(fn($s) => str_word_count(trim($s)), array_values($sentences));
        $mean   = array_sum($lens) / count($lens);
        $stddev = sqrt(array_sum(array_map(fn($l) => ($l - $mean) ** 2, $lens)) / count($lens));
        if ($stddev < 4.0 && $mean > 14) {
            $flags[] = "Suspiciously uniform sentence length (σ=" . round($stddev, 1) . " words, mean=" . round($mean, 1) . ") — may indicate AI-generated or template-copied text.";
            $score += 10;
        }
    }

    // ── RULE 6: Excessive passive voice (> 40 % of sentence count) ───────────
    $passiveCount = 0;
    foreach (['/\bwas\s+\w+ed\b/i', '/\bwere\s+\w+ed\b/i', '/\bis\s+\w+ed\b/i',
               '/\bare\s+\w+ed\b/i', '/\bbeen\s+\w+ed\b/i'] as $pat) {
        $passiveCount += preg_match_all($pat, $text);
    }
    $sentCount = max(1, count($sentences));
    if ($passiveCount / $sentCount > 0.40) {
        $flags[] = "Excessive passive voice ($passiveCount instances across $sentCount sentences) — a common marker of copied academic or textbook text.";
        $score += 10;
    }

    // ── RULE 7: Repeated short phrases (3-word phrases appearing 3+ times) ───
    preg_match_all('/(\b\w+ \w+ \w+\b)/', $lower, $phraseMatches);
    $phraseCounts    = array_count_values($phraseMatches[1]);
    $repeatedPhrases = array_filter($phraseCounts, fn($c) => $c >= 3);
    if (count($repeatedPhrases) > 2) {
        $rp = count($repeatedPhrases);
        $flags[] = "$rp distinct 3-word phrases repeated 3+ times — indicative of templated or auto-generated content.";
        $score += 8;
    }

    // ── RULE 8: Formal transition-word overuse (encyclopaedic cadence) ────────
    $transitions = [
        'however', 'moreover', 'furthermore', 'nevertheless', 'consequently',
        'therefore', 'additionally', 'subsequently', 'notwithstanding',
        'henceforth', 'albeit', 'wherein', 'thereof', 'therein', 'inasmuch'
    ];
    $transHits = 0;
    foreach ($transitions as $t) {
        if (preg_match_all('/\b' . $t . '\b/i', $lower) >= 2) $transHits++;
    }
    if ($transHits > 3) {
        $flags[] = "Heavy formal transition-word usage ($transHits word-types used ≥2 times each) — encyclopaedic writing style.";
        $score += 8;
    }

    return ['score' => min(100, $score), 'flags' => $flags];
}

// ── MAIN ENGINE FUNCTION ─────────────────────────────────────────────────────
/**
 * Run the full plagiarism analysis for a newly saved submission.
 *
 * @param int    $submission_id   assignment_submissions.id just inserted
 * @param string $submission_text The submitted body text
 * @param int    $assignment_id   Parent assignment
 * @param int    $student_id      Author — excluded from peer comparison
 * @param mysqli $conn            Active database connection
 *
 * @return array {
 *   overall_score            float,
 *   student_similarity_score float,
 *   internet_similarity_score float,
 *   verdict                  string  ('LOW RISK'|'MEDIUM RISK'|'HIGH RISK'),
 *   matched_students         array,
 *   flags                    string[]
 * }
 */
function runPlagiarismEngine(
    int    $submission_id,
    string $submission_text,
    int    $assignment_id,
    int    $student_id,
           $conn
): array {

    $word_count = str_word_count($submission_text);

    // ── Step 1: Build trigrams for the new submission ─────────────────
    $normNew   = plgr_normalize($submission_text);
    $tokensNew = plgr_tokenize($normNew);
    $ngramsNew = ($word_count >= PLGR_MIN_WORDS) ? plgr_ngrams($tokensNew, 3) : [];

    // ── Step 2: Peer comparison ───────────────────────────────────────
    $matchedStudents = [];
    $maxPeerScore    = 0.0;

    $peers = mysqli_query($conn,
        "SELECT s.id, s.student_id, s.submission_text, u.full_name
         FROM assignment_submissions s
         JOIN users u ON u.id = s.student_id
         WHERE s.assignment_id = $assignment_id
           AND s.student_id   != $student_id
           AND s.id            != $submission_id"
    );

    if ($peers) {
        while ($peer = mysqli_fetch_assoc($peers)) {
            $normP   = plgr_normalize($peer['submission_text']);
            $tokensP = plgr_tokenize($normP);
            $ngramsP = plgr_ngrams($tokensP, 3);
            $sim     = plgr_jaccard($ngramsNew, $ngramsP);
            $maxPeerScore = max($maxPeerScore, $sim);

            if ($sim >= PLGR_PEER_THRESHOLD) {
                $matchedStudents[] = [
                    'student_name'  => $peer['full_name'],
                    'similarity'    => $sim,
                    'submission_id' => (int) $peer['id'],
                ];
            }
        }
    }
    // Sort matches highest first
    usort($matchedStudents, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

    // ── Step 3: Internet / generic-content heuristics ─────────────────
    $inet      = plgr_internet_heuristics($submission_text);
    $inetScore = floatval($inet['score']);
    $flags     = $inet['flags'];

    // ── Step 4: Weighted overall score ────────────────────────────────
    // 60 % peer similarity, 40 % internet heuristic
    $overall = round(($maxPeerScore * 0.60) + ($inetScore * 0.40), 2);

    // ── Step 5: Verdict ───────────────────────────────────────────────
    $verdict = match (true) {
        $overall >= PLGR_HIGH_RISK   => 'HIGH RISK',
        $overall >= PLGR_MEDIUM_RISK => 'MEDIUM RISK',
        default                      => 'LOW RISK',
    };

    // ── Step 6: Persist report in DB ──────────────────────────────────
    $peersJson   = mysqli_real_escape_string($conn, json_encode($matchedStudents));
    $flagsJson   = mysqli_real_escape_string($conn, json_encode($flags));
    $verdictSql  = mysqli_real_escape_string($conn, $verdict);
    $peerDb      = floatval($maxPeerScore);
    $inetDb      = floatval($inetScore);
    $overallDb   = floatval($overall);

    mysqli_query($conn,
        "INSERT INTO plagiarism_reports
            (submission_id, student_similarity_score, internet_similarity_score,
             overall_score, verdict, matched_students, flags, analysed_at)
         VALUES
            ($submission_id, $peerDb, $inetDb, $overallDb,
             '$verdictSql', '$peersJson', '$flagsJson', NOW())
         ON DUPLICATE KEY UPDATE
            student_similarity_score  = $peerDb,
            internet_similarity_score = $inetDb,
            overall_score             = $overallDb,
            verdict                   = '$verdictSql',
            matched_students          = '$peersJson',
            flags                     = '$flagsJson',
            analysed_at               = NOW()"
    );

    return [
        'overall_score'             => $overall,
        'student_similarity_score'  => $maxPeerScore,
        'internet_similarity_score' => $inetScore,
        'verdict'                   => $verdict,
        'matched_students'          => $matchedStudents,
        'flags'                     => $flags,
    ];
}
?>