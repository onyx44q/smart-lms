<?php
include 'config.php';
$course_id = mysqli_real_escape_string($conn, $_GET['course_id']);
$query = mysqli_query($conn, "SELECT * FROM materials WHERE course_id = '$course_id' ORDER BY id DESC");
$data = [];
while($row = mysqli_fetch_assoc($query)) {
    $data[] = $row;
}
echo json_encode($data);
?>