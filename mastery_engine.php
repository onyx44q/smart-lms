<?php
/**
 * mastery_engine.php — ONE definition of "mastery" for the whole system.
 *
 * WHY THIS EXISTS
 *   Mastery used to be a running total of fixed bonuses (+8.5 for any score
 *   ≥ 80, +3.2 for 50–79, −5 below 50) starting at 0, and the same formula was
 *   copy-pasted into five files. A student who scored 100% on a quiz therefore
 *   showed "8.5% mastery", which then fed the AI advisor, the risk flags, the
 *   course-health cards and the top-performer rule.
 *
 * HOW MASTERY IS CALCULATED NOW (all of it from real quiz scores)
 *   1. For every quiz a student has taken, use their LATEST attempt (their
 *      current standing on that quiz; a retry that improves is credited).
 *   2. Skill mastery  = average of those latest scores for quizzes in that skill.
 *   3. Overall mastery = average of the skill masteries the student has data for.
 *      Skills with no quiz yet are "not assessed" — never counted as 0%.
 *   4. Confidence reflects how much evidence there is: 1 quiz = early
 *      indication, 2–3 = developing, 4+ = reliable. A perfect first quiz is
 *      shown as the 100% it is, but labelled "early".
 *
 * Everything (student dashboard, AI advisor, lecturer AI Decision Dashboard,
 * risk dashboard, chatbot, quiz engine) calls these functions, so every screen
 * agrees. The stored student_mastery table is just a cache of the same numbers.
 */

if (!defined('ME_LOADED')) {
define('ME_LOADED', true);
define('ME_MODEL_VERSION', 'v2-score-based');

/** Skill name for a quiz: explicit value → title keywords → stable rotation by id. */
function me_infer_skill(?string $skill, ?string $title, int $quiz_id): string {
    $skill = trim((string)$skill);
    if ($skill !== '') return $skill;
    $t = strtolower((string)$title);
    if (str_contains($t, 'practical') || str_contains($t, 'lab') || str_contains($t, 'applied'))       return 'Practical Application';
    if (str_contains($t, 'aptitude')  || str_contains($t, 'general') || str_contains($t, 'reasoning')) return 'General Aptitude';
    if (str_contains($t, 'theory')    || str_contains($t, 'core')    || str_contains($t, 'concept'))    return 'Core Theory';
    $pool = ['General Aptitude', 'Practical Application', 'Core Theory'];
    return $pool[$quiz_id % 3];
}

function me_confidence(int $quizzes): string {
    return $quizzes >= 4 ? 'high' : ($quizzes >= 2 ? 'medium' : 'low');
}

/** All attempts for the given students (oldest first), optionally limited to some courses. */
function me_attempts($conn, array $student_ids, ?array $course_ids = null): array {
    $student_ids = array_values(array_unique(array_filter(array_map('intval', $student_ids))));
    if (empty($student_ids)) return [];
    $sql = "SELECT r.id, r.student_id, r.quiz_id, r.score, r.attempt_no, r.created_at,
                   q.skill_name, q.title AS quiz_title, q.course_id
            FROM results r JOIN quizzes q ON q.id = r.quiz_id
            WHERE r.student_id IN (" . implode(',', $student_ids) . ")";
    if ($course_ids !== null) {
        $course_ids = array_values(array_unique(array_map('intval', $course_ids)));
        if (empty($course_ids)) return [];
        $sql .= " AND q.course_id IN (" . implode(',', $course_ids) . ")";
    }
    $sql .= " ORDER BY r.student_id ASC, r.created_at ASC, r.id ASC";
    $out = []; $res = mysqli_query($conn, $sql);
    if ($res) while ($row = mysqli_fetch_assoc($res)) $out[] = $row;
    return $out;
}

/** Turn ONE student's attempts (oldest first) into the mastery summary. */
function me_summarise(array $attempts): array {
    $latest = []; $attemptsPerQuiz = [];
    foreach ($attempts as $a) {
        $qid = intval($a['quiz_id']);
        $latest[$qid] = $a;                                   // later rows overwrite → latest attempt wins
        $attemptsPerQuiz[$qid] = ($attemptsPerQuiz[$qid] ?? 0) + 1;
    }
    $bySkill = [];
    foreach ($latest as $qid => $a) {
        $skill = me_infer_skill($a['skill_name'] ?? '', $a['quiz_title'] ?? '', $qid);
        $bySkill[$skill]['scores'][]   = floatval($a['score']);
        $bySkill[$skill]['attempts']   = ($bySkill[$skill]['attempts'] ?? 0) + $attemptsPerQuiz[$qid];
        $bySkill[$skill]['last_at']    = max($bySkill[$skill]['last_at'] ?? '', (string)$a['created_at']);
    }
    $skills = [];
    foreach ($bySkill as $skill => $d) {
        $n = count($d['scores']);
        $skills[$skill] = [
            'mastery'    => round(array_sum($d['scores']) / $n, 1),
            'quizzes'    => $n,
            'attempts'   => $d['attempts'],
            'best'       => round(max($d['scores']), 1),
            'lowest'     => round(min($d['scores']), 1),
            'confidence' => me_confidence($n),
            'last_at'    => $d['last_at'],
        ];
    }
    uasort($skills, fn($a, $b) => $b['mastery'] <=> $a['mastery']);          // strongest first
    $avg = empty($skills) ? null : round(array_sum(array_column($skills, 'mastery')) / count($skills), 1);

    $recent = [];                                                            // last scores, oldest → newest
    foreach (array_slice($attempts, -6) as $a) $recent[] = floatval($a['score']);

    return ['skills' => $skills, 'avg' => $avg, 'quizzes' => count($latest), 'attempts' => count($attempts),
            'recent' => $recent, 'confidence' => me_confidence(count($latest))];
}

/**
 * Mastery for one student.
 *   $course_id : only quizzes of that course (null = all courses)
 *   $pending   : include a quiz result that is about to be saved —
 *                ['quiz_id'=>int,'score'=>float] (skill/course are looked up)
 */
function me_compute_student($conn, int $student_id, ?int $course_id = null, ?array $pending = null): array {
    $attempts = me_attempts($conn, [$student_id], $course_id ? [$course_id] : null);
    if ($pending) {
        $qid = intval($pending['quiz_id']);
        $q = mysqli_fetch_assoc(mysqli_query($conn, "SELECT skill_name, title, course_id FROM quizzes WHERE id = $qid"));
        if ($q && (!$course_id || intval($q['course_id']) === $course_id)) {
            $attempts[] = ['id' => PHP_INT_MAX, 'student_id' => $student_id, 'quiz_id' => $qid, 'score' => floatval($pending['score']),
                           'attempt_no' => 0, 'created_at' => date('Y-m-d H:i:s'),
                           'skill_name' => $q['skill_name'], 'quiz_title' => $q['title'], 'course_id' => $q['course_id']];
        }
    }
    return me_summarise($attempts);
}

/**
 * Mastery for many students at once (one query).
 * Returns [student_id => ['all' => summary, 'by_course' => [course_id => summary]]]
 */
function me_compute_bulk($conn, array $student_ids, ?array $course_ids = null): array {
    $rows = me_attempts($conn, $student_ids, $course_ids);
    $perStudent = []; $perCourse = [];
    foreach ($rows as $r) {
        $sid = intval($r['student_id']); $cid = intval($r['course_id']);
        $perStudent[$sid][] = $r;
        $perCourse[$sid][$cid][] = $r;
    }
    $out = [];
    foreach ($perStudent as $sid => $list) {
        $out[$sid] = ['all' => me_summarise($list), 'by_course' => []];
        foreach ($perCourse[$sid] as $cid => $cl) $out[$sid]['by_course'][$cid] = me_summarise($cl);
    }
    return $out;
}

/** Flat [skill => mastery] map (what older code expects). */
function me_skill_map(array $summary): array {
    $m = [];
    foreach ($summary['skills'] as $skill => $s) $m[$skill] = $s['mastery'];
    return $m;
}

/** Make the stored student_mastery rows match the computed truth (cache refresh). */
function me_sync_student($conn, int $student_id): array {
    $summary = me_compute_student($conn, $student_id);
    mysqli_query($conn, "DELETE FROM student_mastery WHERE student_id = $student_id");
    foreach ($summary['skills'] as $skill => $s) {
        $sk = mysqli_real_escape_string($conn, $skill);
        mysqli_query($conn, "INSERT INTO student_mastery (student_id, skill_name, mastery_level) VALUES ($student_id, '$sk', " . floatval($s['mastery']) . ")");
    }
    return me_skill_map($summary);
}

/** Is the cache out of date? (different skills, or any value off by more than 0.05) */
function me_needs_sync($conn, int $student_id): bool {
    $want = me_skill_map(me_compute_student($conn, $student_id));
    $have = [];
    $r = mysqli_query($conn, "SELECT skill_name, mastery_level FROM student_mastery WHERE student_id = $student_id");
    $rows = 0;
    if ($r) while ($x = mysqli_fetch_assoc($r)) { $have[$x['skill_name']] = floatval($x['mastery_level']); $rows++; }
    if ($rows !== count($want) || count($have) !== count($want)) return true;
    foreach ($want as $skill => $v) if (!isset($have[$skill]) || abs($have[$skill] - $v) > 0.05) return true;
    return false;
}

/**
 * One-time upgrade from the old +8.5/+3.2/−5 model: rebuild every student's
 * stored mastery from their real scores. Safe to call on every request — it
 * claims the job atomically and only the first request does the work.
 */
function me_migrate_if_needed($conn): void {
    static $done = false;
    if ($done) return;
    $done = true;
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS app_meta (meta_key VARCHAR(60) PRIMARY KEY, meta_value VARCHAR(255) NOT NULL)");
    $r = mysqli_query($conn, "SELECT meta_value FROM app_meta WHERE meta_key = 'mastery_model'");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    if ($row && $row['meta_value'] === ME_MODEL_VERSION) return;
    if ($row) {                                               // another request is migrating (or finished with a different version)
        mysqli_query($conn, "UPDATE app_meta SET meta_value = 'migrating' WHERE meta_key = 'mastery_model' AND meta_value <> 'migrating'");
        if (mysqli_affected_rows($conn) < 1) return;
    } else {
        mysqli_query($conn, "INSERT IGNORE INTO app_meta (meta_key, meta_value) VALUES ('mastery_model', 'migrating')");
        if (mysqli_affected_rows($conn) < 1) return;
    }
    // everyone who has results, plus everyone who has stale rows without results (old zero-seeded profiles)
    $ids = [];
    $q = mysqli_query($conn, "SELECT DISTINCT student_id FROM results UNION SELECT DISTINCT student_id FROM student_mastery");
    if ($q) while ($x = mysqli_fetch_assoc($q)) if ($x['student_id']) $ids[] = intval($x['student_id']);
    foreach ($ids as $sid) me_sync_student($conn, $sid);
    mysqli_query($conn, "UPDATE app_meta SET meta_value = '" . ME_MODEL_VERSION . "' WHERE meta_key = 'mastery_model'");
}

} // ME_LOADED
