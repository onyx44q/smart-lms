<?php
include 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_SESSION['user_id'])) {
    $lecturer_id = $_SESSION['user_id'];
    $course_id = mysqli_real_escape_string($conn, $_POST['course_id']);
    $title = mysqli_real_escape_string($conn, $_POST['title']);
    $type = $_POST['type']; // 'pdf' or 'video'
    
    // Create folders if they don't exist
    if (!is_dir('uploads/notes')) mkdir('uploads/notes', 0777, true);
    if (!is_dir('uploads/videos')) mkdir('uploads/videos', 0777, true);

    $file = $_FILES['resource_file'];
    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // Validate File Type
    $allowed = ($type == 'video') ? ['mp4', 'mov', 'avi'] : ['pdf'];
    
    if (in_array($fileExt, $allowed)) {
        $newName = time() . "_" . preg_replace("/[^A-Za-z0-9.]/", "_", $file['name']);
        $targetDir = ($type == 'video') ? "uploads/videos/" : "uploads/notes/";
        $targetPath = $targetDir . $newName;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            // Save to database
            $sql = "INSERT INTO materials (course_id, lecturer_id, title, type, file_path) 
                    VALUES ('$course_id', '$lecturer_id', '$title', '$type', '$targetPath')";
            
            if (mysqli_query($conn, $sql)) {
                header("Location: lecturer_dashboard.php?upload=success");
            } else {
                echo "Database Error: " . mysqli_error($conn);
            }
        } else {
            echo "Failed to move uploaded file.";
        }
    } else {
        echo "Invalid file type for selected category.";
    }
}
?>