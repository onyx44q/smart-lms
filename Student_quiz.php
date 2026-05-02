<?php
/**
 * student_quiz.php — Student Quiz Interface
 *
 * Flow:
 *   1. Student arrives via ?quiz_id=X (linked from student_dashboard)
 *   2. Quiz validity + enrollment check performed
 *   3. Questions rendered (JS one-at-a-time navigation)
 *   4. On submit → POST to submit_quiz.php with per-question answers
 *   5. Results page shown inline with:
 *        - Score, grade, mastery delta
 *        - Per-question breakdown with explanations
 *        - AI engine next-step recommendation
 */

include 'config.php';
include 'ai_engine.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// ── Auth ─────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php");
    exit();
}

$student_id = intval($_SESSION['user_id']);

// ── Quiz lookup ───────────────────────────────────────────────────────
$quiz_id = intval($_GET['quiz_id'] ?? 0);
if ($quiz_id === 0) {
    header("Location: student_dashboard.php?status=error&msg=Invalid+quiz.");
    exit();
}

$quiz = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT q.*, c.title as course_title, cu.title AS unit_title, cu.unit_code
     FROM quizzes q
     JOIN courses c ON c.id = q.course_id
     LEFT JOIN course_units cu ON cu.id = q.unit_id
     WHERE q.id = $quiz_id AND q.is_active = 1"
));

if (!$quiz) {
    header("Location: student_dashboard.php?status=error&msg=Quiz+not+found+or+not+published.");
    exit();
}

// ── Enrollment check ─────────────────────────────────────────────────
$enrolled = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id FROM enrollments WHERE student_id = $student_id AND course_id = " . intval($quiz['course_id'])
));
if (!$enrolled) {
    header("Location: student_dashboard.php?status=error&msg=You+are+not+enrolled+in+this+course.");
    exit();
}

// ── Attempt count ─────────────────────────────────────────────────────
$attempt_res = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as cnt FROM results WHERE student_id = $student_id AND quiz_id = $quiz_id"
));
$attempt_no = intval($attempt_res['cnt']) + 1;

// ── Fetch questions ────────────────────────────────────────────────────
$q_res = mysqli_query($conn,
    "SELECT id, question_text, option_a, option_b, option_c, option_d, correct_option, explanation
     FROM questions WHERE quiz_id = $quiz_id ORDER BY id ASC"
);
$questions = [];
while ($q = mysqli_fetch_assoc($q_res)) $questions[] = $q;

$total_questions = count($questions);
if ($total_questions === 0) {
    header("Location: student_dashboard.php?status=error&msg=This+quiz+has+no+questions+yet.");
    exit();
}

// ── Handle submission ─────────────────────────────────────────────────
$show_results = false;
$result_data  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['answers'])) {
    $submitted_answers = $_POST['answers']; // [question_id => 'A'/'B'/'C'/'D']
    $correct_count = 0;
    $answer_review = [];

    foreach ($questions as $q) {
        $qid     = intval($q['id']);
        $chosen  = strtoupper(trim($submitted_answers[$qid] ?? ''));
        $correct = strtoupper(trim($q['correct_option']));
        $is_correct = ($chosen === $correct && !empty($chosen));
        if ($is_correct) $correct_count++;
        $answer_review[] = [
            'question_text'  => $q['question_text'],
            'option_a'       => $q['option_a'],
            'option_b'       => $q['option_b'],
            'option_c'       => $q['option_c'],
            'option_d'       => $q['option_d'],
            'chosen'         => $chosen,
            'correct_option' => $correct,
            'explanation'    => $q['explanation'],
            'is_correct'     => $is_correct,
            'id'             => $qid,
        ];
    }

    $raw_score = $total_questions > 0
        ? (int)round(($correct_count / $total_questions) * 100)
        : 0;

    // Load student mastery for ai_engine
    $mastery_res = mysqli_query($conn,
        "SELECT skill_name, mastery_level FROM student_mastery WHERE student_id = $student_id"
    );
    $all_mastery = [];
    while ($m = mysqli_fetch_assoc($mastery_res)) {
        $all_mastery[$m['skill_name']] = floatval($m['mastery_level']);
    }

    $student_row  = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT full_name, email, career_path FROM users WHERE id = $student_id"
    ));
    $career_path  = $student_row['career_path'] ?? 'General Software Engineering';
    // Resolve skill_name — if NULL (older quizzes), infer from quiz title
    $skill_name = $quiz['skill_name'];
    if (empty($skill_name)) {
        $title_lower = strtolower($quiz['title'] ?? '');
        if (str_contains($title_lower, 'practical') || str_contains($title_lower, 'lab') || str_contains($title_lower, 'applied')) {
            $skill_name = 'Practical Application';
        } elseif (str_contains($title_lower, 'aptitude') || str_contains($title_lower, 'general') || str_contains($title_lower, 'reasoning')) {
            $skill_name = 'General Aptitude';
        } elseif (str_contains($title_lower, 'theory') || str_contains($title_lower, 'core') || str_contains($title_lower, 'concept')) {
            $skill_name = 'Core Theory';
        } else {
            // Last resort: rotate across all three skills by quiz_id so results
            // spread across skills instead of always landing on Core Theory.
            $skills_pool = ['General Aptitude', 'Practical Application', 'Core Theory'];
            $skill_name  = $skills_pool[$quiz_id % 3];
        }
    }

    // Ensure skill exists
    if (!isset($all_mastery[$skill_name])) {
        mysqli_query($conn,
            "INSERT IGNORE INTO student_mastery (student_id, skill_name, mastery_level)
             VALUES ($student_id, '" . mysqli_real_escape_string($conn, $skill_name) . "', 0.00)"
        );
        $all_mastery[$skill_name] = 0.0;
    }

    // ── Run AI engine ─────────────────────────────────────────────────
    $decision = runAdaptiveEngine(
        $student_id, $quiz_id, $skill_name,
        $raw_score, $attempt_no, $career_path, $all_mastery, $conn
    );

    // ── Update mastery ────────────────────────────────────────────────
    $new_m = floatval($decision['new_mastery']);
    mysqli_query($conn,
        "UPDATE student_mastery SET mastery_level = $new_m, last_updated = NOW()
         WHERE student_id = $student_id AND skill_name = '" . mysqli_real_escape_string($conn, $skill_name) . "'"
    );

    // ── Save result ───────────────────────────────────────────────────
    $feedback_esc = mysqli_real_escape_string($conn, $decision['feedback']);
    $action_esc   = mysqli_real_escape_string($conn, $decision['action']);
    $band_esc     = mysqli_real_escape_string($conn, $decision['performance_band']);
    $grade_esc    = mysqli_real_escape_string($conn, $decision['predicted_grade']);
    $diff_esc2    = mysqli_real_escape_string($conn, $decision['difficulty_next']);

    mysqli_query($conn,
        "INSERT INTO results
           (student_id, quiz_id, score, recommendation, action_taken,
            performance_band, predicted_grade, difficulty_next, attempt_no, created_at)
         VALUES
           ($student_id, $quiz_id, $raw_score, '$feedback_esc', '$action_esc',
            '$band_esc', '$grade_esc', '$diff_esc2', $attempt_no, NOW())"
    );
    $result_id = intval(mysqli_insert_id($conn));

    // ── Save per-question answers ─────────────────────────────────────
    foreach ($answer_review as $ar) {
        $qid2     = intval($ar['id']);
        $chosen2  = mysqli_real_escape_string($conn, $ar['chosen']);
        $correct2 = intval($ar['is_correct']);
        mysqli_query($conn,
            "INSERT INTO student_answers (result_id, student_id, question_id, chosen, is_correct, answered_at)
             VALUES ($result_id, $student_id, $qid2, '$chosen2', $correct2, NOW())"
        );
    }

    // ── Notification ──────────────────────────────────────────────────
    $notif_msg = mysqli_real_escape_string($conn, $decision['notification_msg']);
    mysqli_query($conn,
        "INSERT INTO notifications (user_id, message, is_read, created_at)
         VALUES ($student_id, '$notif_msg', 0, NOW())"
    );

    // ── Email ──────────────────────────────────────────────────────────
    if (!empty($student_row['email'])) {
        sendLmsEmail($student_row['email'], $decision['email_subject'], $decision['email_body']);
    }

    $show_results = true;
    $result_data  = [
        'score'         => $raw_score,
        'correct'       => $correct_count,
        'total'         => $total_questions,
        'action'        => $decision['action'],
        'feedback'      => $decision['feedback'],
        'next_topic'    => $decision['next_topic_message'],
        'predicted_grade'=> $decision['predicted_grade'],
        'new_mastery'   => $decision['new_mastery'],
        'mastery_delta' => $decision['mastery_delta'],
        'skill_gaps'    => $decision['skill_gaps'],
        'difficulty_next'=> $decision['difficulty_next'],
        'answers'       => $answer_review,
    ];
}

$studentName = $_SESSION['user_name'] ?? 'Student';

// Difficulty badge colors
$diffBadge = [
    'beginner'     => 'bg-emerald-100 text-emerald-700',
    'intermediate' => 'bg-amber-100 text-amber-700',
    'advanced'     => 'bg-rose-100 text-rose-700',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($quiz['title']); ?> | SmartLMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; }

        /* Question cards slide in */
        .q-slide { display: none; }
        .q-slide.active { display: block; animation: fadeSlide 0.3s ease-out; }
        @keyframes fadeSlide {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Option buttons */
        .opt-btn {
            cursor: pointer;
            border: 2px solid #e2e8f0;
            transition: all 0.15s ease;
        }
        .opt-btn:hover   { border-color: #6366f1; background: #eef2ff; }
        .opt-btn.selected { border-color: #6366f1; background: #eef2ff; }
        .opt-btn.correct  { border-color: #22c55e; background: #f0fdf4 !important; }
        .opt-btn.wrong    { border-color: #ef4444; background: #fef2f2 !important; }

        /* Progress bar */
        #progress-fill { transition: width 0.4s ease; }

        /* Score ring */
        .score-ring {
            width: 120px; height: 120px;
            border-radius: 50%;
            background: conic-gradient(var(--ring-color) var(--ring-pct), #e2e8f0 0);
            display: flex; align-items: center; justify-content: center;
            position: relative;
        }
        .score-ring::before {
            content: '';
            position: absolute;
            width: 90px; height: 90px;
            border-radius: 50%;
            background: #fff;
        }
        .score-ring span { position: relative; z-index: 1; font-size: 26px; font-weight: 900; }

        /* Action banner colours */
        .banner-advance  { background: linear-gradient(135deg, #065f46, #047857); }
        .banner-retry    { background: linear-gradient(135deg, #92400e, #b45309); }
        .banner-remedial { background: linear-gradient(135deg, #7f1d1d, #991b1b); }
    </style>
</head>
<body class="bg-slate-50 min-h-screen">

    <!-- ── Top Bar ── -->
    <nav class="bg-white border-b border-slate-200 px-6 py-4 flex items-center justify-between sticky top-0 z-40 shadow-sm">
        <div class="flex items-center space-x-4">
            <a href="student_dashboard.php" class="text-slate-400 hover:text-slate-700 transition">
                <i class="fa-solid fa-arrow-left mr-1"></i>
                <span class="text-xs font-bold uppercase tracking-widest">Dashboard</span>
            </a>
        </div>
        <div class="flex items-center space-x-2 text-center">
            <i class="fa-solid fa-brain text-blue-600"></i>
            <span class="font-extrabold text-slate-900 text-sm tracking-tight">Smart<span class="text-blue-600">LMS</span></span>
        </div>
        <div class="text-right">
            <p class="text-xs font-black text-slate-900 uppercase"><?php echo htmlspecialchars($studentName); ?></p>
            <p class="text-[10px] text-blue-600 font-bold uppercase">Student</p>
        </div>
    </nav>

    <div class="max-w-3xl mx-auto px-4 py-10">

    <?php if (!$show_results): ?>
    <!-- ════════════════════════════════════════════════════
         QUIZ TAKING INTERFACE
    ════════════════════════════════════════════════════ -->

        <!-- Quiz header -->
        <div class="mb-8">
            <div class="flex items-center space-x-2 mb-3 flex-wrap gap-2">
                <span class="<?php echo $diffBadge[$quiz['difficulty']] ?? 'bg-slate-100 text-slate-700'; ?> text-[9px] font-black uppercase px-2 py-1 rounded-lg tracking-widest">
                    <?php echo ucfirst($quiz['difficulty']); ?>
                </span>
                <span class="bg-blue-50 text-blue-700 text-[9px] font-black uppercase px-2 py-1 rounded-lg tracking-widest">
                    <?php echo htmlspecialchars($quiz['skill_name'] ?? 'Core Theory'); ?>
                </span>
                <span class="bg-slate-100 text-slate-600 text-[9px] font-black uppercase px-2 py-1 rounded-lg tracking-widest">
                    Attempt <?php echo $attempt_no; ?>
                </span>
            </div>
            <h1 class="text-2xl font-extrabold text-slate-900"><?php echo htmlspecialchars($quiz['title']); ?></h1>
            <p class="text-slate-500 text-sm mt-1">
                <i class="fa-solid fa-book mr-1"></i><?php echo htmlspecialchars($quiz['course_title']); ?>
                &nbsp;·&nbsp; <?php echo $total_questions; ?> questions
            </p>
        </div>

        <!-- Progress bar -->
        <div class="mb-8">
            <div class="flex justify-between mb-2">
                <span id="progress-label" class="text-[10px] font-black uppercase text-slate-400 tracking-widest">Question 1 of <?php echo $total_questions; ?></span>
                <span id="progress-pct" class="text-[10px] font-black text-indigo-600">0%</span>
            </div>
            <div class="h-2 bg-slate-200 rounded-full overflow-hidden">
                <div id="progress-fill" class="h-full bg-gradient-to-r from-indigo-500 to-violet-500 rounded-full" style="width:0%"></div>
            </div>
        </div>

        <!-- Question form -->
        <form method="POST" id="quiz-form">
            <?php foreach ($questions as $idx => $q):
                $qid = intval($q['id']);
                $optLabels = ['A' => $q['option_a'], 'B' => $q['option_b'], 'C' => $q['option_c'], 'D' => $q['option_d']];
            ?>
            <div class="q-slide <?php echo $idx === 0 ? 'active' : ''; ?>" id="q-<?php echo $idx; ?>">
                <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-8 mb-6">
                    <!-- Question number pill -->
                    <div class="flex items-center space-x-3 mb-5">
                        <div class="w-9 h-9 bg-indigo-600 text-white rounded-xl flex items-center justify-center font-black text-sm"><?php echo $idx + 1; ?></div>
                        <span class="text-[10px] font-black uppercase text-slate-400 tracking-widest">Question <?php echo $idx + 1; ?> of <?php echo $total_questions; ?></span>
                    </div>

                    <p class="text-slate-900 font-bold text-base leading-relaxed mb-7"><?php echo htmlspecialchars($q['question_text']); ?></p>

                    <!-- Options -->
                    <div class="space-y-3" id="opts-<?php echo $idx; ?>">
                        <?php foreach ($optLabels as $letter => $text): ?>
                        <label class="opt-btn flex items-start space-x-4 p-4 rounded-2xl bg-white cursor-pointer"
                               onclick="selectOption(<?php echo $idx; ?>, '<?php echo $letter; ?>', this)">
                            <input type="radio" name="answers[<?php echo $qid; ?>]"
                                   value="<?php echo $letter; ?>"
                                   class="hidden"
                                   id="opt-<?php echo $idx; ?>-<?php echo $letter; ?>">
                            <div class="w-7 h-7 rounded-lg border-2 border-slate-300 flex items-center justify-center font-black text-xs text-slate-500 flex-shrink-0 opt-circle"><?php echo $letter; ?></div>
                            <span class="text-slate-700 text-sm leading-snug pt-0.5"><?php echo htmlspecialchars($text); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Navigation -->
                <div class="flex items-center justify-between">
                    <button type="button"
                        onclick="navigate(<?php echo $idx; ?>, <?php echo $idx - 1; ?>)"
                        class="<?php echo $idx === 0 ? 'invisible' : ''; ?> flex items-center space-x-2 px-5 py-3 bg-white border border-slate-200 text-slate-600 rounded-xl text-xs font-bold hover:bg-slate-50 transition-all">
                        <i class="fa-solid fa-arrow-left"></i><span>Previous</span>
                    </button>

                    <?php if ($idx < $total_questions - 1): ?>
                    <button type="button"
                        onclick="navigate(<?php echo $idx; ?>, <?php echo $idx + 1; ?>)"
                        id="next-<?php echo $idx; ?>"
                        class="flex items-center space-x-2 px-6 py-3 bg-indigo-600 text-white rounded-xl text-xs font-bold hover:bg-indigo-700 transition-all shadow-lg disabled:opacity-40 disabled:cursor-not-allowed">
                        <span>Next</span><i class="fa-solid fa-arrow-right"></i>
                    </button>
                    <?php else: ?>
                    <button type="button" onclick="submitQuiz()"
                        id="submit-btn"
                        class="flex items-center space-x-2 px-8 py-3 bg-emerald-600 text-white rounded-xl text-xs font-black uppercase tracking-widest hover:bg-emerald-700 transition-all shadow-lg shadow-emerald-100 active:scale-95">
                        <i class="fa-solid fa-paper-plane"></i><span>Submit Quiz</span>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </form>

    <?php else: ?>
    <!-- ════════════════════════════════════════════════════
         RESULTS PAGE (after submission)
    ════════════════════════════════════════════════════ -->
    <?php
        $r = $result_data;
        $action = $r['action'];
        $bannerClass = 'banner-' . $action;
        $actionIcon = match ($action) {
            'advance'  => 'fa-circle-up',
            'retry'    => 'fa-rotate',
            'remedial' => 'fa-circle-down',
            default    => 'fa-circle-info'
        };
        $scoreColor = $r['score'] >= 80 ? '#22c55e' : ($r['score'] >= 50 ? '#f59e0b' : '#ef4444');
    ?>

        <!-- Action Banner -->
        <div class="<?php echo $bannerClass; ?> rounded-3xl p-7 mb-8 text-white">
            <div class="flex items-center space-x-4">
                <div class="w-12 h-12 bg-white/10 rounded-2xl flex items-center justify-center flex-shrink-0">
                    <i class="fa-solid <?php echo $actionIcon; ?> text-white text-xl"></i>
                </div>
                <div>
                    <p class="text-[9px] font-black uppercase tracking-widest opacity-70 mb-1">AI Engine Decision</p>
                    <p class="font-extrabold text-lg leading-snug"><?php echo htmlspecialchars($r['feedback']); ?></p>
                </div>
            </div>
        </div>

        <!-- Score + Stats row -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-8">
            <!-- Score ring -->
            <div class="bg-white rounded-3xl border border-slate-200 p-6 flex flex-col items-center shadow-sm">
                <div class="score-ring mb-3" style="--ring-pct: <?php echo $r['score']; ?>%; --ring-color: <?php echo $scoreColor; ?>">
                    <span style="color: <?php echo $scoreColor; ?>"><?php echo $r['score']; ?>%</span>
                </div>
                <p class="text-[10px] font-black uppercase text-slate-400 tracking-widest">Score</p>
                <p class="text-slate-700 text-xs font-bold mt-1"><?php echo $r['correct']; ?> / <?php echo $r['total']; ?> correct</p>
            </div>

            <!-- Grade & Mastery -->
            <div class="bg-white rounded-3xl border border-slate-200 p-6 shadow-sm space-y-4">
                <div>
                    <p class="text-[9px] font-black uppercase text-slate-400 tracking-widest mb-1">Predicted Grade</p>
                    <p class="text-4xl font-black <?php echo in_array($r['predicted_grade'],['A','B'])?'text-emerald-600':($r['predicted_grade']==='C'?'text-amber-500':'text-red-600'); ?>">
                        <?php echo htmlspecialchars($r['predicted_grade']); ?>
                    </p>
                </div>
                <div>
                    <p class="text-[9px] font-black uppercase text-slate-400 tracking-widest mb-1">Mastery Change</p>
                    <p class="font-extrabold text-lg <?php echo $r['mastery_delta'] >= 0 ? 'text-emerald-600' : 'text-red-600'; ?>">
                        <?php echo $r['mastery_delta'] >= 0 ? '+' : ''; ?><?php echo $r['mastery_delta']; ?>%
                        <span class="text-xs text-slate-400 font-normal ml-1">→ <?php echo $r['new_mastery']; ?>% mastery</span>
                    </p>
                </div>
            </div>

            <!-- Next Step -->
            <div class="bg-white rounded-3xl border border-slate-200 p-6 shadow-sm">
                <p class="text-[9px] font-black uppercase text-slate-400 tracking-widest mb-2">Next Step</p>
                <div class="flex items-start space-x-2 mb-3">
                    <i class="fa-solid fa-arrow-right text-indigo-500 mt-0.5 text-xs"></i>
                    <p class="text-slate-700 text-xs leading-snug font-bold"><?php echo htmlspecialchars($r['next_topic']); ?></p>
                </div>
                <p class="text-[9px] font-black uppercase text-slate-400 tracking-widest mb-1">Next Quiz Difficulty</p>
                <span class="<?php echo $diffBadge[$r['difficulty_next']] ?? 'bg-slate-100 text-slate-700'; ?> text-[9px] font-black uppercase px-2 py-1 rounded-lg tracking-widest">
                    <?php echo ucfirst($r['difficulty_next']); ?>
                </span>
            </div>
        </div>

        <!-- Skill Gap Alerts -->
        <?php if (!empty($r['skill_gaps'])): ?>
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5 mb-8">
            <p class="text-[9px] font-black uppercase text-amber-600 tracking-widest mb-3">
                <i class="fa-solid fa-triangle-exclamation mr-1"></i>Skill Gaps Detected for Your Career Path
            </p>
            <div class="space-y-2">
                <?php foreach ($r['skill_gaps'] as $gap): ?>
                <div class="flex items-center justify-between text-xs">
                    <span class="font-bold text-amber-900"><?php echo htmlspecialchars($gap['skill']); ?></span>
                    <span class="text-amber-700">
                        Current: <?php echo $gap['current']; ?>% &nbsp;|&nbsp;
                        Target: <?php echo $gap['target']; ?>% &nbsp;|&nbsp;
                        Gap: <strong><?php echo $gap['gap']; ?> pts</strong>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Per-Question Breakdown -->
        <div class="mb-8">
            <h2 class="text-lg font-extrabold text-slate-900 mb-5">Question Breakdown</h2>
            <div class="space-y-4">
                <?php foreach ($r['answers'] as $i => $ar): ?>
                <div class="bg-white rounded-2xl border <?php echo $ar['is_correct'] ? 'border-emerald-200' : 'border-red-200'; ?> p-6 shadow-sm">
                    <!-- Question -->
                    <div class="flex items-start space-x-3 mb-4">
                        <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0 <?php echo $ar['is_correct'] ? 'bg-emerald-100' : 'bg-red-100'; ?>">
                            <i class="fa-solid <?php echo $ar['is_correct'] ? 'fa-check text-emerald-600' : 'fa-xmark text-red-600'; ?> text-sm"></i>
                        </div>
                        <p class="font-bold text-slate-800 text-sm leading-snug"><?php echo htmlspecialchars($ar['question_text']); ?></p>
                    </div>

                    <!-- Options grid -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mb-4">
                        <?php foreach (['A','B','C','D'] as $opt):
                            $optKey   = 'option_' . strtolower($opt);
                            $isCorr   = $opt === $ar['correct_option'];
                            $isChosen = $opt === $ar['chosen'];
                            $cls = 'bg-slate-50 border-slate-200 text-slate-600';
                            if ($isCorr)                    $cls = 'bg-emerald-50 border-emerald-300 text-emerald-800';
                            if ($isChosen && !$ar['is_correct']) $cls = 'bg-red-50 border-red-300 text-red-800';
                        ?>
                        <div class="flex items-start space-x-2 border <?php echo $cls; ?> rounded-xl px-3 py-2.5">
                            <span class="font-black text-[10px] uppercase mt-0.5 flex-shrink-0"><?php echo $opt; ?>.</span>
                            <span class="text-xs leading-snug flex-1"><?php echo htmlspecialchars($ar[$optKey] ?? ''); ?></span>
                            <?php if ($isCorr): ?>
                            <i class="fa-solid fa-check text-emerald-500 text-[10px] flex-shrink-0 mt-0.5"></i>
                            <?php elseif ($isChosen && !$ar['is_correct']): ?>
                            <i class="fa-solid fa-xmark text-red-500 text-[10px] flex-shrink-0 mt-0.5"></i>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Your answer summary -->
                    <p class="text-[10px] font-bold text-slate-500 mb-3">
                        Your answer: <span class="<?php echo $ar['is_correct'] ? 'text-emerald-600' : 'text-red-600'; ?> font-black">
                            <?php echo $ar['chosen'] ?: 'Not answered'; ?>
                        </span>
                        &nbsp;·&nbsp; Correct: <span class="text-emerald-600 font-black"><?php echo $ar['correct_option']; ?></span>
                    </p>

                    <!-- Explanation -->
                    <?php if ($ar['explanation']): ?>
                    <div class="bg-indigo-50 border border-indigo-100 rounded-xl px-4 py-3">
                        <p class="text-[9px] font-black uppercase text-indigo-400 tracking-widest mb-1">Explanation</p>
                        <p class="text-indigo-800 text-xs leading-snug"><?php echo htmlspecialchars($ar['explanation']); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex flex-col sm:flex-row gap-3">
            <a href="student_dashboard.php"
               class="flex-1 text-center py-4 bg-slate-900 text-white font-black text-xs uppercase tracking-widest rounded-2xl hover:bg-slate-700 transition-all shadow-lg">
                <i class="fa-solid fa-house mr-2"></i>Return to Dashboard
            </a>
            <?php if ($action !== 'advance'): ?>
            <a href="student_quiz.php?quiz_id=<?php echo $quiz_id; ?>"
               class="flex-1 text-center py-4 bg-indigo-600 text-white font-black text-xs uppercase tracking-widest rounded-2xl hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100">
                <i class="fa-solid fa-rotate mr-2"></i>Retry Quiz
            </a>
            <?php endif; ?>
        </div>

    <?php endif; // End results page ?>
    </div>

    <?php if (!$show_results): ?>
    <script>
    // ── State ──────────────────────────────────────────────────────────
    const totalQ    = <?php echo $total_questions; ?>;
    const answered  = new Array(totalQ).fill(false);
    let currentIdx  = 0;

    // ── Option selection ───────────────────────────────────────────────
    function selectOption(qIdx, letter, labelEl) {
        // Deselect all options in this question
        const allOpts = document.querySelectorAll(`#opts-${qIdx} .opt-btn`);
        allOpts.forEach(o => o.classList.remove('selected'));

        // Select clicked
        labelEl.classList.add('selected');
        answered[qIdx] = true;
        updateProgress();
    }

    // ── Navigation ─────────────────────────────────────────────────────
    function navigate(from, to) {
        if (to < 0 || to >= totalQ) return;
        document.getElementById('q-' + from).classList.remove('active');
        document.getElementById('q-' + to).classList.add('active');
        currentIdx = to;
        updateProgress();
    }

    // ── Progress bar ───────────────────────────────────────────────────
    function updateProgress() {
        const done = answered.filter(Boolean).length;
        const pct  = Math.round((done / totalQ) * 100);
        document.getElementById('progress-fill').style.width  = pct + '%';
        document.getElementById('progress-pct').textContent   = pct + '%';
        document.getElementById('progress-label').textContent =
            `Question ${currentIdx + 1} of ${totalQ} — ${done} answered`;
    }

    // ── Submit guard ────────────────────────────────────────────────────
    function submitQuiz() {
        const unanswered = answered.filter(v => !v).length;
        if (unanswered > 0) {
            if (!confirm(`You have ${unanswered} unanswered question(s). Submit anyway?`)) return;
        }
        document.getElementById('quiz-form').submit();
    }

    updateProgress();
    </script>
    <?php endif; ?>

</body>
</html>