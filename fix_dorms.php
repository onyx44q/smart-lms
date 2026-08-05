<?php
include 'config.php';

// Step 1: Show current state
$res = mysqli_query($conn, "SELECT id, name, gender FROM boarding_dorms ORDER BY gender, name, id");
$all = [];
while($r = mysqli_fetch_assoc($res)) $all[] = $r;
echo "<h2>Before fix: " . count($all) . " dorm records</h2><pre>";
foreach($all as $r) echo "id={$r['id']} {$r['gender']} {$r['name']}\n";
echo "</pre>";

// Step 2: Delete duplicates - keep lowest id per name+gender
$del = mysqli_query($conn, "
    DELETE d1 FROM boarding_dorms d1
    INNER JOIN boarding_dorms d2
    ON d1.name = d2.name AND d1.gender = d2.gender AND d1.id > d2.id
");
echo "<p>Deleted duplicates: " . mysqli_affected_rows($conn) . " rows removed</p>";

// Step 3: Add unique key to prevent future duplicates
@mysqli_query($conn, "ALTER TABLE boarding_dorms DROP INDEX uq_dorm_name_gender");
$uk = mysqli_query($conn, "ALTER TABLE boarding_dorms ADD UNIQUE KEY uq_dorm_name_gender (name, gender)");
echo "<p>Unique key: " . ($uk ? "✅ Added" : "⚠ " . mysqli_error($conn)) . "</p>";

// Step 4: Show final state
$res2 = mysqli_query($conn, "SELECT id, name, gender FROM boarding_dorms ORDER BY gender, name");
$final = [];
while($r = mysqli_fetch_assoc($res2)) $final[] = $r;
echo "<h2>After fix: " . count($final) . " dorm records</h2><pre>";
foreach($final as $r) echo "id={$r['id']} {$r['gender']} {$r['name']}\n";
echo "</pre>";
echo "<p style='color:green;font-weight:bold'>Done! Delete this file now.</p>";
?>
