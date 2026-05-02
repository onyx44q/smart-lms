<?php
include 'config.php';
checkRole('lecturer');

$id    = intval($_GET['id']);
$query = mysqli_query($conn, "SELECT * FROM materials WHERE id = $id");
$data  = mysqli_fetch_assoc($query);

if (!$data) {
    header("Location: lecturer_dashboard.php");
    exit();
}

if (isset($_POST['update_resource'])) {
    $title     = mysqli_real_escape_string($conn, $_POST['title']);
    $course_id = intval($_POST['course_id']);

    mysqli_query($conn, "UPDATE materials SET title = '$title', course_id = $course_id WHERE id = $id");
    header("Location: lecturer_dashboard.php?view_course=$course_id&updated=1");
    exit();
}

// The course to return to when Cancel is clicked (original course before any change)
$return_course = intval($data['course_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>Edit Resource</title>
</head>
<body class="bg-slate-50 flex items-center justify-center min-h-screen">
    <div class="bg-white p-8 rounded-[2.5rem] shadow-2xl border border-slate-200 w-full max-w-md">
        <h2 class="text-xl font-black uppercase italic mb-6">Update <span class="text-indigo-600">Material</span></h2>
        <form method="POST" class="space-y-4">
            <div>
                <label class="text-[10px] font-black uppercase text-slate-400">Title</label>
                <input type="text" name="title" value="<?php echo htmlspecialchars($data['title']); ?>" class="w-full p-4 bg-slate-50 border rounded-2xl outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="text-[10px] font-black uppercase text-slate-400">Change Course</label>
                <select name="course_id" class="w-full p-4 bg-slate-50 border rounded-2xl">
                    <?php 
                    $courses = mysqli_query($conn, "SELECT * FROM courses");
                    while ($c = mysqli_fetch_assoc($courses)) {
                        $selected = ($c['id'] == $data['course_id']) ? "selected" : "";
                        echo "<option value='{$c['id']}' $selected>{$c['title']}</option>";
                    }
                    ?>
                </select>
            </div>
            <button type="submit" name="update_resource" class="w-full py-4 bg-indigo-600 text-white rounded-2xl font-black uppercase tracking-widest shadow-lg">Save Changes</button>
            <a href="lecturer_dashboard.php?view_course=<?php echo $return_course; ?>" class="block text-center text-slate-400 text-[10px] font-black uppercase tracking-widest mt-4">Cancel</a>
        </form>
    </div>
</body>
</html>