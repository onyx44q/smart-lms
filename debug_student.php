<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include 'config.php';
if (!isset($_SESSION['user_id'])) { echo "Not logged in"; exit(); }
$uid = intval($_SESSION['user_id']);
echo "<h2>Debug for user_id=$uid</h2><pre>";

// Test 1: unit_registrations
$r1 = mysqli_query($conn, "SELECT * FROM unit_registrations WHERE student_id=$uid");
echo "unit_registrations rows: " . mysqli_num_rows($r1) . "\n";
while($r=mysqli_fetch_assoc($r1)) print_r($r);

// Test 2: course_units
$r2 = mysqli_query($conn, "SELECT cu.*, c.title as course FROM course_units cu JOIN courses c ON c.id=cu.course_id");
echo "\ncourse_units rows: " . mysqli_num_rows($r2) . "\n";
while($r=mysqli_fetch_assoc($r2)) print_r($r);

// Test 3: unit_assessments
$r3 = mysqli_query($conn, "SELECT * FROM unit_assessments");
echo "\nunit_assessments rows: " . mysqli_num_rows($r3) . "\n";
while($r=mysqli_fetch_assoc($r3)) print_r($r);

// Test 4: unit_marks
$r4 = mysqli_query($conn, "SELECT * FROM unit_marks WHERE student_id=$uid");
echo "\nunit_marks rows: " . mysqli_num_rows($r4) . "\n";
while($r=mysqli_fetch_assoc($r4)) print_r($r);

// Test 5: boarding_allocations
$r5 = mysqli_query($conn, "SELECT * FROM boarding_allocations WHERE student_id=$uid");
echo "\nboarding_allocations rows: " . ($r5 ? mysqli_num_rows($r5) : "TABLE MISSING") . "\n";
if($r5) while($r=mysqli_fetch_assoc($r5)) print_r($r);

// Test 6: Full result query
$r6 = mysqli_query($conn, "
    SELECT cu.id AS unit_id, cu.title, cu.unit_code, cu.course_id, c.title AS course_title,
           ua.id AS aid, ua.name AS aname, ua.type, ua.max_mark, ua.sort_order,
           um.mark, um.remarks
    FROM unit_registrations ur
    INNER JOIN course_units cu ON cu.id = ur.unit_id
    INNER JOIN courses c ON c.id = cu.course_id
    LEFT JOIN unit_assessments ua ON ua.unit_id = cu.id
    LEFT JOIN unit_marks um ON um.assessment_id = ua.id AND um.student_id = $uid
    WHERE ur.student_id = $uid
    ORDER BY c.title ASC, cu.title ASC, ua.sort_order ASC
");
echo "\nFull result query rows: " . ($r6 ? mysqli_num_rows($r6) : "QUERY FAILED: ".mysqli_error($conn)) . "\n";
if($r6) while($r=mysqli_fetch_assoc($r6)) print_r($r);

// Test 7: Boarding full query
$r7 = mysqli_query($conn, "
    SELECT ba.*, d.name AS dorm_name, d.gender, bm.full_name AS bm_name
    FROM boarding_allocations ba
    JOIN boarding_dorms d ON d.id = ba.dorm_id
    LEFT JOIN users bm ON bm.id = ba.allocated_by
    WHERE ba.student_id = $uid
");
echo "\nBoarding full query: " . ($r7 ? mysqli_num_rows($r7) : "FAILED: ".mysqli_error($conn)) . "\n";
if($r7) while($r=mysqli_fetch_assoc($r7)) print_r($r);

// Test 8: PHP errors
echo "\nPHP error_reporting: " . error_reporting() . "\n";
echo "</pre>";
