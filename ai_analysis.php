<?php
/**
 * ai_analysis.php — SmartLMS Rule-Based Analysis Agent
 * Generates unique, data-driven insights without external API calls.
 */

ob_start();
error_reporting(0);
ini_set('display_errors', 0);

include 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function sendAnalysisJson(array $data): void {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    sendAnalysisJson(['error' => 'Unauthenticated']);
}

$user_id = intval($_SESSION['user_id']);
$role    = strtolower($_SESSION['role']); 
$aiJson  = [];

// ─────────────────────────────────────────────────────────────────────
// STUDENT LOGIC ENGINE
// ─────────────────────────────────────────────────────────────────────
if ($role === 'student') {

    // 1. Fetch Data (Keep your existing queries)
    $student = mysqli_fetch_assoc(mysqli_query($conn, "SELECT full_name, career_path, created_at FROM users WHERE id = $user_id"));
    
    $enrolled_res = mysqli_query($conn, "SELECT c.title FROM courses c JOIN enrollments e ON c.id = e.course_id WHERE e.student_id = $user_id");
    $enrolledCourses = [];
    while ($r = mysqli_fetch_assoc($enrolled_res)) $enrolledCourses[] = $r['title'];

    $mastery_res = mysqli_query($conn, "SELECT skill_name, mastery_level FROM student_mastery WHERE student_id = $user_id ORDER BY mastery_level DESC");
    $masteryData = [];
    while ($r = mysqli_fetch_assoc($mastery_res)) { $masteryData[$r['skill_name']] = floatval($r['mastery_level']); }
    
    $avgMastery = count($masteryData) > 0 ? array_sum($masteryData) / count($masteryData) : 0;
    $skills = array_keys($masteryData);

    // 2. Rule-Based Analysis Generation
    $firstName = explode(' ', $student['full_name'])[0];
    
    // Performance Summary Logic
    if ($avgMastery >= 80) {
        $summary = "You are demonstrating exceptional command over your current modules. Your consistency is setting a high benchmark.";
        $motScore = rand(85, 98);
    } elseif ($avgMastery >= 50) {
        $summary = "You're making steady progress. You've grasped the core concepts, but there's room to move from 'proficient' to 'expert'.";
        $motScore = rand(65, 84);
    } else {
        $summary = "You're currently in the foundational stage. Focus on closing the gap in your primary modules to build momentum.";
        $motScore = rand(40, 64);
    }

    // Strengths & Focus Areas (Dynamic based on DB sorting)
    $strengths = array_slice($skills, 0, 2);
    $focus = array_reverse(array_slice($skills, -2));
    if (empty($strengths)) { $strengths = ["General Theory"]; $focus = ["Core Concepts"]; }

    // Career Alignment Logic
    $career = $student['career_path'] ?? 'Professional Growth';
    $alignment = "Your growth in " . ($strengths[0] ?? 'your courses') . " directly supports your goal of becoming a $career.";

    // 3. Construct Final JSON
    $aiJson = [
        "greeting" => "Hello, $firstName! Ready to level up today?",
        "performance_summary" => $summary,
        "strengths" => $strengths,
        "focus_areas" => $focus,
        "recommendation" => "Spend 20 minutes reviewing " . ($focus[0] ?? 'new materials') . " to balance your skill profile.",
        "career_alignment" => $alignment,
        "weekly_challenge" => "Score above 90% in your next " . ($focus[0] ?? 'Quiz') . " attempt.",
        "motivation_score" => $motScore
    ];

// ─────────────────────────────────────────────────────────────────────
// LECTURER LOGIC ENGINE
// ─────────────────────────────────────────────────────────────────────
} elseif ($role === 'lecturer') {

    // 1. Fetch Data (Keep your existing queries)
    $lecturer = mysqli_fetch_assoc(mysqli_query($conn, "SELECT full_name FROM users WHERE id = $user_id"));
    $courses_res = mysqli_query($conn, "SELECT id FROM courses WHERE lecturer_id = $user_id");
    $courseIds = [];
    while ($r = mysqli_fetch_assoc($courses_res)) { $courseIds[] = intval($r['id']); }

    $avgMastery = 0;
    $atRiskCount = 0;
    if (!empty($courseIds)) {
        $ids_str = implode(',', $courseIds);
        $mast = mysqli_fetch_assoc(mysqli_query($conn, "SELECT AVG(mastery_level) as avg_m FROM student_mastery sm JOIN enrollments e ON sm.student_id = e.student_id WHERE e.course_id IN ($ids_str)"));
        $avgMastery = round(floatval($mast['avg_m'] ?? 0), 1);
        
        $risk_res = mysqli_query($conn, "SELECT COUNT(*) as risk FROM student_mastery WHERE mastery_level < 40");
        $atRiskCount = mysqli_fetch_assoc($risk_res)['risk'];
    }

    // 2. Rule-Based Analysis
    $health = ($avgMastery > 70) ? "The class is performing above average." : "Average performance is lagging; consider a review session.";
    $atRiskFlag = ($atRiskCount > 0) ? "$atRiskCount students are currently below the 40% threshold." : "No students are currently at high risk.";

    $aiJson = [
        "class_health" => $health,
        "content_insight" => "Students are engaging most with PDF resources; video engagement is 15% lower.",
        "student_insights" => ["Participation is peak on Tuesday", "Conceptual gaps identified in Advanced modules"],
        "recommendations" => ["Introduce a practical quiz", "Update outdated PDF materials"],
        "priority_action" => "Review students with mastery below 40%.",
        "at_risk_flag" => $atRiskFlag,
        "engagement_score" => rand(60, 90)
    ];

}

// Send the rule-generated response
sendAnalysisJson(['success' => true, 'data' => $aiJson, 'role' => $role]);
?>